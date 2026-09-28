<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CertificateRequest;
use App\Models\CertificateTemplate;
use App\Models\CertificateTemplateVersion;
use App\Models\IssuedCertificate;
use App\Services\CertificateResolverService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CertificateRequestController extends Controller
{
    public function __construct(protected CertificateResolverService $resolver) {}

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = CertificateRequest::with(['employee', 'template']);

        // Managers of certificates may list everyone in scope with ?all=1;
        // everyone else only ever sees their own requests.
        if ($request->boolean('all') && $user->can('certificates.manage')) {
            $query->visibleTo($user);
        } else {
            $query->where('employee_id', $user->employee_id ?? 0);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'template_id' => 'required|exists:certificate_templates,id',
        ]);

        $employeeId = $validated['employee_id'] ?? $user->employee_id;

        if (!$employeeId) {
            return response()->json(['message' => 'Employee not found for this user.'], 422);
        }

        // Requesting on someone else's behalf is an HR action.
        if ((int) $employeeId !== $user->employee_id) {
            abort_unless($user->can('certificates.manage'), 403, 'You can only request certificates for yourself.');
        }
        $employee = $this->authorizeEmployeeVisible((int) $employeeId);

        $template = CertificateTemplate::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->findOrFail($validated['template_id']);
        if ($template->branch_id !== $employee->branch_id) {
            return response()->json(['message' => "This template is not available for the employee's branch."], 422);
        }

        if ($template->status !== 'published') {
            return response()->json(['message' => 'Template must be published.'], 422);
        }

        $certRequest = CertificateRequest::create([
            'employee_id'  => $employeeId,
            'template_id'  => $validated['template_id'],
            'status'       => 'pending',
            'requested_by' => $user->id,
        ]);

        return response()->json(['data' => $certRequest->load(['employee', 'template'])], 201);
    }

    public function show(CertificateRequest $request): JsonResponse
    {
        $this->authorizeEmployeeVisible($request->employee_id);

        return response()->json(['data' => $request->load(['employee', 'template', 'issuedCertificate'])]);
    }

    public function approve(CertificateRequest $request): JsonResponse
    {
        $this->assertActionable($request);

        $request->update([
            'approved_by' => auth()->id(),
            'status'      => 'approved',
        ]);

        $template = $request->template;
        $employee = $request->employee;

        // Create version snapshot of current template content
        $maxVersion = $template->versions()->max('version_no') ?? 0;
        $version = CertificateTemplateVersion::create([
            'template_id' => $template->id,
            'html_body'   => $template->html_body,
            'header_html' => $template->header_html,
            'footer_html' => $template->footer_html,
            'version_no'  => $maxVersion + 1,
        ]);

        // Resolve tokens
        $resolvedHtml = $this->resolver->resolve($template->html_body, $employee);

        // Generate certificate number
        $branchCode   = strtoupper(substr(str_replace(' ', '', $employee->branch->name ?? 'HQ'), 0, 3));
        $certNumber   = 'CERT-' . $branchCode . '-' . date('Y') . '-' . str_pad($request->id, 4, '0', STR_PAD_LEFT);

        $resolvedHtml = $this->resolver->resolveWithCertNumber($resolvedHtml, $certNumber);

        // Generate PDF
        $fullHtml = view('certificate_pdf', [
            'resolvedHtml'  => $resolvedHtml,
            'headerHtml'    => $template->header_html,
            'footerHtml'    => $template->footer_html,
            'logoPath'      => $template->logo_path
                ? storage_path('app/public/' . $template->logo_path)
                : null,
            'signaturePath' => $template->signature_path
                ? storage_path('app/public/' . $template->signature_path)
                : null,
        ])->render();

        $pdf      = Pdf::loadHtml($fullHtml)->setPaper('a4', 'portrait');
        $filename = \App\Support\Tenancy\TenantStorage::path($request->company_id, 'certificates/' . $certNumber . '.pdf');
        Storage::disk('local')->put($filename, $pdf->output());

        // Create IssuedCertificate
        $issued = IssuedCertificate::create([
            'request_id'          => $request->id,
            'template_version_id' => $version->id,
            'employee_id'         => $employee->id,
            'resolved_html'       => $resolvedHtml,
            'pdf_path'            => $filename,
            'certificate_number'  => $certNumber,
            'issued_at'           => now(),
        ]);

        return response()->json(['data' => $issued->load(['employee', 'templateVersion'])]);
    }

    public function reject(CertificateRequest $request): JsonResponse
    {
        $this->assertActionable($request);

        $request->validate([
            'comments' => 'nullable|string',
        ]);

        $request->update([
            'status'   => 'rejected',
            'comments' => request('comments'),
        ]);

        return response()->json(['data' => $request]);
    }

    private function assertActionable(CertificateRequest $certRequest): void
    {
        abort_unless($certRequest->status === 'pending', 422, 'This request has already been processed.');
        $this->authorizeEmployeeVisible($certRequest->employee_id);

        $user = request()->user();
        abort_if($certRequest->employee_id === $user->employee_id && ! $user->isTenantAdmin(), 403,
            'You cannot approve or reject your own request.');
    }
}

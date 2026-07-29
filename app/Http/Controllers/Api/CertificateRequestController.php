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
        $user     = auth()->user();
        $employee = $user->employee ?? null;

        $query = CertificateRequest::with(['employee', 'template']);

        // Employees only see their own requests
        if ($employee && !$request->boolean('all')) {
            $query->where('employee_id', $employee->id);
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

        $employeeId = $validated['employee_id'] ?? optional($user->employee)->id;

        if (!$employeeId) {
            return response()->json(['message' => 'Employee not found for this user.'], 422);
        }

        $template = CertificateTemplate::findOrFail($validated['template_id']);

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
        return response()->json(['data' => $request->load(['employee', 'template', 'issuedCertificate'])]);
    }

    public function approve(CertificateRequest $request): JsonResponse
    {
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
        $filename = 'certificates/' . $certNumber . '.pdf';
        Storage::disk('public')->put($filename, $pdf->output());

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
        $request->validate([
            'comments' => 'nullable|string',
        ]);

        $request->update([
            'status'   => 'rejected',
            'comments' => request('comments'),
        ]);

        return response()->json(['data' => $request]);
    }
}

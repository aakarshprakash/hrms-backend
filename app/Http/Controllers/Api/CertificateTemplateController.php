<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CertificateTemplate;
use App\Models\CertificateTemplateVersion;
use App\Models\IssuedCertificate;
use App\Services\HtmlPurifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CertificateTemplateController extends Controller
{
    public function __construct(protected HtmlPurifierService $purifier) {}

    public function index(Request $request): JsonResponse
    {
        $query = CertificateTemplate::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        return response()->json(['data' => $query->withCount('versions')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'name'        => 'required|string|max:255',
            'type'        => 'required|in:experience,joining,salary_hike,relieving,noc,custom',
            'html_body'   => 'required|string',
            'header_html' => 'nullable|string',
            'footer_html' => 'nullable|string',
            'status'      => 'nullable|in:draft,published',
        ]);

        $validated['html_body']   = $this->purifier->purify($validated['html_body']);
        $validated['header_html'] = isset($validated['header_html']) ? $this->purifier->purify($validated['header_html']) : null;
        $validated['footer_html'] = isset($validated['footer_html']) ? $this->purifier->purify($validated['footer_html']) : null;
        $validated['created_by']  = auth()->id();

        $template = CertificateTemplate::create($validated);

        return response()->json(['data' => $template], 201);
    }

    public function show(CertificateTemplate $template): JsonResponse
    {
        return response()->json(['data' => $template->load('versions')->loadCount('versions')]);
    }

    public function update(Request $request, CertificateTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'type'        => 'sometimes|in:experience,joining,salary_hike,relieving,noc,custom',
            'html_body'   => 'sometimes|string',
            'header_html' => 'nullable|string',
            'footer_html' => 'nullable|string',
            'status'      => 'nullable|in:draft,published',
        ]);

        // If published and being edited, snapshot current version first
        if ($template->status === 'published' && isset($validated['html_body'])) {
            $maxVersion = $template->versions()->max('version_no') ?? 0;
            CertificateTemplateVersion::create([
                'template_id' => $template->id,
                'html_body'   => $template->html_body,
                'header_html' => $template->header_html,
                'footer_html' => $template->footer_html,
                'version_no'  => $maxVersion + 1,
            ]);
        }

        if (isset($validated['html_body'])) {
            $validated['html_body'] = $this->purifier->purify($validated['html_body']);
        }
        if (array_key_exists('header_html', $validated) && $validated['header_html'] !== null) {
            $validated['header_html'] = $this->purifier->purify($validated['header_html']);
        }
        if (array_key_exists('footer_html', $validated) && $validated['footer_html'] !== null) {
            $validated['footer_html'] = $this->purifier->purify($validated['footer_html']);
        }

        $template->update($validated);

        return response()->json(['data' => $template]);
    }

    public function destroy(CertificateTemplate $template): JsonResponse
    {
        $hasIssued = IssuedCertificate::whereHas('request', fn($q) => $q->where('template_id', $template->id))
            ->exists();

        if ($hasIssued) {
            return response()->json(['message' => 'Cannot delete template with issued certificates.'], 422);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    public function publish(CertificateTemplate $template): JsonResponse
    {
        $template->update(['status' => 'published']);

        return response()->json(['data' => $template]);
    }

    public function clone(CertificateTemplate $template): JsonResponse
    {
        $clone = $template->replicate();
        $clone->name   = 'Copy of ' . $template->name;
        $clone->status = 'draft';
        $clone->save();

        return response()->json(['data' => $clone], 201);
    }
}

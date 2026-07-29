<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IssuedCertificate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class IssuedCertificateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = IssuedCertificate::with(['employee', 'templateVersion']);

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(IssuedCertificate $certificate): JsonResponse
    {
        return response()->json(['data' => $certificate->load(['employee', 'templateVersion', 'request'])]);
    }

    public function pdf(IssuedCertificate $certificate): Response
    {
        if ($certificate->pdf_path && Storage::disk('public')->exists($certificate->pdf_path)) {
            $contents = Storage::disk('public')->get($certificate->pdf_path);
            return response($contents, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $certificate->certificate_number . '.pdf"',
            ]);
        }

        // Regenerate if missing
        $pdf      = Pdf::loadHtml($certificate->resolved_html)->setPaper('a4', 'portrait');
        $filename = 'certificates/' . $certificate->certificate_number . '.pdf';
        Storage::disk('public')->put($filename, $pdf->output());

        $certificate->update(['pdf_path' => $filename]);

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $certificate->certificate_number . '.pdf"',
        ]);
    }
}

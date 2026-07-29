<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IssuedCertificate;
use Illuminate\Http\JsonResponse;

class PublicCertificateController extends Controller
{
    public function verify(string $certificateNumber): JsonResponse
    {
        $certificate = IssuedCertificate::with(['employee', 'request.template'])
            ->where('certificate_number', $certificateNumber)
            ->first();

        if (!$certificate) {
            return response()->json(['valid' => false], 200);
        }

        $employee = $certificate->employee;
        $lastName = $employee->last_name ?? '';
        $lastInitial = $lastName ? strtoupper(substr($lastName, 0, 1)) . '.' : '';

        return response()->json([
            'valid'          => true,
            'type'           => $certificate->request?->template?->type ?? 'custom',
            'issued_at'      => $certificate->issued_at?->format('d M Y') ?? '',
            'employee_name'  => ($employee->first_name ?? '') . ' ' . $lastInitial,
            'branch'         => $employee->branch?->name ?? '',
        ]);
    }
}

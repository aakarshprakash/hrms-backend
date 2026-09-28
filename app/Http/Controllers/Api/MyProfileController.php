<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service profile edits: the personal details an employee owns
 * (contact, address, emergency contact) and their income-tax regime and
 * declarations. Employment, pay and identity fields stay with HR. Every
 * change is in the employee's audit trail.
 */
class MyProfileController extends Controller
{
    public const EDITABLE = [
        'phone', 'personal_email', 'marital_status', 'blood_group',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'tax_regime', 'declared_deductions',
    ];

    public function update(Request $request): JsonResponse
    {
        $employee = $this->actorEmployee();
        abort_unless($employee, 422, 'Your account isn’t linked to an employee profile.');

        $validated = $request->validate([
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{8,20}$/'],
            'personal_email' => 'sometimes|nullable|email|max:191',
            'marital_status' => 'sometimes|nullable|in:single,married,divorced,widowed',
            'blood_group' => 'sometimes|nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'address_line1' => 'sometimes|nullable|string|max:255',
            'address_line2' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:100',
            'state' => 'sometimes|nullable|string|max:100',
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9 -]+$/'],
            'country' => 'sometimes|nullable|string|max:100',
            'emergency_contact_name' => 'sometimes|nullable|string|max:191',
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[+0-9 ()-]{8,20}$/'],
            'emergency_contact_relation' => 'sometimes|nullable|string|max:100',
            'tax_regime' => 'sometimes|in:new,old',
            'declared_deductions' => 'sometimes|nullable|numeric|min:0|max:10000000',
        ]);

        $employee->fill(array_intersect_key($validated, array_flip(self::EDITABLE)))->save();

        return response()->json([
            'data' => $employee->fresh()->only(array_merge(['id'], self::EDITABLE)),
            'message' => 'Your details are updated.',
        ]);
    }
}

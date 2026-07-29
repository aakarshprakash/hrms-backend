<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        $media = $employee->getMedia('documents');

        return response()->json([
            'data' => $media,
            'message' => 'Documents retrieved successfully.',
        ]);
    }

    public function upload(Request $request, Employee $employee): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $media = $employee->addMediaFromRequest('file')
            ->toMediaCollection('documents');

        return response()->json([
            'data' => $media,
            'message' => 'Document uploaded successfully.',
        ], 201);
    }

    public function destroy(Employee $employee, int $mediaId): JsonResponse
    {
        $media = $employee->getMedia('documents')->firstWhere('id', $mediaId);

        if (! $media) {
            return response()->json([
                'data' => null,
                'message' => 'Document not found.',
            ], 404);
        }

        $media->delete();

        return response()->json([
            'data' => null,
            'message' => 'Document deleted successfully.',
        ], 204);
    }
}

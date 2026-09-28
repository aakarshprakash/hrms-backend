<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee documents (ID proofs, offer letters, ...). Stored on the private
 * disk and only ever served through download(), after authorization -- never
 * via a public URL.
 */
class DocumentController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        $this->authorize('viewSensitive', $employee);

        $documents = $employee->getMedia('documents')->map(fn (Media $media) => [
            'id' => $media->id,
            'name' => $media->name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'document_type' => $media->getCustomProperty('document_type'),
            'created_at' => $media->created_at,
            'download_url' => url("/api/employees/{$employee->id}/documents/{$media->id}/download"),
        ])->values();

        return response()->json([
            'data' => $documents,
            'message' => 'Documents retrieved successfully.',
        ]);
    }

    public function upload(Request $request, Employee $employee): JsonResponse
    {
        // Your own documents, or anyone's within scope with employees.manage.
        $this->authorize('updateAvatar', $employee);

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'document_type' => ['nullable', 'string', 'max:50'],
        ]);

        $media = $employee->addMediaFromRequest('file')
            ->withCustomProperties(['document_type' => $request->input('document_type'), 'uploaded_by' => $request->user()->id])
            ->toMediaCollection('documents');

        return response()->json([
            'data' => ['id' => $media->id, 'file_name' => $media->file_name],
            'message' => 'Document uploaded successfully.',
        ], 201);
    }

    public function download(Employee $employee, int $media): StreamedResponse
    {
        $this->authorize('viewSensitive', $employee);

        $item = $employee->getMedia('documents')->firstWhere('id', $media);
        abort_unless($item, 404, 'Document not found.');

        return response()->streamDownload(function () use ($item) {
            $stream = $item->stream();
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, $item->file_name, ['Content-Type' => $item->mime_type ?: 'application/octet-stream']);
    }

    public function destroy(Employee $employee, int $media): JsonResponse
    {
        $this->authorize('update', $employee);

        $item = $employee->getMedia('documents')->firstWhere('id', $media);

        if (! $item) {
            return response()->json([
                'data' => null,
                'message' => 'Document not found.',
            ], 404);
        }

        $item->delete();

        return response()->json([
            'data' => null,
            'message' => 'Document deleted successfully.',
        ], 204);
    }
}

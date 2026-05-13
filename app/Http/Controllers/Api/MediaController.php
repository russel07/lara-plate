<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Http\Requests\UpdateMediaRequest;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private MediaService $service) {}

    public function upload(UploadMediaRequest $request): JsonResponse
    {
        try {
            $file = $request->hasFile('file')
                ? $request->file('file')
                : $this->makeUploadedFileFromContent(
                    $request->input('content'),
                    $request->input('filename', 'upload.bin')
                );

            $media = $this->service->upload(
                $file,
                $this->normalizeType($request->validated('type')),
                $request->input('visibility', 'public'),
            );

            return response()->json([
                'status' => true,
                'message' => 'File uploaded successfully.',
                'data' => [
                    'id' => $media->id,
                    'hash' => $media->hash,
                    'url' => $media->url,
                    'original_name' => $media->original_name,
                    'file_type' => $media->file_type,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'visibility' => $media->visibility,
                    'created_at' => $media->created_at,
                    'uploaded_by' => $media->user ? [
                        'id' => $media->user->id,
                        'name' => $media->user->name,
                    ] : null,
                    'title' => $media->title,
                    'alternate_text' => $media->alternate_text,
                    'caption' => $media->caption,
                    'description' => $media->description,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to upload file.',
                'error' => app()->environment(['local', 'testing']) ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['type', 'date_from', 'date_to', 'search']);
        $perPage = (int) $request->get('per_page', 20);

        $paginator = $this->service->list($filters, $perPage);

        $items = collect($paginator->items())->map(fn ($media) => [
            'id' => $media->id,
            'hash' => $media->hash,
            'url' => $media->url,
            'original_name' => $media->original_name,
            'file_type' => $media->file_type,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'visibility' => $media->visibility,
            'created_at' => $media->created_at,
            'uploaded_by' => $media->user ? [
                'id' => $media->user->id,
                'name' => $media->user->name,
            ] : null,
            'title' => $media->title,
            'alternate_text' => $media->alternate_text,
            'caption' => $media->caption,
            'description' => $media->description,
        ]);

        return response()->json([
            'status' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        try {
            $media = $this->service->find($id);

            return response()->json([
                'status' => true,
                'data' => [
                    'id' => $media->id,
                    'hash' => $media->hash,
                    'url' => $media->url,
                    'original_name' => $media->original_name,
                    'file_name' => $media->file_name,
                    'file_type' => $media->file_type,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'disk' => $media->disk,
                    'visibility' => $media->visibility,
                    'created_at' => $media->created_at,
                    'updated_at' => $media->updated_at,
                    'uploaded_by' => $media->user ? [
                        'id' => $media->user->id,
                        'name' => $media->user->name,
                    ] : null,
                    'title' => $media->title,
                    'alternate_text' => $media->alternate_text,
                    'caption' => $media->caption,
                    'description' => $media->description,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Media not found.',
            ], 404);
        }
    }

    public function update(int $id, UpdateMediaRequest $request): JsonResponse
    {
        try {
            $file = $request->hasFile('file')
                ? $request->file('file')
                : ($request->filled('content')
                    ? $this->makeUploadedFileFromContent(
                        $request->input('content'),
                        $request->input('filename', 'upload.bin')
                    )
                    : null);

            $media = $this->service->update(
                $id,
                $file,
                $request->filled('type') ? $this->normalizeType($request->validated('type')) : null,
                $request
            );

            return response()->json([
                'status' => true,
                'message' => 'File updated successfully.',
                'data' => [
                    'id' => $media->id,
                    'hash' => $media->hash,
                    'url' => $media->url,
                    'original_name' => $media->original_name,
                    'file_type' => $media->file_type,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'visibility' => $media->visibility,
                    'created_at' => $media->created_at,
                    'uploaded_by' => $media->user ? [
                        'id' => $media->user->id,
                        'name' => $media->user->name,
                    ] : null,
                    'title' => $media->title,
                    'alternate_text' => $media->alternate_text,
                    'caption' => $media->caption,
                    'description' => $media->description,
                    'updated_at' => $media->updated_at,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Media not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update file.',
                'error' => app()->environment(['local', 'testing']) ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->service->delete($id);

            return response()->json([
                'status' => true,
                'message' => 'File deleted successfully.',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Media not found.',
            ], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }
    }

    private function makeUploadedFileFromContent(string $content, string $filename): UploadedFile
    {
        $decoded = base64_decode($content, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid file content.');
        }

        $path = tempnam(sys_get_temp_dir(), 'upload_');
        file_put_contents($path, $decoded);

        // testMode=true tells Symfony to delete the temp file after it is moved
        return new UploadedFile($path, $filename, null, null, true);
    }

    private function normalizeType(string $type): string
    {
        if (!str_contains($type, '/')) {
            return $type;
        }

        [$group, $sub] = explode('/', $type, 2);

        return match ($group) {
            'image' => 'image',
            'video' => 'video',
            'audio' => 'audio',
            'application' => $sub === 'pdf' ? 'pdf' : 'document',
            'text' => 'document',
            default => 'document',
        };
    }

    public function serve(string $hash): StreamedResponse
    {
        return $this->service->serve($hash);
    }
}

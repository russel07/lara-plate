<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaService
{
    public function upload(UploadedFile $file, string $type, string $visibility = 'public'): Media
    {
        $orgSlug = app('currentOrganization')->slug;
        $userId = auth()->id();
        $disk = config('media.disk', 'public');

        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $fileName = Str::uuid() . '.' . $extension;
        $directory = "uploads/{$orgSlug}/{$type}";

        Storage::disk($disk)->putFileAs($directory, $file, $fileName);

        $hash = hash('sha256', Str::uuid()->toString() . microtime(true) . $originalName);

        return Media::create([
            'organization_id' => app('currentOrganization')->id,
            'user_id' => $userId,
            'original_name' => $originalName,
            'file_name' => $fileName,
            'file_path' => "{$directory}/{$fileName}",
            'file_type' => $type,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash,
            'disk' => $disk,
            'visibility' => $visibility,
        ]);
    }

    public function serve(string $hash): StreamedResponse
    {
        $media = Media::withoutGlobalScopes()->where('hash', $hash)->firstOrFail();

        $disk = Storage::disk($media->disk);

        if (!$disk->exists($media->file_path)) {
            abort(404, 'File not found.');
        }

        return $disk->response($media->file_path, $media->original_name, [
            'Content-Type' => $media->mime_type,
        ]);
    }

    public function list(array $filters = [], int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = Media::query()->orderByDesc('created_at');

        if (!empty($filters['type'])) {
            $query->where('file_type', $filters['type']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }

        return $query->paginate($perPage);
    }

    public function find(int $id): Media
    {
        return Media::findOrFail($id);
    }

    public function update(int $id, ?UploadedFile $file, ?string $type, Request $request): Media
    {
        $media = Media::findOrFail($id);

        // Multi-tenant safety
        if ($media->organization_id !== app('currentOrganization')->id) {
            abort(403, 'Unauthorized');
        }

        $resolvedType = $type ?? $media->file_type;

        $updateData = [
            'file_type' => $resolvedType,
            'visibility' => $request->input('visibility', $media->visibility),
            'alternate_text' => $request->input('alternate_text', $media->alternate_text),
            'title' => $request->input('title', $media->title),
            'caption' => $request->input('caption', $media->caption),
            'description' => $request->input('description', $media->description),
        ];

        // If new file is uploaded → replace file
        if ($file) {

            // Delete old file safely
            if ($media->file_path && Storage::disk($media->disk)->exists($media->file_path)) {
                Storage::disk($media->disk)->delete($media->file_path);
            }

            $orgSlug = app('currentOrganization')->slug;
            $directory = "uploads/{$orgSlug}/{$resolvedType}";
            $extension = $file->getClientOriginalExtension();
            $fileName = Str::uuid() . '.' . $extension;

            Storage::disk($media->disk)->putFileAs($directory, $file, $fileName);

            // Merge file-related fields
            $updateData = array_merge($updateData, [
                'original_name' => $file->getClientOriginalName(),
                'file_name' => $fileName,
                'file_path' => "{$directory}/{$fileName}",
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }

        $media->update($updateData);

        return $media->fresh();
    }

    public function delete(int $id): void
    {
        $media = Media::findOrFail($id);

        $user = auth()->user();
        if ((int) $media->user_id !== (int) $user->id && $user->role !== 'admin') {
            abort(403, 'You do not have permission to delete this file.');
        }

        Storage::disk($media->disk)->delete($media->file_path);
        $media->delete();
    }
}

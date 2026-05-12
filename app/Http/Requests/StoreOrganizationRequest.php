<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize()
    {
       return auth()->check();
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|unique:organizations,slug|max:20',
            'logo' => 'nullable',
            'filename' => 'nullable|string|max:255',
            'website' => 'nullable|max:255|regex:/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i',
            'industry' => 'nullable|string|max:255',
            'company_size' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:255'
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->hasFile('logo')) {
                $fileValidator = Validator::make(
                    ['logo' => $this->file('logo')],
                    ['logo' => 'image|mimes:png,jpg,jpeg,svg|dimensions:min_width=100,min_height=100|max:512']
                );

                if ($fileValidator->fails()) {
                    foreach ($fileValidator->errors()->get('logo') as $message) {
                        $validator->errors()->add('logo', $message);
                    }
                }

                return;
            }

            if (!$this->filled('logo')) {
                return;
            }

            $rawLogo = $this->input('logo');
            if (!is_string($rawLogo)) {
                $validator->errors()->add('logo', 'Logo must be an uploaded file or base64 content string.');
                return;
            }

            $payload = str_contains($rawLogo, ',') ? explode(',', $rawLogo, 2)[1] : $rawLogo;
            $decoded = base64_decode($payload, true);

            if ($decoded === false) {
                $validator->errors()->add('logo', 'Logo content must be valid base64 encoded data.');
                return;
            }

            if (strlen($decoded) > (512 * 1024)) {
                $validator->errors()->add('logo', 'Logo cannot exceed 512 KB.');
                return;
            }

            $isSvg = str_contains(strtolower($decoded), '<svg');
            if ($isSvg) {
                return;
            }

            $dimensions = @getimagesizefromstring($decoded);
            if ($dimensions === false) {
                $validator->errors()->add('logo', 'Logo must be a valid image.');
                return;
            }

            $width = $dimensions[0] ?? 0;
            $height = $dimensions[1] ?? 0;
            if ($width < 100 || $height < 100) {
                $validator->errors()->add('logo', 'Logo must be at least 100x100 pixels.');
            }

            $mime = strtolower((string) ($dimensions['mime'] ?? ''));
            if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
                $validator->errors()->add('logo', 'Logo must be a PNG, JPG, JPEG, or SVG file.');
            }
        });
    }

    public function messages()
    {
        return [
            'name.required' => 'Organization name is required.',
            'name.string' => 'Organization name must be a string.',
            'name.max' => 'Organization name cannot exceed 255 characters.',
            'slug.required' => 'Subdomin is required',
            'slug.string' => 'Subdomin must be a string',
            'slug.unique' => 'Subdomin already exists',
            'slug.max' => 'Subdomin cannot exceed 20 characters',
            'logo.string' => 'Logo must be a string',
            'logo.image' => 'Logo must be an image',
            'logo.mimes' => 'Logo must be a PNG, JPG, JPEG, or SVG file',
            'logo.dimensions' => 'Logo must be at least 100x100 pixels',
            'logo.max' => 'Logo cannot exceed 512 KB',
            'filename.string' => 'Filename must be a string.',
            'filename.max' => 'Filename cannot exceed 255 characters.',
            'website.url' => 'Website must be a valid URL.',
            'website.max' => 'Website cannot exceed 255 characters.',
            'industry.string' => 'Industry must be a string.',
            'industry.max' => 'Industry cannot exceed 255 characters.',
            'company_size.string' => 'Company size must be a string.',
            'company_size.max' => 'Company size cannot exceed 255 characters.',
            'description.string' => 'Description must be a string.',
            'description.max' => 'Description cannot exceed 255 characters.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], 422));
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['sometimes', 'file', 'max:102400'], // 100MB max
            'content' => ['sometimes', 'string'],
            'filename' => ['sometimes', 'string'],
            'type' => ['sometimes', 'string', function ($attribute, $value, $fail) {
                $allowed = ['image', 'video', 'audio', 'pdf', 'document'];

                if (in_array($value, $allowed, true)) {
                    return;
                }

                if (str_contains($value, '/')) {
                    [$group, $sub] = explode('/', $value, 2);

                    if (!in_array($group, ['image', 'video', 'audio', 'application', 'text'], true)) {
                        return $fail('The selected '.$attribute.' is invalid.');
                    }

                    if ($group === 'application' && $sub !== 'pdf' && !str_contains($sub, 'offic') && !str_contains($sub, 'msword')) {
                        return $fail('The selected '.$attribute.' is invalid.');
                    }

                    return;
                }

                $fail('The selected '.$attribute.' is invalid.');
            }],
            'visibility' => ['sometimes', 'string', Rule::in(['public', 'private'])],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alternate_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->hasFile('file') && !$this->filled('content')) {
            // Only treat raw body as file bytes for non-JSON requests without parsed input.
            if ($this->isJson() || !empty($this->all())) {
                return;
            }

            $raw = $this->getContent();

            if (!empty($raw)) {
                $this->merge([
                    'content' => base64_encode($raw),
                    'filename' => $this->header('X-File-Name') ?? $this->query('filename') ?? 'upload.bin',
                ]);
            }
        }
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->hasFile('file')) {
                $extension = strtolower($this->file('file')->getClientOriginalExtension());
                $blocked = ['exe', 'bat', 'cmd', 'sh', 'php', 'phar', 'pl', 'py', 'rb', 'js', 'jsp', 'asp', 'aspx', 'cgi', 'com', 'scr', 'msi', 'dll', 'vbs', 'ws', 'wsf'];

                if (in_array($extension, $blocked, true)) {
                    $validator->errors()->add('file', 'This file type is not allowed.');
                }
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors(),
        ], 422));
    }
}

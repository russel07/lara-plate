<?php

namespace App\Http\Requests;

use App\Models\Organization;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $organizationId = $this->route('id');

        if (!$organizationId && app()->bound('currentOrganization')) {
            $organizationId = app('currentOrganization')->id;
        }

        if (!$organizationId && auth()->check()) {
            $organizationId = auth()->user()->organization_id;
        }

        return [
            'name' => 'required|string|max:255',
            'slug' => [
                'required',
                'string',
                'max:20',
                Rule::unique(Organization::class, 'slug')->ignore($organizationId),
            ],
            'logo' => 'nullable|string|max:2048',
            'website' => 'nullable|max:255|regex:/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(\/.*)?$/i',
            'industry' => 'nullable|string|max:255',
            'company_size' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
        ];
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
            'logo.string' => 'Logo must be a valid file path string.',
            'logo.max' => 'Logo path cannot exceed 2048 characters.',
            'website.url' => 'Website must be a valid URL.',
            'website.max' => 'Website cannot exceed 255 characters.',
            'industry.string' => 'Industry must be a string.',
            'industry.max' => 'Industry cannot exceed 255 characters.',
            'company_size.string' => 'Company size must be a string.',
            'company_size.max' => 'Company size cannot exceed 255 characters.',
            'description.string' => 'Description must be a string.',
            'description.max' => 'Description cannot exceed 1000 characters.',
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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListActivityLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'sort_order' => 'nullable|string|in:asc,desc',
            'organization_id' => 'nullable|integer|exists:organizations,id',
            'module' => 'nullable|string',
            'action' => 'nullable|string',
            'user_id' => 'nullable|integer|exists:users,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:255',
        ];
    }
}

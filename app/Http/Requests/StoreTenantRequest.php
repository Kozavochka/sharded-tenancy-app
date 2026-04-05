<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'plan' => ['required', 'string', Rule::in(['free', 'enterprise'])],
            'tenant_size' => ['required', 'string', Rule::in(['small', 'large'])],
            'domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i', 'unique:domains,domain'],
        ];
    }
}

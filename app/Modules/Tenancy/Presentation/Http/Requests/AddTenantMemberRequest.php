<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AddTenantMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no tiene un formato válido.',
            'roles.required' => 'Selecciona al menos un rol.',
            'roles.min' => 'Selecciona al menos un rol.',
        ];
    }
}

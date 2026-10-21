<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation\Http\Requests;

use App\Modules\Customers\Application\CustomerData;
use App\Modules\Customers\Domain\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CustomerRequest extends FormRequest
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
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'document_type' => ['nullable', 'string', 'max:20'],
            'document_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.-]+$/'],
            'business_id' => ['nullable', 'ulid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Selecciona el tipo de cliente.',
            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'email.email' => 'El correo no es válido.',
            'phone.regex' => 'El teléfono solo admite números, espacios, +, (, ) y guiones.',
            'business_id.ulid' => 'Selecciona una empresa válida.',
        ];
    }

    public function toData(): CustomerData
    {
        $businessId = $this->validated('business_id');

        return new CustomerData(
            type: CustomerType::from((string) $this->validated('type')),
            name: (string) $this->validated('name'),
            documentType: $this->validated('document_type'),
            documentNumber: $this->validated('document_number'),
            email: $this->validated('email'),
            phone: $this->validated('phone'),
            businessId: is_string($businessId) && $businessId !== '' ? strtolower($businessId) : null,
            notes: $this->validated('notes'),
        );
    }
}

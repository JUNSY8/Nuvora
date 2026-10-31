<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Customers\Domain\CustomerDocument;
use App\Modules\Customers\Domain\CustomerRuleViolation;
use App\Modules\Customers\Infrastructure\Persistence\Customer;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared write rules for creating and updating customers: document
 * uniqueness and affiliation to an organization of the same tenant.
 */
final class CustomerAttributes
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(string $tenantId, CustomerData $data, ?Customer $current = null): array
    {
        $document = CustomerDocument::fromInput($data->documentType, $data->documentNumber);
        $businessId = $data->type->isOrganization() ? null : $data->businessId;

        if ($data->type->isOrganization() && $data->businessId !== null) {
            throw ValidationException::withMessages(['business_id' => 'Solo las personas pueden vincularse a una empresa.']);
        }

        if ($businessId !== null) {
            $this->assertOrganization($tenantId, $businessId, $current);
        }

        if ($current !== null && ! $data->type->isOrganization() && $current->type->isOrganization()) {
            $hasMembers = Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('business_id', $current->id)
                ->exists();

            if ($hasMembers) {
                throw new CustomerRuleViolation(
                    'La empresa tiene personas vinculadas. Desvincúlalas antes de cambiarla a persona.',
                    'CUSTOMER_HAS_MEMBERS',
                );
            }
        }

        if ($document !== null) {
            $taken = Customer::query()
                ->where('tenant_id', $tenantId)
                ->where('document_type', $document->type)
                ->where('document_normalized', $document->normalized)
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current?->id))
                ->exists();

            if ($taken) {
                throw self::documentTaken();
            }
        }

        return [
            'type' => $data->type,
            'name' => trim($data->name),
            'document_type' => $document?->type,
            'document_number' => $document?->number,
            'document_normalized' => $document?->normalized,
            'email' => self::nullable($data->email === null ? null : mb_strtolower($data->email)),
            'phone' => self::nullable($data->phone),
            'business_id' => $businessId,
            'notes' => self::nullable($data->notes),
        ];
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public static function write(Closure $write): mixed
    {
        try {
            // Own transaction: a failed insert must not abort an enclosing one.
            return DB::transaction(fn () => $write());
        } catch (UniqueConstraintViolationException) {
            throw self::documentTaken();
        }
    }

    public static function documentTaken(): ValidationException
    {
        return ValidationException::withMessages(['document_number' => 'Ya existe un cliente con ese documento.']);
    }

    private function assertOrganization(string $tenantId, string $businessId, ?Customer $current): void
    {
        if ($current !== null && $current->id === $businessId) {
            throw ValidationException::withMessages(['business_id' => 'Un cliente no puede vincularse a sí mismo.']);
        }

        $business = Customer::query()->where('tenant_id', $tenantId)->whereKey($businessId)->first();

        if ($business === null || ! $business->type->isOrganization()) {
            throw ValidationException::withMessages(['business_id' => 'Selecciona una empresa registrada como cliente.']);
        }
    }

    private static function nullable(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}

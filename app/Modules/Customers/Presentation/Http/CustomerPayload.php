<?php

declare(strict_types=1);

namespace App\Modules\Customers\Presentation\Http;

use App\Modules\Customers\Infrastructure\Persistence\Customer;

final class CustomerPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Customer $customer): array
    {
        $business = $customer->relationLoaded('business') ? $customer->business : null;

        return [
            'id' => $customer->id,
            'type' => $customer->type->value,
            'name' => $customer->name,
            'document_type' => $customer->document_type,
            'document_number' => $customer->document_number,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'business' => $business === null ? null : ['id' => $business->id, 'name' => $business->name],
            'members_count' => (int) ($customer->members_count ?? 0),
            'notes' => $customer->notes,
            'status' => $customer->status->value,
            'created_at' => $customer->created_at?->toIso8601String(),
        ];
    }
}

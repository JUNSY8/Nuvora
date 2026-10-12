<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

use Illuminate\Support\Str;

final class TenantSlug
{
    public static function fromName(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'empresa';
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain;

final class MembershipRules
{
    public static function suspensionBlockReason(bool $isSelf, bool $targetIsOwner, int $otherActiveOwners): ?string
    {
        if ($isSelf) {
            return 'No puedes suspender tu propia membresía.';
        }

        if ($targetIsOwner && $otherActiveOwners < 1) {
            return 'Debe permanecer al menos un propietario activo.';
        }

        return null;
    }

    public static function roleChangeBlockReason(
        bool $actorIsOwner,
        bool $targetIsOwner,
        bool $resultIsOwner,
        int $otherActiveOwners,
    ): ?string {
        if (($targetIsOwner || $resultIsOwner) && ! $actorIsOwner) {
            return 'Solo un propietario puede cambiar el rol de propietario.';
        }

        if ($targetIsOwner && ! $resultIsOwner && $otherActiveOwners < 1) {
            return 'Debe permanecer al menos un propietario activo.';
        }

        return null;
    }
}

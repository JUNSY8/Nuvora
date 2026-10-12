<?php

namespace Tests\Unit;

use App\Modules\Tenancy\Domain\MembershipRules;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Domain\SystemRoles;
use App\Modules\Tenancy\Domain\TenantSlug;
use PHPUnit\Framework\TestCase;

class TenancyRulesTest extends TestCase
{
    public function test_slug_is_stable_and_falls_back_when_the_name_has_no_letters(): void
    {
        $this->assertSame('parqueadero-norte', TenantSlug::fromName('  Parqueadero Norte '));
        $this->assertSame('bogota', TenantSlug::fromName('Bogotá'));
        $this->assertSame('empresa', TenantSlug::fromName('!!!'));
    }

    public function test_owner_role_contains_every_catalog_permission_and_no_unknown_ones(): void
    {
        $definitions = SystemRoles::definitions();
        $catalog = Permission::all();

        $this->assertSame($catalog, $definitions[SystemRoles::OWNER]['permissions']);

        foreach ($definitions as $key => $definition) {
            $this->assertNotSame('', $key);
            $this->assertNotSame('', $definition['name']);
            $this->assertSame([], array_diff($definition['permissions'], $catalog));
        }

        $this->assertSame(array_keys($definitions), SystemRoles::keys());
    }

    public function test_membership_rules_keep_an_active_owner(): void
    {
        $this->assertSame(
            'No puedes suspender tu propia membresía.',
            MembershipRules::suspensionBlockReason(isSelf: true, targetIsOwner: true, otherActiveOwners: 1),
        );

        $this->assertSame(
            'Debe permanecer al menos un propietario activo.',
            MembershipRules::suspensionBlockReason(isSelf: false, targetIsOwner: true, otherActiveOwners: 0),
        );

        $this->assertNull(MembershipRules::suspensionBlockReason(isSelf: false, targetIsOwner: false, otherActiveOwners: 0));

        $this->assertSame(
            'Solo un propietario puede cambiar el rol de propietario.',
            MembershipRules::roleChangeBlockReason(
                actorIsOwner: false,
                targetIsOwner: false,
                resultIsOwner: true,
                otherActiveOwners: 1,
            ),
        );

        $this->assertSame(
            'Debe permanecer al menos un propietario activo.',
            MembershipRules::roleChangeBlockReason(
                actorIsOwner: true,
                targetIsOwner: true,
                resultIsOwner: false,
                otherActiveOwners: 0,
            ),
        );

        $this->assertNull(MembershipRules::roleChangeBlockReason(
            actorIsOwner: true,
            targetIsOwner: true,
            resultIsOwner: false,
            otherActiveOwners: 1,
        ));
    }
}

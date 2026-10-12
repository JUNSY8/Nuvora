<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Tenancy\Application\CreateTenant;
use App\Modules\Tenancy\Infrastructure\Persistence\TenantMembership;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed local development data.
     *
     * The password comes from the user factory and is only created when APP_ENV=local.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $user = User::query()->where('email', 'admin@nuvora.test')->first();

        if ($user === null) {
            $user = User::factory()->create([
                'name' => 'Platform Admin',
                'email' => 'admin@nuvora.test',
            ]);
        }

        if (! TenantMembership::query()->where('user_id', $user->id)->exists()) {
            app(CreateTenant::class)->handle($user, 'Nuvora Demo', 'America/Bogota');
        }
    }
}

<?php

namespace App\Providers;

use App\Modules\Audit\Infrastructure\AuditContext;
use App\Modules\Memberships\Application\PeriodPayables;
use App\Modules\ParkingSessions\Application\SessionPayables;
use App\Modules\Payments\Application\PayableSources;
use App\Modules\Tenancy\Infrastructure\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(AuditContext::class);

        // Each module that charges registers how Payments reaches its payables. See ADR-019.
        $this->app->tag([SessionPayables::class, PeriodPayables::class], PayableSources::TAG);
        $this->app->when(PayableSources::class)->needs('$sources')->giveTagged(PayableSources::TAG);
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers());

        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('email-verification', function (Request $request): Limit {
            $actor = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(6)->by((string) $actor);
        });
    }
}

<?php

use App\Modules\Shared\Presentation\Http\ApiExceptionRenderer;
use App\Modules\Shared\Presentation\Http\Middleware\AssignRequestId;
use App\Modules\Shared\Presentation\Http\Middleware\CaptureAuditContext;
use App\Modules\Tenancy\Presentation\Http\Middleware\EnsureTenantPermission;
use App\Modules\Tenancy\Presentation\Http\Middleware\RequireTenantContext;
use App\Modules\Tenancy\Presentation\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Identity/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Tenancy/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Audit/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Parking/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Devices/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Customers/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Vehicles/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/ParkingSessions/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Tariffs/Presentation/Routes/api.php'));

            Route::middleware('web')
                ->prefix('api/v1')
                ->group(base_path('app/Modules/Shared/Presentation/Routes/live.php'));

            require base_path('app/Modules/Shared/Presentation/Routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->statefulApi();
        $middleware->appendToGroup('web', CaptureAuditContext::class);
        $middleware->alias([
            'tenant.context' => ResolveTenantContext::class,
            'tenant.required' => RequireTenantContext::class,
            'permission' => EnsureTenantPermission::class,
        ]);

        PreventRequestsDuringMaintenance::except([
            '/up',
            '/health',
            '/health/live',
            '/health/ready',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('health')
                || $request->is('health/*')
                || $request->expectsJson(),
        );

        ApiExceptionRenderer::register($exceptions);
    })->create();

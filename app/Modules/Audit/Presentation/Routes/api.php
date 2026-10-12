<?php

declare(strict_types=1);

use App\Modules\Audit\Presentation\Http\Controllers\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant.context', 'tenant.required', 'permission:audit.view'])->group(function (): void {
    Route::get('tenant/audit', [AuditLogController::class, 'index'])->name('tenant.audit.index');
});

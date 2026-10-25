<?php

use App\Modules\Memberships\Application\MembershipRenewer;
use App\Modules\Payments\Application\ProviderSync;
use App\Modules\Reservations\Application\ReservationSweeper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expires holds, marks no-shows and holds reserved spaces. See ADR-017.
Artisan::command('reservations:sweep', function (ReservationSweeper $sweeper) {
    $this->info('Reservas actualizadas: '.$sweeper->sweep(now()->toImmutable()));
})->purpose('Advance reservations with the clock');

Schedule::command('reservations:sweep')->everyMinute()->withoutOverlapping();

// Renews or expires memberships whose period ended, in each tenant's local day. See ADR-018.
Artisan::command('memberships:renew', function (MembershipRenewer $renewer) {
    $this->info('Mensualidades actualizadas: '.$renewer->run());
})->purpose('Renew or expire memberships whose period ended');

Schedule::command('memberships:renew')->hourly()->withoutOverlapping();

// Asks providers about online payments and refunds no webhook closed. See ADR-020.
Artisan::command('payments:sync', function (ProviderSync $sync) {
    $result = $sync->run(now()->toImmutable());
    $this->info("Pagos consultados: {$result['payments']}, actualizados: {$result['changed']}, vencidos: {$result['expired']}, reembolsos: {$result['refunds']}");
})->purpose('Follow up online payments and refunds with their providers');

Schedule::command('payments:sync')->everyFiveMinutes()->withoutOverlapping();

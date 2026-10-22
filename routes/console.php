<?php

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

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Solo en la demo (APP_DEMO=true): la deja como nueva todas las noches, desde
 * el fixture y sin llamar a la IA. En la productiva el filtro no deja que corra
 * nunca, y el comando además se niega solo.
 *
 * Corre DENTRO del proceso de schedule:run (Schedule::call), no con
 * Schedule::command: ese lanza otro PHP con proc_open, y en el hosting
 * compartido falló con «posix_spawn() failed: Operation not permitted» la
 * primera noche, así que el reset no corrió.
 */
Schedule::call(function () {
    if (Artisan::call('docbrainer:demo-reset') !== 0) {
        throw new RuntimeException('docbrainer:demo-reset falló: '.trim(Artisan::output()));
    }
})
    ->name('docbrainer:demo-reset')
    ->timezone(config('demo.reset_timezone'))
    ->dailyAt(config('demo.reset_at'))
    ->when(fn () => config('demo.enabled'))
    ->withoutOverlapping(30);

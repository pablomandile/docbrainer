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
 */
Schedule::command('docbrainer:demo-reset')
    ->dailyAt(config('demo.reset_at'))
    ->when(fn () => config('demo.enabled'))
    ->withoutOverlapping();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup diario de la base de datos (Parte XV) — corre dentro del contenedor
// "scheduler" (docker-compose.yml), vía `php artisan schedule:work`.
Schedule::command('backup:database')->dailyAt('03:00')->withoutOverlapping();

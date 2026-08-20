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

// Recuerda al dueño turnos vencidos sin resolver y cierra por respaldo los
// que llevan más de 7 días sin respuesta (Incremento 2) — sin esto,
// GestionReservaAgent ofrece indefinidamente turnos cuya fecha ya pasó
// como si siguieran activos. Diaria a las 9am: hora hábil, para que el
// dueño vea el recordatorio de WhatsApp en un horario razonable.
Schedule::command('bookings:review-past')->dailyAt('09:00')->withoutOverlapping();

// Recordatorio al cliente ~24h antes de su turno (Incremento 3), por
// plantilla de Meta — cada hora, para que la ventana de 23-24h no se salte
// ninguna reserva entre corridas.
Schedule::command('bookings:send-upcoming-reminders')->hourly()->withoutOverlapping();

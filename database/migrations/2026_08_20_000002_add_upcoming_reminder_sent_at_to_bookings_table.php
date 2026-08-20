<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distinta de reminder_sent_at (Incremento 2): esa es el aviso al
        // DUEÑO sobre un turno YA PASADO sin resolver; esta es el
        // recordatorio al CLIENTE antes de un turno FUTURO (Incremento 3) —
        // dos hechos independientes de la misma reserva, cada uno con su
        // propia marca de "ya se mandó" para no reenviarlo dos veces.
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('upcoming_reminder_sent_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('upcoming_reminder_sent_at');
        });
    }
};

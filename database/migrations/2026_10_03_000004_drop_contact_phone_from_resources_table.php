<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B8 — Resource.contact_phone deja de existir. El recurso es una entidad de
 * agenda (quién atiende y cuándo), no un destinatario telefónico: los avisos
 * del negocio van al owner (Organization.owner_phone) por el CENTRAL (B6), y
 * la agenda la consulta el owner (B7). Ninguna parte del código lo lee ni lo
 * escribe desde B8.
 *
 * Destructiva a propósito: los valores existentes se pierden (en staging, el
 * teléfono de 1 recurso piloto, sin uso desde B6/B7). El resto de cada
 * Resource queda intacto. down() vuelve a crear la columna nullable, vacía —
 * los valores solo se recuperan desde el backup previo al despliegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resources', 'contact_phone')) {
            return;
        }

        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('resources', 'contact_phone')) {
            return;
        }

        Schema::table('resources', function (Blueprint $table) {
            $table->string('contact_phone')->nullable()->after('capacity');
        });
    }
};

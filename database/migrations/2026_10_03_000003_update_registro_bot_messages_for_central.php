<?php

use App\Application\Conversations\BotMessages\BotMessageRepository;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * B5 — el onboarding ahora ocurre en el número CENTRAL, así que dos textos
 * del registro dejan de ser ciertos:
 *  - registro.listo decía "Ya podés recibir reservas por acá": por el
 *    CENTRAL nunca se reserva; los clientes reservan por el WhatsApp propio
 *    del negocio, que se conecta después.
 *  - registro.negocio_bloqueado hablaba de "este negocio": el bloqueo ahora
 *    es por owner (owner_phone), no por el Channel.
 *
 * bot_messages se edita desde el panel: cada texto se reemplaza SOLO si
 * todavía es el sembrado originalmente — una edición manual nunca se pisa.
 * Los textos se cachean una hora (BotMessageRepository): se invalida la
 * caché para que el cambio se vea apenas corre la migración.
 */
return new class extends Migration
{
    private const LISTO_ANTERIOR = '¡Listo! «{negocio}» quedó registrado. Ya podés recibir reservas por acá.';

    private const LISTO_NUEVO = '¡Listo! «{negocio}» quedó registrado. El próximo paso es conectar el WhatsApp propio de tu negocio: cuando esté activo, tus clientes van a poder reservar escribiéndole a ese número. Mientras tanto, podés administrar tu negocio desde acá.';

    private const BLOQUEADO_ANTERIOR = 'Este negocio ya está registrado. Si necesitás agregar un servicio, cambiar un horario o hacer otra gestión, contame qué querés hacer.';

    private const BLOQUEADO_NUEVO = 'Tu negocio ya está registrado con este número. Si necesitás agregar un servicio, cambiar un horario o hacer otra gestión, contame qué querés hacer.';

    public function up(): void
    {
        $this->replace('registro.listo', self::LISTO_ANTERIOR, self::LISTO_NUEVO);
        $this->replace('registro.negocio_bloqueado', self::BLOQUEADO_ANTERIOR, self::BLOQUEADO_NUEVO);

        DB::table('bot_messages')->where('key', 'registro.negocio_bloqueado')->update([
            'description' => 'Respuesta cuando un owner que ya tiene una Organization intenta arrancar un registro nuevo desde el CENTRAL (Intent::RegistroNegocioBloqueado) — tanto por el guard del Router como, si el registro ya estaba en curso o hubo una carrera, por RegistroNegocioAgent al capturar OwnerAlreadyRegisteredException.',
        ]);
    }

    public function down(): void
    {
        $this->replace('registro.listo', self::LISTO_NUEVO, self::LISTO_ANTERIOR);
        $this->replace('registro.negocio_bloqueado', self::BLOQUEADO_NUEVO, self::BLOQUEADO_ANTERIOR);

        DB::table('bot_messages')->where('key', 'registro.negocio_bloqueado')->update([
            'description' => 'Respuesta cuando un Channel ya registrado intenta arrancar un registro nuevo (Intent::RegistroNegocioBloqueado) — tanto por el guard del Router como, en el caso raro de condición de carrera, por RegistroNegocioAgent al capturar ChannelAlreadyRegisteredException.',
        ]);
    }

    private function replace(string $key, string $from, string $to): void
    {
        DB::table('bot_messages')
            ->where('key', $key)
            ->where('template', $from)
            ->update(['template' => $to, 'updated_at' => now()]);

        Cache::forget(BotMessageRepository::CACHE_KEY);
    }
};

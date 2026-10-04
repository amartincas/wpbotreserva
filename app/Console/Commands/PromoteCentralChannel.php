<?php

namespace App\Console\Commands;

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelRole;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Convierte un Channel existente en el CENTRAL de WpbotReserva (B2) — una
 * única vez por entorno, sobre el número que ya atiende el onboarding. Sin
 * --apply es un dry-run: muestra exactamente qué cambiaría y no escribe nada.
 *
 * Con --apply, todo dentro de una transacción con lock de fila:
 *  1. Re-verifica las precondiciones sobre la fila bloqueada (existe,
 *     ACTIVE, todavía BUSINESS, ningún otro CENTRAL).
 *  2. Borra sus vínculos en channel_organization ANTES de cambiar el rol —
 *     nunca existe un estado intermedio CENTRAL-y-vinculado.
 *  3. Cambia solo `role`. Credenciales, phone_number_id, WABA y status no
 *     son atributos "dirty", así que Eloquent no los reescribe (ni vuelve a
 *     encriptar el token); se verifica igual comparando las columnas crudas
 *     antes/después.
 *  4. Limpia las sesiones de ese Channel: current_intent a null en todas
 *     (un flujo de cliente a medias no tiene sentido en el CENTRAL), y
 *     organization_id a null salvo en la sesión del propio owner de esa
 *     Organization (la que sigue siendo válida en el CENTRAL).
 * Cualquier excepción revierte todo. Los drafts viven en cache (no son
 * transaccionales), así que se olvidan recién después del commit.
 *
 * Idempotente: si el Channel ya es CENTRAL y no tiene vínculos, no hace
 * nada (en particular no vuelve a limpiar sesiones, que a esa altura ya
 * pueden ser onboardings reales en curso). Si ya es CENTRAL pero aparece
 * vinculado (solo posible con SQL manual), se detiene sin reparar nada.
 */
class PromoteCentralChannel extends Command
{
    /**
     * Columnas que definen la configuración Meta del Channel — no deben
     * cambiar en una promoción. Se comparan crudas (credentials encriptado).
     */
    private const PRESERVED_COLUMNS = [
        'provider',
        'channel_type',
        'phone_number',
        'phone_number_id',
        'business_account_id',
        'status',
        'credentials',
        'metadata',
    ];

    protected $signature = 'channels:promote-central
                            {phone_number_id : phone_number_id de Meta del Channel a promover}
                            {--apply : Ejecuta la promoción (sin esta opción es un dry-run)}';

    protected $description = 'Convierte un Channel existente en el número CENTRAL de WpbotReserva (dry-run por defecto)';

    public function handle(ConversationDraftRepositoryInterface $drafts): int
    {
        $channel = Channel::where('phone_number_id', $this->argument('phone_number_id'))->first();

        if ($channel === null) {
            $this->error("No existe ningún Channel con phone_number_id {$this->argument('phone_number_id')}.");

            return self::FAILURE;
        }

        if ($channel->isCentral()) {
            return $this->handleAlreadyCentral($channel);
        }

        $problem = $this->preconditionProblem($channel);

        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $this->describePlan($channel);

        if (! $this->option('apply')) {
            $this->warn('DRY-RUN: no se modificó nada. Volver a correr con --apply para ejecutar.');

            return self::SUCCESS;
        }

        try {
            $sessionIds = DB::transaction(fn () => $this->promote($channel->id));
        } catch (RuntimeException|DomainException $e) {
            $this->error("Promoción revertida, no se modificó nada: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach (ConversationSession::whereKey($sessionIds)->get() as $session) {
            $drafts->forget($session);
        }

        $this->info("Channel #{$channel->id} promovido a CENTRAL.");

        return self::SUCCESS;
    }

    private function handleAlreadyCentral(Channel $channel): int
    {
        if ($channel->organizations()->exists()) {
            $this->error("El Channel #{$channel->id} ya es CENTRAL pero está vinculado a una Organization — estado inconsistente, revisar manualmente. No se modificó nada.");

            return self::FAILURE;
        }

        $this->info("El Channel #{$channel->id} ya es CENTRAL y no tiene Organizations vinculadas. Nada que hacer.");

        return self::SUCCESS;
    }

    private function preconditionProblem(Channel $channel): ?string
    {
        if (! $channel->isActive()) {
            return "El Channel #{$channel->id} no está ACTIVE (estado: {$channel->status->value}). No se modificó nada.";
        }

        $otherCentral = Channel::where('role', ChannelRole::CENTRAL->value)
            ->whereKeyNot($channel->id)
            ->first();

        if ($otherCentral !== null) {
            return "Ya existe otro Channel CENTRAL (#{$otherCentral->id}). No se modificó nada.";
        }

        return null;
    }

    /**
     * @return array<int> ids de las sesiones del Channel, para olvidar sus drafts después del commit
     */
    private function promote(int $channelId): array
    {
        $channel = Channel::whereKey($channelId)->lockForUpdate()->firstOrFail();
        Channel::where('role', ChannelRole::CENTRAL->value)->lockForUpdate()->get();

        if ($channel->isCentral()) {
            throw new RuntimeException("El Channel #{$channel->id} pasó a CENTRAL mientras se preparaba la promoción.");
        }

        $problem = $this->preconditionProblem($channel);

        if ($problem !== null) {
            throw new RuntimeException($problem);
        }

        $before = $this->preservedColumns($channel->id);
        $ownersByOrganization = $channel->organizations()->pluck('owner_phone', 'organizations.id');

        DB::table('channel_organization')->where('channel_id', $channel->id)->delete();

        $channel->role = ChannelRole::CENTRAL;
        $channel->save();

        $sessions = ConversationSession::where('channel_id', $channel->id)->get();

        foreach ($sessions as $session) {
            $keepsOrganization = $session->organization_id !== null
                && $ownersByOrganization->get($session->organization_id) === $session->customer_phone?->value();

            $session->update([
                'current_intent' => null,
                'organization_id' => $keepsOrganization ? $session->organization_id : null,
            ]);
        }

        if ($this->preservedColumns($channel->id) !== $before) {
            throw new RuntimeException('La configuración Meta del Channel cambió durante la promoción.');
        }

        if (DB::table('channel_organization')->where('channel_id', $channel->id)->exists()) {
            throw new RuntimeException('El Channel sigue vinculado a una Organization después de desvincularlo.');
        }

        return $sessions->modelKeys();
    }

    private function preservedColumns(int $channelId): array
    {
        return (array) DB::table('channels')->where('id', $channelId)->first(self::PRESERVED_COLUMNS);
    }

    private function describePlan(Channel $channel): void
    {
        $organizations = $channel->organizations()->get();
        $sessions = ConversationSession::where('channel_id', $channel->id)->get();

        $this->line("Channel #{$channel->id} — {$channel->phone_number} (phone_number_id {$channel->phone_number_id}, WABA {$channel->business_account_id})");
        $this->line("Rol actual: {$channel->role->value} → CENTRAL. Credenciales, phone_number_id, WABA y status no cambian.");

        $this->line($organizations->isEmpty()
            ? 'Organizations vinculadas: ninguna.'
            : 'Se desvinculan: '.$organizations->map(fn (Organization $o) => "#{$o->id} {$o->name} (owner {$o->owner_phone})")->implode(', '));

        $this->line("Sesiones del Channel: {$sessions->count()} — current_intent y draft se limpian en todas.");
        $this->describeSessions($sessions, $organizations);
    }

    private function describeSessions(Collection $sessions, Collection $organizations): void
    {
        $owners = $organizations->pluck('owner_phone', 'id');

        foreach ($sessions as $session) {
            $keeps = $session->organization_id !== null
                && $owners->get($session->organization_id) === $session->customer_phone?->value();

            $this->line(sprintf(
                '  sesión #%d %s: intent %s → null, organization %s',
                $session->id,
                $session->customer_phone?->value(),
                $session->current_intent ?? 'null',
                $keeps ? "#{$session->organization_id} (owner, se conserva)" : ($session->organization_id === null ? 'null' : "#{$session->organization_id} → null"),
            ));
        }
    }
}

<?php

namespace App\Application\Conversations\Classification;

use App\Application\Contracts\IntentClassifierStrategy;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\CRM\Customer;

/**
 * Fase 3 — reconoce determinísticamente una respuesta a un recordatorio de
 * asistencia (botón o texto libre "sí"/"no"), SOLO cuando existe una fila
 * PendingAttendanceConfirmation real y vigente para ese cliente en esa
 * Organization. Sin eso, "sí"/"no" sueltos no son clasificables (mismo
 * criterio documentado en la auditoría: nunca se adivina con IA acá).
 *
 * Corre DESPUÉS de ConversationContinuityStrategy a propósito (ver
 * AppServiceProvider): si el cliente ya está a mitad de un flujo activo
 * distinto, ese flujo tiene prioridad — un recordatorio es una espera
 * pasiva, no debe interrumpir una conversación en curso.
 */
class PendingAttendanceConfirmationStrategy implements IntentClassifierStrategy
{
    private const RECOGNIZED = ['si', 'sí', 'no', 'confirmar_asistencia', 'no_asistencia_reserva'];

    public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
    {
        if ($session->organization_id === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($message->text));

        if (! in_array($normalized, self::RECOGNIZED, true)) {
            return null;
        }

        $customer = Customer::where('organization_id', $session->organization_id)
            ->where('phone', $message->fromPhone)
            ->first();

        if ($customer === null) {
            return null;
        }

        $hasPending = PendingAttendanceConfirmation::whereIn('booking_id', $customer->bookings()->pluck('id'))
            ->where('expires_at', '>', now())
            ->exists();

        return $hasPending ? Intent::ConfirmacionAsistencia : null;
    }
}

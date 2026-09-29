<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Shared\PhoneNumber;
use InvalidArgumentException;

/**
 * Teléfono de WhatsApp del profesional (Fase 2A) — completamente
 * determinista, sin componer AiFieldExtractor: a diferencia de duración o
 * nombre, un número de teléfono no tiene una interpretación en lenguaje
 * libre que valga la pena pedirle a la IA que adivine. Nunca infiere ni
 * inventa un número a partir de texto no numérico.
 *
 * Campo OBLIGATORIO (decisión de producto aprobada, Fase 2A: el teléfono es
 * el destinatario real de las notificaciones de Fase 2B, no un dato
 * complementario como descripción/precio) — "no" y equivalentes NO
 * producen éxito con NULL como FreeTextFieldExtractor/ServicePriceFieldExtractor;
 * se tratan como fallo, con un mensaje que explica por qué hace falta el
 * dato, y el flujo vuelve a preguntar. No existe ningún camino por el que
 * el registro de un profesional nuevo termine con contact_phone = NULL.
 *
 * Reconoce E.164 directo, código de país sin "+", el local colombiano de 10
 * dígitos (inicia en 3) y variantes con espacios/guiones/paréntesis.
 * Validación final siempre delegada a PhoneNumber (VO ya existente,
 * reutilizado, no reinventado).
 */
class ContactPhoneFieldExtractor implements FieldExtractorInterface
{
    // Mismo vocabulario que NO_WORDS en los Agents de este dominio.
    private const REFUSAL_WORDS = ['no', 'nel', 'nop', 'no gracias', 'ninguno', 'ninguna'];

    public function __construct(private readonly ?BotMessageRepository $botMessages = null) {}

    public function extract(string $answer, array $draftSoFar): FieldExtractionResult
    {
        $resourceName = $draftSoFar['_pendingNewResourceName'] ?? 'esta persona';
        $trimmed = trim($answer);
        $normalized = mb_strtolower(rtrim($trimmed, '.'));

        if (in_array($normalized, self::REFUSAL_WORDS, true)) {
            return FieldExtractionResult::failure(
                $this->botMessages?->render('recurso.telefono_obligatorio', ['recurso' => $resourceName])
                    ?? "Necesitamos el número de WhatsApp de {$resourceName} para poder avisarle cuando tenga una reserva nueva. ¿Cuál es?"
            );
        }

        $candidate = $this->toE164Candidate($trimmed);

        if ($candidate === null) {
            return $this->invalidResult($resourceName);
        }

        try {
            $phone = new PhoneNumber($candidate);
        } catch (InvalidArgumentException) {
            return $this->invalidResult($resourceName);
        }

        return FieldExtractionResult::success($phone->value());
    }

    /**
     * Limpia espacios/guiones/paréntesis (conserva un "+" inicial si lo
     * hay) y prueba, en orden, los formatos mínimos pedidos: E.164 directo,
     * código de país sin "+", y local colombiano de 10 dígitos (inicia en
     * 3). Cualquier otra cosa no es un candidato — nunca se adivina.
     */
    private function toE164Candidate(string $text): ?string
    {
        $digits = preg_replace('/[^\d+]/', '', $text);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '+')) {
            return $digits;
        }

        if (preg_match('/^57\d{10}$/', $digits)) {
            return '+'.$digits;
        }

        if (preg_match('/^3\d{9}$/', $digits)) {
            return '+57'.$digits;
        }

        return null;
    }

    private function invalidResult(string $resourceName): FieldExtractionResult
    {
        return FieldExtractionResult::failure(
            $this->botMessages?->render('recurso.telefono_invalido', ['recurso' => $resourceName])
                ?? "No pude reconocer el número de {$resourceName}. Escribilo con el código de país, por ejemplo +573001234567."
        );
    }
}

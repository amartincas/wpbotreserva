<?php

namespace App\Application\Conversations\Agents;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\InfoNegocio\BusinessContextBuilder;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 1 (información general del negocio): responde preguntas abiertas
 * sobre el negocio (qué ofrece, dónde queda, cuánto cuesta un servicio) que
 * antes caían todas a FueraDeAlcance porque no existía ningún Agent que
 * respondiera con datos reales — ver Intent::InfoNegocio.
 *
 * De un único turno (pregunta → respuesta), a diferencia de
 * RegistroNegocioAgent/GestionNegocioAgent: no acumula draft ni espera una
 * segunda respuesta, así que limpia current_intent apenas contesta (mismo
 * contrato que documenta ConversationContinuityStrategy: "el Agent dueño
 * del flujo es quien limpia current_intent al completarlo").
 *
 * Mismo patrón anti-alucinación que AiFieldExtractor/AiIntentClassifierStrategy
 * (sentinels que la IA devuelve tal cual, nunca prosa libre, para casos que
 * el bot no puede responder con datos reales): SIN_INFORMACION cuando el
 * contexto no tiene el dato pedido, PRECIO_NO_REGISTRADO cuando preguntan el
 * precio de un servicio que existe pero no lo tiene cargado. Ninguno de los
 * dos sentinels llega nunca al cliente tal cual — se traducen a un
 * BotMessage editable antes de responder.
 */
class InfoNegocioAgent implements AgentInterface
{
    private const SENTINEL_SIN_INFORMACION = 'SIN_INFORMACION';

    private const SENTINEL_PRECIO_NO_REGISTRADO = 'PRECIO_NO_REGISTRADO';

    private const SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
        Sos el asistente de información de "{negocio}", un negocio que recibe
        reservas por WhatsApp. Tu única fuente de verdad es el contexto de
        abajo — nunca inventes ni asumas un dato que no esté ahí, ni
        aunque parezca razonable.

        {contexto}

        Reglas para tu respuesta (elegí UNA):
        1. Si la pregunta es sobre el precio de un servicio que SÍ aparece en
           el contexto pero con "precio: no registrado", respondé
           exactamente y solo: PRECIO_NO_REGISTRADO
        2. Si no podés responder con lo que hay en el contexto (el dato no
           está, o lo que preguntan no existe ahí), respondé exactamente y
           solo: SIN_INFORMACION
        3. Si podés responder con el contexto, escribí una respuesta breve y
           natural en español, sin mencionar la palabra "contexto" ni que
           sos una IA.
        PROMPT;

    public function __construct(
        private readonly BusinessContextBuilder $contextBuilder,
        private readonly NotificationSenderInterface $notifications,
        private readonly ConversationSessionRepositoryInterface $sessions,
        private readonly BotMessageRepository $botMessages,
        private readonly AiServiceInterface $ai,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
    {
        $systemPrompt = strtr(self::SYSTEM_PROMPT_TEMPLATE, [
            '{negocio}' => $organization->name,
            '{contexto}' => $this->contextBuilder->build($organization),
        ]);

        try {
            $response = trim($this->ai->getResponse($message->text, $systemPrompt, []));
        } catch (Throwable $e) {
            Log::warning('InfoNegocioAgent: la llamada a la IA falló', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);

            $this->reply($organization, $message->fromPhone, $this->sinInformacionText());
            $this->sessions->recordIntent($session, null);

            return;
        }

        $reply = match ($response) {
            self::SENTINEL_SIN_INFORMACION => $this->sinInformacionText(),
            self::SENTINEL_PRECIO_NO_REGISTRADO => $this->precioNoRegistradoText(),
            default => $response,
        };

        $this->reply($organization, $message->fromPhone, $reply);
        $this->sessions->recordIntent($session, null);
    }

    private function sinInformacionText(): string
    {
        return $this->botMessages->render('info_negocio.sin_datos')
            ?? 'No tengo esa información todavía. Te recomiendo consultarlo directamente con el negocio.';
    }

    private function precioNoRegistradoText(): string
    {
        return $this->botMessages->render('info_negocio.precio_no_registrado')
            ?? 'Todavía no tengo el precio de ese servicio cargado. Te recomiendo consultarlo directamente con el negocio.';
    }

    private function reply(Organization $organization, string $toPhone, string $text): void
    {
        $this->notifications->send($organization, $toPhone, $text);
    }
}

<?php

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\BookingChoiceAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function bookingChoiceNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA en este turno.');
        }
    };
}

/**
 * @param  string[]  $responses
 */
function bookingChoiceQueuedAi(array $responses): AiServiceInterface
{
    return new class($responses) implements AiServiceInterface
    {
        public function __construct(private array $responses) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            if ($this->responses === []) {
                throw new RuntimeException('Se llamó a la IA más veces de las esperadas por el test.');
            }

            return array_shift($this->responses);
        }
    };
}

function bookingChoiceFakeDraftRepository(): ConversationDraftRepositoryInterface
{
    return new class implements ConversationDraftRepositoryInterface
    {
        private array $store = [];

        public function get(ConversationSession $session): array
        {
            return $this->store[$session->id] ?? [];
        }

        public function put(ConversationSession $session, array $draft): void
        {
            $this->store[$session->id] = $draft;
        }

        public function forget(ConversationSession $session): void
        {
            unset($this->store[$session->id]);
        }
    };
}

function bookingChoiceFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}
    };
}

function bookingChoiceFixtureOrganization(): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-booking-choice-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);

    return $organization;
}

function bookingChoiceFixtureSession(Organization $organization): ConversationSession
{
    return ConversationSession::create([
        'channel_id' => $organization->channels()->first()->id,
        'customer_phone' => '+573001234567',
        'organization_id' => $organization->id,
    ]);
}

function bookingChoiceFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-booking-choice', '+573001234567', $text, now()->toImmutable());
}

function buildBookingChoiceAgent(ConversationDraftRepositoryInterface $drafts, array &$sent, AiServiceInterface $ai): BookingChoiceAgent
{
    return new BookingChoiceAgent($drafts, new EloquentConversationSessionRepository, bookingChoiceFakeNotificationSender($sent), $ai);
}

test('el primer mensaje pregunta nueva o gestionar, sin decidir nada todavía ni llamar a la IA', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent, bookingChoiceNeverCalledAi());

    $agent->handle(bookingChoiceFixtureMessage('quiero una cita para el 24'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('nueva o gestionar');
    expect($drafts->get($session)['_awaiting_choice'])->toBeTrue();
    expect($session->fresh()->current_intent)->toBeNull(); // todavía no se decidió nada
});

test('responder "nueva" sin fecha en el mensaje original pregunta la fecha normalmente', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent, bookingChoiceQueuedAi(['NO_ENCONTRADO']));

    $agent->handle(bookingChoiceFixtureMessage('hola'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('nueva'), $session, $organization);

    expect($sent[1]['message'])->toContain('qué día');
    expect($drafts->get($session))->toBe(['_started' => true]);
    expect($session->fresh()->current_intent)->toBe(Intent::Reserva->value);
});

test('responder "nueva" cuando el mensaje original ya tenía una fecha SIN ambigüedad, la reaprovecha y salta directo a preguntar el nombre', function () {
    // Con mes explícito ("24 de agosto"), no un día suelto — un día suelto
    // ("para el 24") es justamente el caso ambiguo que DateFieldExtractor
    // ahora corta antes de la IA (ver DateFieldExtractorTest), así que acá
    // no debería resolverse solo con el número.
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $targetDate = now()->addDays(4)->toDateString();
    $agent = buildBookingChoiceAgent($drafts, $sent, bookingChoiceQueuedAi([$targetDate]));

    $agent->handle(bookingChoiceFixtureMessage('quiero crear una reserva para el 24 de agosto'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('nueva'), $session, $organization);

    expect($sent[1]['message'])->toContain('nombre de quién');
    expect($drafts->get($session)['_started'])->toBeTrue();
    expect($drafts->get($session)['date']->toDateString())->toBe($targetDate);
    expect($session->fresh()->current_intent)->toBe(Intent::Reserva->value);
});

test('responder "gestionar" deja el Intent en GestionReserva y limpia el draft, sin llamar a la IA', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent, bookingChoiceNeverCalledAi());

    $agent->handle(bookingChoiceFixtureMessage('hola'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('gestionar'), $session, $organization);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBe(Intent::GestionReserva->value);
});

test('una respuesta que no es ni nueva ni gestionar vuelve a preguntar, sin decidir nada ni llamar a la IA', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent, bookingChoiceNeverCalledAi());

    $agent->handle(bookingChoiceFixtureMessage('hola'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('no sé'), $session, $organization);

    expect($sent[1]['message'])->toContain('No entendí');
    expect($session->fresh()->current_intent)->toBeNull();
});

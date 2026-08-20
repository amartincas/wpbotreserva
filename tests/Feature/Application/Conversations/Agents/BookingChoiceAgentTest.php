<?php

use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\BookingChoiceAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

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

function buildBookingChoiceAgent(ConversationDraftRepositoryInterface $drafts, array &$sent): BookingChoiceAgent
{
    return new BookingChoiceAgent($drafts, new EloquentConversationSessionRepository, bookingChoiceFakeNotificationSender($sent));
}

test('el primer mensaje pregunta nueva o gestionar, sin decidir nada todavía', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent);

    $agent->handle(bookingChoiceFixtureMessage('quiero una cita para el 24'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('nueva o gestionar');
    expect($drafts->get($session)['_awaiting_choice'])->toBeTrue();
    expect($session->fresh()->current_intent)->toBeNull(); // todavía no se decidió nada
});

test('responder "nueva" arranca ReservaAgent desde su primera pregunta', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent);

    $agent->handle(bookingChoiceFixtureMessage('hola'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('nueva'), $session, $organization);

    expect($sent[1]['message'])->toContain('qué día');
    expect($drafts->get($session))->toBe(['_started' => true]);
    expect($session->fresh()->current_intent)->toBe(Intent::Reserva->value);
});

test('responder "gestionar" deja el Intent en GestionReserva y limpia el draft', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent);

    $agent->handle(bookingChoiceFixtureMessage('hola'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('gestionar'), $session, $organization);

    expect($drafts->get($session))->toBe([]);
    expect($session->fresh()->current_intent)->toBe(Intent::GestionReserva->value);
});

test('una respuesta que no es ni nueva ni gestionar vuelve a preguntar, sin decidir nada', function () {
    $organization = bookingChoiceFixtureOrganization();
    $session = bookingChoiceFixtureSession($organization);
    $drafts = bookingChoiceFakeDraftRepository();
    $sent = [];
    $agent = buildBookingChoiceAgent($drafts, $sent);

    $agent->handle(bookingChoiceFixtureMessage('hola'), $session, $organization);
    $agent->handle(bookingChoiceFixtureMessage('no sé'), $session, $organization);

    expect($sent[1]['message'])->toContain('No entendí');
    expect($session->fresh()->current_intent)->toBeNull();
});

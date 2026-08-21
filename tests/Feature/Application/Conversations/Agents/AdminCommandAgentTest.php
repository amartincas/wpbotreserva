<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\ConfirmBookingCommand;
use App\Application\Booking\MarkBookingNoShowCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\AdminCommandAgent;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function adminAgentFakeNotificationSender(array &$sent): NotificationSenderInterface
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

function adminAgentFixtureOrganization(string $phoneNumberId = 'wamid-admin-agent'): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30)],
        resources: [new ResourceRegistrationData('Carlos', array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '09:00', endTime: '17:00'),
            range(0, 6)
        ))],
    ));

    return Organization::findOrFail($result->organizationId);
}

function adminAgentFixtureSession(Organization $organization): ConversationSession
{
    return ConversationSession::create([
        'channel_id' => $organization->channels()->first()->id,
        'customer_phone' => '+573009999999',
        'organization_id' => $organization->id,
    ]);
}

function adminAgentFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-admin-agent', '+573009999999', $text, now()->toImmutable());
}

function adminAgentFixtureBooking(Organization $organization, CarbonImmutable $startsAt): Booking
{
    $result = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: '+573001234567',
        customerName: 'Ana',
        startsAt: $startsAt,
        resource: $organization->resources()->first(),
    ));

    return Booking::findOrFail($result->bookingId);
}

function buildAdminCommandAgent(array &$sent): AdminCommandAgent
{
    return new AdminCommandAgent(
        adminAgentFakeNotificationSender($sent),
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new ConfirmBookingCommand(app(BookingSchedulerInterface::class)),
        new MarkBookingNoShowCommand(app(BookingSchedulerInterface::class)),
    );
}

test('"reservas hoy" sin reservas para hoy avisa que no hay ninguna', function () {
    $organization = adminAgentFixtureOrganization();
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('reservas hoy'), $session, $organization);

    expect($sent[0]['message'])->toContain('No tenés reservas para hoy');
});

test('"reservas hoy" lista las reservas de hoy con id, hora, servicio y cliente', function () {
    $organization = adminAgentFixtureOrganization();
    adminAgentFixtureBooking($organization, now()->setTime(10, 0));
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('reservas hoy'), $session, $organization);

    expect($sent[0]['message'])->toContain('10:00');
    expect($sent[0]['message'])->toContain('Corte de cabello');
    expect($sent[0]['message'])->toContain('Ana');
});

test('"reservas dd/mm/aaaa" lista las reservas de esa fecha específica, no las de hoy', function () {
    $organization = adminAgentFixtureOrganization();
    adminAgentFixtureBooking($organization, now()->setTime(10, 0)); // hoy, no debería aparecer
    $target = now()->addDays(5);
    adminAgentFixtureBooking($organization, $target->setTime(11, 0));
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('reservas '.$target->format('d/m/Y')), $session, $organization);

    expect($sent[0]['message'])->toContain('11:00');
    expect($sent[0]['message'])->not->toContain('10:00');
});

test('"reservas hoy"/"reservas dd/mm/aaaa" nunca listan una reserva cancelada', function () {
    $organization = adminAgentFixtureOrganization();
    $cancelled = adminAgentFixtureBooking($organization, now()->setTime(10, 0));
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($cancelled);
    $stillActive = adminAgentFixtureBooking($organization, now()->setTime(11, 0));
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('reservas hoy'), $session, $organization);

    expect($sent[0]['message'])->toContain('11:00');
    expect($sent[0]['message'])->not->toContain('10:00');
});

test('"reservas dd/mm/aaaa" sin reservas ese día avisa con la fecha pedida', function () {
    $organization = adminAgentFixtureOrganization();
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('reservas 22/08/2026'), $session, $organization);

    expect($sent[0]['message'])->toContain('No tenés reservas para 22/08/2026');
});

test('"reservas dd/mm/aaaa" con una fecha que no existe en el calendario avisa sin romper', function () {
    $organization = adminAgentFixtureOrganization();
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('reservas 31/02/2026'), $session, $organization);

    expect($sent[0]['message'])->toContain('no es válida');
});

test('"cancelar <id>" cancela la reserva de esta organización', function () {
    $organization = adminAgentFixtureOrganization();
    $booking = adminAgentFixtureBooking($organization, now()->addDay()->setTime(10, 0));
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("cancelar {$booking->id}"), $session, $organization);

    expect($booking->fresh()->status)->toBe(BookingStatus::CANCELLED);
    expect($sent[0]['message'])->toContain("cancelé la reserva #{$booking->id}");
});

test('"cancelar <id>" con un id inexistente responde que no la encontró, sin romper', function () {
    $organization = adminAgentFixtureOrganization();
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage('cancelar 999999'), $session, $organization);

    expect($sent[0]['message'])->toContain('No encontré la reserva');
});

test('"cancelar <id>" nunca puede cancelar una reserva de OTRA organización', function () {
    $organizationA = adminAgentFixtureOrganization('wamid-admin-agent-a');
    $organizationB = adminAgentFixtureOrganization('wamid-admin-agent-b');
    $bookingOfB = adminAgentFixtureBooking($organizationB, now()->addDay()->setTime(10, 0));
    $sessionA = adminAgentFixtureSession($organizationA);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("cancelar {$bookingOfB->id}"), $sessionA, $organizationA);

    expect($bookingOfB->fresh()->status)->toBe(BookingStatus::CONFIRMED);
    expect($sent[0]['message'])->toContain('No encontré la reserva');
});

test('"confirmar <id>" transiciona una reserva PENDING a CONFIRMED', function () {
    $organization = adminAgentFixtureOrganization();
    $booking = adminAgentFixtureBooking($organization, now()->addDay()->setTime(10, 0));
    $booking->update(['status' => BookingStatus::PENDING]);
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("confirmar {$booking->id}"), $session, $organization);

    expect($booking->fresh()->status)->toBe(BookingStatus::CONFIRMED);
    expect($sent[0]['message'])->toContain("confirmé la reserva #{$booking->id}");
});

test('"cancelar <id>" sobre una reserva ya terminal avisa en vez de romper', function () {
    $organization = adminAgentFixtureOrganization();
    $booking = adminAgentFixtureBooking($organization, now()->addDay()->setTime(10, 0));
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("cancelar {$booking->id}"), $session, $organization);

    expect($sent[0]['message'])->toContain('ya estaba en un estado terminal');
});

test('"ausente <id>" marca la reserva como NO_SHOW', function () {
    $organization = adminAgentFixtureOrganization();
    $booking = adminAgentFixtureBooking($organization, now()->addDay()->setTime(10, 0));
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("ausente {$booking->id}"), $session, $organization);

    expect($booking->fresh()->status)->toBe(BookingStatus::NO_SHOW);
    expect($sent[0]['message'])->toContain("marqué la reserva #{$booking->id} como ausente");
});

test('"ausente <id>" también funciona sobre una reserva ya COMPLETED — corrige un auto-completado', function () {
    $organization = adminAgentFixtureOrganization();
    $booking = adminAgentFixtureBooking($organization, now()->addDay()->setTime(10, 0));
    $booking->update(['status' => BookingStatus::COMPLETED]);
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("ausente {$booking->id}"), $session, $organization);

    expect($booking->fresh()->status)->toBe(BookingStatus::NO_SHOW);
});

test('"ausente <id>" sobre una reserva cancelada avisa en vez de romper', function () {
    $organization = adminAgentFixtureOrganization();
    $booking = adminAgentFixtureBooking($organization, now()->addDay()->setTime(10, 0));
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);
    $session = adminAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAdminCommandAgent($sent);

    $agent->handle(adminAgentFixtureMessage("ausente {$booking->id}"), $session, $organization);

    expect($sent[0]['message'])->toContain('ya estaba en un estado terminal');
});

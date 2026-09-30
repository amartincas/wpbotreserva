<?php

use App\Application\Booking\CancelBookingCommand;
use App\Application\Booking\CreateBookingCommand;
use App\Application\Booking\CreateBookingData;
use App\Application\Booking\RecordBookingAttendanceCommand;
use App\Application\Booking\RescheduleBookingCommand;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\ConfirmacionAsistenciaAgent;
use App\Application\Conversations\Agents\GestionReservaAgent;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\AttendanceStatus;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function confirmAttendanceFakeDraftRepository(): ConversationDraftRepositoryInterface
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

function confirmAttendanceFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['toPhoneE164' => $toPhoneE164, 'message' => $bodyText, 'buttons' => $buttons];
        }
    };
}

function confirmAttendanceNeverCalledAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA en este turno.');
        }
    };
}

function confirmAttendanceFixtureOrganization(string $phoneNumberId = 'wamid-confirm-attendance'): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId.'-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
        resources: [new ResourceRegistrationData('Carlos', array_map(
            fn (int $weekday) => new WeeklyScheduleSlot(weekday: $weekday, startTime: '00:00', endTime: '23:59'),
            range(0, 6)
        ))],
    ));

    return Organization::findOrFail($result->organizationId);
}

function confirmAttendanceFixtureSession(Organization $organization, string $customerPhone = '+573001234567'): ConversationSession
{
    return ConversationSession::create([
        'channel_id' => $organization->channels()->first()->id,
        'customer_phone' => $customerPhone,
        'organization_id' => $organization->id,
    ]);
}

function confirmAttendanceFixtureMessage(string $text, string $fromPhone = '+573001234567'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-confirm-attendance', $fromPhone, $text, now()->toImmutable());
}

function confirmAttendanceFixtureBooking(Organization $organization, string $customerPhone, CarbonImmutable $startsAt): Booking
{
    $result = (new CreateBookingCommand(app(BookingSchedulerInterface::class)))->handle(new CreateBookingData(
        organization: $organization,
        location: $organization->locations()->first(),
        service: $organization->services()->first(),
        customerPhone: $customerPhone,
        customerName: null,
        startsAt: $startsAt,
        resource: $organization->resources()->first(),
    ));

    return Booking::findOrFail($result->bookingId);
}

function confirmAttendancePendingFor(Booking $booking, ?CarbonImmutable $expiresAt = null): PendingAttendanceConfirmation
{
    return PendingAttendanceConfirmation::create([
        'booking_id' => $booking->id,
        'template_name' => 'confirmacion_asistencia_reserva',
        'expires_at' => $expiresAt ?? $booking->starts_at,
    ]);
}

function buildConfirmacionAsistenciaAgent(ConversationDraftRepositoryInterface $drafts, array &$sent): ConfirmacionAsistenciaAgent
{
    $gestionReservaAgent = new GestionReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        confirmAttendanceFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        app(ActiveBookingsFinderInterface::class),
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new RescheduleBookingCommand(app(BookingSchedulerInterface::class)),
        confirmAttendanceNeverCalledAi(),
    );

    return new ConfirmacionAsistenciaAgent(
        $drafts,
        new EloquentConversationSessionRepository,
        confirmAttendanceFakeNotificationSender($sent),
        new RecordBookingAttendanceCommand,
        $gestionReservaAgent,
    );
}

// --- "Sí" ---

test('Sí: registra CONFIRMED, borra la fila pendiente y responde al cliente', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    confirmAttendancePendingFor($booking);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    $booking = $booking->fresh();
    expect($booking->attendance_status)->toBe(AttendanceStatus::CONFIRMED);
    expect($booking->attendance_responded_at)->not->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->exists())->toBeFalse();
    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('esperamos');
    expect($session->fresh()->current_intent)->toBeNull();
});

test('Sí duplicado: si ya había attendance_status, no reprocesa', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $booking->update(['attendance_status' => AttendanceStatus::CONFIRMED, 'attendance_responded_at' => now()]);
    confirmAttendancePendingFor($booking);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    expect($sent[0]['message'])->toContain('Ya habías confirmado');
});

test('Booking terminal (cancelado por una vía ajena al recordatorio): no registra asistencia, borra la fila pendiente', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    confirmAttendancePendingFor($booking);
    // Cancelación directa (no vía "No"): declined_at nunca se marca, así que
    // el listener de Fase 3 no borra la fila (es no-op, caso ya cubierto en
    // el test de "cancelación normal") — la fila queda huérfana hasta que
    // ConfirmacionAsistenciaAgent la encuentra y la limpia acá.
    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    expect($booking->fresh()->attendance_status)->toBeNull();
    expect($sent[0]['message'])->toContain('ya no está activa');
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->exists())->toBeFalse();
});

test('Booking ya pasado (starts_at en el pasado): no registra asistencia', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    confirmAttendancePendingFor($booking, now()->addDay()->addDay()); // expires_at futuro, pero starts_at se fuerza al pasado abajo
    $booking->forceFill(['starts_at' => now()->subHour()])->save();
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    expect($booking->fresh()->attendance_status)->toBeNull();
    expect($sent[0]['message'])->toContain('ya pasó');
});

test('multi-tenancy: una fila pendiente de otra Organization nunca se resuelve', function () {
    $orgA = confirmAttendanceFixtureOrganization('wamid-org-a');
    $orgB = confirmAttendanceFixtureOrganization('wamid-org-b');
    $bookingB = confirmAttendanceFixtureBooking($orgB, '+573001234567', now()->addDay()->setTime(9, 0));
    confirmAttendancePendingFor($bookingB);

    // Sesión de A, pero forzamos que resolveAnswer reciba (vía startFlow) las
    // filas de A — que no tiene ninguna — probando el camino "0 pendientes".
    $sessionA = confirmAttendanceFixtureSession($orgA);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('si'), $sessionA, $orgA);

    expect($sent[0]['message'])->toContain('No tengo ningún recordatorio pendiente');
    expect($bookingB->fresh()->attendance_status)->toBeNull();
});

// --- "No" ---

test('No: marca declined_at en la fila (sin borrarla), no toca attendance_status, entrega a GestionReservaAgent', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $pending = confirmAttendancePendingFor($booking);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('no'), $session, $organization);

    expect($pending->fresh()->declined_at)->not->toBeNull();
    expect($booking->fresh()->attendance_status)->toBeNull();
    expect($session->fresh()->current_intent)->toBe(Intent::GestionReserva->value);
    // presentBookingAndAskAction (GestionReservaAgent, reutilizado) manda los
    // botones de acción — confirma el handoff real, no una simulación.
    expect($sent)->toHaveCount(1);
    expect(array_column($sent[0]['buttons'], 'id'))->toBe(['cancelar', 'reprogramar', 'estado']);
    expect($drafts->get($session))->toMatchArray(['bookingId' => $booking->id, '_awaiting_action' => true]);
});

test('No → Cancelar: el listener detecta declined_at y registra DECLINED; la fila se borra', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $pending = confirmAttendancePendingFor($booking);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('no'), $session, $organization);
    // Reutiliza GestionReservaAgent sin duplicar: cancelar/confirmar tal cual.
    $gestionReservaAgent = new GestionReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        confirmAttendanceFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        app(ActiveBookingsFinderInterface::class),
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new RescheduleBookingCommand(app(BookingSchedulerInterface::class)),
        confirmAttendanceNeverCalledAi(),
    );
    $gestionReservaAgent->handle(confirmAttendanceFixtureMessage('cancelar'), $session, $organization);
    $gestionReservaAgent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    $booking = $booking->fresh();
    expect($booking->status)->toBe(BookingStatus::CANCELLED);
    expect($booking->attendance_status)->toBe(AttendanceStatus::DECLINED);
    expect($booking->attendance_responded_at)->not->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->exists())->toBeFalse();
});

test('No → Modificar: el listener limpia la fila pendiente, attendance_status permanece NULL', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $pending = confirmAttendancePendingFor($booking);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('no'), $session, $organization);

    $gestionReservaAgent = new GestionReservaAgent(
        new ConversationalFlowRunner,
        $drafts,
        confirmAttendanceFakeNotificationSender($sent),
        app(AvailabilityCalculatorInterface::class),
        app(ActiveBookingsFinderInterface::class),
        new CancelBookingCommand(app(BookingSchedulerInterface::class)),
        new RescheduleBookingCommand(app(BookingSchedulerInterface::class)),
        confirmAttendanceNeverCalledAi(),
    );
    $gestionReservaAgent->handle(confirmAttendanceFixtureMessage('reprogramar'), $session, $organization);
    // "para el jueves" resuelve determinista (DateFieldExtractor), sin IA —
    // mismo caso real ya probado en GestionReservaAgentTest.
    $gestionReservaAgent->handle(confirmAttendanceFixtureMessage('para el jueves'), $session, $organization);
    $gestionReservaAgent->handle(confirmAttendanceFixtureMessage('1'), $session, $organization);
    $gestionReservaAgent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    $booking = $booking->fresh();
    expect($booking->status)->toBe(BookingStatus::CONFIRMED);
    expect($booking->attendance_status)->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->exists())->toBeFalse();
});

test('cancelación normal, sin recordatorio de por medio, nunca toca attendance_status (listener no-op)', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    // Sin PendingAttendanceConfirmation alguna.

    (new CancelBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking, 'Cancelación normal, sin recordatorio');

    expect($booking->fresh()->status)->toBe(BookingStatus::CANCELLED);
    expect($booking->fresh()->attendance_status)->toBeNull();
});

test('reprogramación normal, sin recordatorio de por medio, es no-op para el listener de limpieza', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));

    (new RescheduleBookingCommand(app(BookingSchedulerInterface::class)))->handle($booking, now()->addDays(2)->setTime(9, 0));

    expect($booking->fresh()->attendance_status)->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking->id)->exists())->toBeFalse();
});

// --- 2+ pendientes ---

test('2+ pendientes: pide desambiguación numerada, preserva _pendingAnswer, resuelve al Booking correcto', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking1 = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $booking2 = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(11, 0));
    confirmAttendancePendingFor($booking1);
    confirmAttendancePendingFor($booking2);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('si'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toContain('más de un recordatorio pendiente');
    $draft = $drafts->get($session);
    expect($draft['_awaitingAttendanceBookingSelection'])->toBeTrue();
    expect($draft['_pendingAnswer'])->toBe('si');
    expect($draft['_candidateBookingIds'])->toBe([$booking1->id, $booking2->id]);
    expect($session->fresh()->current_intent)->toBe(Intent::ConfirmacionAsistencia->value);

    $agent->handle(confirmAttendanceFixtureMessage('2'), $session, $organization);

    expect($booking2->fresh()->attendance_status)->toBe(AttendanceStatus::CONFIRMED);
    expect($booking1->fresh()->attendance_status)->toBeNull();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking2->id)->exists())->toBeFalse();
    expect(PendingAttendanceConfirmation::where('booking_id', $booking1->id)->exists())->toBeTrue();
});

test('botón con 2+ pendientes: misma ambigüedad que texto libre, mismo flujo de desambiguación', function () {
    $organization = confirmAttendanceFixtureOrganization();
    $booking1 = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(9, 0));
    $booking2 = confirmAttendanceFixtureBooking($organization, '+573001234567', now()->addDay()->setTime(11, 0));
    confirmAttendancePendingFor($booking1);
    confirmAttendancePendingFor($booking2);
    $session = confirmAttendanceFixtureSession($organization);
    $drafts = confirmAttendanceFakeDraftRepository();
    $sent = [];
    $agent = buildConfirmacionAsistenciaAgent($drafts, $sent);

    $agent->handle(confirmAttendanceFixtureMessage('no_asistencia_reserva'), $session, $organization);

    $draft = $drafts->get($session);
    expect($draft['_awaitingAttendanceBookingSelection'])->toBeTrue();
    expect($draft['_pendingAnswer'])->toBe('no');
});

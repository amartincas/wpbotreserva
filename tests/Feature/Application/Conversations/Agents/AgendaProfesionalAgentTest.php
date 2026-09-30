<?php

use App\Application\Booking\Agenda\AgendaDateResolver;
use App\Application\Booking\Agenda\AgendaQueryService;
use App\Application\Booking\Agenda\ProfessionalResolver;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\AgendaProfesionalAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function agendaAgentFakeSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void {}
    };
}

function agendaAgentFixtureOrganization(string $timezone = 'America/Bogota'): Organization
{
    return Organization::create(['name' => 'Barbería Don Carlos', 'timezone' => $timezone]);
}

function agendaAgentFixtureResource(Organization $organization, string $contactPhone = '+573001111111'): Resource
{
    return Resource::create([
        'organization_id' => $organization->id, 'resource_type' => 'HUMAN',
        'display_name' => 'Carlos', 'contact_phone' => $contactPhone,
    ]);
}

function agendaAgentFixtureBooking(Organization $organization, Resource $resource, CarbonImmutable $startsAt, BookingStatus $status = BookingStatus::CONFIRMED): Booking
{
    static $sequence = 0;
    $sequence++;

    $location = Location::create(['organization_id' => $organization->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $organization->id, 'name' => 'Corte de cabello', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $organization->id, 'name' => 'Ana', 'phone' => '+5730099900'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT)]);

    // Normalizado a config('app.timezone') antes de persistir — mismo
    // criterio que CreateBookingCommand::handle() (corrección transversal
    // de timezone): este fixture simula una reserva ya creada por el
    // sistema real, así que tiene que respetar la misma convención de
    // persistencia, no guardar $startsAt con el timezone de la Organization
    // todavía adjunto.
    $normalizedStartsAt = $startsAt->setTimezone(config('app.timezone'));

    $booking = Booking::create([
        'organization_id' => $organization->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => $normalizedStartsAt, 'ends_at' => $normalizedStartsAt->addMinutes(30),
        'duration_minutes' => 30, 'status' => $status,
    ]);

    BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);

    return $booking;
}

function agendaAgentFixtureSession(Organization $organization): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-agenda-agent-'.uniqid(), 'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id, 'customer_phone' => '+573001111111', 'organization_id' => $organization->id,
    ]);
}

function agendaAgentFixtureMessage(string $text, string $fromPhone = '+573001111111'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-agenda-agent', $fromPhone, $text, now()->toImmutable());
}

function buildAgendaProfesionalAgent(array &$sent): AgendaProfesionalAgent
{
    return new AgendaProfesionalAgent(
        new ProfessionalResolver,
        new AgendaDateResolver,
        new AgendaQueryService,
        agendaAgentFakeSender($sent),
        app(BotMessageRepository::class),
    );
}

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 09:00:00', 'America/Bogota'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('modo cantidad: "cuántas citas tengo hoy" responde con el número correcto', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0));
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(11, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 2 citas para 15/10/2026.');
});

test('modo cantidad, singular: "tenés 1 cita"', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 1 cita para 15/10/2026.');
});

test('modo detalle: "qué tengo hoy" lista id, hora, servicio y cliente', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    $booking = agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toContain("#{$booking->id} 10:00 — Corte de cabello (Ana)");
});

test('sin citas: modo cantidad y modo detalle responden el mismo mensaje de vacío', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureResource($organization);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('No tenés citas para 15/10/2026.');
});

test('CANCELLED se excluye de la agenda del profesional', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0), BookingStatus::CANCELLED);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('No tenés citas para 15/10/2026.');
});

test('COMPLETED y NO_SHOW se incluyen en la agenda del profesional', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(9, 30), BookingStatus::COMPLETED);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0), BookingStatus::NO_SHOW);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 2 citas para 15/10/2026.');
});

test('aislamiento entre Resources de la misma Organization: cada profesional ve solo lo suyo', function () {
    $organization = agendaAgentFixtureOrganization();
    $resourceA = agendaAgentFixtureResource($organization, '+573001111111');
    $resourceB = agendaAgentFixtureResource($organization, '+573002222222');
    agendaAgentFixtureBooking($organization, $resourceA, now()->setTime(10, 0));
    agendaAgentFixtureBooking($organization, $resourceB, now()->setTime(11, 0));
    agendaAgentFixtureBooking($organization, $resourceB, now()->setTime(12, 0));

    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?', '+573001111111'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 1 cita para 15/10/2026.');
});

test('aislamiento entre Organizations: mismo contact_phone, cada Organization ve solo lo suyo', function () {
    $orgA = agendaAgentFixtureOrganization();
    $orgB = agendaAgentFixtureOrganization();
    $resourceA = agendaAgentFixtureResource($orgA, '+573001111111');
    $resourceB = agendaAgentFixtureResource($orgB, '+573001111111');
    agendaAgentFixtureBooking($orgA, $resourceA, now()->setTime(10, 0));
    agendaAgentFixtureBooking($orgB, $resourceB, now()->setTime(11, 0));
    agendaAgentFixtureBooking($orgB, $resourceB, now()->setTime(12, 0));

    $sessionA = agendaAgentFixtureSession($orgA);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $sessionA, $orgA);

    expect($sent[0]['message'])->toBe('Tenés 1 cita para 15/10/2026.');
});

test('teléfono desconocido: el Agent no responde nada (el gate ya lo filtra)', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureResource($organization, '+573001111111');
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?', '+573009999999'), $session, $organization);

    expect($sent)->toBeEmpty();
});

test('"31/02/2026" llega al Agent y responde fecha inválida', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureResource($organization);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo el 31/02/2026?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Esa fecha no es válida.');
});

test('timezone distinto del servidor: "mañana" se calcula con Organization.timezone', function () {
    // Servidor de test corre en su propio timezone (config app.timezone) —
    // Asia/Tokyo está muy adelantado, fuerza a que "mañana" difiera de lo
    // que now() sin argumento daría.
    $organization = agendaAgentFixtureOrganization('Asia/Tokyo');
    $resource = agendaAgentFixtureResource($organization);
    $tokyoTomorrow = CarbonImmutable::now('Asia/Tokyo')->addDay()->startOfDay();
    agendaAgentFixtureBooking($organization, $resource, $tokyoTomorrow->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo mañana?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 1 cita para '.$tokyoTomorrow->format('d/m/Y').'.');
});

test('fecha "d de mes": "qué tengo el 31 de octubre"', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, CarbonImmutable::parse('2026-10-31 10:00:00', 'America/Bogota'));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo el 31 de octubre?'), $session, $organization);

    expect($sent[0]['message'])->toContain('31/10/2026');
});

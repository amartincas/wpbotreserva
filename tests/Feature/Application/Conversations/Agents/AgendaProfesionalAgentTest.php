<?php

use App\Application\Booking\Agenda\AgendaDateResolver;
use App\Application\Booking\Agenda\AgendaQueryService;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Conversations\Agents\AgendaProfesionalAgent;
use App\Application\Conversations\Agents\CentralOutOfScopeAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\ConversationReplier;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Domain\Booking\Booking;
use App\Domain\Booking\BookingResource;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

function agendaAgentFakeReplier(array &$sent): ConversationReplier
{
    return new ConversationReplier(new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sent[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $message];
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['channel' => $channel, 'toPhoneE164' => $to, 'message' => $bodyText, 'buttons' => $buttons];
        }
    });
}

/**
 * B7: la agenda la consulta el OWNER — el remitente por default de
 * agendaAgentFixtureMessage() es el owner_phone de esta Organization.
 */
function agendaAgentFixtureOrganization(string $timezone = 'America/Bogota', string $ownerPhone = '+573001111111'): Organization
{
    return Organization::create(['name' => 'Barbería Don Carlos', 'timezone' => $timezone, 'owner_phone' => $ownerPhone]);
}

function agendaAgentFixtureResource(Organization $organization, string $name = 'Carlos', bool $isActive = true): Resource
{
    return Resource::create([
        'organization_id' => $organization->id, 'resource_type' => 'HUMAN',
        'display_name' => $name, 'is_active' => $isActive,
    ]);
}

function agendaAgentFixtureBooking(Organization $organization, ?Resource $resource, CarbonImmutable $startsAt, BookingStatus $status = BookingStatus::CONFIRMED): Booking
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

    if ($resource !== null) {
        BookingResource::create(['booking_id' => $booking->id, 'resource_id' => $resource->id]);
    }

    return $booking;
}

function agendaAgentFixtureSession(Organization $organization): ConversationSession
{
    // B7: el owner consulta su agenda desde el CENTRAL.
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
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
        new AgendaDateResolver,
        new AgendaQueryService,
        agendaAgentFakeReplier($sent),
        app(BotMessageRepository::class),
        new EloquentConversationSessionRepository,
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

test('modo detalle: "qué tengo hoy" lista hora (12h AM/PM), cliente, servicio y quién atiende, sin id', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toContain('• 10:00 AM — Ana — Corte de cabello — Carlos');
    expect($sent[0]['message'])->not->toMatch('/#\d+/');
});

test('modo detalle con varias citas: formato exacto de cada línea (hora, cliente, servicio, recurso) y orden cronológico', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    $booking1 = agendaAgentFixtureBooking($organization, $resource, now()->setTime(15, 0));
    $booking1->customer->update(['name' => 'Pedro Gómez']);
    $booking1->service->update(['name' => 'Masaje deportivo']);
    $booking2 = agendaAgentFixtureBooking($organization, $resource, now()->setTime(9, 0));
    $booking2->customer->update(['name' => 'Juan']);
    $booking2->service->update(['name' => 'Corte de cabello']);
    $booking3 = agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0));
    $booking3->customer->update(['name' => 'María']);
    $booking3->service->update(['name' => 'Masaje relajante']);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe(
        "📅 Tus citas de 15/10/2026:\n\n".
        "• 9:00 AM — Juan — Corte de cabello — Carlos\n".
        "• 10:00 AM — María — Masaje relajante — Carlos\n".
        '• 3:00 PM — Pedro Gómez — Masaje deportivo — Carlos'
    );
});

test('modo detalle: una cita a las 12:00 AM (medianoche) se formatea correctamente', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(0, 0))->customer->update(['name' => 'Ana']);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toContain('• 12:00 AM — Ana — Corte de cabello');
});

test('modo detalle: una cita a las 12:00 PM (mediodía) se formatea correctamente', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(12, 0))->customer->update(['name' => 'Ana']);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toContain('• 12:00 PM — Ana — Corte de cabello');
});

test('sin citas: modo cantidad y modo detalle responden el mismo mensaje de vacío, con el emoji de agenda', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureResource($organization);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('📅 No tenés citas para 15/10/2026.');
});

test('consulta de un único día: "hoy", "mañana" y una fecha explícita ("D de mes") devuelven la agenda de ese día', function (string $text, CarbonImmutable $expectedDate) {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, $expectedDate->setTime(10, 0))->customer->update(['name' => 'Ana']);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage($text), $session, $organization);

    expect($sent[0]['message'])->toBe(
        "📅 Tus citas de {$expectedDate->format('d/m/Y')}:\n\n• 10:00 AM — Ana — Corte de cabello — Carlos"
    );
})->with([
    '¿Qué citas tengo hoy?' => ['¿Qué citas tengo hoy?', CarbonImmutable::parse('2026-10-15 00:00:00', 'America/Bogota')],
    '¿Qué citas tengo mañana?' => ['¿Qué citas tengo mañana?', CarbonImmutable::parse('2026-10-16 00:00:00', 'America/Bogota')],
    // "5 de octubre" ya pasó respecto al "hoy" congelado (15/10/2026) —
    // AgendaDateResolver asume la próxima ocurrencia, igual criterio que
    // "3 de septiembre" en AgendaDateResolverTest: resuelve a 2027, no 2026.
    '¿Qué citas tengo para el 5 de octubre?' => ['¿Qué citas tengo para el 5 de octubre?', CarbonImmutable::parse('2027-10-05 00:00:00', 'America/Bogota')],
]);

test('CANCELLED se excluye de la agenda del owner', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0), BookingStatus::CANCELLED);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('📅 No tenés citas para 15/10/2026.');
});

test('PENDING, COMPLETED y NO_SHOW se incluyen en la agenda del owner', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(9, 0), BookingStatus::PENDING);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(9, 30), BookingStatus::COMPLETED);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0), BookingStatus::NO_SHOW);
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 3 citas para 15/10/2026.');
});

test('B7: el owner con varios Resources ve las citas de TODOS, cada una con quién la atiende', function () {
    $organization = agendaAgentFixtureOrganization();
    $carlos = agendaAgentFixtureResource($organization, name: 'Carlos');
    $ana = agendaAgentFixtureResource($organization, name: 'Ana María');
    agendaAgentFixtureBooking($organization, $carlos, now()->setTime(10, 0));
    agendaAgentFixtureBooking($organization, $ana, now()->setTime(11, 0));
    agendaAgentFixtureBooking($organization, $ana, now()->setTime(12, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $session, $organization);
    $agent->handle(agendaAgentFixtureMessage('¿Qué citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe('Tenés 3 citas para 15/10/2026.');
    expect($sent[1]['message'])->toContain('• 10:00 AM — Ana — Corte de cabello — Carlos');
    expect($sent[1]['message'])->toContain('• 11:00 AM — Ana — Corte de cabello — Ana María');
    expect($sent[1]['message'])->toContain('• 12:00 PM — Ana — Corte de cabello — Ana María');
});

test('B7: un Resource inactivo sigue apareciendo si tiene reservas válidas', function () {
    $organization = agendaAgentFixtureOrganization();
    $inactive = agendaAgentFixtureResource($organization, name: 'Pedro', isActive: false);
    agendaAgentFixtureBooking($organization, $inactive, now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];

    buildAgendaProfesionalAgent($sent)->handle(agendaAgentFixtureMessage('¿Qué citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toContain('• 10:00 AM — Ana — Corte de cabello — Pedro');
});

test('B7: el owner sin Resource propio (ni reservas con recurso asignado) igual consulta su agenda', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureBooking($organization, null, now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];

    buildAgendaProfesionalAgent($sent)->handle(agendaAgentFixtureMessage('¿Qué citas tengo hoy?'), $session, $organization);

    expect($sent[0]['message'])->toBe("📅 Tus citas de 15/10/2026:\n\n• 10:00 AM — Ana — Corte de cabello");
});

test('aislamiento entre Organizations: el owner de A ve solo las citas de A', function () {
    $orgA = agendaAgentFixtureOrganization();
    $orgB = agendaAgentFixtureOrganization(ownerPhone: '+573008888888');
    $resourceA = agendaAgentFixtureResource($orgA);
    $resourceB = agendaAgentFixtureResource($orgB);
    agendaAgentFixtureBooking($orgA, $resourceA, now()->setTime(10, 0));
    agendaAgentFixtureBooking($orgB, $resourceB, now()->setTime(11, 0));
    agendaAgentFixtureBooking($orgB, $resourceB, now()->setTime(12, 0));

    $sessionA = agendaAgentFixtureSession($orgA);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Cuántas citas tengo hoy?'), $sessionA, $orgA);

    expect($sent[0]['message'])->toBe('Tenés 1 cita para 15/10/2026.');
});

test('un número que no es el owner no recibe la agenda — tampoco el de quien atiende', function () {
    $organization = agendaAgentFixtureOrganization();
    $resource = agendaAgentFixtureResource($organization);
    agendaAgentFixtureBooking($organization, $resource, now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $sent = [];
    $agent = buildAgendaProfesionalAgent($sent);

    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?', '+573002222222'), $session, $organization); // el teléfono de quien atiende
    $agent->handle(agendaAgentFixtureMessage('¿Qué tengo hoy?', '+573009998877'), $session, $organization); // un cliente cualquiera

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

test('B3: responde por el Channel de la sesión y limpia current_intent al terminar', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureResource($organization);
    $session = agendaAgentFixtureSession($organization);
    $session->update(['current_intent' => Intent::AgendaProfesional->value]);
    $sent = [];

    buildAgendaProfesionalAgent($sent)->handle(agendaAgentFixtureMessage('cuántas citas tengo hoy'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['channel']->is($session->channel))->toBeTrue();
    expect($session->fresh()->current_intent)->toBeNull();
});

test('B3/B7: si el remitente no es el owner no responde, pero igual limpia current_intent', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureResource($organization);
    $session = agendaAgentFixtureSession($organization);
    $session->update(['current_intent' => Intent::AgendaProfesional->value]);
    $sent = [];

    buildAgendaProfesionalAgent($sent)->handle(agendaAgentFixtureMessage('cuántas citas tengo hoy', '+573002222222'), $session, $organization);

    expect($sent)->toBe([]);
    expect($session->fresh()->current_intent)->toBeNull();
});

test('B7: el botón "Consultar agenda" del CENTRAL devuelve la agenda del día del owner', function () {
    $organization = agendaAgentFixtureOrganization();
    agendaAgentFixtureBooking($organization, agendaAgentFixtureResource($organization), now()->setTime(10, 0));
    $session = agendaAgentFixtureSession($organization);
    $buttonText = collect(CentralOutOfScopeAgent::BUTTONS)->firstWhere('title', 'Consultar agenda')['id'];
    $sent = [];

    buildAgendaProfesionalAgent($sent)->handle(agendaAgentFixtureMessage($buttonText), $session, $organization);

    expect($sent[0]['message'])->toBe("📅 Tus citas de 15/10/2026:\n\n• 10:00 AM — Ana — Corte de cabello — Carlos");
});

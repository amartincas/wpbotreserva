<?php

use App\Application\Channels\PhoneNumberIdChannelResolver;
use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Contracts\OrganizationResolverInterface;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\InboundMessageRouter;
use App\Application\Organizations\OrganizationResolution;
use App\Application\Organizations\OwnerOrganizationResolver;
use App\Application\Organizations\SingleOrganizationResolver;
use App\Domain\Booking\Booking;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Events\InboundMessageRejected;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

const ROUTER_FROM_PHONE = '+573001234567';

function routerFixtureMessage(string $phoneNumberId, string $text = 'hola'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), $phoneNumberId, ROUTER_FROM_PHONE, $text, now()->toImmutable());
}

function routerFixtureChannel(string $phoneNumberId, ChannelRole $role = ChannelRole::BUSINESS, ChannelStatus $status = ChannelStatus::ACTIVE): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => $status,
    ]);
}

/**
 * Un BUSINESS con su Organization vinculada — el caso normal del lado cliente.
 */
function routerFixtureBusiness(string $phoneNumberId): array
{
    $channel = routerFixtureChannel($phoneNumberId);
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);

    return [$channel, $organization];
}

/**
 * Classifier falso: nunca llama IA real, deja al test decidir el Intent —
 * cada test de Router prueba orquestación, no clasificación (ya cubierta en
 * CompositeIntentClassifierTest/AiIntentClassifierStrategyTest).
 */
function routerFixtureClassifier(Intent $intent): IntentClassifierInterface
{
    return new class($intent) implements IntentClassifierInterface
    {
        public function __construct(private readonly Intent $intent) {}

        public function classify(InboundMessage $message, ConversationSession $session): Intent
        {
            return $this->intent;
        }
    };
}

function routerFixtureAgent(array &$calls): AgentInterface
{
    return new class($calls) implements AgentInterface
    {
        public function __construct(private array &$calls) {}

        public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void
        {
            $this->calls[] = compact('message', 'session', 'organization');
        }
    };
}

function routerFixtureOrganizationlessAgent(array &$calls): OrganizationlessAgentInterface
{
    return new class($calls) implements OrganizationlessAgentInterface
    {
        public function __construct(private array &$calls) {}

        public function handle(InboundMessage $message, ConversationSession $session): void
        {
            $this->calls[] = compact('message', 'session');
        }
    };
}

/**
 * Fake configurable: por defecto nunca encuentra reservas activas (para no
 * afectar los tests de orquestación que no tienen nada que ver con
 * desambiguación) — los tests de ReservaOGestion lo cargan con bookings.
 */
function routerFixtureActiveBookingsFinder(Collection $bookings = new Collection): ActiveBookingsFinderInterface
{
    return new class($bookings) implements ActiveBookingsFinderInterface
    {
        public function __construct(private readonly Collection $bookings) {}

        public function forCustomer(Organization $organization, string $phone): Collection
        {
            return $this->bookings;
        }
    };
}

/**
 * Repositorio real que además registra cada recordIntent() — para probar
 * que un Intent inválido para el rol nunca se graba, ni siquiera un
 * instante antes de convertirse en FueraDeAlcance.
 */
function routerFixtureRecordingSessions(array &$recorded): EloquentConversationSessionRepository
{
    return new class($recorded) extends EloquentConversationSessionRepository
    {
        public function __construct(private array &$recorded) {}

        public function recordIntent(ConversationSession $session, ?Intent $intent): void
        {
            $this->recorded[] = $intent;
            parent::recordIntent($session, $intent);
        }
    };
}

function routerFixtureInconsistentResolver(string $reason): OwnerOrganizationResolver
{
    return new class($reason) extends OwnerOrganizationResolver
    {
        public function __construct(private readonly string $reason) {}

        public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution
        {
            return OrganizationResolution::inconsistent($this->reason);
        }
    };
}

/**
 * $businessAgents por defecto: solo el Intent clasificado, con un Agent
 * normal. $centralAgents por defecto: vacío — cada test del CENTRAL declara
 * explícitamente su allowlist.
 */
function buildRouter(
    Intent $intent,
    array &$agentCalls,
    ?array $businessAgents = null,
    ?ActiveBookingsFinderInterface $activeBookings = null,
    array $centralAgents = [],
    ?EloquentConversationSessionRepository $sessions = null,
    ?OwnerOrganizationResolver $centralOrganizations = null,
    ?OrganizationResolverInterface $businessOrganizations = null,
): InboundMessageRouter {
    $businessAgents ??= [$intent->value => routerFixtureAgent($agentCalls)];

    return new InboundMessageRouter(
        new PhoneNumberIdChannelResolver,
        $sessions ?? new EloquentConversationSessionRepository,
        $businessOrganizations ?? new SingleOrganizationResolver,
        $centralOrganizations ?? new OwnerOrganizationResolver,
        routerFixtureClassifier($intent),
        new AgentSelector(centralAgents: $centralAgents, businessAgents: $businessAgents),
        $activeBookings ?? routerFixtureActiveBookingsFinder(),
    );
}

function routerSessionFor(Channel $channel): ConversationSession
{
    return ConversationSession::where('channel_id', $channel->id)->where('customer_phone', ROUTER_FROM_PHONE)->firstOrFail();
}

// --- Channel ----------------------------------------------------------------

test('rechaza el mensaje si el Channel no existe', function () {
    Event::fake([InboundMessageRejected::class]);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('no-existe'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_not_found');
    expect($calls)->toBeEmpty();
});

test('rechaza el mensaje si el Channel existe pero no está activo', function () {
    Event::fake([InboundMessageRejected::class]);
    routerFixtureChannel('wamid-router-inactive', status: ChannelStatus::SUSPENDED);
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('wamid-router-inactive'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_inactive');
    expect($calls)->toBeEmpty();
});

// --- BUSINESS ---------------------------------------------------------------

test('BUSINESS + Organization válida: camino feliz, delega con la Organization del vínculo', function () {
    [$channel, $org] = routerFixtureBusiness('wamid-router-happy');
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $message = routerFixtureMessage('wamid-router-happy', 'quiero un turno');
    $router->handle($message);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['message'])->toBe($message);
    expect($calls[0]['organization']->is($org))->toBeTrue();
    expect($calls[0]['session']->channel_id)->toBe($channel->id);

    $session = routerSessionFor($channel);
    expect($session->organization_id)->toBe($org->id);
    expect($session->current_intent)->toBe('reserva');
});

test('BUSINESS sin Organization: channel_unlinked, se loguea y nunca abre un onboarding (aunque el Intent sea RegistroNegocio)', function () {
    Event::fake([InboundMessageRejected::class]);
    Log::spy();
    $channel = routerFixtureChannel('wamid-router-unlinked');
    $registroCalls = [];
    $router = buildRouter(
        Intent::RegistroNegocio,
        $registroCalls,
        businessAgents: [Intent::RegistroNegocio->value => routerFixtureOrganizationlessAgent($registroCalls)],
    );

    $router->handle(routerFixtureMessage('wamid-router-unlinked', 'quiero registrar mi negocio'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_unlinked');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'BUSINESS sin Organization') && $context['channel_id'] === $channel->id);
    expect($registroCalls)->toBeEmpty();
    expect(routerSessionFor($channel)->current_intent)->toBeNull();
});

test('BUSINESS desvinculado entre dos mensajes: el segundo es channel_unlinked — la sesión memoizada ya no sirve de atajo', function () {
    Event::fake([InboundMessageRejected::class]);
    [$channel, $org] = routerFixtureBusiness('wamid-router-continuity');
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls);

    $router->handle(routerFixtureMessage('wamid-router-continuity', 'primer mensaje'));
    $channel->organizations()->detach($org->id);
    $router->handle(routerFixtureMessage('wamid-router-continuity', 'segundo mensaje'));

    expect($calls)->toHaveCount(1);
    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_unlinked');
});

test('BUSINESS con resolución inconsistente (2+ vínculos): falla cerrado, sin Agent ni Intent grabado', function () {
    Event::fake([InboundMessageRejected::class]);
    Log::spy();
    $channel = routerFixtureChannel('wamid-router-business-inconsistent');
    $calls = [];
    $inconsistent = new class implements OrganizationResolverInterface
    {
        public function resolve(Channel $channel, ConversationSession $session): OrganizationResolution
        {
            return OrganizationResolution::inconsistent('channel_multiple_organizations');
        }
    };
    $router = buildRouter(Intent::Reserva, $calls, businessOrganizations: $inconsistent);

    $router->handle(routerFixtureMessage('wamid-router-business-inconsistent'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'channel_multiple_organizations');
    Log::shouldHaveReceived('error')->once();
    expect($calls)->toBeEmpty();
    expect(routerSessionFor($channel)->current_intent)->toBeNull();
});

test('Intent de CENTRAL recibido en BUSINESS (el owner escribe "gestionar negocio" al número del negocio): FueraDeAlcance del BUSINESS', function () {
    [$channel] = routerFixtureBusiness('wamid-router-central-on-business');
    $gestionCalls = [];
    $outOfScopeCalls = [];
    $recorded = [];
    $router = buildRouter(
        Intent::GestionNegocio,
        $gestionCalls,
        businessAgents: [Intent::FueraDeAlcance->value => routerFixtureOrganizationlessAgent($outOfScopeCalls)],
        centralAgents: [Intent::GestionNegocio->value => routerFixtureAgent($gestionCalls)],
        sessions: routerFixtureRecordingSessions($recorded),
    );

    $router->handle(routerFixtureMessage('wamid-router-central-on-business', 'gestionar negocio'));

    expect($gestionCalls)->toBeEmpty();
    expect($outOfScopeCalls)->toHaveCount(1);
    expect(routerSessionFor($channel)->current_intent)->toBe(Intent::FueraDeAlcance->value);
    expect($recorded)->toBe([Intent::FueraDeAlcance]);
});

test('con reservas activas, un mensaje nuevo clasificado como Reserva se desvía a ReservaOGestion en vez de ir directo al Agent', function () {
    [$channel] = routerFixtureBusiness('wamid-router-choice-reserva');
    $calls = [];
    $choiceCalls = [];
    $router = buildRouter(
        Intent::Reserva,
        $calls,
        businessAgents: [
            Intent::Reserva->value => routerFixtureAgent($calls),
            Intent::ReservaOGestion->value => routerFixtureAgent($choiceCalls),
        ],
        activeBookings: routerFixtureActiveBookingsFinder(collect([new Booking])),
    );

    $router->handle(routerFixtureMessage('wamid-router-choice-reserva', 'quiero un turno'));

    expect($calls)->toBeEmpty(); // ReservaAgent nunca se invoca directo
    expect($choiceCalls)->toHaveCount(1);
    expect(routerSessionFor($channel)->current_intent)->toBe(Intent::ReservaOGestion->value);
});

test('con reservas activas, un mensaje nuevo clasificado como GestionReserva también se desvía a ReservaOGestion', function () {
    routerFixtureBusiness('wamid-router-choice-gestion');
    $calls = [];
    $choiceCalls = [];
    $router = buildRouter(
        Intent::GestionReserva,
        $calls,
        businessAgents: [
            Intent::GestionReserva->value => routerFixtureAgent($calls),
            Intent::ReservaOGestion->value => routerFixtureAgent($choiceCalls),
        ],
        activeBookings: routerFixtureActiveBookingsFinder(collect([new Booking])),
    );

    $router->handle(routerFixtureMessage('wamid-router-choice-gestion', 'quiero cancelar mi turno'));

    expect($calls)->toBeEmpty();
    expect($choiceCalls)->toHaveCount(1);
});

test('sin reservas activas, Reserva va directo al Agent sin pasar por la desambiguación', function () {
    routerFixtureBusiness('wamid-router-nochoice');
    $calls = [];
    $router = buildRouter(Intent::Reserva, $calls); // activeBookings default: vacío

    $router->handle(routerFixtureMessage('wamid-router-nochoice', 'quiero un turno'));

    expect($calls)->toHaveCount(1);
});

test('mid-flujo (current_intent ya activo), nunca se re-evalúa la desambiguación aunque haya reservas activas', function () {
    routerFixtureBusiness('wamid-router-midflow');
    $calls = [];
    $choiceCalls = [];

    // A diferencia de routerFixtureClassifier (siempre devuelve lo mismo),
    // este fake imita el comportamiento real de ConversationContinuityStrategy:
    // si la sesión ya tiene un Intent activo, lo repite — es justamente esa
    // repetición la que hace que $isFreshFlow sea false en el segundo
    // mensaje y la desambiguación nunca se vuelva a evaluar.
    $continuityLikeClassifier = new class implements IntentClassifierInterface
    {
        public function classify(InboundMessage $message, ConversationSession $session): Intent
        {
            return $session->current_intent !== null
                ? Intent::from($session->current_intent)
                : Intent::Reserva;
        }
    };

    $router = new InboundMessageRouter(
        new PhoneNumberIdChannelResolver,
        new EloquentConversationSessionRepository,
        new SingleOrganizationResolver,
        new OwnerOrganizationResolver,
        $continuityLikeClassifier,
        new AgentSelector(centralAgents: [], businessAgents: [
            Intent::Reserva->value => routerFixtureAgent($calls),
            Intent::ReservaOGestion->value => routerFixtureAgent($choiceCalls),
        ]),
        routerFixtureActiveBookingsFinder(collect([new Booking])),
    );

    // Primer mensaje: ya arranca con reservas activas — se desvía a choice.
    $router->handle(routerFixtureMessage('wamid-router-midflow', 'primer mensaje'));
    expect($choiceCalls)->toHaveCount(1);

    // Segundo mensaje: current_intent ya quedó en ReservaOGestion, así que
    // $isFreshFlow es false — sigue yendo a choice, nunca se vuelve a
    // evaluar la condición de reservas activas.
    $router->handle(routerFixtureMessage('wamid-router-midflow', 'segundo mensaje'));
    expect($choiceCalls)->toHaveCount(2);
    expect($calls)->toBeEmpty();
});

// --- CENTRAL ----------------------------------------------------------------

test('CENTRAL + owner sin Organization (teléfono desconocido): onboarding permitido, organización null', function () {
    $central = routerFixtureChannel('wamid-router-central-new', ChannelRole::CENTRAL);
    Organization::create(['name' => 'Otro Negocio', 'owner_phone' => '+573008888888']);
    $calls = [];
    $router = buildRouter(
        Intent::RegistroNegocio,
        $calls,
        centralAgents: [Intent::RegistroNegocio->value => routerFixtureOrganizationlessAgent($calls)],
    );

    $message = routerFixtureMessage('wamid-router-central-new', 'quiero registrar mi negocio');
    $router->handle($message);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['message'])->toBe($message);
    expect($calls[0]['session']->channel_id)->toBe($central->id);

    $session = routerSessionFor($central);
    expect($session->organization_id)->toBeNull();
    expect($session->current_intent)->toBe('registro_negocio');
});

test('CENTRAL + owner con Organization: la resuelve por owner_phone y delega la administración con esa Organization', function () {
    $central = routerFixtureChannel('wamid-router-central-owner', ChannelRole::CENTRAL);
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => ROUTER_FROM_PHONE]);
    $calls = [];
    $router = buildRouter(
        Intent::GestionNegocio,
        $calls,
        centralAgents: [Intent::GestionNegocio->value => routerFixtureAgent($calls)],
    );

    $router->handle(routerFixtureMessage('wamid-router-central-owner', 'gestionar negocio'));

    expect($calls)->toHaveCount(1);
    expect($calls[0]['organization']->is($org))->toBeTrue();
    expect(routerSessionFor($central)->organization_id)->toBe($org->id);
});

test('CENTRAL + owner desconocido con un Intent que requiere Organization: FueraDeAlcance del CENTRAL, nunca el Intent original', function () {
    $central = routerFixtureChannel('wamid-router-central-unknown', ChannelRole::CENTRAL);
    $gestionCalls = [];
    $outOfScopeCalls = [];
    $recorded = [];
    $router = buildRouter(
        Intent::GestionNegocio,
        $gestionCalls,
        centralAgents: [
            Intent::GestionNegocio->value => routerFixtureAgent($gestionCalls),
            Intent::FueraDeAlcance->value => routerFixtureOrganizationlessAgent($outOfScopeCalls),
        ],
        sessions: routerFixtureRecordingSessions($recorded),
    );

    $router->handle(routerFixtureMessage('wamid-router-central-unknown', 'gestionar negocio'));

    expect($gestionCalls)->toBeEmpty();
    expect($outOfScopeCalls)->toHaveCount(1);
    expect(routerSessionFor($central)->current_intent)->toBe(Intent::FueraDeAlcance->value);
    expect($recorded)->toBe([Intent::FueraDeAlcance]);
});

test('CENTRAL + owner con más de una Organization: falla cerrado, se loguea, sin Agent ni Intent grabado', function () {
    Event::fake([InboundMessageRejected::class]);
    Log::spy();
    $central = routerFixtureChannel('wamid-router-central-ambiguous', ChannelRole::CENTRAL);
    $calls = [];
    // UNIQUE(owner_phone) impide construir el dato real; el resolver falso
    // devuelve exactamente lo que OwnerOrganizationResolver devolvería.
    $router = buildRouter(
        Intent::GestionNegocio,
        $calls,
        centralAgents: [Intent::GestionNegocio->value => routerFixtureAgent($calls)],
        centralOrganizations: routerFixtureInconsistentResolver('owner_multiple_organizations'),
    );

    $router->handle(routerFixtureMessage('wamid-router-central-ambiguous', 'gestionar negocio'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'owner_multiple_organizations');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $context['reason'] === 'owner_multiple_organizations');
    expect($calls)->toBeEmpty();
    expect(routerSessionFor($central)->current_intent)->toBeNull();
});

test('CENTRAL vinculado a una Organization (SQL manual): inconsistencia, se rechaza y se loguea como error', function () {
    Event::fake([InboundMessageRejected::class]);
    Log::spy();
    $central = routerFixtureChannel('wamid-router-central-linked', ChannelRole::CENTRAL);
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => ROUTER_FROM_PHONE]);
    DB::table('channel_organization')->insert([
        'channel_id' => $central->id, 'organization_id' => $org->id, 'is_primary' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $calls = [];
    $router = buildRouter(
        Intent::GestionNegocio,
        $calls,
        centralAgents: [Intent::GestionNegocio->value => routerFixtureAgent($calls)],
    );

    $router->handle(routerFixtureMessage('wamid-router-central-linked', 'gestionar negocio'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'central_linked');
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => str_contains($message, 'CENTRAL') && $context['channel_id'] === $central->id);
    expect($calls)->toBeEmpty();
    expect(ConversationSession::where('channel_id', $central->id)->exists())->toBeFalse();
});

test('CENTRAL nunca obtiene Organization desde el pivot ni desde una sesión memoizada: quien no es owner queda sin Organization', function () {
    $central = routerFixtureChannel('wamid-router-central-memo', ChannelRole::CENTRAL);
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573008888888']);
    // Sesión memoizada a una Organization de la que este teléfono NO es owner
    // (ej. un cliente de la época en que el CENTRAL era el número del piloto).
    ConversationSession::create([
        'channel_id' => $central->id,
        'customer_phone' => ROUTER_FROM_PHONE,
        'organization_id' => $org->id,
    ]);
    $calls = [];
    $router = buildRouter(
        Intent::RegistroNegocio,
        $calls,
        centralAgents: [Intent::RegistroNegocio->value => routerFixtureOrganizationlessAgent($calls)],
    );

    $router->handle(routerFixtureMessage('wamid-router-central-memo', 'quiero registrar mi negocio'));

    expect($calls)->toHaveCount(1);
    expect(routerSessionFor($central)->organization_id)->toBeNull();
});

test('Intent de BUSINESS recibido en CENTRAL ("quiero reservar"): FueraDeAlcance del CENTRAL, sin grabar nunca el Intent original', function () {
    $central = routerFixtureChannel('wamid-router-business-on-central', ChannelRole::CENTRAL);
    Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => ROUTER_FROM_PHONE]);
    $reservaCalls = [];
    $outOfScopeCalls = [];
    $recorded = [];
    $router = buildRouter(
        Intent::Reserva,
        $reservaCalls,
        businessAgents: [Intent::Reserva->value => routerFixtureAgent($reservaCalls)],
        centralAgents: [Intent::FueraDeAlcance->value => routerFixtureOrganizationlessAgent($outOfScopeCalls)],
        sessions: routerFixtureRecordingSessions($recorded),
    );

    $router->handle(routerFixtureMessage('wamid-router-business-on-central', 'quiero reservar un turno'));

    expect($reservaCalls)->toBeEmpty();
    expect($outOfScopeCalls)->toHaveCount(1);
    expect(routerSessionFor($central)->current_intent)->toBe(Intent::FueraDeAlcance->value);
    expect($recorded)->toBe([Intent::FueraDeAlcance]);
});

test('Reset funciona en los dos roles', function () {
    $central = routerFixtureChannel('wamid-router-reset-central', ChannelRole::CENTRAL);
    [$business] = routerFixtureBusiness('wamid-router-reset-business');
    $resetCalls = [];
    $reset = routerFixtureOrganizationlessAgent($resetCalls);
    $router = buildRouter(
        Intent::Reset,
        $resetCalls,
        businessAgents: [Intent::Reset->value => $reset],
        centralAgents: [Intent::Reset->value => $reset],
    );

    $router->handle(routerFixtureMessage('wamid-router-reset-central', 'salir'));
    $router->handle(routerFixtureMessage('wamid-router-reset-business', 'salir'));

    expect($resetCalls)->toHaveCount(2);
    expect($resetCalls[0]['session']->channel_id)->toBe($central->id);
    expect($resetCalls[1]['session']->channel_id)->toBe($business->id);
});

test('sin Agent para el Intent ni FueraDeAlcance registrado: agent_not_available, y lo grabado es FueraDeAlcance, nunca el Intent inválido', function () {
    Event::fake([InboundMessageRejected::class]);
    [$channel, $org] = routerFixtureBusiness('wamid-router-noagent');
    $calls = [];
    $router = buildRouter(Intent::GestionNegocio, $calls, businessAgents: []);

    $router->handle(routerFixtureMessage('wamid-router-noagent'));

    Event::assertDispatched(InboundMessageRejected::class, fn ($e) => $e->reason === 'agent_not_available');
    expect($calls)->toBeEmpty();

    $session = routerSessionFor($channel);
    expect($session->organization_id)->toBe($org->id);
    expect($session->current_intent)->toBe(Intent::FueraDeAlcance->value);
});

test('Fase 6 en el CENTRAL: owner con Organization + arranque fresco de RegistroNegocio se sustituye por RegistroNegocioBloqueado', function () {
    $central = routerFixtureChannel('wamid-router-doble-registro', ChannelRole::CENTRAL);
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => ROUTER_FROM_PHONE]);
    $registroCalls = [];
    $bloqueadoCalls = [];
    // routerFixtureClassifier devuelve RegistroNegocio sin importar de dónde
    // vino (botón o IA) — el guard del Router actúa sobre el Intent ya
    // clasificado, nunca sobre quién lo produjo, así que este único test
    // cubre ambas rutas por construcción.
    $router = buildRouter(
        Intent::RegistroNegocio,
        $registroCalls,
        centralAgents: [
            Intent::RegistroNegocio->value => routerFixtureOrganizationlessAgent($registroCalls),
            Intent::RegistroNegocioBloqueado->value => routerFixtureAgent($bloqueadoCalls),
        ],
    );

    $router->handle(routerFixtureMessage('wamid-router-doble-registro', 'quiero registrar mi negocio'));

    expect($registroCalls)->toBeEmpty(); // RegistroNegocioAgent nunca se invoca
    expect($bloqueadoCalls)->toHaveCount(1);
    expect($bloqueadoCalls[0]['organization']->is($org))->toBeTrue();
    expect(routerSessionFor($central)->current_intent)->toBe(Intent::RegistroNegocioBloqueado->value);
});

test('Fase 6 en el CENTRAL: un registro YA en curso (no fresco) nunca se bloquea, aunque el owner ya resuelva a una Organization', function () {
    $central = routerFixtureChannel('wamid-router-registro-en-curso', ChannelRole::CENTRAL);
    // Se sembra current_intent = RegistroNegocio ANTES del mensaje para que
    // $isFreshFlow sea false de entrada — exactamente la distinción que el
    // guard tiene que respetar: Organization existente + NO fresco = nunca
    // bloquear.
    Organization::create(['name' => 'Negocio Viejo', 'owner_phone' => ROUTER_FROM_PHONE]);
    $sessions = new EloquentConversationSessionRepository;
    $session = $sessions->findOrCreateFor($central, ROUTER_FROM_PHONE);
    $sessions->recordIntent($session, Intent::RegistroNegocio);

    $registroCalls = [];
    $bloqueadoCalls = [];
    $router = buildRouter(
        Intent::RegistroNegocio, // el classifier de continuidad real repetiría esto mismo
        $registroCalls,
        centralAgents: [
            Intent::RegistroNegocio->value => routerFixtureOrganizationlessAgent($registroCalls),
            Intent::RegistroNegocioBloqueado->value => routerFixtureAgent($bloqueadoCalls),
        ],
    );

    $router->handle(routerFixtureMessage('wamid-router-registro-en-curso', 'Impulzar'));

    expect($bloqueadoCalls)->toBeEmpty();
    expect($registroCalls)->toHaveCount(1);
});

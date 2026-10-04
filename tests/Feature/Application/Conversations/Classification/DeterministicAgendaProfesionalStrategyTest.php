<?php

use App\Application\Conversations\Agents\CentralOutOfScopeAgent;
use App\Application\Conversations\Classification\DeterministicAdminCommandStrategy;
use App\Application\Conversations\Classification\DeterministicAgendaProfesionalStrategy;
use App\Application\Conversations\Classification\DeterministicBusinessManagementStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

const AGENDA_OWNER_PHONE = '+573001111111';

/**
 * B7: la agenda es del OWNER — consulta desde el CENTRAL, con la
 * Organization ya resuelta por owner_phone (B4).
 */
function agendaStrategyFixtureSession(Organization $organization, string $customerPhone = AGENDA_OWNER_PHONE): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-agenda-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => $customerPhone,
        'organization_id' => $organization->id,
    ]);
}

function agendaStrategyFixtureMessage(string $text, string $fromPhone = AGENDA_OWNER_PHONE): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-agenda', $fromPhone, $text, now()->toImmutable());
}

function agendaStrategyFixtureOrganization(?string $ownerPhone = AGENDA_OWNER_PHONE): Organization
{
    return Organization::create(['name' => 'Negocio', 'owner_phone' => $ownerPhone]);
}

test('el owner, con fecha + contexto de agenda: clasifica AgendaProfesional', function (string $text) {
    $session = agendaStrategyFixtureSession(agendaStrategyFixtureOrganization());

    $intent = (new DeterministicAgendaProfesionalStrategy)->attempt(agendaStrategyFixtureMessage($text), $session);

    expect($intent)->toBe(Intent::AgendaProfesional);
})->with([
    '¿Cuántas citas tengo hoy?',
    '¿Qué tengo hoy?',
    '¿Cuántas tengo mañana?',
    '¿Qué citas tengo mañana?',
    '¿Qué reservas tengo el 15 de octubre?',
    '¿Qué tengo el 15 de octubre?',
    '¿Cuántas citas tengo el 15/10/2026?',
    '¿Qué tengo el 31/02/2026?',
]);

test('B7: el owner sin ningún Resource propio también clasifica — el gate no depende de recursos', function () {
    $organization = agendaStrategyFixtureOrganization();
    expect($organization->resources()->count())->toBe(0);

    $intent = (new DeterministicAgendaProfesionalStrategy)
        ->attempt(agendaStrategyFixtureMessage('¿Qué tengo hoy?'), agendaStrategyFixtureSession($organization));

    expect($intent)->toBe(Intent::AgendaProfesional);
});

test('B7: un número que no es el owner nunca clasifica — tampoco el de quien atiende', function (string $fromPhone) {
    $organization = agendaStrategyFixtureOrganization();
    Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos']);
    $session = agendaStrategyFixtureSession($organization, $fromPhone);

    $intent = (new DeterministicAgendaProfesionalStrategy)->attempt(agendaStrategyFixtureMessage('¿Qué tengo hoy?', $fromPhone), $session);

    expect($intent)->toBeNull();
})->with([
    'el teléfono de quien atiende' => '+573002222222',
    'un cliente cualquiera' => '+573009998877',
]);

test('B7: una Organization sin owner_phone nunca expone la agenda', function () {
    $session = agendaStrategyFixtureSession(agendaStrategyFixtureOrganization(ownerPhone: null));

    expect((new DeterministicAgendaProfesionalStrategy)->attempt(agendaStrategyFixtureMessage('¿Qué tengo hoy?'), $session))->toBeNull();
});

/**
 * Corrección del falso positivo de "tengo" (post-implementación): acotar
 * solo "tengo" no alcanzaba, porque "turno" ya es por sí solo uno de los
 * sustantivos de agenda — esta afirmación matcheaba antes vía "turno",
 * no solo vía "tengo". Ahora exige una palabra interrogativa en el mismo
 * mensaje, ausente acá (es una afirmación, no una pregunta).
 */
test('afirmación no relacionada con agenda que contiene "tengo": nunca clasifica, aunque el remitente sea el owner', function () {
    $session = agendaStrategyFixtureSession(agendaStrategyFixtureOrganization());

    $intent = (new DeterministicAgendaProfesionalStrategy)
        ->attempt(agendaStrategyFixtureMessage('mañana tengo turno con el dentista'), $session);

    expect($intent)->toBeNull();
});

test('el owner sin ninguna forma de fecha reconocible: no clasifica (cae al flujo normal)', function () {
    $session = agendaStrategyFixtureSession(agendaStrategyFixtureOrganization());

    expect((new DeterministicAgendaProfesionalStrategy)->attempt(agendaStrategyFixtureMessage('hola, buen día'), $session))->toBeNull();
});

test('el owner, mensaje con fecha pero sin contexto de agenda: no clasifica', function () {
    $session = agendaStrategyFixtureSession(agendaStrategyFixtureOrganization());

    expect((new DeterministicAgendaProfesionalStrategy)->attempt(agendaStrategyFixtureMessage('hoy hace calor'), $session))->toBeNull();
});

test('sin organization resuelta en la sesión, nunca clasifica', function () {
    agendaStrategyFixtureOrganization();
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-'.uniqid(), 'status' => ChannelStatus::ACTIVE,
    ]);
    $sessionWithoutOrg = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => AGENDA_OWNER_PHONE]);

    expect((new DeterministicAgendaProfesionalStrategy)->attempt(agendaStrategyFixtureMessage('¿Qué tengo hoy?'), $sessionWithoutOrg))->toBeNull();
});

test('B7: el botón "Consultar agenda" del CENTRAL clasifica AgendaProfesional y ninguna strategy anterior lo reclama', function () {
    $session = agendaStrategyFixtureSession(agendaStrategyFixtureOrganization());
    $buttonText = collect(CentralOutOfScopeAgent::BUTTONS)->firstWhere('title', 'Consultar agenda')['id'];
    $message = agendaStrategyFixtureMessage($buttonText);

    // Corren antes en CompositeIntentClassifier (ver AppServiceProvider).
    expect((new DeterministicAdminCommandStrategy)->attempt($message, $session))->toBeNull();
    expect((new DeterministicBusinessManagementStrategy)->attempt($message, $session))->toBeNull();

    expect((new DeterministicAgendaProfesionalStrategy)->attempt($message, $session))->toBe(Intent::AgendaProfesional);
});

test('B7: ProfessionalResolver ya no existe y el código de la agenda no lee contact_phone', function () {
    expect(class_exists('App\Application\Booking\Agenda\ProfessionalResolver'))->toBeFalse();

    foreach ([
        app_path('Application/Conversations/Classification/DeterministicAgendaProfesionalStrategy.php'),
        app_path('Application/Conversations/Agents/AgendaProfesionalAgent.php'),
        app_path('Application/Booking/Agenda/AgendaQueryService.php'),
    ] as $file) {
        expect(file_get_contents($file))->not->toContain('contact_phone')->not->toContain('ProfessionalResolver');
    }
});

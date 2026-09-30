<?php

use App\Application\Booking\Agenda\ProfessionalResolver;
use App\Application\Conversations\Classification\DeterministicAgendaProfesionalStrategy;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function agendaStrategyFixtureSession(Organization $organization): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-agenda-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => '+573001111111',
        'organization_id' => $organization->id,
    ]);
}

function agendaStrategyFixtureMessage(string $text, string $fromPhone = '+573001111111'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-agenda', $fromPhone, $text, now()->toImmutable());
}

test('profesional reconocido, mensaje con fecha + contexto de agenda: clasifica AgendaProfesional', function (string $text) {
    $organization = Organization::create(['name' => 'Negocio']);
    Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos', 'contact_phone' => '+573001111111']);
    $session = agendaStrategyFixtureSession($organization);

    $intent = (new DeterministicAgendaProfesionalStrategy(new ProfessionalResolver))
        ->attempt(agendaStrategyFixtureMessage($text), $session);

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

/**
 * Corrección del falso positivo de "tengo" (post-implementación): acotar
 * solo "tengo" no alcanzaba, porque "turno" ya es por sí solo uno de los
 * sustantivos de agenda — esta afirmación matcheaba antes vía "turno",
 * no solo vía "tengo". Ahora exige una palabra interrogativa en el mismo
 * mensaje, ausente acá (es una afirmación, no una pregunta).
 */
test('afirmación no relacionada con agenda que contiene "tengo": nunca clasifica, aunque el remitente sea un profesional reconocido', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos', 'contact_phone' => '+573001111111']);
    $session = agendaStrategyFixtureSession($organization);

    $intent = (new DeterministicAgendaProfesionalStrategy(new ProfessionalResolver))
        ->attempt(agendaStrategyFixtureMessage('mañana tengo turno con el dentista'), $session);

    expect($intent)->toBeNull();
});

test('teléfono desconocido (no resuelve a ningún Resource): nunca clasifica', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    // Ningún Resource con este contact_phone.
    $session = agendaStrategyFixtureSession($organization);

    $intent = (new DeterministicAgendaProfesionalStrategy(new ProfessionalResolver))
        ->attempt(agendaStrategyFixtureMessage('¿Qué tengo hoy?'), $session);

    expect($intent)->toBeNull();
});

test('profesional reconocido pero sin ninguna forma de fecha reconocible: no clasifica (cae al flujo normal)', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos', 'contact_phone' => '+573001111111']);
    $session = agendaStrategyFixtureSession($organization);

    $intent = (new DeterministicAgendaProfesionalStrategy(new ProfessionalResolver))
        ->attempt(agendaStrategyFixtureMessage('hola, buen día'), $session);

    expect($intent)->toBeNull();
});

test('profesional reconocido, mensaje con fecha pero sin contexto de agenda: no clasifica', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos', 'contact_phone' => '+573001111111']);
    $session = agendaStrategyFixtureSession($organization);

    $intent = (new DeterministicAgendaProfesionalStrategy(new ProfessionalResolver))
        ->attempt(agendaStrategyFixtureMessage('hoy hace calor'), $session);

    expect($intent)->toBeNull();
});

test('sin organization resuelta en la sesión, nunca clasifica', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    Resource::create(['organization_id' => $organization->id, 'resource_type' => 'HUMAN', 'display_name' => 'Carlos', 'contact_phone' => '+573001111111']);

    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-'.uniqid(), 'status' => ChannelStatus::ACTIVE,
    ]);
    $sessionWithoutOrg = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001111111']);

    $intent = (new DeterministicAgendaProfesionalStrategy(new ProfessionalResolver))
        ->attempt(agendaStrategyFixtureMessage('¿Qué tengo hoy?'), $sessionWithoutOrg);

    expect($intent)->toBeNull();
});

<?php

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\Agents\CentralOutOfScopeAgent;
use App\Application\Conversations\Agents\OutOfScopeAgent;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\OrganizationAgentInvoker;
use App\Application\Conversations\OrganizationlessAgentInvoker;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function agentSelectorFakeAgent(array &$calls): AgentInterface
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

function agentSelectorFakeOrganizationlessAgent(array &$calls): OrganizationlessAgentInterface
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

function agentSelectorFixtureSession(): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-agent-selector-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);
}

function agentSelectorFixtureMessage(): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-agent-selector', '+573001234567', 'hola', now()->toImmutable());
}

test('con Organization resuelta, devuelve un OrganizationAgentInvoker que le pasa la Organization al Agent', function () {
    $calls = [];
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $selector = new AgentSelector(centralAgents: [], businessAgents: [Intent::Reserva->value => agentSelectorFakeAgent($calls)]);

    $invoker = $selector->selectFor(ChannelRole::BUSINESS, Intent::Reserva, $org);

    expect($invoker)->toBeInstanceOf(OrganizationAgentInvoker::class);

    $invoker->handle(agentSelectorFixtureMessage(), agentSelectorFixtureSession());

    expect($calls)->toHaveCount(1);
    expect($calls[0]['organization']->is($org))->toBeTrue();
});

test('sin Organization, un Agent que la requiere no tiene invoker disponible', function () {
    $calls = [];
    $selector = new AgentSelector(centralAgents: [Intent::GestionNegocio->value => agentSelectorFakeAgent($calls)], businessAgents: []);

    expect($selector->selectFor(ChannelRole::CENTRAL, Intent::GestionNegocio, null))->toBeNull();
    expect($calls)->toBeEmpty();
});

test('sin Organization, un OrganizationlessAgentInterface sí tiene invoker disponible', function () {
    $calls = [];
    $selector = new AgentSelector(centralAgents: [Intent::RegistroNegocio->value => agentSelectorFakeOrganizationlessAgent($calls)], businessAgents: []);

    $invoker = $selector->selectFor(ChannelRole::CENTRAL, Intent::RegistroNegocio, null);

    expect($invoker)->toBeInstanceOf(OrganizationlessAgentInvoker::class);

    $invoker->handle(agentSelectorFixtureMessage(), agentSelectorFixtureSession());

    expect($calls)->toHaveCount(1);
});

test('un OrganizationlessAgentInterface también funciona si hay Organization resuelta', function () {
    $calls = [];
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $selector = new AgentSelector(centralAgents: [Intent::RegistroNegocio->value => agentSelectorFakeOrganizationlessAgent($calls)], businessAgents: []);

    expect($selector->selectFor(ChannelRole::CENTRAL, Intent::RegistroNegocio, $org))->toBeInstanceOf(OrganizationlessAgentInvoker::class);
});

test('cada rol solo ve su propio mapa: el mismo Intent existe en uno y no en el otro', function () {
    $calls = [];
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $selector = new AgentSelector(
        centralAgents: [Intent::GestionNegocio->value => agentSelectorFakeAgent($calls)],
        businessAgents: [Intent::Reserva->value => agentSelectorFakeAgent($calls)],
    );

    expect($selector->selectFor(ChannelRole::CENTRAL, Intent::GestionNegocio, $org))->not->toBeNull();
    expect($selector->selectFor(ChannelRole::BUSINESS, Intent::GestionNegocio, $org))->toBeNull();
    expect($selector->selectFor(ChannelRole::BUSINESS, Intent::Reserva, $org))->not->toBeNull();
    expect($selector->selectFor(ChannelRole::CENTRAL, Intent::Reserva, $org))->toBeNull();
    expect($selector->allows(ChannelRole::CENTRAL, Intent::Reserva))->toBeFalse();
    expect($selector->allows(ChannelRole::BUSINESS, Intent::Reserva))->toBeTrue();
});

test('effectiveIntent: un Intent que el rol no atiende, o que requiere una Organization que no hay, pasa a FueraDeAlcance', function () {
    $calls = [];
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $selector = new AgentSelector(
        centralAgents: [
            Intent::GestionNegocio->value => agentSelectorFakeAgent($calls),
            Intent::FueraDeAlcance->value => agentSelectorFakeOrganizationlessAgent($calls),
        ],
        businessAgents: [
            Intent::Reserva->value => agentSelectorFakeAgent($calls),
            Intent::FueraDeAlcance->value => agentSelectorFakeOrganizationlessAgent($calls),
        ],
    );

    expect($selector->effectiveIntent(ChannelRole::CENTRAL, Intent::GestionNegocio, $org))->toBe(Intent::GestionNegocio);
    expect($selector->effectiveIntent(ChannelRole::CENTRAL, Intent::Reserva, $org))->toBe(Intent::FueraDeAlcance);
    expect($selector->effectiveIntent(ChannelRole::BUSINESS, Intent::GestionNegocio, $org))->toBe(Intent::FueraDeAlcance);
    expect($selector->effectiveIntent(ChannelRole::CENTRAL, Intent::GestionNegocio, null))->toBe(Intent::FueraDeAlcance);
});

test('devuelve null si no hay Agent registrado para ese Intent en ese rol', function () {
    $selector = new AgentSelector(centralAgents: [], businessAgents: []);

    expect($selector->selectFor(ChannelRole::CENTRAL, Intent::RegistroNegocio, null))->toBeNull();
});

/**
 * Construir el AgentSelector real instancia todos los agentes, y varios
 * reciben un cliente de IA que en test no tiene API key — se reemplaza por
 * uno que nunca se llama (estos tests solo inspeccionan el cableado).
 */
function agentSelectorRealWiring(): AgentSelector
{
    app()->instance(AiServiceInterface::class, new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('La IA no debería llamarse al inspeccionar el cableado.');
        }
    });

    return app(AgentSelector::class);
}

test('cableado real (AppServiceProvider): allowlist exacta de cada rol', function () {
    $selector = agentSelectorRealWiring();

    $central = [
        Intent::RegistroNegocio, Intent::RegistroNegocioBloqueado, Intent::RegistroNegocioExpirado,
        Intent::GestionNegocio, Intent::AdminCommand, Intent::AgendaProfesional,
        Intent::Reset, Intent::FueraDeAlcance,
    ];
    $business = [
        Intent::Reserva, Intent::GestionReserva, Intent::ReservaOGestion,
        Intent::InfoNegocio, Intent::ConfirmacionAsistencia,
        Intent::Reset, Intent::FueraDeAlcance,
    ];

    foreach (Intent::cases() as $intent) {
        expect($selector->allows(ChannelRole::CENTRAL, $intent))->toBe(in_array($intent, $central, true), "CENTRAL / {$intent->value}");
        expect($selector->allows(ChannelRole::BUSINESS, $intent))->toBe(in_array($intent, $business, true), "BUSINESS / {$intent->value}");
    }
});

test('cableado real: FueraDeAlcance usa CentralOutOfScopeAgent en el CENTRAL y OutOfScopeAgent en BUSINESS, ambos sin Organization', function () {
    $selector = agentSelectorRealWiring();
    $readAgent = function () {
        return $this->agent;
    };
    $agentOf = fn (ChannelRole $role) => Closure::bind($readAgent, $selector->selectFor($role, Intent::FueraDeAlcance, null), OrganizationlessAgentInvoker::class)();

    expect($agentOf(ChannelRole::CENTRAL))->toBeInstanceOf(CentralOutOfScopeAgent::class);
    expect($agentOf(ChannelRole::BUSINESS))->toBeInstanceOf(OutOfScopeAgent::class);
});

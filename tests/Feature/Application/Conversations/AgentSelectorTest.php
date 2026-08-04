<?php

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\OrganizationAgentInvoker;
use App\Application\Conversations\OrganizationlessAgentInvoker;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
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
    $agent = agentSelectorFakeAgent($calls);
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $selector = new AgentSelector([Intent::Reserva->value => $agent]);

    $invoker = $selector->selectFor(Intent::Reserva, $org);

    expect($invoker)->toBeInstanceOf(OrganizationAgentInvoker::class);

    $message = agentSelectorFixtureMessage();
    $session = agentSelectorFixtureSession();
    $invoker->handle($message, $session);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['organization']->is($org))->toBeTrue();
});

test('sin Organization, un Agent que la requiere no tiene invoker disponible', function () {
    $calls = [];
    $agent = agentSelectorFakeAgent($calls);
    $selector = new AgentSelector([Intent::Reserva->value => $agent]);

    expect($selector->selectFor(Intent::Reserva, null))->toBeNull();
    expect($calls)->toBeEmpty();
});

test('sin Organization, un OrganizationlessAgentInterface sí tiene invoker disponible', function () {
    $calls = [];
    $agent = agentSelectorFakeOrganizationlessAgent($calls);
    $selector = new AgentSelector([Intent::RegistroNegocio->value => $agent]);

    $invoker = $selector->selectFor(Intent::RegistroNegocio, null);

    expect($invoker)->toBeInstanceOf(OrganizationlessAgentInvoker::class);

    $message = agentSelectorFixtureMessage();
    $session = agentSelectorFixtureSession();
    $invoker->handle($message, $session);

    expect($calls)->toHaveCount(1);
});

test('un OrganizationlessAgentInterface también funciona si hay Organization resuelta', function () {
    $calls = [];
    $agent = agentSelectorFakeOrganizationlessAgent($calls);
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $selector = new AgentSelector([Intent::RegistroNegocio->value => $agent]);

    $invoker = $selector->selectFor(Intent::RegistroNegocio, $org);

    expect($invoker)->toBeInstanceOf(OrganizationlessAgentInvoker::class);
});

test('devuelve null si no hay Agent registrado para ese Intent', function () {
    $selector = new AgentSelector([]);

    expect($selector->selectFor(Intent::RegistroNegocio, null))->toBeNull();
});

<?php

use App\Application\Contracts\AgentInterface;
use App\Application\Conversations\AgentSelector;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Organization;

function agentSelectorFakeAgent(): AgentInterface
{
    return new class implements AgentInterface
    {
        public function handle(InboundMessage $message, ConversationSession $session, Organization $organization): void {}
    };
}

test('devuelve el Agent registrado para el Intent', function () {
    $agent = agentSelectorFakeAgent();
    $selector = new AgentSelector([Intent::Reserva->value => $agent]);

    expect($selector->selectFor(Intent::Reserva))->toBe($agent);
});

test('devuelve null si no hay Agent registrado para ese Intent', function () {
    $selector = new AgentSelector([]);

    expect($selector->selectFor(Intent::RegistroNegocio))->toBeNull();
});

<?php

namespace App\Application\Conversations;

use App\Application\Contracts\AgentInterface;
use App\Application\Contracts\AgentInvoker;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Organization;

final class OrganizationAgentInvoker implements AgentInvoker
{
    public function __construct(
        private readonly AgentInterface $agent,
        private readonly Organization $organization,
    ) {}

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $this->agent->handle($message, $session, $this->organization);
    }
}

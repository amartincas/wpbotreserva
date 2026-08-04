<?php

namespace App\Application\Conversations;

use App\Application\Contracts\AgentInvoker;
use App\Application\Contracts\OrganizationlessAgentInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;

final class OrganizationlessAgentInvoker implements AgentInvoker
{
    public function __construct(private readonly OrganizationlessAgentInterface $agent) {}

    public function handle(InboundMessage $message, ConversationSession $session): void
    {
        $this->agent->handle($message, $session);
    }
}

<?php

namespace App\Application\Conversations\Flows;

/**
 * Estados deterministas que puede devolver ConversationalFlowRunner::advance()
 * — mismo criterio que OrganizationResolutionStatus (Hito 4): estados
 * explícitos, nunca una excepción para un resultado esperado.
 */
enum FlowProgressStatus
{
    case NextStep;
    case Invalid;
    case Completed;
}

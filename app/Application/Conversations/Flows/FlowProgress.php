<?php

namespace App\Application\Conversations\Flows;

/**
 * Result DTO devuelto por ConversationalFlowRunner — el único vocabulario
 * de salida del motor de flujo, deliberadamente pobre (nunca un Agent
 * concreto, nunca una acción a ejecutar): el Agent que llama al Runner es
 * quien decide qué hacer con cada estado.
 */
final class FlowProgress
{
    private function __construct(
        public readonly FlowProgressStatus $status,
        public readonly ?FlowStep $step = null,
        public readonly ?string $reason = null,
        public readonly array $draft = [],
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     */
    public static function nextStep(FlowStep $step, array $draft): self
    {
        return new self(FlowProgressStatus::NextStep, step: $step, draft: $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public static function invalid(FlowStep $step, string $reason, array $draft): self
    {
        return new self(FlowProgressStatus::Invalid, step: $step, reason: $reason, draft: $draft);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public static function completed(array $draft): self
    {
        return new self(FlowProgressStatus::Completed, draft: $draft);
    }
}

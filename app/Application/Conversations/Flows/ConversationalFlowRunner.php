<?php

namespace App\Application\Conversations\Flows;

/**
 * Motor de flujo puro y determinista (validado explícitamente antes de
 * implementar el Hito 5): dado el mismo FlowStep[], draft y resultado de
 * extracción, siempre produce el mismo FlowProgress. Nunca invoca IA, no
 * conoce servicios externos, no conoce lógica de negocio, y nunca modifica
 * la definición del flujo que recibe — solo la lee para decidir cuál es el
 * próximo paso.
 *
 * No llama a FieldExtractorInterface: recibe un FieldExtractionResult ya
 * calculado. Quien invoca al extractor (y por lo tanto quien toca IA) es
 * el Agent, nunca este motor — así el Runner queda sin ningún punto de
 * contacto con IO en su propio call stack, no solo desacoplado por
 * interfaz. Sin estado propio, sin dependencias inyectadas.
 */
final class ConversationalFlowRunner
{
    /**
     * @param  FlowStep[]  $steps
     * @param  array<string, mixed>  $draft
     */
    public function currentStep(array $steps, array $draft): ?FlowStep
    {
        foreach ($steps as $step) {
            if (! array_key_exists($step->key, $draft)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @param  FlowStep[]  $steps
     * @param  array<string, mixed>  $draft
     */
    public function advance(array $steps, array $draft, FlowStep $answeredStep, FieldExtractionResult $result): FlowProgress
    {
        if (! $result->successful) {
            return FlowProgress::invalid($answeredStep, $result->reason, $draft);
        }

        $draft[$answeredStep->key] = $result->value;

        $next = $this->currentStep($steps, $draft);

        return $next !== null
            ? FlowProgress::nextStep($next, $draft)
            : FlowProgress::completed($draft);
    }
}

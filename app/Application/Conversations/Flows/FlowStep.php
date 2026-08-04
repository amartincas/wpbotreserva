<?php

namespace App\Application\Conversations\Flows;

use App\Application\Contracts\FieldExtractorInterface;
use Closure;

/**
 * Objeto estrictamente declarativo — key, prompt y extractor, sin métodos
 * de comportamiento (validado antes de implementar el Hito 5). Si en el
 * futuro un caso real necesita algo más, se introduce una abstracción
 * nueva antes que convertir esto en un pequeño motor de workflow.
 *
 * El prompt es un Closure (no un string fijo) para poder referenciar datos
 * ya recolectados del propio flujo (ej. "¿Cuál es el horario de {recurso}?")
 * — sigue siendo declarativo: formatea texto, no decide nada ni toca IO.
 */
final class FlowStep
{
    /**
     * @param  Closure(array<string, mixed>): string  $prompt
     */
    public function __construct(
        public readonly string $key,
        public readonly Closure $prompt,
        public readonly FieldExtractorInterface $extractor,
    ) {}
}

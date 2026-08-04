<?php

namespace App\Application\Contracts;

use App\Application\Conversations\Flows\FieldExtractionResult;

/**
 * Interpreta la respuesta libre de un cliente para UN campo — nunca varios.
 *
 * Decisión de diseño (Hito 5, validada antes de implementar): en el MVP,
 * cada interacción procesa únicamente el campo esperado por el FlowStep
 * actual. Información adicional presente en el mismo mensaje se ignora
 * deliberadamente para mantener el flujo simple y determinista. Si los
 * pilotos muestran que los usuarios responden habitualmente varios campos
 * en un solo mensaje, esa capacidad se evalúa como una evolución de
 * FieldExtractor, nunca de ConversationalFlowRunner (que no debe conocer
 * IA ni lógica de negocio).
 *
 * Consecuencia directa para cualquier implementación: el prompt/lógica de
 * extracción debe pedir explícitamente el campo propio y nada más — no
 * "extraé todo lo que encuentres y después quedate con uno", que arriesga
 * que la IA devuelva estructura multi-campo para descartar después.
 */
interface FieldExtractorInterface
{
    /**
     * @param  array<string, mixed>  $draftSoFar  Campos ya confirmados de este flujo — contexto, nunca mutado acá.
     */
    public function extract(string $answer, array $draftSoFar): FieldExtractionResult;
}

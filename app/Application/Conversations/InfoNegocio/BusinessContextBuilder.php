<?php

namespace App\Application\Conversations\InfoNegocio;

use App\Application\Conversations\Flows\SpanishWeekdayNames;
use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Collection;

/**
 * Arma el bloque de texto que InfoNegocioAgent usa como system prompt de la
 * IA (Fase 1, información general del negocio) — nunca genera texto propio,
 * solo transcribe lo que ya existe en el dominio. No re-deriva disponibilidad
 * real (eso sigue siendo trabajo de AvailabilityCalculator, fuera de
 * alcance acá): esto es una descripción textual del horario declarado de
 * cada recurso, para que la IA pueda contestar "¿qué días atienden?", no
 * para calcular huecos libres.
 *
 * Cada sección distingue explícitamente "sin datos" de "hay datos" — nunca
 * omite en silencio ni inventa un valor por default, porque el texto que
 * arma acá es exactamente lo que la IA tiene permitido citar (ver
 * InfoNegocioAgent::SYSTEM_PROMPT_TEMPLATE: "respondé ÚNICAMENTE con datos
 * de este contexto").
 */
class BusinessContextBuilder
{
    public function build(Organization $organization): string
    {
        $organization->loadMissing(['locations', 'services', 'resources.schedules']);

        $lines = [
            "Nombre del negocio: {$organization->name}",
            'Descripción: '.($organization->description ?? '(sin descripción registrada)'),
            '',
            'Ubicaciones:',
            $this->formatLocations($organization->locations),
            '',
            'Servicios:',
            $this->formatServices($organization->services),
            '',
            'Horarios de atención declarados:',
            $this->formatResourceSchedules($organization->resources),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function formatLocations(Collection $locations): string
    {
        if ($locations->isEmpty()) {
            return '(sin ubicación registrada)';
        }

        return $locations->map(function (Location $location) {
            $parts = array_filter([$location->address, $location->city]);

            return $parts !== [] ? '- '.implode(', ', $parts) : '- (sin dirección registrada)';
        })->implode("\n");
    }

    /**
     * @param  Collection<int, Service>  $services
     */
    private function formatServices(Collection $services): string
    {
        if ($services->isEmpty()) {
            return '(sin servicios registrados)';
        }

        return $services->map(function (Service $service) {
            $line = "- {$service->name} ({$service->duration_minutes} min)";
            $line .= $service->price !== null
                ? sprintf(', precio: $%s', number_format((float) $service->price, 0, ',', '.'))
                : ', precio: no registrado';

            if ($service->description !== null) {
                $line .= " — {$service->description}";
            }

            return $line;
        })->implode("\n");
    }

    /**
     * @param  Collection<int, resource>  $resources
     */
    private function formatResourceSchedules(Collection $resources): string
    {
        if ($resources->isEmpty()) {
            return '(sin recursos registrados)';
        }

        return $resources->map(function (Resource $resource) {
            $schedule = $resource->schedules
                ->sortBy(fn ($slot) => array_search($slot->weekday, SpanishWeekdayNames::WEEK_ORDER, true))
                ->map(fn ($slot) => sprintf(
                    '%s de %s a %s',
                    SpanishWeekdayNames::nameOf($slot->weekday) ?? "día {$slot->weekday}",
                    substr($slot->start_time, 0, 5),
                    substr($slot->end_time, 0, 5),
                ))
                ->implode(', ');

            return $schedule !== ''
                ? "- {$resource->display_name}: {$schedule}"
                : "- {$resource->display_name}: (sin horario registrado)";
        })->implode("\n");
    }
}

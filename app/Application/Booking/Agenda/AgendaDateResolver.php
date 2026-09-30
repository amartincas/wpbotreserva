<?php

namespace App\Application\Booking\Agenda;

use App\Application\Conversations\Flows\SpanishMonthNames;
use App\Domain\Tenancy\Organization;
use Carbon\CarbonImmutable;

/**
 * Resuelve la fecha de una consulta de agenda del profesional ("hoy",
 * "mañana", fecha explícita) de forma 100% determinista, sin IA — a
 * diferencia de DateFieldExtractor (resuelve "para qué fecha querés el
 * turno", con fallback a IA para frases sueltas), acá el alcance está
 * deliberadamente acotado a estas 4 formas; cualquier otra frase es
 * unrecognized(). No se toca ni se reusa DateFieldExtractor (Fase 4 no se
 * mezcla con el parser de horarios de Fase 7 ni con el flujo de reserva de
 * clientes).
 *
 * "hoy"/"mañana" usan Organization.timezone (nunca el timezone del
 * servidor) — mismo patrón ya probado en
 * SendProfessionalBookingConfirmationNotification::buildBodyParameters().
 */
class AgendaDateResolver
{
    private const NUMERIC_PATTERN = '/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/u';

    private const NAMED_MONTH_PATTERN = '/\b(\d{1,2})\s+de\s+([a-záéíóúñ]+)\b/u';

    public function resolve(string $text, Organization $organization): AgendaDateResolution
    {
        $normalized = mb_strtolower(trim($text));

        if (preg_match('/\bhoy\b/u', $normalized)) {
            return AgendaDateResolution::success(
                CarbonImmutable::now($organization->timezone)->startOfDay()
            );
        }

        if (preg_match('/\bma[ñn]ana\b/u', $normalized)) {
            return AgendaDateResolution::success(
                CarbonImmutable::now($organization->timezone)->addDay()->startOfDay()
            );
        }

        if (preg_match(self::NUMERIC_PATTERN, $normalized, $matches)) {
            return $this->resolveNumericDate((int) $matches[1], (int) $matches[2], (int) $matches[3], $organization);
        }

        if (preg_match(self::NAMED_MONTH_PATTERN, $normalized, $matches)) {
            return $this->resolveNamedMonthDate((int) $matches[1], $matches[2], $organization);
        }

        return AgendaDateResolution::unrecognized();
    }

    /**
     * Fecha numérica con año explícito (dd/mm/aaaa) — se permite que sea
     * pasada (consulta histórica válida, a diferencia de DateFieldExtractor
     * que rechaza fechas pasadas porque ahí no se puede reservar en el
     * pasado; acá es una consulta de solo lectura).
     */
    private function resolveNumericDate(int $day, int $month, int $year, Organization $organization): AgendaDateResolution
    {
        if (! checkdate($month, $day, $year)) {
            return AgendaDateResolution::invalidCalendarDate();
        }

        return AgendaDateResolution::success(CarbonImmutable::createFromDate($year, $month, $day, $organization->timezone)->startOfDay());
    }

    /**
     * Sin año explícito ("15 de octubre"): se asume el año actual en el
     * timezone de la Organization; si esa fecha ya pasó este año, se asume
     * el año siguiente — mismo criterio de "próxima ocurrencia" que ya usa
     * DateFieldExtractor::resolveNextWeekday() para días de semana sin
     * fecha explícita.
     */
    private function resolveNamedMonthDate(int $day, string $monthName, Organization $organization): AgendaDateResolution
    {
        $month = SpanishMonthNames::indexOf($monthName);

        if ($month === null) {
            return AgendaDateResolution::unrecognized();
        }

        $today = CarbonImmutable::now($organization->timezone)->startOfDay();
        $year = $today->year;

        if (! checkdate($month, $day, $year)) {
            return AgendaDateResolution::invalidCalendarDate();
        }

        $date = CarbonImmutable::createFromDate($year, $month, $day, $organization->timezone)->startOfDay();

        if ($date->lessThan($today)) {
            $year++;

            if (! checkdate($month, $day, $year)) {
                return AgendaDateResolution::invalidCalendarDate();
            }

            $date = CarbonImmutable::createFromDate($year, $month, $day, $organization->timezone)->startOfDay();
        }

        return AgendaDateResolution::success($date);
    }
}

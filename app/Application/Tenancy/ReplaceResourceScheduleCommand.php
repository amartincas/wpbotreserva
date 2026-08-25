<?php

namespace App\Application\Tenancy;

use App\Domain\Scheduling\Resource;
use App\Domain\Scheduling\ResourceSchedule;
use Illuminate\Support\Facades\DB;

/**
 * Reemplaza el horario semanal completo de un recurso (Incremento 4, punto
 * E de la prueba real — "quiero cambiar la agenda" no tenía ningún flujo).
 * Reemplazo total, no edición parcial: un horario semanal es un patrón
 * completo, no una lista de franjas independientes — pedir "agregá el
 * miércoles" o "sacá el viernes" es ambigüedad real que no vale la pena
 * resolver todavía sin un piloto que lo haya pedido.
 */
class ReplaceResourceScheduleCommand
{
    /**
     * @param  WeeklyScheduleSlot[]  $weeklySchedule
     */
    public function handle(Resource $resource, array $weeklySchedule): void
    {
        DB::transaction(function () use ($resource, $weeklySchedule) {
            $resource->schedules()->delete();

            foreach ($weeklySchedule as $slot) {
                ResourceSchedule::create([
                    'resource_id' => $resource->id,
                    'weekday' => $slot->weekday,
                    'start_time' => $slot->startTime,
                    'end_time' => $slot->endTime,
                ]);
            }
        });
    }
}

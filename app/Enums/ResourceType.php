<?php

namespace App\Enums;

/**
 * Distinción estructural única de Resource (Parte III/Parte II R1) — humano
 * o activo físico. La clasificación fina ("estilista", "mesa") es dato
 * configurable (Resource::subtype), nunca un caso de este enum.
 */
enum ResourceType: string
{
    case HUMAN = 'HUMAN';
    case ASSET = 'ASSET';
}

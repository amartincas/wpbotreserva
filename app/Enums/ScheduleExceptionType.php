<?php

namespace App\Enums;

enum ScheduleExceptionType: string
{
    case HOLIDAY = 'HOLIDAY';
    case BLOCK = 'BLOCK';
    case SPECIAL_HOURS = 'SPECIAL_HOURS';
}

<?php

use App\Application\Conversations\Flows\WeeklyScheduleFieldExtractor;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Contracts\AiServiceInterface;

function weeklyScheduleFakeService(string $response): AiServiceInterface
{
    return new class($response) implements AiServiceInterface
    {
        public function __construct(private readonly string $response) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            return $this->response;
        }
    };
}

test('parsea un JSON válido en un array de WeeklyScheduleSlot', function () {
    $json = json_encode([
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
        ['weekday' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
    ]);
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService($json));

    $result = $extractor->extract('Lunes y Martes de 9 a 17', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toHaveCount(2);
    expect($result->value[0])->toBeInstanceOf(WeeklyScheduleSlot::class);
    expect($result->value[0]->weekday)->toBe(1);
    expect($result->value[0]->startTime)->toBe('09:00');
    expect($result->value[1]->weekday)->toBe(2);
});

test('devuelve fallo cuando la IA responde NO_ENCONTRADO', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('NO_ENCONTRADO'));

    $result = $extractor->extract('no sé', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde un JSON inválido', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('{esto no es json'));

    $result = $extractor->extract('Lunes de 9 a 17', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde un array vacío', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('[]'));

    $result = $extractor->extract('nunca atendemos', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando un slot no tiene la forma esperada', function () {
    $extractor = new WeeklyScheduleFieldExtractor(weeklyScheduleFakeService('[{"weekday": 1}]'));

    $result = $extractor->extract('Lunes', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo (no propaga la excepción) si la llamada a la IA falla', function () {
    $throwing = new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('proveedor de IA caído');
        }
    };
    $extractor = new WeeklyScheduleFieldExtractor($throwing);

    $result = $extractor->extract('Lunes de 9 a 17', []);

    expect($result->successful)->toBeFalse();
});

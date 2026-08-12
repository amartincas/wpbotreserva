<?php

use App\Application\Conversations\Flows\DateFieldExtractor;
use App\Contracts\AiServiceInterface;

function dateExtractorFakeService(string $response): AiServiceInterface
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

test('parsea una fecha futura válida', function () {
    $tomorrow = now()->addDay()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($tomorrow));

    $result = $extractor->extract('mañana', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($tomorrow);
});

test('acepta el día de hoy', function () {
    $today = now()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($today));

    $result = $extractor->extract('hoy', []);

    expect($result->successful)->toBeTrue();
    expect($result->value->toDateString())->toBe($today);
});

test('rechaza una fecha que ya pasó', function () {
    $yesterday = now()->subDay()->toDateString();
    $extractor = new DateFieldExtractor(dateExtractorFakeService($yesterday));

    $result = $extractor->extract('ayer', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde NO_ENCONTRADO', function () {
    $extractor = new DateFieldExtractor(dateExtractorFakeService('NO_ENCONTRADO'));

    $result = $extractor->extract('asdkjhasd', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo cuando la IA responde algo que no es una fecha válida', function () {
    $extractor = new DateFieldExtractor(dateExtractorFakeService('no es una fecha'));

    $result = $extractor->extract('cualquier cosa', []);

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
    $extractor = new DateFieldExtractor($throwing);

    $result = $extractor->extract('mañana', []);

    expect($result->successful)->toBeFalse();
});

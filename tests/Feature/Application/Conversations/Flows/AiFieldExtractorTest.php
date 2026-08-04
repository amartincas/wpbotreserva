<?php

use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Contracts\AiServiceInterface;

function aiFieldExtractorFakeService(string $response): AiServiceInterface
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

function aiFieldExtractorThrowingService(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('proveedor de IA caído');
        }
    };
}

test('devuelve éxito con el valor extraído', function () {
    $extractor = new AiFieldExtractor(aiFieldExtractorFakeService('Restaurante El Sabor'), 'nombre del negocio', 'El nombre comercial.');

    $result = $extractor->extract('Se llama Restaurante El Sabor', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('Restaurante El Sabor');
});

test('recorta espacios de la respuesta de la IA', function () {
    $extractor = new AiFieldExtractor(aiFieldExtractorFakeService('  Bogotá  '), 'ciudad', 'La ciudad.');

    $result = $extractor->extract('Bogotá', []);

    expect($result->value)->toBe('Bogotá');
});

test('devuelve fallo cuando la IA responde NO_ENCONTRADO', function () {
    $extractor = new AiFieldExtractor(aiFieldExtractorFakeService('NO_ENCONTRADO'), 'ciudad', 'La ciudad.');

    $result = $extractor->extract('asdkjhasd', []);

    expect($result->successful)->toBeFalse();
    expect($result->reason)->not->toBeEmpty();
});

test('devuelve fallo cuando la IA responde vacío', function () {
    $extractor = new AiFieldExtractor(aiFieldExtractorFakeService(''), 'ciudad', 'La ciudad.');

    $result = $extractor->extract('asdkjhasd', []);

    expect($result->successful)->toBeFalse();
});

test('devuelve fallo (no propaga la excepción) si la llamada a la IA falla', function () {
    $extractor = new AiFieldExtractor(aiFieldExtractorThrowingService(), 'ciudad', 'La ciudad.');

    $result = $extractor->extract('Bogotá', []);

    expect($result->successful)->toBeFalse();
});

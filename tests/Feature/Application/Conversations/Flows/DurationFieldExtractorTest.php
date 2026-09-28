<?php

use App\Application\Conversations\Flows\AiFieldExtractor;
use App\Application\Conversations\Flows\DurationFieldExtractor;
use App\Contracts\AiServiceInterface;

function durationExtractorThrowingAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('No debería haberse llamado a la IA: el parser determinista debía resolverlo.');
        }
    };
}

function durationExtractorFakeAi(string $response): AiServiceInterface
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

function buildDurationExtractor(AiServiceInterface $ai): DurationFieldExtractor
{
    return new DurationFieldExtractor(
        new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.')
    );
}

// --- Casos deterministas: nunca tocan la IA ---

test('reconoce un entero desnudo sin llamar a la IA', function ($input, $expectedMinutes) {
    $extractor = buildDurationExtractor(durationExtractorThrowingAi());

    $result = $extractor->extract($input, []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe((string) $expectedMinutes);
})->with([
    ['45', 45],
    ['60', 60],
    ['90', 90],
    ['45 minutos', 45],
    ['45min', 45],
    ['45 min.', 45],
    ['999', 999],
]);

// --- Casos que caen al fallback de IA ---

test('un texto libre/ambiguo cae al fallback de IA', function ($input) {
    $extractor = buildDurationExtractor(durationExtractorFakeAi('45'));

    $result = $extractor->extract($input, []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('45');
})->with([
    '45 minutos aproximadamente',
    'una hora',
    'hora y media',
    'valor no numérico',
]);

test('0 minutos no es determinista — cae al fallback de IA', function () {
    $extractor = buildDurationExtractor(durationExtractorFakeAi('30'));

    $result = $extractor->extract('0', []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('30');
});

test('valores fuera de 1-999 caen al fallback de IA', function ($input) {
    $extractor = buildDurationExtractor(durationExtractorFakeAi('120'));

    $result = $extractor->extract($input, []);

    expect($result->successful)->toBeTrue();
    expect($result->value)->toBe('120');
})->with(['1000', '9999']);

test('confirma explícitamente que el fallback SÍ invoca al servicio de IA (no un no-op silencioso)', function () {
    $calls = [];
    $ai = new class($calls) implements AiServiceInterface
    {
        public function __construct(private array &$calls) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            $this->calls[] = $userMessage;

            return '45';
        }
    };
    $extractor = new DurationFieldExtractor(
        new AiFieldExtractor($ai, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.')
    );

    $extractor->extract('una hora', []);

    expect($calls)->toBe(['una hora']);
});

// --- Delegación transparente del contrato de AiFieldExtractor ---

test('cuando la IA responde NO_ENCONTRADO para una expresión libre, el resultado es un fallo (mismo comportamiento que AiFieldExtractor)', function () {
    $extractor = buildDurationExtractor(durationExtractorFakeAi('NO_ENCONTRADO'));

    $result = $extractor->extract('no sé cuánto dura', []);

    expect($result->successful)->toBeFalse();
});

test('si la llamada a la IA falla en el fallback, el resultado es un fallo (no propaga la excepción)', function () {
    $throwing = new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('proveedor de IA caído');
        }
    };
    $extractor = new DurationFieldExtractor(
        new AiFieldExtractor($throwing, 'duración en minutos', 'La duración del servicio, en minutos, como número entero.')
    );

    $result = $extractor->extract('una hora', []);

    expect($result->successful)->toBeFalse();
});

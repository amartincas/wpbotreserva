<?php

use App\Application\Contracts\FieldExtractorInterface;
use App\Application\Conversations\Flows\ConversationalFlowRunner;
use App\Application\Conversations\Flows\FieldExtractionResult;
use App\Application\Conversations\Flows\FlowProgressStatus;
use App\Application\Conversations\Flows\FlowStep;

/**
 * Extractor falso que nunca se invoca desde estos tests — ConversationalFlowRunner
 * nunca debe llamarlo (regla validada antes de implementar el Hito 5); si
 * alguna vez lo hiciera, este fake haría explotar el test.
 */
function runnerNeverCalledExtractor(): FieldExtractorInterface
{
    return new class implements FieldExtractorInterface
    {
        public function extract(string $answer, array $draftSoFar): FieldExtractionResult
        {
            throw new RuntimeException('ConversationalFlowRunner nunca debe invocar al extractor.');
        }
    };
}

function runnerFixtureSteps(): array
{
    return [
        new FlowStep('nombre', fn () => '¿Nombre?', runnerNeverCalledExtractor()),
        new FlowStep('ciudad', fn () => '¿Ciudad?', runnerNeverCalledExtractor()),
    ];
}

test('currentStep devuelve el primer step sin responder', function () {
    $runner = new ConversationalFlowRunner;
    $steps = runnerFixtureSteps();

    expect($runner->currentStep($steps, []))->toBe($steps[0]);
    expect($runner->currentStep($steps, ['nombre' => 'Carlos']))->toBe($steps[1]);
});

test('currentStep devuelve null cuando todos los steps están respondidos', function () {
    $runner = new ConversationalFlowRunner;
    $steps = runnerFixtureSteps();

    expect($runner->currentStep($steps, ['nombre' => 'Carlos', 'ciudad' => 'Bogotá']))->toBeNull();
});

test('advance con resultado exitoso agrega el valor al draft y avanza al siguiente step', function () {
    $runner = new ConversationalFlowRunner;
    $steps = runnerFixtureSteps();

    $progress = $runner->advance($steps, [], $steps[0], FieldExtractionResult::success('Carlos'));

    expect($progress->status)->toBe(FlowProgressStatus::NextStep);
    expect($progress->step)->toBe($steps[1]);
    expect($progress->draft)->toBe(['nombre' => 'Carlos']);
});

test('advance con resultado exitoso en el último step devuelve Completed con el draft final', function () {
    $runner = new ConversationalFlowRunner;
    $steps = runnerFixtureSteps();

    $progress = $runner->advance($steps, ['nombre' => 'Carlos'], $steps[1], FieldExtractionResult::success('Bogotá'));

    expect($progress->status)->toBe(FlowProgressStatus::Completed);
    expect($progress->draft)->toBe(['nombre' => 'Carlos', 'ciudad' => 'Bogotá']);
});

test('advance con resultado fallido devuelve Invalid con el motivo, sin tocar el draft', function () {
    $runner = new ConversationalFlowRunner;
    $steps = runnerFixtureSteps();

    $progress = $runner->advance($steps, [], $steps[0], FieldExtractionResult::failure('no entendí'));

    expect($progress->status)->toBe(FlowProgressStatus::Invalid);
    expect($progress->step)->toBe($steps[0]);
    expect($progress->reason)->toBe('no entendí');
    expect($progress->draft)->toBe([]);
});

test('mismo steps/draft/resultado siempre produce el mismo FlowProgress (determinismo)', function () {
    $runner = new ConversationalFlowRunner;
    $steps = runnerFixtureSteps();

    $first = $runner->advance($steps, [], $steps[0], FieldExtractionResult::success('Carlos'));
    $second = $runner->advance($steps, [], $steps[0], FieldExtractionResult::success('Carlos'));

    expect($first->status)->toBe($second->status);
    expect($first->draft)->toBe($second->draft);
    expect($first->step)->toBe($second->step);
});

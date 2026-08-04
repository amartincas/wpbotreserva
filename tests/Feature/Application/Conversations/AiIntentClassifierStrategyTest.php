<?php

use App\Application\Conversations\Classification\AiIntentClassifierStrategy;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function aiClassifierFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage(
        messageId: 'wamid.msg-'.uniqid(),
        phoneNumberId: 'wamid-ai-classifier',
        fromPhone: '+573001234567',
        text: $text,
        receivedAt: now()->toImmutable(),
    );
}

function aiClassifierFixtureSession(): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-ai-classifier-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);
}

/**
 * Fake que no sabe nada de OpenAI/Grok/Gemini — si esto compila y funciona,
 * la dependencia de AiIntentClassifierStrategy es realmente la interfaz
 * App\Contracts\AiServiceInterface, no una implementación concreta.
 */
function aiClassifierFakeService(string $response): AiServiceInterface
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

function aiClassifierThrowingService(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('proveedor de IA caído');
        }
    };
}

test('clasifica registro_negocio a partir de la respuesta de la IA', function () {
    $strategy = new AiIntentClassifierStrategy(aiClassifierFakeService('registro_negocio'));

    $intent = $strategy->attempt(aiClassifierFixtureMessage('quiero registrar mi barbería'), aiClassifierFixtureSession());

    expect($intent)->toBe(Intent::RegistroNegocio);
});

test('clasifica reserva a partir de la respuesta de la IA', function () {
    $strategy = new AiIntentClassifierStrategy(aiClassifierFakeService('reserva'));

    $intent = $strategy->attempt(aiClassifierFixtureMessage('quiero un turno para mañana'), aiClassifierFixtureSession());

    expect($intent)->toBe(Intent::Reserva);
});

test('normaliza mayúsculas y espacios de la respuesta de la IA', function () {
    $strategy = new AiIntentClassifierStrategy(aiClassifierFakeService('  RESERVA  '));

    $intent = $strategy->attempt(aiClassifierFixtureMessage('hola'), aiClassifierFixtureSession());

    expect($intent)->toBe(Intent::Reserva);
});

test('devuelve null si la IA responde algo no clasificable', function () {
    $strategy = new AiIntentClassifierStrategy(aiClassifierFakeService('no tengo idea che'));

    $intent = $strategy->attempt(aiClassifierFixtureMessage('???'), aiClassifierFixtureSession());

    expect($intent)->toBeNull();
});

test('devuelve null (no propaga la excepción) si la llamada a la IA falla', function () {
    $strategy = new AiIntentClassifierStrategy(aiClassifierThrowingService());

    $intent = $strategy->attempt(aiClassifierFixtureMessage('hola'), aiClassifierFixtureSession());

    expect($intent)->toBeNull();
});

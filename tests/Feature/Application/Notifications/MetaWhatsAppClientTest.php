<?php

use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\ConversationMessage;
use Illuminate\Support\Facades\Http;

function metaChannel(?array $credentials = ['access_token' => 'fake-token'], ?string $phoneNumberId = null): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        // phone_number_id es único (Hito 1) — cada llamada necesita el suyo
        // para no chocar cuando un mismo test crea más de un Channel.
        'phone_number_id' => $phoneNumberId ?? 'wamid-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
        'credentials' => $credentials,
    ]);
}

test('arma la URL y el payload correctos, y autentica con Bearer token', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);

    (new MetaWhatsAppClient)->sendTextMessage(metaChannel(phoneNumberId: 'wamid-123'), '+573001234567', 'Hola');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/wamid-123/messages'
            && $request->hasHeader('Authorization', 'Bearer fake-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['recipient_type'] === 'individual'
            && $request['to'] === '573001234567' // el "+" se quita para la API de Meta
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Hola';
    });
});

test('lanza NotificationDeliveryException si faltan credenciales de Meta (access_token o phone_number_id)', function () {
    expect(fn () => (new MetaWhatsAppClient)->sendTextMessage(metaChannel(credentials: null), '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);

    expect(fn () => (new MetaWhatsAppClient)->sendTextMessage(metaChannel(credentials: ['other_key' => 'x']), '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
});

test('lanza NotificationDeliveryException con el status y el body cuando Meta responde con error', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    expect(fn () => (new MetaWhatsAppClient)->sendTextMessage(metaChannel(), '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class, 'Meta API respondió 401');
});

test('no lanza excepción cuando Meta responde exitosamente', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);

    (new MetaWhatsAppClient)->sendTextMessage(metaChannel(), '+573001234567', 'Hola');
})->throwsNoExceptions();

test('sendTemplateMessage arma el payload de plantilla con los parámetros posicionales en orden', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);

    (new MetaWhatsAppClient)->sendTemplateMessage(
        metaChannel(phoneNumberId: 'wamid-456'),
        '+573001234567',
        'recordatorio_reserva',
        'es',
        ['Ana', 'Corte de cabello', 'AMC Studios', '24/08/2026', '15:00'],
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/wamid-456/messages'
            && $request['type'] === 'template'
            && $request['template']['name'] === 'recordatorio_reserva'
            && $request['template']['language']['code'] === 'es'
            && $request['template']['components'][0]['type'] === 'body'
            && $request['template']['components'][0]['parameters'] === [
                ['type' => 'text', 'text' => 'Ana'],
                ['type' => 'text', 'text' => 'Corte de cabello'],
                ['type' => 'text', 'text' => 'AMC Studios'],
                ['type' => 'text', 'text' => '24/08/2026'],
                ['type' => 'text', 'text' => '15:00'],
            ];
    });
});

test('sendTemplateMessage lanza NotificationDeliveryException si faltan credenciales, igual que sendTextMessage', function () {
    expect(fn () => (new MetaWhatsAppClient)->sendTemplateMessage(metaChannel(credentials: null), '+573001234567', 'recordatorio_reserva', 'es', ['Ana']))
        ->toThrow(NotificationDeliveryException::class);
});

test('sendTemplateMessage lanza NotificationDeliveryException cuando Meta responde con error (ej. plantilla no aprobada)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);

    expect(fn () => (new MetaWhatsAppClient)->sendTemplateMessage(metaChannel(), '+573001234567', 'recordatorio_reserva', 'es', ['Ana']))
        ->toThrow(NotificationDeliveryException::class, 'Meta API respondió 400');
});

test('sendButtonsMessage arma el payload interactivo de tipo "button" con id y title de cada botón', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);

    (new MetaWhatsAppClient)->sendButtonsMessage(
        metaChannel(phoneNumberId: 'wamid-789'),
        '+573001234567',
        '¿Confirmás el turno?',
        [
            ['id' => 'si', 'title' => 'Sí'],
            ['id' => 'no', 'title' => 'No'],
        ],
    );

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/wamid-789/messages'
            && $request['type'] === 'interactive'
            && $request['interactive']['type'] === 'button'
            && $request['interactive']['body']['text'] === '¿Confirmás el turno?'
            && $request['interactive']['action']['buttons'] === [
                ['type' => 'reply', 'reply' => ['id' => 'si', 'title' => 'Sí']],
                ['type' => 'reply', 'reply' => ['id' => 'no', 'title' => 'No']],
            ];
    });
});

test('sendButtonsMessage lanza NotificationDeliveryException si faltan credenciales, igual que sendTextMessage', function () {
    expect(fn () => (new MetaWhatsAppClient)->sendButtonsMessage(metaChannel(credentials: null), '+573001234567', '¿Confirmás?', [['id' => 'si', 'title' => 'Sí']]))
        ->toThrow(NotificationDeliveryException::class);
});

// --- Post-E2E Fase 1 (Hallazgo 5): trazabilidad mínima de mensajes salientes ---

test('sendTextMessage crea una fila en conversation_messages con direction=outbound, el texto real y el WAMID que devuelve Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.respuesta-real']]], 200)]);
    $channel = metaChannel(phoneNumberId: 'wamid-log-text');

    (new MetaWhatsAppClient)->sendTextMessage($channel, '+573001234567', 'Hola, tu turno quedó confirmado');

    $logged = ConversationMessage::where('channel_id', $channel->id)->firstOrFail();
    expect($logged->direction)->toBe('outbound');
    expect($logged->customer_phone)->toBe('+573001234567');
    expect($logged->body)->toBe('Hola, tu turno quedó confirmado');
    expect($logged->message_id)->toBe('wamid.respuesta-real');
    expect($logged->organization_id)->toBeNull(); // ChannelClientInterface no recibe Organization — ver docblock de la clase
});

test('sendTemplateMessage crea una fila en conversation_messages describiendo la plantilla y sus parámetros', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.respuesta-template']]], 200)]);
    $channel = metaChannel(phoneNumberId: 'wamid-log-template');

    (new MetaWhatsAppClient)->sendTemplateMessage($channel, '+573001234567', 'recordatorio_reserva', 'es', ['Ana', 'Corte de cabello']);

    $logged = ConversationMessage::where('channel_id', $channel->id)->firstOrFail();
    expect($logged->direction)->toBe('outbound');
    expect($logged->body)->toContain('recordatorio_reserva');
    expect($logged->body)->toContain('Ana');
    expect($logged->body)->toContain('Corte de cabello');
});

test('sendButtonsMessage crea una fila en conversation_messages con el texto del cuerpo (no la lista de botones en crudo)', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.respuesta-botones']]], 200)]);
    $channel = metaChannel(phoneNumberId: 'wamid-log-buttons');

    (new MetaWhatsAppClient)->sendButtonsMessage($channel, '+573001234567', '¿Confirmás el turno?', [['id' => 'si', 'title' => 'Sí'], ['id' => 'no', 'title' => 'No']]);

    $logged = ConversationMessage::where('channel_id', $channel->id)->firstOrFail();
    expect($logged->direction)->toBe('outbound');
    expect($logged->body)->toBe('¿Confirmás el turno?');
});

test('si Meta responde error, no se registra ninguna fila outbound — solo se registra lo que realmente se envió', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);
    $channel = metaChannel(phoneNumberId: 'wamid-log-failed');

    expect(fn () => (new MetaWhatsAppClient)->sendTextMessage($channel, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);

    expect(ConversationMessage::where('channel_id', $channel->id)->count())->toBe(0);
});

test('nunca registra credenciales, tokens ni headers en el body — solo el contenido conversacional', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);
    $channel = metaChannel(credentials: ['access_token' => 'secreto-super-sensible'], phoneNumberId: 'wamid-log-privacy');

    (new MetaWhatsAppClient)->sendTextMessage($channel, '+573001234567', 'Hola');

    $logged = ConversationMessage::where('channel_id', $channel->id)->firstOrFail();
    expect($logged->body)->not->toContain('secreto-super-sensible');
    expect($logged->body)->not->toContain('Bearer');
});

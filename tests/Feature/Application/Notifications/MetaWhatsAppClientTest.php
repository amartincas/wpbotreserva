<?php

use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\MetaWhatsAppClient;
use Illuminate\Support\Facades\Http;

test('arma la URL y el payload correctos, y autentica con Bearer token', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);

    (new MetaWhatsAppClient)->sendTextMessage('wamid-123', 'fake-token', '+573001234567', 'Hola');

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

test('lanza NotificationDeliveryException con el status y el body cuando Meta responde con error', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    expect(fn () => (new MetaWhatsAppClient)->sendTextMessage('wamid-123', 'bad-token', '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class, 'Meta API respondió 401');
});

test('no lanza excepción cuando Meta responde exitosamente', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);

    (new MetaWhatsAppClient)->sendTextMessage('wamid-123', 'fake-token', '+573001234567', 'Hola');
})->throwsNoExceptions();

<?php

use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
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

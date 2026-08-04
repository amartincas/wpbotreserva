<?php

use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Application\Notifications\WhatsAppNotificationSender;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\Http;

function organizationWithChannel(ChannelStatus $status = ChannelStatus::ACTIVE, ?array $credentials = ['access_token' => 'fake-token']): array
{
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-123',
        'status' => $status,
        'credentials' => $credentials,
    ]);
    $channel->organizations()->attach($org->id, ['is_primary' => true]);

    return [$org, $channel];
}

function whatsAppSender(): WhatsAppNotificationSender
{
    return new WhatsAppNotificationSender(new MetaWhatsAppClient);
}

test('resuelve el channel activo de la organización y delega el envío en MetaWhatsAppClient', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);
    [$org] = organizationWithChannel();

    whatsAppSender()->send($org, '+573001234567', 'Hola, tu reserva está confirmada.');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://graph.facebook.com/v21.0/wamid-123/messages'
            && $request['to'] === '573001234567'
            && $request['text']['body'] === 'Hola, tu reserva está confirmada.'
            && $request->hasHeader('Authorization', 'Bearer fake-token');
    });
});

test('lanza NotificationDeliveryException si la organización no tiene channel de WhatsApp', function () {
    $org = Organization::create(['name' => 'Sin canal']);

    expect(fn () => whatsAppSender()->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
});

test('lanza NotificationDeliveryException si el channel no está activo', function () {
    [$org] = organizationWithChannel(status: ChannelStatus::SUSPENDED);

    expect(fn () => whatsAppSender()->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
});

test('lanza NotificationDeliveryException si faltan credenciales', function () {
    [$org] = organizationWithChannel(credentials: null);

    expect(fn () => whatsAppSender()->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
});

test('propaga la excepción de MetaWhatsAppClient cuando Meta responde con error', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);
    [$org] = organizationWithChannel();

    expect(fn () => whatsAppSender()->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
});

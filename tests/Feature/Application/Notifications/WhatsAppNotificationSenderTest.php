<?php

use App\Application\Contracts\ChannelClientInterface;
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

/**
 * Fake que no sabe nada de Meta/Graph API — si esto compila y funciona, la
 * dependencia de WhatsAppNotificationSender es realmente la interfaz, no la
 * clase concreta MetaWhatsAppClient (justo lo que pidió la revisión).
 */
function fakeChannelClient(array &$calls): ChannelClientInterface
{
    return new class($calls) implements ChannelClientInterface
    {
        public function __construct(private array &$sharedRef) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sharedRef[] = compact('channel', 'to', 'message');
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void
        {
            $this->sharedRef[] = compact('channel', 'to', 'templateName', 'language', 'bodyParameters');
        }
    };
}

test('depende de ChannelClientInterface: un cliente falso (sin saber nada de Meta) recibe el channel resuelto', function () {
    $calls = [];
    [$org, $channel] = organizationWithChannel();

    (new WhatsAppNotificationSender(fakeChannelClient($calls)))
        ->send($org, '+573001234567', 'Hola, tu reserva está confirmada.');

    expect($calls)->toHaveCount(1);
    expect($calls[0]['channel']->is($channel))->toBeTrue();
    expect($calls[0]['to'])->toBe('+573001234567');
    expect($calls[0]['message'])->toBe('Hola, tu reserva está confirmada.');
});

test('con la implementación real (MetaWhatsAppClient), efectivamente llega a la Graph API de Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);
    [$org] = organizationWithChannel();

    (new WhatsAppNotificationSender(new MetaWhatsAppClient))
        ->send($org, '+573001234567', 'Hola, tu reserva está confirmada.');

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/wamid-123/messages');
});

test('sendTemplate depende de ChannelClientInterface: un cliente falso recibe el channel resuelto y los parámetros posicionales', function () {
    $calls = [];
    [$org, $channel] = organizationWithChannel();

    (new WhatsAppNotificationSender(fakeChannelClient($calls)))
        ->sendTemplate($org, '+573001234567', 'recordatorio_reserva', 'es', ['Ana', 'Corte', 'AMC Studios', '24/08/2026', '15:00']);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['channel']->is($channel))->toBeTrue();
    expect($calls[0]['templateName'])->toBe('recordatorio_reserva');
    expect($calls[0]['language'])->toBe('es');
    expect($calls[0]['bodyParameters'])->toBe(['Ana', 'Corte', 'AMC Studios', '24/08/2026', '15:00']);
});

test('sendTemplate con la implementación real llega a la Graph API de Meta', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.abc']]], 200)]);
    [$org] = organizationWithChannel();

    (new WhatsAppNotificationSender(new MetaWhatsAppClient))
        ->sendTemplate($org, '+573001234567', 'recordatorio_reserva', 'es', ['Ana']);

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/wamid-123/messages'
        && $request['type'] === 'template');
});

test('lanza NotificationDeliveryException si la organización no tiene channel de WhatsApp', function () {
    $org = Organization::create(['name' => 'Sin canal']);
    $calls = [];

    expect(fn () => (new WhatsAppNotificationSender(fakeChannelClient($calls)))->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
    expect($calls)->toBeEmpty(); // ni siquiera llega a llamar al cliente
});

test('lanza NotificationDeliveryException si el channel no está activo', function () {
    [$org] = organizationWithChannel(status: ChannelStatus::SUSPENDED);
    $calls = [];

    expect(fn () => (new WhatsAppNotificationSender(fakeChannelClient($calls)))->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
    expect($calls)->toBeEmpty();
});

test('propaga la excepción del cliente cuando el proveedor falla', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);
    [$org] = organizationWithChannel();

    expect(fn () => (new WhatsAppNotificationSender(new MetaWhatsAppClient))->send($org, '+573001234567', 'Hola'))
        ->toThrow(NotificationDeliveryException::class);
});

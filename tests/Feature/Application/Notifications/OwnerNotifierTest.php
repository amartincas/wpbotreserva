<?php

use App\Application\Channels\CentralChannelProvider;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\OwnerNotifierInterface;
use App\Application\Exceptions\CentralChannelUnavailableException;
use App\Application\Exceptions\NotificationDeliveryException;
use App\Application\Notifications\OwnerNotifier;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function ownerNotifierFakeClient(array &$calls): ChannelClientInterface
{
    return new class($calls) implements ChannelClientInterface
    {
        public function __construct(private array &$calls) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void {}

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void
        {
            $this->calls[] = compact('channel', 'to', 'templateName', 'language', 'bodyParameters');
        }

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void {}
    };
}

function ownerNotifierFixtureChannel(string $phoneNumberId, ChannelRole $role): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);
}

test('el binding real de OwnerNotifierInterface es OwnerNotifier', function () {
    expect(app(OwnerNotifierInterface::class))->toBeInstanceOf(OwnerNotifier::class);
});

test('envía al owner_phone por el CENTRAL activo, aunque la Organization tenga su BUSINESS', function () {
    $central = ownerNotifierFixtureChannel('wamid-owner-notifier-central', ChannelRole::CENTRAL);
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573009999999']);
    ownerNotifierFixtureChannel('wamid-owner-notifier-business', ChannelRole::BUSINESS)
        ->organizations()->attach($org->id, ['is_primary' => true]);
    $calls = [];

    (new OwnerNotifier(new CentralChannelProvider, ownerNotifierFakeClient($calls)))
        ->sendTemplate($org, 'aviso_turno_vencido', 'es', ['a', 'b']);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['channel']->is($central))->toBeTrue();
    expect($calls[0]['to'])->toBe('+573009999999');
    expect($calls[0]['bodyParameters'])->toBe(['a', 'b']);
});

test('sin owner_phone: NotificationDeliveryException, sin enviar nada', function () {
    ownerNotifierFixtureChannel('wamid-owner-notifier-central', ChannelRole::CENTRAL);
    $org = Organization::create(['name' => 'Sin dueño']);
    $calls = [];

    expect(fn () => (new OwnerNotifier(new CentralChannelProvider, ownerNotifierFakeClient($calls)))->sendTemplate($org, 't', 'es', []))
        ->toThrow(NotificationDeliveryException::class, 'owner_phone');
    expect($calls)->toBe([]);
});

test('sin CENTRAL activo: NotificationDeliveryException (con la causa encadenada), sin caer al BUSINESS', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573009999999']);
    ownerNotifierFixtureChannel('wamid-owner-notifier-business', ChannelRole::BUSINESS)
        ->organizations()->attach($org->id, ['is_primary' => true]);
    $calls = [];

    try {
        (new OwnerNotifier(new CentralChannelProvider, ownerNotifierFakeClient($calls)))->sendTemplate($org, 't', 'es', []);
        $this->fail('Se esperaba NotificationDeliveryException.');
    } catch (NotificationDeliveryException $e) {
        expect($e->getPrevious())->toBeInstanceOf(CentralChannelUnavailableException::class);
    }

    expect($calls)->toBe([]);
});

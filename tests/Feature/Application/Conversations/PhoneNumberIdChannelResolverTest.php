<?php

use App\Application\Channels\PhoneNumberIdChannelResolver;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function resolverFixtureChannel(string $phoneNumberId = 'wamid-resolver', ChannelStatus $status = ChannelStatus::ACTIVE): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => $phoneNumberId,
        'status' => $status,
    ]);
}

test('resuelve el Channel por phone_number_id', function () {
    $channel = resolverFixtureChannel();

    $resolved = (new PhoneNumberIdChannelResolver)->resolve('wamid-resolver');

    expect($resolved)->not->toBeNull();
    expect($resolved->is($channel))->toBeTrue();
});

test('devuelve null si no existe ningún Channel con ese phone_number_id', function () {
    $resolved = (new PhoneNumberIdChannelResolver)->resolve('no-existe');

    expect($resolved)->toBeNull();
});

test('no filtra por status — eso lo decide el caller vía Channel::isActive()', function () {
    resolverFixtureChannel('wamid-suspendido', ChannelStatus::SUSPENDED);

    $resolved = (new PhoneNumberIdChannelResolver)->resolve('wamid-suspendido');

    expect($resolved)->not->toBeNull();
    expect($resolved->isActive())->toBeFalse();
});

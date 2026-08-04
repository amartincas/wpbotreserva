<?php

use App\Domain\Conversational\ConversationSession;
use App\Domain\CRM\Customer;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Database\QueryException;

test('PhoneNumber Value Object valida formato E.164', function () {
    $phone = new PhoneNumber('+573001234567');
    expect($phone->value())->toBe('+573001234567');
    expect((string) $phone)->toBe('+573001234567');
});

test('PhoneNumber rechaza formatos inválidos', function () {
    expect(fn () => new PhoneNumber('3001234567'))->toThrow(InvalidArgumentException::class); // sin +
    expect(fn () => new PhoneNumber('+57abc'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new PhoneNumber('+0123'))->toThrow(InvalidArgumentException::class); // no puede empezar en 0
});

test('dos PhoneNumber con el mismo valor son iguales', function () {
    expect((new PhoneNumber('+573001234567'))->equals(new PhoneNumber('+573001234567')))->toBeTrue();
    expect((new PhoneNumber('+573001234567'))->equals(new PhoneNumber('+573007654321')))->toBeFalse();
});

test('un customer es único por organization + phone, y su phone se castea a PhoneNumber', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);

    $customer = Customer::create([
        'organization_id' => $org->id,
        'phone' => '+573001234567',
        'name' => 'Ana',
    ]);

    expect($customer->phone)->toBeInstanceOf(PhoneNumber::class);
    expect($customer->phone->value())->toBe('+573001234567');
    expect($customer->organization->is($org))->toBeTrue();

    expect(fn () => Customer::create([
        'organization_id' => $org->id,
        'phone' => '+573001234567',
        'name' => 'Otra Ana',
    ]))->toThrow(QueryException::class);
});

test('el mismo teléfono puede ser un customer distinto en otra organization', function () {
    $orgA = Organization::create(['name' => 'Barbería Don Carlos']);
    $orgB = Organization::create(['name' => 'Spa Relax']);

    Customer::create(['organization_id' => $orgA->id, 'phone' => '+573001234567']);
    $second = Customer::create(['organization_id' => $orgB->id, 'phone' => '+573001234567']);

    expect($second->exists)->toBeTrue();
});

test('conversation_session nace sin organization resuelta y es única por (channel, teléfono)', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-schema-test',
        'status' => ChannelStatus::ACTIVE,
    ]);

    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);

    expect($session->organization_id)->toBeNull();
    expect($session->current_intent)->toBeNull();

    expect(fn () => ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']))
        ->toThrow(QueryException::class);
});

test('conversation_session expone su channel', function () {
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-schema-test-relation',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);

    expect($session->channel->is($channel))->toBeTrue();
});

test('un mismo teléfono puede tener una conversation_session distinta por cada channel (Parte XVI)', function () {
    $channelA = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-schema-test-a',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $channelB = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-schema-test-b',
        'status' => ChannelStatus::ACTIVE,
    ]);

    ConversationSession::create(['channel_id' => $channelA->id, 'customer_phone' => '+573001234567']);
    $second = ConversationSession::create(['channel_id' => $channelB->id, 'customer_phone' => '+573001234567']);

    expect($second->exists)->toBeTrue();
});

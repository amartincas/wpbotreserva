<?php

use App\Application\Contracts\ChannelClientInterface;
use App\Application\Conversations\ConversationReplier;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function replierFakeChannelClient(array &$sent): ChannelClientInterface
{
    return new class($sent) implements ChannelClientInterface
    {
        public function __construct(private array &$sent) {}

        public function sendTextMessage(Channel $channel, string $to, string $message): void
        {
            $this->sent[] = ['channel' => $channel, 'to' => $to, 'message' => $message];
        }

        public function sendTemplateMessage(Channel $channel, string $to, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtonsMessage(Channel $channel, string $to, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['channel' => $channel, 'to' => $to, 'message' => $bodyText, 'buttons' => $buttons];
        }
    };
}

function replierFixtureChannel(string $phoneNumberId, ChannelRole $role): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => $role,
        'phone_number_id' => $phoneNumberId,
        'status' => ChannelStatus::ACTIVE,
    ]);
}

test('CENTRAL sin Organization: texto y botones salen por el Channel de la sesión', function () {
    $central = replierFixtureChannel('wamid-replier-central', ChannelRole::CENTRAL);
    $session = ConversationSession::create(['channel_id' => $central->id, 'customer_phone' => '+573001234567']);
    $sent = [];
    $replier = new ConversationReplier(replierFakeChannelClient($sent));

    $replier->send($session, '+573001234567', 'hola');
    $replier->sendButtons($session, '+573001234567', '¿Seguimos?', [['id' => 'si', 'title' => 'Sí']]);

    expect($sent)->toHaveCount(2);
    expect($sent[0]['channel']->is($central))->toBeTrue();
    expect($sent[0]['to'])->toBe('+573001234567');
    expect($sent[1]['channel']->is($central))->toBeTrue();
    expect($sent[1]['buttons'])->toBe([['id' => 'si', 'title' => 'Sí']]);
});

test('BUSINESS con Organization: sale por el BUSINESS de la sesión', function () {
    $business = replierFixtureChannel('wamid-replier-business', ChannelRole::BUSINESS);
    $organization = Organization::create(['name' => 'Barbería Don Carlos']);
    $business->organizations()->attach($organization->id, ['is_primary' => true]);
    $session = ConversationSession::create(['channel_id' => $business->id, 'customer_phone' => '+573001234567', 'organization_id' => $organization->id]);
    $sent = [];

    (new ConversationReplier(replierFakeChannelClient($sent)))->send($session, '+573001234567', 'hola');

    expect($sent[0]['channel']->is($business))->toBeTrue();
});

test('el owner de una Organization que escribe por el CENTRAL recibe la respuesta por el CENTRAL, no por el BUSINESS de su Organization', function () {
    $central = replierFixtureChannel('wamid-replier-central-owner', ChannelRole::CENTRAL);
    $business = replierFixtureChannel('wamid-replier-business-owner', ChannelRole::BUSINESS);
    $organization = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573009999999']);
    $business->organizations()->attach($organization->id, ['is_primary' => true]);
    $session = ConversationSession::create(['channel_id' => $central->id, 'customer_phone' => '+573009999999', 'organization_id' => $organization->id]);
    $sent = [];

    (new ConversationReplier(replierFakeChannelClient($sent)))->send($session, '+573009999999', 'reservas de hoy...');

    expect($sent[0]['channel']->is($central))->toBeTrue();
    expect($sent[0]['channel']->is($business))->toBeFalse();
});

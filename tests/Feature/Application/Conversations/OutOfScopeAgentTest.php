<?php

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\OutOfScopeAgent;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function outOfScopeFakeSender(array &$calls): NotificationSenderInterface
{
    return new class($calls) implements NotificationSenderInterface
    {
        public function __construct(private array &$calls) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->calls[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void
        {
            $this->calls[] = ['organization' => $organization, 'toPhoneE164' => $toPhoneE164, 'message' => $bodyText, 'buttons' => $buttons];
        }
    };
}

test('envía un menú de botones (registrar negocio/reservar/gestionar) a través de NotificationSenderInterface', function () {
    $calls = [];
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-oos',
        'status' => ChannelStatus::ACTIVE,
    ]);
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $session = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);
    $message = new InboundMessage('wamid.msg-oos', 'wamid-oos', '+573001234567', 'asdkjhaskjd', now()->toImmutable());

    (new OutOfScopeAgent(outOfScopeFakeSender($calls)))->handle($message, $session, $org);

    expect($calls)->toHaveCount(1);
    expect($calls[0]['organization']->is($org))->toBeTrue();
    expect($calls[0]['toPhoneE164'])->toBe('+573001234567');
    expect($calls[0]['message'])->not->toBeEmpty();
    expect($calls[0]['buttons'])->toHaveCount(3);
    expect(array_column($calls[0]['buttons'], 'id'))->toBe(['menu_registro_negocio', 'menu_reserva', 'menu_gestion_reserva']);
});

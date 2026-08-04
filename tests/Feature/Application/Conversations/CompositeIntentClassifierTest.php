<?php

use App\Application\Contracts\IntentClassifierStrategy;
use App\Application\Conversations\Classification\CompositeIntentClassifier;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\Events\RouterIntentUnresolved;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Support\Facades\Event;

function compositeFixtureMessage(): InboundMessage
{
    return new InboundMessage(
        phoneNumberId: 'wamid-composite',
        fromPhone: '+573001234567',
        text: 'hola',
        receivedAt: now()->toImmutable(),
    );
}

function compositeFixtureSession(): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-composite-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);
}

function compositeStrategyReturning(?Intent $intent, array &$calls, string $name): IntentClassifierStrategy
{
    return new class($intent, $calls, $name) implements IntentClassifierStrategy
    {
        public function __construct(
            private readonly ?Intent $intent,
            private array &$calls,
            private readonly string $name,
        ) {}

        public function attempt(InboundMessage $message, ConversationSession $session): ?Intent
        {
            $this->calls[] = $this->name;

            return $this->intent;
        }
    };
}

test('devuelve el resultado de la primera estrategia que responde, sin llamar a las siguientes', function () {
    $calls = [];
    $composite = new CompositeIntentClassifier([
        compositeStrategyReturning(Intent::Reserva, $calls, 'primera'),
        compositeStrategyReturning(Intent::RegistroNegocio, $calls, 'segunda'),
    ]);

    $intent = $composite->classify(compositeFixtureMessage(), compositeFixtureSession());

    expect($intent)->toBe(Intent::Reserva);
    expect($calls)->toBe(['primera']);
});

test('sigue a la siguiente estrategia cuando la primera no tiene opinión', function () {
    $calls = [];
    $composite = new CompositeIntentClassifier([
        compositeStrategyReturning(null, $calls, 'primera'),
        compositeStrategyReturning(Intent::RegistroNegocio, $calls, 'segunda'),
    ]);

    $intent = $composite->classify(compositeFixtureMessage(), compositeFixtureSession());

    expect($intent)->toBe(Intent::RegistroNegocio);
    expect($calls)->toBe(['primera', 'segunda']);
});

test('cuando una estrategia clasifica activamente FueraDeAlcance, no dispara RouterIntentUnresolved', function () {
    Event::fake([RouterIntentUnresolved::class]);
    $calls = [];
    $composite = new CompositeIntentClassifier([
        compositeStrategyReturning(Intent::FueraDeAlcance, $calls, 'unica'),
    ]);

    $intent = $composite->classify(compositeFixtureMessage(), compositeFixtureSession());

    expect($intent)->toBe(Intent::FueraDeAlcance);
    Event::assertNotDispatched(RouterIntentUnresolved::class);
});

test('cuando ninguna estrategia responde, dispara RouterIntentUnresolved y devuelve FueraDeAlcance por default', function () {
    Event::fake([RouterIntentUnresolved::class]);
    $calls = [];
    $composite = new CompositeIntentClassifier([
        compositeStrategyReturning(null, $calls, 'primera'),
        compositeStrategyReturning(null, $calls, 'segunda'),
    ]);

    $intent = $composite->classify(compositeFixtureMessage(), compositeFixtureSession());

    expect($intent)->toBe(Intent::FueraDeAlcance);
    Event::assertDispatched(RouterIntentUnresolved::class);
});

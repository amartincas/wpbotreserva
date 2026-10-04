<?php

use App\Application\Channels\MetaGraphPhoneNumberVerifier;
use App\Application\Channels\PhoneNumberVerification;
use App\Application\Contracts\MetaPhoneNumberVerifierInterface;
use App\Application\Exceptions\BusinessChannelConnectionException;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Application\Notifications\WhatsAppNotificationSender;
use App\Application\Tenancy\ConnectBusinessChannelCommand;
use App\Application\Tenancy\ConnectBusinessChannelData;
use App\Application\Tenancy\VerifyBusinessChannelCommand;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function verifyFakeVerifier(PhoneNumberVerification $result): MetaPhoneNumberVerifierInterface
{
    return new class($result) implements MetaPhoneNumberVerifierInterface
    {
        public function __construct(private readonly PhoneNumberVerification $result) {}

        public function verify(Channel $channel): PhoneNumberVerification
        {
            return $this->result;
        }
    };
}

function verifyFixturePending(?Organization $organization = null): array
{
    $organization ??= Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573009999999']);
    $channel = (new ConnectBusinessChannelCommand)->handle(new ConnectBusinessChannelData(
        organization: $organization,
        phoneNumber: '+573001234567',
        phoneNumberId: '111222333',
        businessAccountId: '444555666',
        accessToken: 'token-del-negocio',
    ));

    return [$organization, $channel];
}

function verifyFixtureCentral(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number' => '+57 321 3467615',
        'phone_number_id' => '1265332936673819',
        'business_account_id' => '2597666594027606',
        'status' => ChannelStatus::ACTIVE,
        'credentials' => ['access_token' => 'token-del-central'],
    ]);
}

test('verificación exitosa: vincula el Channel a su Organization y lo pasa a ACTIVE, sin dejar de ser BUSINESS', function () {
    [$organization, $channel] = verifyFixturePending();

    $result = (new VerifyBusinessChannelCommand(verifyFakeVerifier(PhoneNumberVerification::verified('+57 300 123 4567', 'Barbería Don Carlos'))))
        ->handle($channel);

    expect($result->successful)->toBeTrue();
    $channel = $channel->fresh();
    expect($channel->status)->toBe(ChannelStatus::ACTIVE);
    expect($channel->role)->toBe(ChannelRole::BUSINESS);
    expect($organization->channels()->pluck('channels.id')->all())->toBe([$channel->id]);
    expect($channel->metadata)->not->toHaveKey('pending_organization_id');
    expect($channel->metadata['verified_display_phone_number'])->toBe('+57 300 123 4567');
    expect(Channel::pendingBusinessFor($organization)->exists())->toBeFalse();
});

test('verificación fallida: queda PENDING_VERIFICATION, sin vínculo, con el motivo guardado para el super-admin', function () {
    [$organization, $channel] = verifyFixturePending();

    $result = (new VerifyBusinessChannelCommand(verifyFakeVerifier(PhoneNumberVerification::failed('El phone_number_id no pertenece a la WABA cargada.'))))
        ->handle($channel);

    expect($result->successful)->toBeFalse();
    $channel = $channel->fresh();
    expect($channel->status)->toBe(ChannelStatus::PENDING_VERIFICATION);
    expect($organization->channels()->count())->toBe(0);
    expect(DB::table('channel_organization')->where('channel_id', $channel->id)->exists())->toBeFalse();
    expect($channel->metadata['pending_organization_id'])->toBe($organization->id);
    expect($channel->metadata['last_verification_error'])->toBe('El phone_number_id no pertenece a la WABA cargada.');
    expect($channel->credentials)->toBe(['access_token' => 'token-del-negocio']);
});

test('si la Organization ya tiene un WhatsApp vinculado al momento de verificar, nada cambia: ni vínculo ni ACTIVE', function () {
    [$organization, $channel] = verifyFixturePending();
    $other = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'otro', 'status' => ChannelStatus::ACTIVE,
    ]);
    $other->organizations()->attach($organization->id, ['is_primary' => true]);

    expect(fn () => (new VerifyBusinessChannelCommand(verifyFakeVerifier(PhoneNumberVerification::verified('+573001234567', null))))->handle($channel))
        ->toThrow(DomainException::class);

    expect($channel->fresh()->status)->toBe(ChannelStatus::PENDING_VERIFICATION);
    expect(DB::table('channel_organization')->where('channel_id', $channel->id)->exists())->toBeFalse();
});

test('solo verifica conexiones de negocio pendientes — nunca el CENTRAL ni un Channel ya activo', function () {
    $central = verifyFixtureCentral();
    $command = new VerifyBusinessChannelCommand(verifyFakeVerifier(PhoneNumberVerification::verified('+573213467615', null)));

    expect(fn () => $command->handle($central))->toThrow(BusinessChannelConnectionException::class);
    expect($central->fresh()->status)->toBe(ChannelStatus::ACTIVE);
    expect($central->fresh()->organizations)->toHaveCount(0);
});

test('de punta a punta con la Graph API simulada: Meta confirma → ACTIVE, y el CENTRAL queda intacto', function () {
    $central = verifyFixtureCentral();
    $centralBefore = (array) DB::table('channels')->where('id', $central->id)->first();
    [$organization, $channel] = verifyFixturePending();
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/v21.0/111222333*' => Http::response(['id' => '111222333', 'display_phone_number' => '+57 300 123 4567', 'verified_name' => 'Barbería'], 200),
        'graph.facebook.com/v21.0/444555666/phone_numbers*' => Http::response(['data' => [['id' => '111222333']]], 200),
    ]);

    (new VerifyBusinessChannelCommand(new MetaGraphPhoneNumberVerifier))->handle($channel);

    expect($channel->fresh()->status)->toBe(ChannelStatus::ACTIVE);
    expect((array) DB::table('channels')->where('id', $central->id)->first())->toBe($centralBefore);
    expect($central->fresh()->organizations)->toHaveCount(0);
});

test('el access token nunca aparece en los logs, ni cuando Meta rechaza la verificación', function () {
    [, $channel] = verifyFixturePending();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = json_encode([$event->message, $event->context]);
    });
    Http::preventStrayRequests();
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401),
    ]);

    $result = (new VerifyBusinessChannelCommand(new MetaGraphPhoneNumberVerifier))->handle($channel);

    expect($result->successful)->toBeFalse();
    expect($logged)->not->toBeEmpty(); // el fallo sí se loguea...
    foreach ($logged as $line) {
        expect($line)->not->toContain('token-del-negocio'); // ...pero nunca el token
    }
    expect(json_encode($channel->fresh()->metadata))->not->toContain('token-del-negocio');
});

test('una vez ACTIVE, el emisor y el cliente de WhatsApp usan ese BUSINESS (su phone_number_id y su token) para hablarle a los clientes', function () {
    verifyFixtureCentral();
    [$organization, $channel] = verifyFixturePending();
    (new VerifyBusinessChannelCommand(verifyFakeVerifier(PhoneNumberVerification::verified('+573001234567', null))))->handle($channel);
    Http::preventStrayRequests();
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ok']]], 200)]);

    (new WhatsAppNotificationSender(new MetaWhatsAppClient))->send($organization->fresh(), '+573005550000', 'Tu reserva quedó confirmada');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://graph.facebook.com/v21.0/111222333/messages'
        && $r->hasHeader('Authorization', 'Bearer token-del-negocio')
        && $r['to'] === '573005550000');
});

<?php

use App\Application\Channels\PhoneNumberVerification;
use App\Application\Contracts\MetaPhoneNumberVerifierInterface;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Models\User;
use Livewire\Livewire;

function connectActionFakeVerifier(PhoneNumberVerification $result): MetaPhoneNumberVerifierInterface
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

function connectActionFormData(array $overrides = []): array
{
    return array_merge([
        'phone_number' => '+573001234567',
        'phone_number_id' => '111222333',
        'business_account_id' => '444555666',
        'access_token' => 'token-del-negocio',
    ], $overrides);
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    $this->organization = Organization::create(['name' => 'Barbería Don Carlos', 'owner_phone' => '+573009999999']);
});

test('"Conectar WhatsApp del negocio" crea el BUSINESS pendiente y habilita "Verificar con Meta"', function () {
    Livewire::test(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->assertActionVisible('connectBusinessChannel')
        ->assertActionHidden('verifyBusinessChannel')
        ->callAction('connectBusinessChannel', data: connectActionFormData())
        ->assertHasNoActionErrors()
        ->assertNotified('WhatsApp pendiente de verificación')
        ->assertActionVisible('verifyBusinessChannel');

    $channel = Channel::pendingBusinessFor($this->organization)->sole();
    expect($channel->role)->toBe(ChannelRole::BUSINESS);
    expect($channel->status)->toBe(ChannelStatus::PENDING_VERIFICATION);
    expect($channel->credentials)->toBe(['access_token' => 'token-del-negocio']);
});

test('campos incompletos: el formulario no se envía y no se crea nada', function () {
    Livewire::test(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction('connectBusinessChannel', data: connectActionFormData(['access_token' => '']))
        ->assertHasActionErrors(['access_token' => 'required']);

    expect(Channel::count())->toBe(0);
});

test('un error de negocio (phone_number_id duplicado) se informa sin crear nada', function () {
    Channel::create([
        'provider' => 'META_CLOUD_API', 'channel_type' => 'WHATSAPP', 'role' => ChannelRole::CENTRAL,
        'phone_number_id' => '111222333', 'status' => ChannelStatus::ACTIVE,
    ]);

    Livewire::test(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction('connectBusinessChannel', data: connectActionFormData())
        ->assertNotified('No se pudo conectar el WhatsApp');

    expect(Channel::count())->toBe(1);
});

test('"Verificar con Meta" exitoso: activa y vincula; las acciones desaparecen', function () {
    app()->instance(MetaPhoneNumberVerifierInterface::class, connectActionFakeVerifier(PhoneNumberVerification::verified('+57 300 123 4567', 'Barbería')));

    Livewire::test(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction('connectBusinessChannel', data: connectActionFormData())
        ->callAction('verifyBusinessChannel')
        ->assertNotified('WhatsApp del negocio activo')
        ->assertActionHidden('connectBusinessChannel')
        ->assertActionHidden('verifyBusinessChannel');

    $channel = $this->organization->channels()->sole();
    expect($channel->status)->toBe(ChannelStatus::ACTIVE);
});

test('"Verificar con Meta" fallido: avisa el motivo y deja la conexión pendiente, sin vínculo', function () {
    app()->instance(MetaPhoneNumberVerifierInterface::class, connectActionFakeVerifier(PhoneNumberVerification::failed('El número registrado en Meta no coincide.')));

    Livewire::test(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction('connectBusinessChannel', data: connectActionFormData())
        ->callAction('verifyBusinessChannel')
        ->assertNotified('Meta no confirmó los datos')
        ->assertActionVisible('verifyBusinessChannel');

    expect($this->organization->channels()->count())->toBe(0);
    expect(Channel::pendingBusinessFor($this->organization)->sole()->status)->toBe(ChannelStatus::PENDING_VERIFICATION);
});

test('la página del negocio nunca muestra el access token', function () {
    Livewire::test(ViewOrganization::class, ['record' => $this->organization->getRouteKey()])
        ->callAction('connectBusinessChannel', data: connectActionFormData());

    $this->get(route('filament.admin.resources.organizations.view', $this->organization))
        ->assertOk()
        ->assertSee('pendiente de verificación')
        ->assertDontSee('token-del-negocio');
});

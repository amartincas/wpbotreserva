<?php

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Application\Tenancy\AddServiceCommand;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function addServiceFixtureOrganization(int $resourceCount = 1): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-add-service-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $resources = [];
    foreach (range(1, $resourceCount) as $i) {
        $resources[] = new ResourceRegistrationData("Recurso {$i}", [
            new WeeklyScheduleSlot(1, '09:00', '17:00'),
        ]);
    }

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30)],
        resources: $resources,
    ));

    return Organization::findOrFail($result->organizationId);
}

test('agrega un servicio nuevo, habilitado SOLO para los recursos indicados explícitamente', function () {
    $organization = addServiceFixtureOrganization(resourceCount: 2);
    $onlyOneResourceId = $organization->resources()->first()->id;

    $service = (new AddServiceCommand(app(EntitlementCheckerInterface::class)))
        ->handle($organization, new ServiceRegistrationData('Barba', 20), [$onlyOneResourceId]);

    expect($service->name)->toBe('Barba');
    expect($service->duration_minutes)->toBe(20);
    expect($service->organization_id)->toBe($organization->id);
    expect($service->resourceRequirements)->toHaveCount(1);
    // Nunca "todos por default" — solo el que se pasó explícitamente,
    // aunque el negocio tenga 2 recursos disponibles.
    expect($service->resources)->toHaveCount(1);
    expect($service->resources->first()->id)->toBe($onlyOneResourceId);

    // El servicio original sigue igual — esto no lo toca.
    expect($organization->services()->count())->toBe(2);
});

test('agrega un servicio habilitado para varios recursos cuando se indican varios', function () {
    $organization = addServiceFixtureOrganization(resourceCount: 3);
    $resourceIds = $organization->resources()->pluck('id')->take(2)->all();

    $service = (new AddServiceCommand(app(EntitlementCheckerInterface::class)))
        ->handle($organization, new ServiceRegistrationData('Barba', 20), $resourceIds);

    expect($service->resources)->toHaveCount(2);
    expect($service->resources->pluck('id')->sort()->values()->all())->toBe(collect($resourceIds)->sort()->values()->all());
});

test('si EntitlementChecker rechaza, lanza EntitlementDeniedException y no crea nada', function () {
    $organization = addServiceFixtureOrganization();
    $resourceId = $organization->resources()->first()->id;
    $denyAll = new class implements EntitlementCheckerInterface
    {
        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            return false;
        }
    };

    expect(fn () => (new AddServiceCommand($denyAll))->handle($organization, new ServiceRegistrationData('Barba', 20), [$resourceId]))
        ->toThrow(EntitlementDeniedException::class);

    expect($organization->services()->count())->toBe(1);
});

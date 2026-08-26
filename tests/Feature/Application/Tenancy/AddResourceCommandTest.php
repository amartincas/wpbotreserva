<?php

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Application\Tenancy\AddResourceCommand;
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

function addResourceFixtureOrganization(): Organization
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-add-resource-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Consultorio Dra. Ríos',
        ownerPhone: '+573008888888',
        channel: $channel,
        city: 'Medellín',
        address: 'Cl 10 # 20-30',
        services: [new ServiceRegistrationData('Consulta', 30)],
        resources: [new ResourceRegistrationData('Dra. Ríos', [new WeeklyScheduleSlot(1, '09:00', '17:00')])],
    ));

    return Organization::findOrFail($result->organizationId);
}

test('crea una persona/recurso nueva con su horario semanal, en la sede principal del negocio', function () {
    $organization = addResourceFixtureOrganization();

    $resource = (new AddResourceCommand(app(EntitlementCheckerInterface::class)))->handle(
        $organization,
        new ResourceRegistrationData('Edgar Torres', [
            new WeeklyScheduleSlot(2, '10:00', '18:00'),
            new WeeklyScheduleSlot(4, '10:00', '18:00'),
        ]),
    );

    expect($resource->display_name)->toBe('Edgar Torres');
    expect($resource->organization_id)->toBe($organization->id);
    expect($resource->location_id)->toBe($organization->locations()->first()->id);
    expect($resource->schedules)->toHaveCount(2);
    expect($resource->schedules->pluck('weekday')->sort()->values()->all())->toBe([2, 4]);

    // No toca el recurso que ya existía.
    expect($organization->resources()->count())->toBe(2);
});

test('si EntitlementChecker rechaza, lanza EntitlementDeniedException y no crea nada', function () {
    $organization = addResourceFixtureOrganization();
    $denyAll = new class implements EntitlementCheckerInterface
    {
        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            return false;
        }
    };

    expect(fn () => (new AddResourceCommand($denyAll))->handle($organization, new ResourceRegistrationData('Edgar Torres', [])))
        ->toThrow(EntitlementDeniedException::class);

    expect($organization->resources()->count())->toBe(1);
});

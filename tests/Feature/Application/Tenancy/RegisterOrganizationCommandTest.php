<?php

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
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
use App\Enums\ResourceType;

function registerOrgFixtureChannel(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-registro',
        'status' => ChannelStatus::ACTIVE,
    ]);
}

function registerOrgData(Channel $channel, array $overrides = []): RegisterOrganizationData
{
    return new RegisterOrganizationData(
        organizationName: $overrides['organizationName'] ?? 'Barbería Don Carlos',
        ownerPhone: $overrides['ownerPhone'] ?? '+573001234567',
        channel: $channel,
        city: $overrides['city'] ?? 'Bogotá',
        address: $overrides['address'] ?? 'Cra 7 # 45-12',
        services: $overrides['services'] ?? [
            new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0]),
        ],
        resources: $overrides['resources'] ?? [
            new ResourceRegistrationData('Carlos', [
                new WeeklyScheduleSlot(weekday: 1, startTime: '09:00', endTime: '17:00'),
                new WeeklyScheduleSlot(weekday: 2, startTime: '09:00', endTime: '17:00'),
            ]),
        ],
    );
}

test('registra una organización de un servicio y un recurso: location, resource, service, requisito, horario y channel vinculado', function () {
    $channel = registerOrgFixtureChannel();
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(registerOrgData($channel));

    $org = Organization::findOrFail($result->organizationId);
    expect($org->name)->toBe('Barbería Don Carlos');
    expect($org->owner_phone)->toBe('+573001234567');
    expect($org->channels)->toHaveCount(1)->and($org->channels->first()->is($channel))->toBeTrue();

    expect($org->locations)->toHaveCount(1);
    $location = $org->locations->first();
    expect($location->id)->toBe($result->locationId);
    expect($location->city)->toBe('Bogotá');

    expect($org->resources)->toHaveCount(1);
    $resource = $org->resources->first();
    expect($result->resourceIds)->toBe([$resource->id]);
    expect($resource->display_name)->toBe('Carlos');
    expect($resource->resource_type)->toBe(ResourceType::HUMAN);

    expect($org->services)->toHaveCount(1);
    $service = $org->services->first();
    expect($result->serviceIds)->toBe([$service->id]);
    expect($service->duration_minutes)->toBe(30);
    expect($service->resourceRequirements)->toHaveCount(1);
    expect($service->resources->pluck('id'))->toContain($resource->id);
    expect($resource->schedules)->toHaveCount(2);
});

test('Fase 1: cada servicio queda asociado solo a los recursos elegidos para él — un recurso puede prestar varios servicios, pero nunca se genera el cruce cartesiano completo', function () {
    $channel = registerOrgFixtureChannel();
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    // Índices de $resources: 0=Carlos, 1=Ana.
    $result = $command->handle(registerOrgData($channel, [
        'services' => [
            new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0]), // solo Carlos
            new ServiceRegistrationData('Barba', 20, resourceKeys: [1]), // solo Ana
            new ServiceRegistrationData('Corte + Barba', 45, resourceKeys: [0, 1]), // ambos
        ],
        'resources' => [
            new ResourceRegistrationData('Carlos', [
                new WeeklyScheduleSlot(weekday: 1, startTime: '09:00', endTime: '17:00'),
            ]),
            new ResourceRegistrationData('Ana', [
                new WeeklyScheduleSlot(weekday: 2, startTime: '10:00', endTime: '18:00'),
                new WeeklyScheduleSlot(weekday: 3, startTime: '10:00', endTime: '18:00'),
            ]),
        ],
    ]));

    $org = Organization::findOrFail($result->organizationId);

    expect($org->resources)->toHaveCount(2);
    expect($result->resourceIds)->toHaveCount(2);
    $carlos = $org->resources->firstWhere('display_name', 'Carlos');
    $ana = $org->resources->firstWhere('display_name', 'Ana');

    // Cada recurso conserva su propio horario.
    expect($carlos->schedules)->toHaveCount(1);
    expect($ana->schedules)->toHaveCount(2);

    expect($org->services)->toHaveCount(3);
    expect($result->serviceIds)->toHaveCount(3);

    $corte = $org->services->firstWhere('name', 'Corte de cabello');
    $barba = $org->services->firstWhere('name', 'Barba');
    $corteYBarba = $org->services->firstWhere('name', 'Corte + Barba');

    // Un servicio puede tener un recurso distinto de otro — nada de "todo
    // recurso presta todo servicio".
    expect($corte->resources->pluck('id')->all())->toBe([$carlos->id]);
    expect($barba->resources->pluck('id')->all())->toBe([$ana->id]);

    // Un mismo recurso puede prestar varios servicios cuando así se eligió.
    expect($corteYBarba->resources->pluck('id')->sort()->values()->all())
        ->toBe(collect([$carlos->id, $ana->id])->sort()->values()->all());

    // Negativo explícito: ni Corte de cabello tiene a Ana, ni Barba tiene a
    // Carlos — si el cruce cartesiano volviera, esto fallaría.
    expect($corte->resources->pluck('id'))->not->toContain($ana->id);
    expect($barba->resources->pluck('id'))->not->toContain($carlos->id);

    foreach ($org->services as $service) {
        expect($service->resourceRequirements)->toHaveCount(1);
    }
});

test('consulta EntitlementChecker con la cantidad real de resources/services que va a crear', function () {
    $channel = registerOrgFixtureChannel();
    $calls = [];
    $spy = new class($calls) implements EntitlementCheckerInterface
    {
        public array $keys = [];

        public array $quantities = [];

        public function __construct(private array &$sharedRef) {}

        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            $this->keys[] = $entitlementKey;
            $this->quantities[] = $requestedQuantity;

            return true;
        }
    };

    (new RegisterOrganizationCommand($spy))->handle(registerOrgData($channel, [
        'services' => [
            new ServiceRegistrationData('Corte de cabello', 30),
            new ServiceRegistrationData('Barba', 20),
        ],
        'resources' => [
            new ResourceRegistrationData('Carlos', []),
        ],
    ]));

    expect($spy->keys)->toBe([
        'scheduling.max_locations',
        'scheduling.max_resources',
        'scheduling.max_services',
    ]);
    expect($spy->quantities)->toBe([1, 1, 2]);
});

test('si EntitlementChecker rechaza, lanza EntitlementDeniedException y no crea nada (transacción revertida)', function () {
    $channel = registerOrgFixtureChannel();
    $denyAll = new class implements EntitlementCheckerInterface
    {
        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            return false;
        }
    };

    expect(fn () => (new RegisterOrganizationCommand($denyAll))->handle(registerOrgData($channel)))
        ->toThrow(EntitlementDeniedException::class);

    expect(Organization::count())->toBe(0);
});

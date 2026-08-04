<?php

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
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
        serviceName: $overrides['serviceName'] ?? 'Corte de cabello',
        serviceDurationMinutes: $overrides['serviceDurationMinutes'] ?? 30,
        resourceName: $overrides['resourceName'] ?? 'Carlos',
        weeklySchedule: $overrides['weeklySchedule'] ?? [
            new WeeklyScheduleSlot(weekday: 1, startTime: '09:00', endTime: '17:00'),
            new WeeklyScheduleSlot(weekday: 2, startTime: '09:00', endTime: '17:00'),
        ],
    );
}

test('registra una organización completa: location, resource, service, requisito, horario y channel vinculado', function () {
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
    expect($resource->id)->toBe($result->resourceId);
    expect($resource->display_name)->toBe('Carlos');
    expect($resource->resource_type)->toBe(ResourceType::HUMAN);

    expect($org->services)->toHaveCount(1);
    $service = $org->services->first();
    expect($service->id)->toBe($result->serviceId);
    expect($service->duration_minutes)->toBe(30);
    expect($service->resourceRequirements)->toHaveCount(1);
    expect($service->resources->pluck('id'))->toContain($resource->id);
    expect($resource->schedules)->toHaveCount(2);
});

test('consulta EntitlementChecker antes de crear location, resource y service', function () {
    $channel = registerOrgFixtureChannel();
    $calls = [];
    $spy = new class($calls) implements EntitlementCheckerInterface
    {
        public array $keys = [];

        public function __construct(private array &$sharedRef) {}

        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            $this->keys[] = $entitlementKey;

            return true;
        }
    };

    (new RegisterOrganizationCommand($spy))->handle(registerOrgData($channel));

    expect($spy->keys)->toBe([
        'scheduling.max_locations',
        'scheduling.max_resources',
        'scheduling.max_services',
    ]);
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

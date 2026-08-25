<?php

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ReplaceResourceScheduleCommand;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Scheduling\Resource;
use App\Domain\Tenancy\Channel;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function replaceScheduleFixtureResource(): Resource
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-replace-schedule-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));
    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: '+573009999999',
        channel: $channel,
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30)],
        resources: [new ResourceRegistrationData('Carlos', [
            new WeeklyScheduleSlot(1, '09:00', '17:00'),
            new WeeklyScheduleSlot(2, '09:00', '17:00'),
            new WeeklyScheduleSlot(3, '09:00', '17:00'),
        ])],
    ));

    return Resource::whereIn('id', $result->resourceIds)->firstOrFail();
}

test('reemplaza el horario completo de un recurso: borra el anterior y crea el nuevo', function () {
    $resource = replaceScheduleFixtureResource();
    expect($resource->schedules)->toHaveCount(3);

    (new ReplaceResourceScheduleCommand)->handle($resource, [
        new WeeklyScheduleSlot(5, '14:00', '20:00'),
    ]);

    $resource->refresh();
    expect($resource->schedules)->toHaveCount(1);
    expect($resource->schedules->first()->weekday)->toBe(5);
    expect($resource->schedules->first()->start_time)->toBe('14:00:00');
    expect($resource->schedules->first()->end_time)->toBe('20:00:00');
});

test('reemplazar con un horario vacío deja al recurso sin ninguna franja', function () {
    $resource = replaceScheduleFixtureResource();

    (new ReplaceResourceScheduleCommand)->handle($resource, []);

    $resource->refresh();
    expect($resource->schedules)->toHaveCount(0);
});

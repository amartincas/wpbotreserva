<?php

use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Application\Exceptions\EntitlementDeniedException;
use App\Application\Exceptions\OwnerAlreadyRegisteredException;
use App\Application\Tenancy\RegisterOrganizationCommand;
use App\Application\Tenancy\RegisterOrganizationData;
use App\Application\Tenancy\ResourceRegistrationData;
use App\Application\Tenancy\ServiceRegistrationData;
use App\Application\Tenancy\WeeklyScheduleSlot;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelRole;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\ResourceType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * El número CENTRAL por el que en producción llega el onboarding — B5: el
 * registro nunca lo toca, así que solo se crea donde el test lo verifica.
 */
function registerOrgFixtureCentral(): Channel
{
    return Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'role' => ChannelRole::CENTRAL,
        'phone_number_id' => 'wamid-registro-central',
        'status' => ChannelStatus::ACTIVE,
    ]);
}

function registerOrgData(array $overrides = []): RegisterOrganizationData
{
    return new RegisterOrganizationData(
        organizationName: $overrides['organizationName'] ?? 'Barbería Don Carlos',
        ownerPhone: $overrides['ownerPhone'] ?? '+573001234567',
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

test('registra una organización de un servicio y un recurso: location, resource, service, requisito y horario — sin vincular ningún Channel', function () {
    $central = registerOrgFixtureCentral();
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(registerOrgData());

    $org = Organization::findOrFail($result->organizationId);
    expect($org->name)->toBe('Barbería Don Carlos');
    expect($org->owner_phone)->toBe('+573001234567');

    // B5: el registro ocurre en el CENTRAL, que nunca se vincula; el
    // BUSINESS del negocio se conecta después (B9).
    expect($org->channels)->toHaveCount(0);
    expect($central->fresh()->organizations)->toHaveCount(0);
    expect(DB::table('channel_organization')->count())->toBe(0);

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
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    // Índices de $resources: 0=Carlos, 1=Ana.
    $result = $command->handle(registerOrgData([
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

test('Fase 1: sin organizationDescription, la organización queda con NULL — nunca inventa un valor por default', function () {
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(registerOrgData());

    expect(Organization::findOrFail($result->organizationId)->description)->toBeNull();
});

test('Fase 1: con organizationDescription, la organización la persiste tal cual', function () {
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(new RegisterOrganizationData(
        organizationName: 'Barbería Don Carlos',
        ownerPhone: '+573001234567',
        city: 'Bogotá',
        address: 'Cra 7 # 45-12',
        services: [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
        resources: [new ResourceRegistrationData('Carlos', [new WeeklyScheduleSlot(1, '09:00', '17:00')])],
        organizationDescription: 'Barbería especializada en cortes clásicos.',
    ));

    expect(Organization::findOrFail($result->organizationId)->description)
        ->toBe('Barbería especializada en cortes clásicos.');
});

test('Fase 1: un servicio sin descripción ni precio queda con ambos en NULL', function () {
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(registerOrgData([
        'services' => [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0])],
    ]));

    $service = Organization::findOrFail($result->organizationId)->services->first();
    expect($service->description)->toBeNull();
    expect($service->price)->toBeNull();
});

test('Fase 1: un servicio con descripción y precio numérico persiste ambos', function () {
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(registerOrgData([
        'services' => [new ServiceRegistrationData('Corte de cabello', 30, resourceKeys: [0], description: 'Incluye lavado.', price: 45000.0)],
    ]));

    $service = Organization::findOrFail($result->organizationId)->services->first();
    expect($service->description)->toBe('Incluye lavado.');
    expect((float) $service->price)->toBe(45000.0);
});

test('Fase 1: un servicio con precio condicional (no numérico) persiste el texto en description y precio NULL', function () {
    $command = new RegisterOrganizationCommand(app(EntitlementCheckerInterface::class));

    $result = $command->handle(registerOrgData([
        'services' => [new ServiceRegistrationData('Consulta', 60, resourceKeys: [0], description: 'depende de la valoración', price: null)],
    ]));

    $service = Organization::findOrFail($result->organizationId)->services->first();
    expect($service->description)->toBe('depende de la valoración');
    expect($service->price)->toBeNull();
});

test('consulta EntitlementChecker con la cantidad real de resources/services que va a crear', function () {
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

    (new RegisterOrganizationCommand($spy))->handle(registerOrgData([
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
    $denyAll = new class implements EntitlementCheckerInterface
    {
        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            return false;
        }
    };

    expect(fn () => (new RegisterOrganizationCommand($denyAll))->handle(registerOrgData()))
        ->toThrow(EntitlementDeniedException::class);

    expect(Organization::count())->toBe(0);
});

test('el registro no depende de channel_organization: funciona sin ningún Channel en la base', function () {
    expect(Channel::count())->toBe(0);

    $result = (new RegisterOrganizationCommand(new UnlimitedEntitlementChecker))->handle(registerOrgData());

    expect(Organization::findOrFail($result->organizationId)->owner_phone)->toBe('+573001234567');
});

/**
 * B5 — capa 2 del doble registro: el Command, invocado dos veces para el
 * mismo owner, nunca crea una segunda Organization. Lo detecta
 * UNIQUE(owner_phone) — el guard conversacional del Router (capa 1) cubre
 * el caso común antes de llegar acá.
 */
test('un segundo registro del mismo owner lanza OwnerAlreadyRegisteredException y revierte toda la transacción', function () {
    $command = new RegisterOrganizationCommand(new UnlimitedEntitlementChecker);
    $first = $command->handle(registerOrgData());
    $before = registerOrgRowCounts();

    expect(fn () => $command->handle(registerOrgData(['organizationName' => 'Otro Negocio'])))
        ->toThrow(OwnerAlreadyRegisteredException::class);

    // La primera Organization permanece intacta — nada del segundo intento
    // (ni "Otro Negocio" en sí, ni su Location/Resource/Service) persistió.
    expect(registerOrgRowCounts())->toBe($before);
    expect(Organization::sole()->id)->toBe($first->organizationId);
});

test('carrera real: si otra confirmación del mismo owner ya insertó su Organization, la detecta el UNIQUE de la base (no un chequeo previo) y no deja nada parcial', function () {
    // "Otro proceso" ganó la carrera: su Organization ya está en la base
    // cuando este registro — que ya pasó el guard del Router — confirma.
    DB::table('organizations')->insert([
        'name' => 'Ganó la carrera', 'owner_phone' => '+573001234567',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = registerOrgRowCounts();

    try {
        (new RegisterOrganizationCommand(new UnlimitedEntitlementChecker))->handle(registerOrgData());
        $this->fail('Se esperaba OwnerAlreadyRegisteredException.');
    } catch (OwnerAlreadyRegisteredException $e) {
        expect($e->getPrevious())->toBeInstanceOf(UniqueConstraintViolationException::class);
    }

    expect(registerOrgRowCounts())->toBe($before);
    expect(Organization::sole()->name)->toBe('Ganó la carrera');
});

test('un error a mitad del registro (después de crear Organization, Location y Resources) revierte todo', function () {
    $denyServices = new class implements EntitlementCheckerInterface
    {
        public function check($organization, string $entitlementKey, int $requestedQuantity = 1): bool
        {
            return $entitlementKey !== 'scheduling.max_services';
        }
    };

    expect(fn () => (new RegisterOrganizationCommand($denyServices))->handle(registerOrgData()))
        ->toThrow(EntitlementDeniedException::class);

    expect(array_sum(registerOrgRowCounts()))->toBe(0);
});

/**
 * @return array<string, int>
 */
function registerOrgRowCounts(): array
{
    return collect(['organizations', 'locations', 'resources', 'resource_schedules', 'services', 'service_resource_requirements', 'channel_organization'])
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
        ->all();
}

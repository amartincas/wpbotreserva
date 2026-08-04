<?php

use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Domain\Tenancy\Organization;

test('siempre permite, sin importar la clave o la cantidad pedida', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);
    $checker = new UnlimitedEntitlementChecker;

    expect($checker->check($org, 'scheduling.max_locations'))->toBeTrue();
    expect($checker->check($org, 'scheduling.max_resources', 999))->toBeTrue();
    expect($checker->check($org, 'cualquier.clave.inventada', 0))->toBeTrue();
});

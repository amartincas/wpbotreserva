<?php

use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use Illuminate\Support\Facades\App;

afterEach(function () {
    // Nunca dejar el binding filtrando los tests que corren después.
    if (App::bound('domain.current_organization_id')) {
        App::forgetInstance('domain.current_organization_id');
    }
});

test('sin tenant vinculado, el scope no filtra nada (no-op deliberado del Hito 1)', function () {
    $orgA = Organization::create(['name' => 'Barbería Don Carlos']);
    $orgB = Organization::create(['name' => 'Spa Relax']);
    Location::create(['organization_id' => $orgA->id, 'name' => 'Sede A']);
    Location::create(['organization_id' => $orgB->id, 'name' => 'Sede B']);

    expect(Location::count())->toBe(2);
});

test('con un tenant vinculado, el scope global aísla los datos de otras organizations', function () {
    $orgA = Organization::create(['name' => 'Barbería Don Carlos']);
    $orgB = Organization::create(['name' => 'Spa Relax']);
    Location::create(['organization_id' => $orgA->id, 'name' => 'Sede A']);
    Location::create(['organization_id' => $orgB->id, 'name' => 'Sede B']);

    App::instance('domain.current_organization_id', $orgA->id);

    $visible = Location::all();

    expect($visible)->toHaveCount(1);
    expect($visible->first()->organization_id)->toBe($orgA->id);
});

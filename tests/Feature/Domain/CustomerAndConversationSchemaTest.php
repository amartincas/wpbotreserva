<?php

use App\Domain\Conversational\ConversationSession;
use App\Domain\CRM\Customer;
use App\Domain\Shared\PhoneNumber;
use App\Domain\Tenancy\Organization;
use Illuminate\Database\QueryException;

test('PhoneNumber Value Object valida formato E.164', function () {
    $phone = new PhoneNumber('+573001234567');
    expect($phone->value())->toBe('+573001234567');
    expect((string) $phone)->toBe('+573001234567');
});

test('PhoneNumber rechaza formatos inválidos', function () {
    expect(fn () => new PhoneNumber('3001234567'))->toThrow(InvalidArgumentException::class); // sin +
    expect(fn () => new PhoneNumber('+57abc'))->toThrow(InvalidArgumentException::class);
    expect(fn () => new PhoneNumber('+0123'))->toThrow(InvalidArgumentException::class); // no puede empezar en 0
});

test('dos PhoneNumber con el mismo valor son iguales', function () {
    expect((new PhoneNumber('+573001234567'))->equals(new PhoneNumber('+573001234567')))->toBeTrue();
    expect((new PhoneNumber('+573001234567'))->equals(new PhoneNumber('+573007654321')))->toBeFalse();
});

test('un customer es único por organization + phone, y su phone se castea a PhoneNumber', function () {
    $org = Organization::create(['name' => 'Barbería Don Carlos']);

    $customer = Customer::create([
        'organization_id' => $org->id,
        'phone' => '+573001234567',
        'name' => 'Ana',
    ]);

    expect($customer->phone)->toBeInstanceOf(PhoneNumber::class);
    expect($customer->phone->value())->toBe('+573001234567');
    expect($customer->organization->is($org))->toBeTrue();

    expect(fn () => Customer::create([
        'organization_id' => $org->id,
        'phone' => '+573001234567',
        'name' => 'Otra Ana',
    ]))->toThrow(QueryException::class);
});

test('el mismo teléfono puede ser un customer distinto en otra organization', function () {
    $orgA = Organization::create(['name' => 'Barbería Don Carlos']);
    $orgB = Organization::create(['name' => 'Spa Relax']);

    Customer::create(['organization_id' => $orgA->id, 'phone' => '+573001234567']);
    $second = Customer::create(['organization_id' => $orgB->id, 'phone' => '+573001234567']);

    expect($second->exists)->toBeTrue();
});

test('conversation_session nace sin organization resuelta y es única por teléfono', function () {
    $session = ConversationSession::create(['customer_phone' => '+573001234567']);

    expect($session->organization_id)->toBeNull();
    expect($session->current_agent)->toBeNull();

    expect(fn () => ConversationSession::create(['customer_phone' => '+573001234567']))
        ->toThrow(QueryException::class);
});

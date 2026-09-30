<?php

use App\Application\Conversations\Classification\PendingAttendanceConfirmationStrategy;
use App\Domain\Booking\Booking;
use App\Domain\Booking\PendingAttendanceConfirmation;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\CRM\Customer;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Location;
use App\Domain\Tenancy\Organization;
use App\Enums\BookingStatus;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function pendingAttendanceFixtureSession(Organization $organization, string $phone = '+573001234567'): ConversationSession
{
    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-pending-attendance-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);

    return ConversationSession::create([
        'channel_id' => $channel->id,
        'customer_phone' => $phone,
        'organization_id' => $organization->id,
    ]);
}

function pendingAttendanceFixtureMessage(string $text, string $fromPhone = '+573001234567'): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-pending', $fromPhone, $text, now()->toImmutable());
}

function pendingAttendanceFixtureBooking(Organization $organization, string $customerPhone = '+573001234567'): Booking
{
    $location = Location::create(['organization_id' => $organization->id, 'name' => 'Sede']);
    $service = Service::create(['organization_id' => $organization->id, 'name' => 'Corte', 'duration_minutes' => 30]);
    $customer = Customer::create(['organization_id' => $organization->id, 'phone' => $customerPhone]);

    return Booking::create([
        'organization_id' => $organization->id, 'location_id' => $location->id, 'service_id' => $service->id,
        'customer_id' => $customer->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
        'duration_minutes' => 30, 'status' => BookingStatus::CONFIRMED,
    ]);
}

test('con una fila pendiente vigente, reconoce botón y texto libre sí/no', function (string $text) {
    $organization = Organization::create(['name' => 'Negocio']);
    $booking = pendingAttendanceFixtureBooking($organization);
    PendingAttendanceConfirmation::create(['booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva', 'expires_at' => $booking->starts_at]);
    $session = pendingAttendanceFixtureSession($organization);

    $intent = (new PendingAttendanceConfirmationStrategy)->attempt(pendingAttendanceFixtureMessage($text), $session);

    expect($intent)->toBe(Intent::ConfirmacionAsistencia);
})->with(['si', 'sí', 'Sí', 'no', 'NO', 'confirmar_asistencia', 'no_asistencia_reserva']);

test('variantes no reconocidas no matchean aunque haya una fila pendiente', function (string $text) {
    $organization = Organization::create(['name' => 'Negocio']);
    $booking = pendingAttendanceFixtureBooking($organization);
    PendingAttendanceConfirmation::create(['booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva', 'expires_at' => $booking->starts_at]);
    $session = pendingAttendanceFixtureSession($organization);

    $intent = (new PendingAttendanceConfirmationStrategy)->attempt(pendingAttendanceFixtureMessage($text), $session);

    expect($intent)->toBeNull();
})->with(['claro', 'sí, asistiré', 'no puedo', 'quizás', 'hola']);

test('sin ninguna fila pendiente, "sí"/"no" no matchean', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    $session = pendingAttendanceFixtureSession($organization);

    $intent = (new PendingAttendanceConfirmationStrategy)->attempt(pendingAttendanceFixtureMessage('si'), $session);

    expect($intent)->toBeNull();
});

test('una fila expirada (expires_at ya pasó) no matchea', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    $booking = pendingAttendanceFixtureBooking($organization);
    PendingAttendanceConfirmation::create(['booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva', 'expires_at' => now()->subHour()]);
    $session = pendingAttendanceFixtureSession($organization);

    $intent = (new PendingAttendanceConfirmationStrategy)->attempt(pendingAttendanceFixtureMessage('si'), $session);

    expect($intent)->toBeNull();
});

test('aislamiento multi-tenant: una fila pendiente de otra Organization no matchea', function () {
    $orgA = Organization::create(['name' => 'Negocio A']);
    $orgB = Organization::create(['name' => 'Negocio B']);
    // Mismo teléfono, cliente distinto en cada organización.
    $bookingB = pendingAttendanceFixtureBooking($orgB, '+573001234567');
    PendingAttendanceConfirmation::create(['booking_id' => $bookingB->id, 'template_name' => 'confirmacion_asistencia_reserva', 'expires_at' => $bookingB->starts_at]);

    $sessionA = pendingAttendanceFixtureSession($orgA);

    $intent = (new PendingAttendanceConfirmationStrategy)->attempt(pendingAttendanceFixtureMessage('si'), $sessionA);

    expect($intent)->toBeNull();
});

test('sin organization resuelta en la sesión, nunca matchea', function () {
    $organization = Organization::create(['name' => 'Negocio']);
    $booking = pendingAttendanceFixtureBooking($organization);
    PendingAttendanceConfirmation::create(['booking_id' => $booking->id, 'template_name' => 'confirmacion_asistencia_reserva', 'expires_at' => $booking->starts_at]);

    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API, 'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-'.uniqid(), 'status' => ChannelStatus::ACTIVE,
    ]);
    $sessionWithoutOrg = ConversationSession::create(['channel_id' => $channel->id, 'customer_phone' => '+573001234567']);

    $intent = (new PendingAttendanceConfirmationStrategy)->attempt(pendingAttendanceFixtureMessage('si'), $sessionWithoutOrg);

    expect($intent)->toBeNull();
});

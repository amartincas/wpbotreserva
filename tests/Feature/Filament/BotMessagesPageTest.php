<?php

use App\Models\User;

test('un super-admin puede abrir la página de Mensajes del bot', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $response = $this->actingAs($admin)->get('/bot-messages');

    $response->assertOk();
    $response->assertSee('registro.nombre_negocio');
});

test('un usuario que no es super-admin no puede acceder', function () {
    $user = User::factory()->create(['is_super_admin' => false]);

    $response = $this->actingAs($user)->get('/bot-messages');

    $response->assertForbidden();
});

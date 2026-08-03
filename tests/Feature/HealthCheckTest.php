<?php

test('el endpoint de salud responde ok sin autenticación', function () {
    $response = $this->get('/health');

    $response->assertOk();
    $response->assertJson(['status' => 'ok']);
});

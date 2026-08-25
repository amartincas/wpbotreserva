<?php

use App\Jobs\ProcessInboundConversationMessage;
use Illuminate\Support\Facades\Bus;

function webhookVerifyToken(): string
{
    return 'test-verify-token';
}

function webhookTextMessagePayload(array $overrides = []): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => array_merge([
                    'metadata' => ['phone_number_id' => $overrides['phoneNumberId'] ?? 'wamid-webhook-channel'],
                    'messages' => [array_merge([
                        'id' => $overrides['messageId'] ?? 'wamid.test-'.uniqid(),
                        'from' => $overrides['from'] ?? '573001234567',
                        'timestamp' => $overrides['timestamp'] ?? (string) now()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => $overrides['text'] ?? 'Hola'],
                    ], $overrides['messageOverrides'] ?? [])],
                ], $overrides['valueOverrides'] ?? []),
            ]],
        ]],
    ];
}

function webhookButtonReplyPayload(string $buttonId, array $overrides = []): array
{
    return [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => $overrides['phoneNumberId'] ?? 'wamid-webhook-channel'],
                    'messages' => [[
                        'id' => $overrides['messageId'] ?? 'wamid.test-'.uniqid(),
                        'from' => $overrides['from'] ?? '573001234567',
                        'timestamp' => (string) now()->timestamp,
                        'type' => 'interactive',
                        'interactive' => [
                            'type' => 'button_reply',
                            'button_reply' => ['id' => $buttonId, 'title' => $overrides['title'] ?? 'Sí'],
                        ],
                    ]],
                ],
            ]],
        ]],
    ];
}

beforeEach(function () {
    config(['services.whatsapp_webhook.verify_token' => webhookVerifyToken()]);
    config(['services.whatsapp_webhook.app_secret' => null]);
});

test('verify responde el challenge cuando el modo y el token son correctos', function () {
    $response = $this->get('/api/wpbotreserva/whatsapp/webhook?hub.mode=subscribe&hub.verify_token='.webhookVerifyToken().'&hub.challenge=1234');

    $response->assertOk();
    $response->assertSee('1234');
});

test('verify responde 403 cuando el token es incorrecto', function () {
    $response = $this->get('/api/wpbotreserva/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=incorrecto&hub.challenge=1234');

    $response->assertStatus(403);
});

test('verify responde 403 cuando el modo no es subscribe', function () {
    $response = $this->get('/api/wpbotreserva/whatsapp/webhook?hub.mode=unsubscribe&hub.verify_token='.webhookVerifyToken().'&hub.challenge=1234');

    $response->assertStatus(403);
});

test('handle despacha ProcessInboundConversationMessage con los datos del mensaje de texto', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', webhookTextMessagePayload([
        'phoneNumberId' => 'wamid-canal-1',
        'from' => '573001234567',
        'text' => 'Quiero un turno',
        'messageId' => 'wamid.abc123',
    ]));

    $response->assertOk();
    Bus::assertDispatched(ProcessInboundConversationMessage::class, function ($job) {
        return $job->message->messageId === 'wamid.abc123'
            && $job->message->phoneNumberId === 'wamid-canal-1'
            && $job->message->fromPhone === '+573001234567'
            && $job->message->text === 'Quiero un turno';
    });
});

test('handle normaliza el from agregando + si Meta no lo incluye', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $this->postJson('/api/wpbotreserva/whatsapp/webhook', webhookTextMessagePayload(['from' => '573001234567']));

    Bus::assertDispatched(ProcessInboundConversationMessage::class, fn ($job) => $job->message->fromPhone === '+573001234567');
});

test('handle no despacha nada cuando el payload solo trae statuses (delivery/read receipts)', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'wamid-canal-1'],
                    'statuses' => [['id' => 'wamid.abc', 'status' => 'delivered']],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    $response->assertOk();
    Bus::assertNotDispatched(ProcessInboundConversationMessage::class);
});

test('handle ignora mensajes que no son de texto (fuera de alcance del Hito 7)', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = webhookTextMessagePayload([
        'messageOverrides' => ['type' => 'image', 'text' => null, 'image' => ['id' => 'media-1']],
    ]);

    $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    Bus::assertNotDispatched(ProcessInboundConversationMessage::class);
});

test('handle interpreta la respuesta de un botón como si el id fuera el texto tipeado', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $this->postJson('/api/wpbotreserva/whatsapp/webhook', webhookButtonReplyPayload('si', ['messageId' => 'wamid.btn1']));

    Bus::assertDispatched(ProcessInboundConversationMessage::class, function ($job) {
        return $job->message->messageId === 'wamid.btn1' && $job->message->text === 'si';
    });
});

test('handle interpreta la respuesta de una lista igual que la de un botón (list_reply.id)', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = webhookTextMessagePayload([
        'messageOverrides' => [
            'type' => 'interactive',
            'text' => null,
            'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'nueva', 'title' => 'Nueva reserva']],
        ],
    ]);

    $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    Bus::assertDispatched(ProcessInboundConversationMessage::class, fn ($job) => $job->message->text === 'nueva');
});

test('handle no despacha nada si falta phone_number_id en metadata', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = webhookTextMessagePayload(['valueOverrides' => ['metadata' => []]]);

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    $response->assertOk();
    Bus::assertNotDispatched(ProcessInboundConversationMessage::class);
});

test('handle despacha un job por cada mensaje cuando el payload trae varios', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'wamid-canal-1'],
                    'messages' => [
                        ['id' => 'wamid.uno', 'from' => '573001111111', 'type' => 'text', 'text' => ['body' => 'Mensaje uno']],
                        ['id' => 'wamid.dos', 'from' => '573002222222', 'type' => 'text', 'text' => ['body' => 'Mensaje dos']],
                    ],
                ],
            ]],
        ]],
    ];

    $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    Bus::assertDispatchedTimes(ProcessInboundConversationMessage::class, 2);
});

test('handle ignora en silencio un mensaje sin id o from, sin afectar al resto del batch', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'wamid-canal-1'],
                    'messages' => [
                        ['type' => 'text', 'text' => ['body' => 'sin id ni from']],
                        ['id' => 'wamid.bueno', 'from' => '573001234567', 'type' => 'text', 'text' => ['body' => 'Hola']],
                    ],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    $response->assertOk();
    Bus::assertDispatchedTimes(ProcessInboundConversationMessage::class, 1);
    Bus::assertDispatched(ProcessInboundConversationMessage::class, fn ($job) => $job->message->messageId === 'wamid.bueno');
});

test('handle no interrumpe el resto del batch si un mensaje individual revienta al construirse (forma realmente inesperada)', function () {
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'value' => [
                    'metadata' => ['phone_number_id' => 'wamid-canal-1'],
                    'messages' => [
                        // 'id' como array en vez de string: TypeError real al
                        // construir InboundMessage, no un simple "faltan campos".
                        ['id' => ['no-debería-ser-un-array'], 'from' => '573001234567', 'type' => 'text', 'text' => ['body' => 'Hola']],
                        ['id' => 'wamid.bueno', 'from' => '573001234567', 'type' => 'text', 'text' => ['body' => 'Hola']],
                    ],
                ],
            ]],
        ]],
    ];

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload);

    $response->assertOk();
    Bus::assertDispatchedTimes(ProcessInboundConversationMessage::class, 1);
    Bus::assertDispatched(ProcessInboundConversationMessage::class, fn ($job) => $job->message->messageId === 'wamid.bueno');
});

test('handle rechaza el payload si la firma X-Hub-Signature-256 no coincide (con app_secret configurado)', function () {
    config(['services.whatsapp_webhook.app_secret' => 'shh-secreto']);
    Bus::fake([ProcessInboundConversationMessage::class]);

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', webhookTextMessagePayload(), [
        'X-Hub-Signature-256' => 'sha256=firma-invalida',
    ]);

    $response->assertStatus(403);
    Bus::assertNotDispatched(ProcessInboundConversationMessage::class);
});

test('handle acepta el payload cuando la firma X-Hub-Signature-256 es correcta', function () {
    config(['services.whatsapp_webhook.app_secret' => 'shh-secreto']);
    Bus::fake([ProcessInboundConversationMessage::class]);

    $payload = webhookTextMessagePayload();
    $signature = 'sha256='.hash_hmac('sha256', json_encode($payload), 'shh-secreto');

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', $payload, [
        'X-Hub-Signature-256' => $signature,
    ]);

    $response->assertOk();
    Bus::assertDispatched(ProcessInboundConversationMessage::class);
});

test('handle no valida firma si no hay app_secret configurado (gap documentado)', function () {
    config(['services.whatsapp_webhook.app_secret' => null]);
    Bus::fake([ProcessInboundConversationMessage::class]);

    $response = $this->postJson('/api/wpbotreserva/whatsapp/webhook', webhookTextMessagePayload());

    $response->assertOk();
    Bus::assertDispatched(ProcessInboundConversationMessage::class);
});

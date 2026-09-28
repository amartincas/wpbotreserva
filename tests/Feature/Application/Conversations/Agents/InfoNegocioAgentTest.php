<?php

use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Conversations\Agents\InfoNegocioAgent;
use App\Application\Conversations\BotMessages\BotMessageRepository;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\InfoNegocio\BusinessContextBuilder;
use App\Contracts\AiServiceInterface;
use App\Domain\Conversational\ConversationSession;
use App\Domain\Conversational\InboundMessage;
use App\Domain\Conversational\Intent;
use App\Domain\Scheduling\Service;
use App\Domain\Tenancy\Channel;
use App\Domain\Tenancy\Organization;
use App\Enums\ChannelProvider;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;

function infoNegocioFakeNotificationSender(array &$sent): NotificationSenderInterface
{
    return new class($sent) implements NotificationSenderInterface
    {
        public function __construct(private array &$sent) {}

        public function send(Organization $organization, string $toPhoneE164, string $message): void
        {
            $this->sent[] = compact('organization', 'toPhoneE164', 'message');
        }

        public function sendTemplate(Organization $organization, string $toPhoneE164, string $templateName, string $language, array $bodyParameters): void {}

        public function sendButtons(Organization $organization, string $toPhoneE164, string $bodyText, array $buttons): void
        {
            $this->sent[] = ['organization' => $organization, 'toPhoneE164' => $toPhoneE164, 'message' => $bodyText, 'buttons' => $buttons];
        }
    };
}

/**
 * A diferencia de un fake que devuelve una respuesta fija, este captura el
 * system prompt recibido — permite verificar que InfoNegocioAgent arma el
 * contexto real de la Organization (vía BusinessContextBuilder) antes de
 * llamar a la IA, no un contexto vacío o mockeado.
 */
function infoNegocioCapturingAi(string $response, ?string &$capturedSystemPrompt): AiServiceInterface
{
    return new class($response, $capturedSystemPrompt) implements AiServiceInterface
    {
        public function __construct(private readonly string $response, private mixed &$captured) {}

        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            $this->captured = $systemPrompt;

            return $this->response;
        }
    };
}

function infoNegocioThrowingAi(): AiServiceInterface
{
    return new class implements AiServiceInterface
    {
        public function getResponse(string $userMessage, string $systemPrompt, array $history = []): string
        {
            throw new RuntimeException('proveedor de IA caído');
        }
    };
}

function infoNegocioFixtureOrganization(): Organization
{
    $organization = Organization::create([
        'name' => 'Barbería Don Carlos',
        'description' => 'Barbería especializada en cortes clásicos.',
    ]);

    Service::create([
        'organization_id' => $organization->id,
        'name' => 'Corte de cabello',
        'duration_minutes' => 30,
        'price' => 45000,
    ]);

    $channel = Channel::create([
        'provider' => ChannelProvider::META_CLOUD_API,
        'channel_type' => ChannelType::WHATSAPP,
        'phone_number_id' => 'wamid-info-negocio-'.uniqid(),
        'status' => ChannelStatus::ACTIVE,
    ]);
    $channel->organizations()->attach($organization->id, ['is_primary' => true]);

    return $organization;
}

function infoNegocioFixtureSession(Organization $organization): ConversationSession
{
    $session = ConversationSession::create([
        'channel_id' => $organization->channels()->first()->id,
        'customer_phone' => '+573009999999',
        'organization_id' => $organization->id,
    ]);
    (new EloquentConversationSessionRepository)->recordIntent($session, Intent::InfoNegocio);

    return $session;
}

function infoNegocioFixtureMessage(string $text): InboundMessage
{
    return new InboundMessage('wamid.msg-'.uniqid(), 'wamid-info-negocio', '+573009999999', $text, now()->toImmutable());
}

function buildInfoNegocioAgent(array &$sent, AiServiceInterface $ai): InfoNegocioAgent
{
    return new InfoNegocioAgent(
        new BusinessContextBuilder,
        infoNegocioFakeNotificationSender($sent),
        new EloquentConversationSessionRepository,
        new BotMessageRepository,
        $ai,
    );
}

test('responde con lo que devuelve la IA cuando no es ninguno de los sentinels, y limpia el Intent (es de un único turno)', function () {
    $organization = infoNegocioFixtureOrganization();
    $session = infoNegocioFixtureSession($organization);
    $sent = [];
    $capturedPrompt = null;
    $agent = buildInfoNegocioAgent($sent, infoNegocioCapturingAi('El corte de cabello cuesta $45.000.', $capturedPrompt));

    $agent->handle(infoNegocioFixtureMessage('¿cuánto cuesta el corte?'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toBe('El corte de cabello cuesta $45.000.');
    expect($session->fresh()->current_intent)->toBeNull();
});

test('arma el contexto real de la organización (vía BusinessContextBuilder) antes de llamar a la IA', function () {
    $organization = infoNegocioFixtureOrganization();
    $session = infoNegocioFixtureSession($organization);
    $sent = [];
    $capturedPrompt = null;
    $agent = buildInfoNegocioAgent($sent, infoNegocioCapturingAi('ok', $capturedPrompt));

    $agent->handle(infoNegocioFixtureMessage('¿qué hacen?'), $session, $organization);

    expect($capturedPrompt)->toContain('Barbería Don Carlos');
    expect($capturedPrompt)->toContain('Barbería especializada en cortes clásicos.');
    expect($capturedPrompt)->toContain('Corte de cabello');
    expect($capturedPrompt)->toContain('precio: $45.000');
});

test('nunca mezcla el contexto de otra organización (multi-tenant)', function () {
    $organizationA = infoNegocioFixtureOrganization();
    $organizationB = Organization::create(['name' => 'Spa Relax', 'description' => 'Otro negocio, otra descripción.']);
    Service::create(['organization_id' => $organizationB->id, 'name' => 'Masaje', 'duration_minutes' => 60]);

    $session = infoNegocioFixtureSession($organizationA);
    $sent = [];
    $capturedPrompt = null;
    $agent = buildInfoNegocioAgent($sent, infoNegocioCapturingAi('ok', $capturedPrompt));

    $agent->handle(infoNegocioFixtureMessage('¿qué hacen?'), $session, $organizationA);

    expect($capturedPrompt)->toContain('Barbería Don Carlos');
    expect($capturedPrompt)->not->toContain('Spa Relax');
    expect($capturedPrompt)->not->toContain('Masaje');
});

test('el sentinel SIN_INFORMACION nunca llega al cliente tal cual — se traduce al BotMessage editable', function () {
    $organization = infoNegocioFixtureOrganization();
    $session = infoNegocioFixtureSession($organization);
    $sent = [];
    $capturedPrompt = null;
    $agent = buildInfoNegocioAgent($sent, infoNegocioCapturingAi('SIN_INFORMACION', $capturedPrompt));

    $agent->handle(infoNegocioFixtureMessage('¿tienen estacionamiento?'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->not->toBe('SIN_INFORMACION');
    expect($sent[0]['message'])->toBe((new BotMessageRepository)->render('info_negocio.sin_datos'));
    expect($session->fresh()->current_intent)->toBeNull();
});

test('el sentinel PRECIO_NO_REGISTRADO nunca llega al cliente tal cual — se traduce al BotMessage editable', function () {
    $organization = infoNegocioFixtureOrganization();
    Service::create(['organization_id' => $organization->id, 'name' => 'Barba', 'duration_minutes' => 15]); // sin precio
    $session = infoNegocioFixtureSession($organization);
    $sent = [];
    $capturedPrompt = null;
    $agent = buildInfoNegocioAgent($sent, infoNegocioCapturingAi('PRECIO_NO_REGISTRADO', $capturedPrompt));

    $agent->handle(infoNegocioFixtureMessage('¿cuánto cuesta la barba?'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->not->toBe('PRECIO_NO_REGISTRADO');
    expect($sent[0]['message'])->toBe((new BotMessageRepository)->render('info_negocio.precio_no_registrado'));
    expect($session->fresh()->current_intent)->toBeNull();
});

test('si la llamada a la IA falla, responde con el mensaje de sin información en vez de propagar la excepción', function () {
    $organization = infoNegocioFixtureOrganization();
    $session = infoNegocioFixtureSession($organization);
    $sent = [];
    $agent = buildInfoNegocioAgent($sent, infoNegocioThrowingAi());

    $agent->handle(infoNegocioFixtureMessage('¿qué hacen?'), $session, $organization);

    expect($sent)->toHaveCount(1);
    expect($sent[0]['message'])->toBe((new BotMessageRepository)->render('info_negocio.sin_datos'));
    expect($session->fresh()->current_intent)->toBeNull();
});

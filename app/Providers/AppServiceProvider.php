<?php

namespace App\Providers;

use App\Application\Booking\Listeners\SendBookingCancellationNotification;
use App\Application\Booking\Listeners\SendBookingConfirmationNotification;
use App\Application\Booking\Listeners\SendBookingRescheduleNotification;
use App\Application\Channels\PhoneNumberIdChannelResolver;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ChannelResolverInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Contracts\OrganizationResolverInterface;
use App\Application\Conversations\Agents\AdminCommandAgent;
use App\Application\Conversations\Agents\BookingChoiceAgent;
use App\Application\Conversations\Agents\ConversationResetAgent;
use App\Application\Conversations\Agents\GestionNegocioAgent;
use App\Application\Conversations\Agents\GestionReservaAgent;
use App\Application\Conversations\Agents\OutOfScopeAgent;
use App\Application\Conversations\Agents\RegistroNegocioAgent;
use App\Application\Conversations\Agents\ReservaAgent;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\Classification\AiIntentClassifierStrategy;
use App\Application\Conversations\Classification\ButtonIntentStrategy;
use App\Application\Conversations\Classification\CompositeIntentClassifier;
use App\Application\Conversations\Classification\ConversationContinuityStrategy;
use App\Application\Conversations\Classification\DeterministicAdminCommandStrategy;
use App\Application\Conversations\Classification\DeterministicBusinessManagementStrategy;
use App\Application\Conversations\Classification\ResetKeywordStrategy;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\CacheConversationDraftRepository;
use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Application\Notifications\WhatsAppNotificationSender;
use App\Application\Organizations\SingleOrganizationResolver;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\ActiveBookingsFinder;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Contracts\ActiveBookingsFinderInterface;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Events\BookingCancelled;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Domain\Booking\Events\BookingRescheduled;
use App\Domain\Conversational\Intent;
use App\Livewire\WhatsAppChatCenter;
use App\Services\AI\GeminiService;
use App\Services\AI\GrokService;
use App\Services\AI\OpenAIService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AvailabilityCalculatorInterface::class, AvailabilityCalculator::class);
        $this->app->bind(BookingSchedulerInterface::class, BookingScheduler::class);
        $this->app->bind(ActiveBookingsFinderInterface::class, ActiveBookingsFinder::class);
        $this->app->bind(NotificationSenderInterface::class, WhatsAppNotificationSender::class);
        $this->app->bind(ChannelClientInterface::class, MetaWhatsAppClient::class);
        $this->app->bind(EntitlementCheckerInterface::class, UnlimitedEntitlementChecker::class);

        $this->app->bind(ChannelResolverInterface::class, PhoneNumberIdChannelResolver::class);
        $this->app->bind(OrganizationResolverInterface::class, SingleOrganizationResolver::class);
        $this->app->bind(ConversationSessionRepositoryInterface::class, EloquentConversationSessionRepository::class);
        $this->app->bind(ConversationDraftRepositoryInterface::class, CacheConversationDraftRepository::class);

        // Credenciales de IA propias de la plataforma (no las de un negocio,
        // ver config/services.php) — App\Contracts\AiServiceInterface es la
        // interfaz que OpenAIService/GrokService/GeminiService realmente
        // implementan; App\Services\AI\AIServiceInterface (y su factory)
        // quedaron desalineadas en el código de turismo y no se usan acá.
        $this->app->bind(AiServiceInterface::class, function () {
            $provider = config('services.intent_classifier.provider');
            $model = config('services.intent_classifier.model');
            $apiKey = config('services.intent_classifier.key');

            return match ($provider) {
                'openai' => new OpenAIService($apiKey, $model),
                'grok' => new GrokService($apiKey, $model),
                'gemini' => new GeminiService($apiKey, $model),
                default => throw new \RuntimeException("Proveedor de IA no soportado para intent_classifier: {$provider}"),
            };
        });

        // Orden explícito (Parte IX punto 3: clases explícitas, no un
        // registro dinámico) — comandos admin deterministas primero (cero
        // costo de IA, coincidencia exacta), luego la palabra de salida
        // (tiene que ir ANTES de continuidad: si corriera después, la
        // continuidad ya habría repetido el Intent activo y nunca le daría
        // la oportunidad de interrumpirlo), luego el botón del menú inicial
        // (mismo motivo: el mensaje anterior quedó clasificado FueraDeAlcance
        // y quedó grabado en la sesión — sin ir antes de continuidad, el
        // click del botón nunca podría reclasificar), luego continuidad de
        // conversación, IA como último recurso.
        $this->app->bind(IntentClassifierInterface::class, function () {
            return new CompositeIntentClassifier([
                $this->app->make(DeterministicAdminCommandStrategy::class),
                $this->app->make(DeterministicBusinessManagementStrategy::class),
                $this->app->make(ResetKeywordStrategy::class),
                $this->app->make(ButtonIntentStrategy::class),
                $this->app->make(ConversationContinuityStrategy::class),
                $this->app->make(AiIntentClassifierStrategy::class),
            ]);
        });

        // Los agentes de negocio (Registro/Reservas, Hito 5/6) se agregan acá
        // como una línea más del array — cero cambios en AgentSelector ni en
        // el Router.
        $this->app->bind(AgentSelector::class, function () {
            return new AgentSelector([
                Intent::FueraDeAlcance->value => $this->app->make(OutOfScopeAgent::class),
                Intent::RegistroNegocio->value => $this->app->make(RegistroNegocioAgent::class),
                Intent::Reserva->value => $this->app->make(ReservaAgent::class),
                Intent::GestionReserva->value => $this->app->make(GestionReservaAgent::class),
                Intent::ReservaOGestion->value => $this->app->make(BookingChoiceAgent::class),
                Intent::Reset->value => $this->app->make(ConversationResetAgent::class),
                Intent::AdminCommand->value => $this->app->make(AdminCommandAgent::class),
                Intent::GestionNegocio->value => $this->app->make(GestionNegocioAgent::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        Schema::defaultStringLength(191);

        // Antes solo miraba environment('production') — en staging (detrás
        // de Nginx Proxy Manager, que termina TLS y reenvía HTTP plano sin
        // headers de proxy confiable) esa condición nunca se cumplía, así
        // que Laravel generaba URLs de assets en http:// aunque el sitio
        // real fuera https://, y el navegador las bloqueaba por contenido
        // mixto. Se basa en el esquema real de APP_URL en vez de una lista
        // fija de nombres de entorno, así que aplica igual en cualquier
        // entorno que esté configurado para servir por HTTPS.
        if (env('FORCE_HTTPS') || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        $this->configureDefaults();

        // Register Livewire components
        Livewire::component('whats-app-chat-center', WhatsAppChatCenter::class);

        // El dominio dispara BookingConfirmed sin saber quién escucha (Parte
        // IX/XIII regla 4) — el mapeo evento→listener vive acá, en la capa
        // de Application, no en app/Listeners (fuera del auto-discovery de
        // Laravel porque el listener vive bajo app/Application).
        Event::listen(BookingConfirmed::class, SendBookingConfirmationNotification::class);
        Event::listen(BookingCancelled::class, SendBookingCancellationNotification::class);
        Event::listen(BookingRescheduled::class, SendBookingRescheduleNotification::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

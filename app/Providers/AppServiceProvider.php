<?php

namespace App\Providers;

use App\Application\Booking\Listeners\SendBookingConfirmationNotification;
use App\Application\Channels\PhoneNumberIdChannelResolver;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\ChannelResolverInterface;
use App\Application\Contracts\ConversationDraftRepositoryInterface;
use App\Application\Contracts\ConversationSessionRepositoryInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\IntentClassifierInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Contracts\OrganizationResolverInterface;
use App\Application\Conversations\Agents\OutOfScopeAgent;
use App\Application\Conversations\Agents\RegistroNegocioAgent;
use App\Application\Conversations\Agents\ReservaAgent;
use App\Application\Conversations\AgentSelector;
use App\Application\Conversations\Classification\AiIntentClassifierStrategy;
use App\Application\Conversations\Classification\CompositeIntentClassifier;
use App\Application\Conversations\Classification\ConversationContinuityStrategy;
use App\Application\Conversations\EloquentConversationSessionRepository;
use App\Application\Conversations\Flows\CacheConversationDraftRepository;
use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Application\Notifications\WhatsAppNotificationSender;
use App\Application\Organizations\SingleOrganizationResolver;
use App\Contracts\AiServiceInterface;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Events\BookingConfirmed;
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
        // registro dinámico) — continuidad de conversación antes que IA.
        // Agregar comandos admin determinista (Incremento 2) es insertar una
        // estrategia más acá, sin tocar el composite ni el Router.
        $this->app->bind(IntentClassifierInterface::class, function () {
            return new CompositeIntentClassifier([
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
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        Schema::defaultStringLength(191);

        if (app()->environment('production') || env('FORCE_HTTPS')) {
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

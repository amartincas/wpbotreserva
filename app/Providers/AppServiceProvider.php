<?php

namespace App\Providers;

use App\Application\Booking\Listeners\SendBookingConfirmationNotification;
use App\Application\Contracts\ChannelClientInterface;
use App\Application\Contracts\EntitlementCheckerInterface;
use App\Application\Contracts\NotificationSenderInterface;
use App\Application\Entitlements\UnlimitedEntitlementChecker;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Application\Notifications\WhatsAppNotificationSender;
use App\Domain\Booking\AvailabilityCalculator;
use App\Domain\Booking\BookingScheduler;
use App\Domain\Booking\Contracts\AvailabilityCalculatorInterface;
use App\Domain\Booking\Contracts\BookingSchedulerInterface;
use App\Domain\Booking\Events\BookingConfirmed;
use App\Livewire\WhatsAppChatCenter;
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

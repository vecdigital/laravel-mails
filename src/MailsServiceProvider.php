<?php

namespace Vormkracht10\Mails;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use SplFileInfo;
use Vormkracht10\Mails\Commands\CheckBounceRateCommand;
use Vormkracht10\Mails\Commands\MonitorMailCommand;
use Vormkracht10\Mails\Commands\PruneMailCommand;
use Vormkracht10\Mails\Commands\ResendMailCommand;
use Vormkracht10\Mails\Commands\WebhooksMailCommand;
use Vormkracht10\Mails\Contracts\MailProviderContract;
use Vormkracht10\Mails\Events\MailEvent;
use Vormkracht10\Mails\Events\MailHardBounced;
use Vormkracht10\Mails\Events\MailUnsuppressed;
use Vormkracht10\Mails\Listeners\AttachMailLogUuid;
use Vormkracht10\Mails\Listeners\LogMailEvent;
use Vormkracht10\Mails\Listeners\LogSendingMail;
use Vormkracht10\Mails\Listeners\LogSentMail;
use Vormkracht10\Mails\Listeners\NotifyOnBounce;
use Vormkracht10\Mails\Listeners\StoreMailRelations;
use Vormkracht10\Mails\Listeners\UnsuppressEmailAddress;
use Vormkracht10\Mails\Managers\MailProviderManager;

class MailsServiceProvider extends PackageServiceProvider
{
    /**
     * @throws BindingResolutionException
     */
    public function registeringPackage(): void
    {
        // Register the mail provider manager as a singleton FIRST
        $this->app->singleton(MailProviderContract::class, fn ($app) => new MailProviderManager($app));

        // Only register basic mail logging events if logging is enabled
        if (config('mails.logging.enabled', true)) {
            // These event listeners should always be registered as they're needed
            // for the basic mail logging functionality
            $this->app['events']->listen(MessageSending::class, AttachMailLogUuid::class);
            $this->app['events']->listen(MessageSending::class, LogSendingMail::class);
            $this->app['events']->listen(MessageSending::class, StoreMailRelations::class);
            $this->app['events']->listen(MessageSent::class, LogSentMail::class);
        }

        // Only register event tracking listeners if tracking is enabled
        if ($this->isTrackingEnabled()) {
            $mailProvider = $this->app->make(MailProviderContract::class);
            $defaultDriver = config('mail.default');

            // Check if the default mail driver is supported for tracking
            if ($defaultDriver && $mailProvider->supports($defaultDriver)) {
                $this->app['events']->listen(MailEvent::class, LogMailEvent::class);
                $this->app['events']->listen(MailHardBounced::class, NotifyOnBounce::class);
                $this->app['events']->listen(MailUnsuppressed::class, UnsuppressEmailAddress::class);
            } else {
                // Log a warning but don't prevent the application from working
//                Log::warning("Mail provider '{$defaultDriver}' is not supported for bounce tracking. Basic mail functionality will work, but bounce tracking is disabled.");
            }
        }
    }

    protected function isTrackingEnabled(): bool
    {
        $trackingConfig = config('mails.logging.tracking', []);

        // If no tracking configuration exists or all tracking options are explicitly disabled
        if (empty($trackingConfig) ||
            (isset($trackingConfig['bounces']) && $trackingConfig['bounces'] === false &&
                isset($trackingConfig['clicks']) && $trackingConfig['clicks'] === false &&
                isset($trackingConfig['complaints']) && $trackingConfig['complaints'] === false &&
                isset($trackingConfig['deliveries']) && $trackingConfig['deliveries'] === false &&
                isset($trackingConfig['opens']) && $trackingConfig['opens'] === false)) {
            return false;
        }

        return true;
    }

    public function bootingPackage(): void
    {
        // Remove the binding from here
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-mails')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigrations($this->getMigrations())
            ->hasRoutes('webhooks')
            ->hasCommands(
                MonitorMailCommand::class,
                PruneMailCommand::class,
                ResendMailCommand::class,
                WebhooksMailCommand::class,
                CheckBounceRateCommand::class,
            );
    }

    /**
     * @return array<string>
     */
    protected function getMigrations(): array
    {
        return collect(app(Filesystem::class)->files(__DIR__.'/../database/migrations'))
            ->map(fn (SplFileInfo $file) => str_replace('.php.stub', '', $file->getBasename()))
            ->toArray();
    }
}

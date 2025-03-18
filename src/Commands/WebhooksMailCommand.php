<?php

namespace Vormkracht10\Mails\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Vormkracht10\Mails\Actions\RegisterWebhooks;
use Vormkracht10\Mails\Contracts\MailProviderContract;

/**
 * Command to register event webhooks for the specified email provider.
 *
 * This command allows users to set up webhook endpoints for email tracking events
 * like bounces, complaints, and deliveries. It checks if the specified provider
 * is supported for tracking before attempting to register webhooks.
 */
class WebhooksMailCommand extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    public $signature = 'mail:webhooks {provider?}';

    /**
     * The console command description.
     *
     * @var string
     */
    public $description = 'Register event webhooks for email provider';

    /**
     * Execute the console command.
     *
     * @param MailProviderContract $mailProviderManager
     * @return int
     */
    public function handle(MailProviderContract $mailProviderManager): int
    {
        $provider = $this->argument('provider') ?? config('mail.default');

        // Check if the provider is supported
        if (!$mailProviderManager->supports($provider)) {
            $this->error("Mail provider '{$provider}' is not supported for bounce tracking.");
            $this->info("Basic mail sending will still work, but bounce tracking won't be available for this provider.");
            $this->info("Supported providers: postmark, mailgun, ses");

            return self::FAILURE;
        }

        try {
            (new RegisterWebhooks)(
                provider: $provider,
                components: $this->components
            );

            $this->info("Successfully registered webhooks for '{$provider}'.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to register webhooks: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Get the array of prompts for missing arguments.
     *
     * @return array<string, string>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'provider' => 'Which email provider would you like to register webhooks for?',
        ];
    }
}

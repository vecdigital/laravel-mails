<?php

namespace Vormkracht10\Mails\Actions;

use Exception;
use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Console\View\Components\Factory;
use Vormkracht10\Mails\Facades\MailProvider;
use Vormkracht10\Mails\Shared\AsAction;

class RegisterWebhooks
{
    use AsAction, InteractsWithIO;

    /**
     * Register webhooks for the specified mail provider.
     *
     * @param string $provider The mail provider identifier (e.g., 'postmark', 'mailgun')
     * @param Factory $components Console components for output
     * @return void
     * @throws Exception If the provider is not supported or webhook registration fails
     */
    public function handle(string $provider, Factory $components): void
    {
        // Check if the provider is supported before attempting to register webhooks
        if (!MailProvider::supports($provider)) {
            throw new Exception("Mail provider '{$provider}' is not supported for webhook registration.");
        }

        // Register webhooks with the provider
        MailProvider::with($provider)->registerWebhooks(
            components: $components
        );
    }
}

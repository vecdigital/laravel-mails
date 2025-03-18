<?php

namespace Vormkracht10\Mails\Drivers;

use Illuminate\Console\View\Components\Factory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Email;
use Vormkracht10\Mails\Contracts\MailDriverContract;

class NullDriver extends MailDriver implements MailDriverContract
{
    /**
     * Process an incoming webhook request.
     *
     * @param Request $request
     * @return Response
     */
    public function webhook(Request $request): Response
    {
        // Return a 200 response to avoid webhook retries
        return response('Unsupported mail driver', 200);
    }

    /**
     * Log mail events from webhook data.
     *
     * @param  array  $payload
     * @return void
     */
    public function processWebhookPayload(array $payload)
    {
        // No-op implementation
    }

    /**
     * Check if the webhook request is valid.
     *
     * @param Request $request
     * @return bool
     */
    public function isValidWebhookRequest(Request $request): bool
    {
        return false;
    }

    /**
     * Register webhooks for this mail provider.
     *
     * @param null $components
     * @return void
     */
    public function registerWebhooks($components = null): void
    {
        // Cannot register webhooks for unsupported driver
        $components?->error('Cannot register webhooks for unsupported mail driver.');
    }

    /**
     * Any other methods required by your interface
     * would be implemented as no-ops here
     */
    public function getUuidFromPayload(array $payload): ?string
    {
        // TODO: Implement getUuidFromPayload() method.
        return null;
    }

    public function dataMapping(): array
    {
        // TODO: Implement dataMapping() method.
        return [];
    }

    protected function getTimestampFromPayload(array $payload): string
    {
        // TODO: Implement getTimestampFromPayload() method.
        return '';
    }

    public function eventMapping(): array
    {
        // TODO: Implement eventMapping() method.
        return [];
    }

    public function verifyWebhookSignature(array $payload): bool
    {
        // TODO: Implement verifyWebhookSignature() method.
        return true;
    }

    public function attachUuidToMail(MessageSending $event, string $uuid): MessageSending
    {
        // TODO: Implement attachUuidToMail() method.
        return new MessageSending(new Email());
    }
}

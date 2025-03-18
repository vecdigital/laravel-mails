<?php

namespace Vormkracht10\Mails\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Vormkracht10\Mails\Enums\Provider;
use Vormkracht10\Mails\Events\MailEvent;
use Vormkracht10\Mails\Facades\MailProvider;

class WebhookController
{
    public function __invoke(Request $request, string $provider): Response
    {
        if (! in_array($provider, array_column(Provider::cases(), 'value'))) {
            return response('Unknown provider.', status: 400);
        }

        // Get the Content-Type header
        $contentType = $request->header('Content-Type');

        // Log the raw input for debugging
//        Log::info('Raw webhook received', [
//            'content_type' => $contentType
//        ]);

        // Parse the input if content type is text/plain and input looks like JSON
        if (str_contains($contentType, 'text/plain')) {
            // Get the raw input
            $rawInput = file_get_contents('php://input');

//            Log::info('Raw webhook received', [
//                'raw_input' => $rawInput,
//            ]);
            try {
                // Attempt to decode the raw input as JSON
                $jsonData = json_decode($rawInput, true);

                // If JSON is valid, override the request data
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    // Manually set the request input
                    $request->replace($jsonData);

//                    Log::info('Webhook data updated from JSON payload', [
//                        'parsed_data' => $jsonData
//                    ]);
                }
            } catch (Exception $e) {
//                Log::error('Error parsing webhook JSON', [
//                    'error' => $e->getMessage(),
//                    'raw_input' => $rawInput
//                ]);
            }
        }

        // Log the final request data
//        Log::info('Webhook Post received', $request->post());
//        Log::info('Webhook All received', $request->all());

        if (! MailProvider::with($provider)->verifyWebhookSignature($request->all())) {
            return response('Invalid signature.', status: 400);
        }

        MailEvent::dispatch($provider, $request->except('signature'));

        return response('Event processed.', status: 202);
    }
}

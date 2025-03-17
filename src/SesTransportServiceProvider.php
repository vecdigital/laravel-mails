<?php

namespace Vormkracht10\Mails;

use Aws\Ses\SesClient;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class SesTransportServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // No bindings needed here
    }

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Log for debugging
        Log::info('SesTransportServiceProvider boot method called');

        // This is the key part - replacing Laravel's builtin SesTransport with our own
        $this->app->bind(SesTransport::class, function ($app) {
            $config = $app['config']->get('services.ses', []);

            // Ensure region is set
            if (empty($config['region'])) {
                $config['region'] = $app['config']->get('mail.mailers.ses.region') ??
                    env('AWS_DEFAULT_REGION', 'us-east-1');
            }

            // Ensure key/secret are set
            if (empty($config['key'])) {
                $config['key'] = $app['config']->get('mail.mailers.ses.key') ??
                    env('AWS_ACCESS_KEY_ID');
            }

            if (empty($config['secret'])) {
                $config['secret'] = $app['config']->get('mail.mailers.ses.secret') ??
                    env('AWS_SECRET_ACCESS_KEY');
            }

            // Create SES client with proper config
            $ses = new SesClient([
                'version' => 'latest',
                'service' => 'email',
                'region' => $config['region'],
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            // Create our custom transport using the config
            $options = $app['config']->get('mail.mailers.ses.options', []);

            Log::info('Creating custom SesTransport', [
                'region' => $config['region'],
                'hasKey' => !empty($config['key']),
                'hasSecret' => !empty($config['secret']),
            ]);

            return new Transports\SesTransport($ses, $options);
        });
    }
}

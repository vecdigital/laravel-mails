<?php

namespace Vormkracht10\Mails\Transports;

use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Illuminate\Mail\Transport\SesTransport as LaravelSesTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Stringable;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;

class SesTransport extends LaravelSesTransport implements Stringable
{
    /**
     * Track whether we need to ensure configuration set exists
     */
    protected bool $ensureConfigSetExists = true;

    /**
     * Configuration set name
     */
    protected string $configSetName = '';

    /**
     * Create a new SES transport instance.
     *
     * @param  array  $options
     * @return void
     */
    public function __construct(SesClient $ses, $options = [])
    {
        // Get configuration set name from config, or use default if not specified
        $this->configSetName = config('mails.providers.ses.configuration_set_name', 'mail-tracking-set');

        // Add environment prefix if enabled
        if (config('mails.providers.ses.use_environment_prefix', true)) {
            $environment = app()->environment();
            if ($environment !== 'production') {
                $this->configSetName = $environment.'-'.$this->configSetName;
            }
        }

        $options['ConfigurationSetName'] = $this->configSetName;
        parent::__construct($ses, $options);

        Log::debug('SES Transport initialized with configuration set', ['name' => $this->configSetName]);
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $message): void
    {
        // Ensure configuration set exists before sending
        if ($this->ensureConfigSetExists) {
            $this->ensureConfigurationSetExists();
            $this->ensureConfigSetExists = false; // Only check once per instance
        }

        $options = $this->options;

        if ($message->getOriginalMessage()) {
            // Extract configuration set from headers if present
            $headers = $message->getOriginalMessage()->getHeaders();

            // Check for SES configuration set header
            if ($headers->has('X-SES-CONFIGURATION-SET')) {
                $configSetHeader = $headers->get('X-SES-CONFIGURATION-SET');
                if ($configSetHeader) {
                    $options['ConfigurationSetName'] = $configSetHeader->getBodyAsString();
                    $this->configSetName = $configSetHeader->getBodyAsString();
                }
            } else {
                // Use default configuration set if not specified in headers
                $options['ConfigurationSetName'] = $this->configSetName;
            }

            // Handle list management options
            if ($listManagementOptions = $this->listManagementOptions($message)) {
                $options['ListManagementOptions'] = $listManagementOptions;
            }

            // Extract message tags from headers
            foreach ($headers->all() as $header) {
                if ($header instanceof MetadataHeader) {
                    $options['Tags'][] = ['Name' => $header->getKey(), 'Value' => $header->getValue()];
                }

                // Parse X-SES-MESSAGE-TAGS header and convert to SES Tags format
                if ($header->getName() === 'X-SES-MESSAGE-TAGS') {
                    $tagValue = $header->getBodyAsString();
                    $tagParts = explode('=', $tagValue, 2);

                    if (count($tagParts) === 2) {
                        $options['Tags'][] = ['Name' => trim($tagParts[0]), 'Value' => trim($tagParts[1])];
                    }
                }
            }
        }

        try {
            $result = $this->ses->sendRawEmail(
                array_merge(
                    $options,
                    [
                        'Source' => $message->getEnvelope()->getSender()->toString(),
                        'Destinations' => (new Collection($message->getEnvelope()->getRecipients()))
                            ->map(function ($recipient) {
                                return $recipient->toString();
                            })
                            ->values()
                            ->all(),
                        'RawMessage' => [
                            'Data' => $message->toString(),
                        ],
                    ]
                )
            );

            $messageId = $result->get('MessageId');

            if ($message->getOriginalMessage()) {
                $message->getOriginalMessage()->getHeaders()->addHeader('X-Message-ID', $messageId);
                $message->getOriginalMessage()->getHeaders()->addHeader('X-SES-Message-ID', $messageId);
            }
        } catch (AwsException $e) {
            $reason = $e->getAwsErrorMessage() ?? $e->getMessage();

            // If the error is about configuration set not existing, try to create it
            if (str_contains($reason, 'Configuration set') && str_contains($reason, 'does not exist')) {
                Log::warning('Configuration set does not exist, attempting to create it', [
                    'configSetName' => $this->configSetName,
                ]);

                // Try to create the configuration set
                if ($this->createConfigurationSet()) {
                    // Try sending again
                    $this->doSend($message);

                    return;
                }
            }

            throw new TransportException(
                sprintf('Request to AWS SES API failed. Reason: %s.', $reason),
                is_int($e->getCode()) ? $e->getCode() : 0,
                $e
            );
        }
    }

    /**
     * Ensure the configuration set exists
     */
    protected function ensureConfigurationSetExists(): void
    {
        try {
            Log::debug('Checking if SES configuration set exists', ['configSetName' => $this->configSetName]);

            // Check if the configuration set exists
            $this->ses->describeConfigurationSet([
                'ConfigurationSetName' => $this->configSetName,
            ]);

            Log::debug('SES configuration set exists', ['configSetName' => $this->configSetName]);
            // If we reach here, the configuration set exists
        } catch (AwsException $e) {
            Log::info('SES configuration set does not exist, creating it', ['configSetName' => $this->configSetName]);

            // Configuration set doesn't exist, create it
            $this->createConfigurationSet();
        }
    }

    /**
     * Create the configuration set
     */
    protected function createConfigurationSet(): bool
    {
        try {
            $this->ses->createConfigurationSet([
                'ConfigurationSet' => [
                    'Name' => $this->configSetName,
                ],
            ]);

            Log::info('SES configuration set created successfully', ['configSetName' => $this->configSetName]);

            return true;
        } catch (AwsException $e) {
            // If it already exists, that's fine too
            if (str_contains($e->getAwsErrorMessage() ?? '', 'ConfigurationSetAlreadyExists')) {
                Log::info('SES configuration set already exists', ['configSetName' => $this->configSetName]);

                return true;
            }

            // Log the error but don't fail the message send
            Log::error('Failed to create SES configuration set', [
                'configSetName' => $this->configSetName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

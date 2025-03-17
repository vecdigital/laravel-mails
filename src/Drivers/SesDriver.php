<?php

namespace Vormkracht10\Mails\Drivers;

use Aws\Ses\SesClient;
use Aws\Sns\SnsClient;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;
use Vormkracht10\Mails\Contracts\MailDriverContract;
use Vormkracht10\Mails\Enums\EventType;
use Vormkracht10\Mails\Enums\Provider;

class SesDriver extends MailDriver implements MailDriverContract
{
    /**
     * Configuration set name used for SES
     *
     * @var string
     */
    protected string $configurationSetName;

    /**
     * Flag to track if configuration set has been confirmed to exist
     *
     * @var bool
     */
    protected bool $configSetExists = false;

    /**
     * Constructor to initialize configurable configuration set name
     */
    public function __construct()
    {
        parent::__construct();

        // Get configuration set name from config, or use default if not specified
        $this->configurationSetName = config('mails.providers.ses.configuration_set_name', 'mail-tracking-set');

        // Add environment prefix if enabled
        if (config('mails.providers.ses.use_environment_prefix', true)) {
            $environment = app()->environment();
            if ($environment !== 'production') {
                $this->configurationSetName = $environment . '-' . $this->configurationSetName;
            }
        }

        Log::debug('SES configuration set name initialized', ['name' => $this->configurationSetName]);
    }

    /**
     * Register webhooks with AWS SES and SNS services
     *
     * @param object $components Logging component for output messages
     * @throws ConnectionException If connection to AWS services fails
     */
    public function registerWebhooks($components): void
    {
        try {
            $trackingConfig = (array) config('mails.logging.tracking');
            $webhookUrl = URL::signedRoute('mails.webhook', ['provider' => Provider::SES]);

            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            Log::info('Starting SES webhook registration process');

            // Initialize clients
            $snsClient = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->baseUrl("https://sns.$region.amazonaws.com");

            $sesClient = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->baseUrl("https://email.$region.amazonaws.com");

            // Step 1: Ensure Configuration Set exists - IMPORTANT: Do this first!
            $this->configSetExists = $this->checkConfigurationSetExists($sesClient, $components);
            if (!$this->configSetExists) {
                $this->createConfigurationSet($sesClient, $components);
            }

            // Step 2: Create or get SNS topic
            $topicArn = $this->createSnsTopic($snsClient, $components);

            if (!$topicArn) {
                $components->error('Failed to get SES SNS topic ARN');
                return;
            }

            // Step 3: Subscribe endpoint to topic
            $subscribed = $this->subscribeToTopic($snsClient, $topicArn, $webhookUrl, $components);

            if (!$subscribed) {
                $components->info('Unable to subscribe to SNS topic, but continuing with configuration');
            }

            // Step 4: Configure SES with events
            $this->configureSesEvents($sesClient, $topicArn, $trackingConfig, $components);

            Log::info('SES webhook registration process completed');

        } catch (ConnectionException $e) {
            Log::error('AWS connection failed during webhook registration: ' . $e->getMessage());
            throw $e;
        } catch (Throwable $e) {
            Log::error('Error registering SES webhooks: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            $components->error('SES webhook registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Create configuration set
     *
     * @param PendingRequest $sesClient
     * @param object $components
     * @return bool
     */
    protected function createConfigurationSet(PendingRequest $sesClient, $components): bool
    {
        try {
            // Get AWS credentials from config
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            // Create AWS SES client directly - this handles the proper signing of requests
            $ses = new SesClient([
                'version' => 'latest',
                'region'  => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);

            try {
                $ses->createConfigurationSet([
                    'ConfigurationSet' => [
                        'Name' => $this->configurationSetName,
                    ],
                ]);

                $components->info("SES configuration set created: {$this->configurationSetName}");
                Log::info("SES configuration set created: {$this->configurationSetName}");
                $this->configSetExists = true;
                return true;
            } catch (Exception $e) {
                // Check if it already exists
                if (str_contains($e->getMessage(), 'ConfigurationSetAlreadyExists')) {
                    $components->info("SES configuration set already exists: {$this->configurationSetName}");
                    Log::info("SES configuration set already exists: {$this->configurationSetName}");
                    $this->configSetExists = true;
                    return true;
                }

                $components->error('Failed to create SES configuration set: ' . $e->getMessage());
                return false;
            }
        } catch (Throwable $e) {
            $components->error('Exception while creating configuration set: ' . $e->getMessage());
            Log::error('Failed to create configuration set', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if configuration set already exists
     *
     * @param PendingRequest $sesClient
     * @param object $components
     * @return bool Whether the configuration set exists
     */
    protected function checkConfigurationSetExists(PendingRequest $sesClient, $components): bool
    {
        try {
            // Get AWS credentials from config
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            // Create AWS SES client directly - this handles the proper signing of requests
            $ses = new SesClient([
                'version' => 'latest',
                'region'  => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);

            try {
                $ses->describeConfigurationSet([
                    'ConfigurationSetName' => $this->configurationSetName,
                ]);

                $components->info("SES configuration set already exists: {$this->configurationSetName}");
                Log::info("SES configuration set already exists: {$this->configurationSetName}");
                return true;
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'ConfigurationSetDoesNotExist')) {
                    Log::debug('Configuration set does not exist');
                    return false;
                }

                Log::debug('Error checking configuration set: ' . $e->getMessage());
                return false;
            }
        } catch (Throwable $e) {
            Log::debug('Error creating SES client: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create SNS topic for webhook notifications
     *
     * @param PendingRequest $snsClient (unused, we'll create a new SnsClient)
     * @param object $components
     * @return string|null
     */
    protected function createSnsTopic(PendingRequest $snsClient, $components): ?string
    {
        try {
            // Get AWS credentials from config
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            // Get SNS topic name from config
            $topicName = config('mails.providers.ses.sns_topic_name', 'mail-tracking-webhook');

            // Add environment prefix if enabled
            if (config('mails.providers.ses.use_environment_prefix', true)) {
                $environment = app()->environment();
                if ($environment !== 'production') {
                    $topicName = $environment . '-' . $topicName;
                }
            }

            // Create AWS SNS client directly - this handles the proper signing of requests
            $sns = new SnsClient([
                'version' => 'latest',
                'region'  => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);

            // Log that we're using AWS SDK
            Log::info('Creating SNS topic using AWS SDK', ['topicName' => $topicName]);

            // First check if topic already exists
            try {
                $listTopicsResult = $sns->listTopics();
                $topics = $listTopicsResult->get('Topics') ?? [];

                foreach ($topics as $topic) {
                    $topicArn = $topic['TopicArn'] ?? '';
                    if (str_contains($topicArn, $topicName)) {
                        $components->info("Using existing SNS topic: {$topicArn}");
                        Log::info("Using existing SNS topic: {$topicArn}");
                        return $topicArn;
                    }
                }

                $components->info("No existing SNS topic found, creating new one");
            } catch (Exception $e) {
                // Log the error but continue to try creating a topic
                $components->info("Error listing topics: " . $e->getMessage());
                Log::warning("Error listing SNS topics", [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
            }

            // Create a new topic
            try {
                $createTopicResult = $sns->createTopic([
                    'Name' => $topicName,
                ]);

                $topicArn = $createTopicResult->get('TopicArn');

                if ($topicArn) {
                    $components->info("SES SNS topic created: $topicArn");
                    Log::info("SES SNS topic created: $topicArn");
                    return $topicArn;
                }

                $components->error('Failed to extract topic ARN from result');
                return null;
            } catch (Exception $e) {
                $components->error('Failed to create SES SNS topic: ' . $e->getMessage());
                Log::error('SNS CreateTopic error', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
                return null;
            }
        } catch (Throwable $e) {
            $components->error('Exception while creating SNS topic: ' . $e->getMessage());
            Log::error('Failed to create SNS topic', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Subscribe webhook endpoint to SNS topic
     *
     * @param PendingRequest $snsClient (unused, we'll create a new SnsClient)
     * @param string $topicArn
     * @param string $webhookUrl
     * @param object $components
     * @return bool Success indicator
     */
    protected function subscribeToTopic(PendingRequest $snsClient, string $topicArn, string $webhookUrl, $components): bool
    {
        try {
            // Get AWS credentials from config
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            // Create AWS SNS client directly - this handles the proper signing of requests
            $sns = new \Aws\Sns\SnsClient([
                'version' => 'latest',
                'region'  => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);

            // First check if subscription already exists
            try {
                $listSubscriptionsResult = $sns->listSubscriptionsByTopic([
                    'TopicArn' => $topicArn
                ]);

                $subscriptions = $listSubscriptionsResult->get('Subscriptions') ?? [];

                foreach ($subscriptions as $subscription) {
                    if (($subscription['Endpoint'] ?? '') === $webhookUrl) {
                        $components->info("Webhook already subscribed: {$subscription['SubscriptionArn']}");
                        Log::info("Webhook already subscribed: {$subscription['SubscriptionArn']}");
                        return true;
                    }
                }
            } catch (Exception $e) {
                // Log but continue with subscription attempt
                Log::warning("Error checking existing subscriptions: " . $e->getMessage());
            }

            // Create new subscription
            try {
                $subscribeOptions = [
                    'TopicArn' => $topicArn,
                    'Protocol' => 'https',
                    'Endpoint' => $webhookUrl,
                ];

                $subscribeResult = $sns->subscribe($subscribeOptions);

                Log::info('Sleeping for 5 secs to take effect...');
                sleep(5);

                $subscriptionArn = $subscribeResult->get('SubscriptionArn');

                if ($subscriptionArn) {
                    try {
                        // If it's pending confirmation, we can still set attributes
                        if ($subscriptionArn === 'pending confirmation') {
                            $components->info("Subscription is pending confirmation, setting raw message delivery");
                            Log::warning("Subscription is pending confirmation, setting raw message delivery", [
                                'topicArn' => $topicArn,
                                'webhookUrl' => $webhookUrl
                            ]);

                            // Set RawMessageDelivery to true for the pending subscription
                            $sns->setSubscriptionAttributes([
                                'SubscriptionArn' => $topicArn,  // Use topic ARN when in pending state
                                'AttributeName' => 'RawMessageDelivery',
                                'AttributeValue' => 'true'
                            ]);

                            // When in pending confirmation state
                            $sns->setSubscriptionAttributes([
                                'SubscriptionArn' => $topicArn,  // Use topic ARN when in pending state
                                'AttributeName' => 'DeliveryPolicy',
                                'AttributeValue' => json_encode([
                                    'healthyRetryPolicy' => [
                                        "numRetries" => 3,
                                        "numNoDelayRetries" => 0,
                                        "minDelayTarget" => 20,
                                        "maxDelayTarget" => 20,
                                        "numMinDelayRetries" => 0,
                                        "numMaxDelayRetries" => 0,
                                        "backoffFunction" => "linear"
                                    ],
                                    'requestPolicy' => [
                                        'headerContentType' => 'application/json'
                                    ]
                                ])
                            ]);

                            $components->info("RawMessageDelivery set for pending subscription");
                            Log::info("RawMessageDelivery set for pending subscription");

                            return true;
                        }

                        $setAttributesResult = $sns->setSubscriptionAttributes([
                            'SubscriptionArn' => $subscriptionArn,
                            'AttributeName' => 'DeliveryPolicy',
                            'AttributeValue' => json_encode([
                                'healthyRetryPolicy' => [
                                    "numRetries" => 3,
                                    "numNoDelayRetries" => 0,
                                    "minDelayTarget" => 20,
                                    "maxDelayTarget" => 20,
                                    "numMinDelayRetries" => 0,
                                    "numMaxDelayRetries" => 0,
                                    "backoffFunction" => "linear"
                                ],
                                'requestPolicy' => [
                                    'headerContentType' => 'application/json'
                                ]
                            ])
                        ]);

                        $components->info("SES webhook Delivery policy set successfully");
                        Log::info("SES webhook Delivery policy set successfully");
                        Log::info($setAttributesResult);

                        return true;
                    } catch (Exception $e) {
                        $components->error('Failed to set subscription attributes: ' . $e->getMessage());
                        Log::error('Failed to set subscription attributes', [
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                            'subscriptionArn' => $subscriptionArn
                        ]);
                        return false;
                    }
                }

                $components->info('Subscription created but ARN not returned');
                Log::warning('SNS subscription created but ARN not returned in response');
                return true;
            } catch (Exception $e) {
                $components->error('Failed to subscribe SES webhook to SNS topic: ' . $e->getMessage());
                Log::error('Failed to subscribe to SNS topic', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
                return false;
            }
        } catch (Throwable $e) {
            $components->error('Exception while subscribing to SNS topic: ' . $e->getMessage());
            Log::error('Failed to subscribe to SNS topic', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Configure SES to publish events to SNS topic
     *
     * @param PendingRequest $sesClient
     * @param string $topicArn
     * @param array $trackingConfig
     * @param object $components
     * @return bool Success indicator
     */
    protected function configureSesEvents(
        PendingRequest $sesClient,
        string $topicArn,
        array $trackingConfig,
                       $components
    ): bool {
        try {
            // Configure event types
            $eventTypes = $this->getEnabledEventTypes($trackingConfig);

            if (count($eventTypes) > 0) {
                return $this->createEventDestination($sesClient, $this->configurationSetName, $eventTypes, $topicArn, $components);
            }

            $components->info('No SES event types enabled in configuration');
            Log::info('No SES event types enabled in configuration');
            return true;
        } catch (Throwable $e) {
            $components->error('Exception while configuring SES events: ' . $e->getMessage());
            Log::error('Failed to configure SES events', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get enabled event types from configuration
     *
     * @param array $trackingConfig
     * @return array
     */
    protected function getEnabledEventTypes(array $trackingConfig): array
    {
        $eventTypes = [];

        // Map config keys to SES event types (lowercase as required by AWS SES API)
        $eventMapping = [
            'opens' => 'open',
            'clicks' => 'click',
            'deliveries' => 'delivery',
            'bounces' => 'bounce',
            'complaints' => 'complaint',
            'unsubscribes' => 'complaint'  // SES doesn't have a direct unsubscribe event
        ];

        foreach ($eventMapping as $configKey => $sesEventType) {
            if ((bool) ($trackingConfig[$configKey] ?? false)) {
                $eventTypes[] = $sesEventType;
            }
        }

        Log::debug('Enabled SES event types', ['eventTypes' => $eventTypes]);
        return $eventTypes;
    }

    /**
     * Create event destination in SES
     *
     * @param PendingRequest $sesClient (unused, we'll create a new SesClient)
     * @param string $configurationSetName
     * @param array $eventTypes
     * @param string $topicArn
     * @param object $components
     * @return bool Success indicator
     */
    protected function createEventDestination(
        PendingRequest $sesClient,
        string $configurationSetName,
        array $eventTypes,
        string $topicArn,
                       $components
    ): bool
    {
        try {
            // Get AWS credentials from config
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            // Create AWS SES client directly - this handles the proper signing of requests
            $ses = new SesClient([
                'version' => 'latest',
                'region'  => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);

            // Check if event destination already exists
            $eventDestExists = false;
            try {
                $listDestinationsResult = $ses->listConfigurationSetEventDestinations([
                    'ConfigurationSetName' => $configurationSetName,
                ]);

                $destinations = $listDestinationsResult->get('EventDestinations') ?? [];

                foreach ($destinations as $destination) {
                    if (($destination['Name'] ?? '') === 'mail-tracking-destination') {
                        $eventDestExists = true;
                        $components->info('SES event destination already exists');
                        Log::info('SES event destination already exists');

                        // Update the existing destination
                        return $this->updateEventDestination(
                            $sesClient,
                            $configurationSetName,
                            $eventTypes,
                            $topicArn,
                            $components
                        );
                    }
                }
            } catch (Exception $e) {
                Log::debug('Error checking event destinations: ' . $e->getMessage());
                // Continue with creation
            }

            // Create new event destination if it doesn't exist
            if (!$eventDestExists) {
                try {
                    $ses->createConfigurationSetEventDestination([
                        'ConfigurationSetName' => $configurationSetName,
                        'EventDestination' => [
                            'Name' => 'mail-tracking-destination',
                            'Enabled' => true,
                            'MatchingEventTypes' => $eventTypes,
                            'SNSDestination' => [
                                'TopicARN' => $topicArn,
                            ],
                        ],
                    ]);

                    $components->info('SES event destination configured successfully');
                    Log::info('SES event destination configured successfully', ['eventTypes' => $eventTypes]);
                    return true;
                } catch (Exception $e) {
                    // Check if it's just because the destination already exists
                    if (strpos($e->getMessage(), 'EventDestinationAlreadyExists') !== false) {
                        $components->info('SES event destination already exists');
                        Log::info('SES event destination already exists');
                        return true;
                    }

                    $components->error('Failed to configure SES event destination: ' . $e->getMessage());
                    Log::error('Failed to configure SES event destination', [
                        'exception' => get_class($e),
                        'message' => $e->getMessage()
                    ]);
                    return false;
                }
            }

            return true;
        } catch (Throwable $e) {
            $components->error('Exception while creating event destination: ' . $e->getMessage());
            Log::error('Failed to create event destination', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Update existing event destination in SES
     *
     * @param PendingRequest $sesClient (unused, we'll create a new SesClient)
     * @param string $configurationSetName
     * @param array $eventTypes
     * @param string $topicArn
     * @param object $components
     * @return bool Success indicator
     */
    protected function updateEventDestination(
        PendingRequest $sesClient,
        string $configurationSetName,
        array $eventTypes,
        string $topicArn,
                       $components
    ): bool
    {
        try {
            // Get AWS credentials from config
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            // Create AWS SES client directly - this handles the proper signing of requests
            $ses = new SesClient([
                'version' => 'latest',
                'region'  => $region,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
            ]);

            try {
                $ses->updateConfigurationSetEventDestination([
                    'ConfigurationSetName' => $configurationSetName,
                    'EventDestination' => [
                        'Name' => 'mail-tracking-destination',
                        'Enabled' => true,
                        'MatchingEventTypes' => $eventTypes,
                        'SNSDestination' => [
                            'TopicARN' => $topicArn,
                        ],
                    ],
                ]);

                $components->info('SES event destination updated successfully');
                Log::info('SES event destination updated successfully', ['eventTypes' => $eventTypes]);
                return true;
            } catch (Exception $e) {
                $components->error('Failed to update SES event destination: ' . $e->getMessage());
                Log::error('Failed to update SES event destination', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage()
                ]);
                return false;
            }
        } catch (Throwable $e) {
            $components->error('Exception while updating event destination: ' . $e->getMessage());
            Log::error('Failed to update event destination', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Verify the signature of a webhook payload from AWS SNS
     *
     * @param array $payload
     * @return bool
     */
    public function verifyWebhookSignature(array $payload): bool
    {
        try {
            // Skip verification during unit tests
            if (app()->runningUnitTests()) {
                return true;
            }

            // Handle subscription confirmation
            if (isset($payload['Type']) && $payload['Type'] === 'SubscriptionConfirmation') {
                if (isset($payload['SubscribeURL'])) {
                    $response = Http::get($payload['SubscribeURL']);
                    return $response->successful();
                }
                return false;
            }

            // Validate certificate URL
            $certUrl = $payload['SigningCertURL'];
            $parsedUrl = parse_url($certUrl);

            if (empty($parsedUrl['scheme']) ||
                $parsedUrl['scheme'] !== 'https' ||
                !preg_match('/^sns\.[a-zA-Z0-9\-]{3,}\.amazonaws\.com(\.cn)?$/', $parsedUrl['host']) ||
                !str_ends_with($certUrl, '.pem')) {
                Log::warning('Invalid certificate URL', ['url' => $certUrl]);
                return false;
            }

            // Fetch certificate
            $certificate = @file_get_contents($certUrl);
            if ($certificate === false) {
                Log::warning('Failed to fetch certificate', ['url' => $certUrl]);
                return false;
            }

            // Validate message
            $signatureVersion = $payload['SignatureVersion'];
            $stringToSign = $this->getStringToSign($payload);
            $signature = base64_decode($payload['Signature']);

            // Determine signature algorithm
            $algo = ($signatureVersion === '1') ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256;

            // Verify signature
            $publicKey = openssl_get_publickey($certificate);
            if (!$publicKey) {
                Log::warning('Failed to extract public key');
                return false;
            }

            $verificationResult = openssl_verify($stringToSign, $signature, $publicKey, $algo);

            // Free the key resource
            openssl_free_key($publicKey);

            return $verificationResult === 1;
        } catch (\Throwable $e) {
            Log::error('SNS signature verification error', [
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Build string to sign for SNS message
     */
    private function getStringToSign(array $payload): string
    {
        $signableKeys = [
            'Message',
            'MessageId',
            'Subject',
            'SubscribeURL',
            'Timestamp',
            'Token',
            'TopicArn',
            'Type',
        ];

        $stringToSign = '';
        foreach ($signableKeys as $key) {
            if (isset($payload[$key])) {
                $stringToSign .= "{$key}\n{$payload[$key]}\n";
            }
        }

        return $stringToSign;
    }

    /**
     * Attach a UUID to the outgoing email
     *
     * @param MessageSending $event
     * @param string $uuid
     * @return MessageSending
     */
    public function attachUuidToMail(MessageSending $event, string $uuid): MessageSending
    {
        try {
            // Ensure config set exists before trying to use it
            if (!$this->configSetExists) {
                $this->ensureConfigSetExists();
            }

            // Set configuration set header
            $event->message->getHeaders()->addTextHeader('X-SES-CONFIGURATION-SET', $this->configurationSetName);

            // Set custom UUID as message tag
            $event->message->getHeaders()->addTextHeader(
                'X-SES-MESSAGE-TAGS',
                config('mails.headers.uuid', 'uuid') . '=' . $uuid
            );

            Log::debug('UUID attached to outgoing SES email', ['uuid' => $uuid]);

            return $event;
        } catch (Throwable $e) {
            // If there's an error, log it but don't prevent the message from being sent
            Log::error('Error attaching UUID to outgoing SES email: ' . $e->getMessage(), [
                'uuid' => $uuid,
                'exception' => get_class($e)
            ]);

            return $event;
        }
    }

    /**
     * Ensure the configuration set exists before sending emails
     *
     * @return bool
     */
    protected function ensureConfigSetExists(): bool
    {
        try {
            $region = config('services.ses.region', 'us-east-1');
            $key = config('services.ses.key');
            $secret = config('services.ses.secret');

            $sesClient = Http::withBasicAuth($key, $secret)
                ->asJson()
                ->baseUrl("https://email.$region.amazonaws.com");

            // First check if it exists
            try {
                $response = $sesClient->post('/', [
                    'Action' => 'DescribeConfigurationSet',
                    'ConfigurationSetName' => $this->configurationSetName,
                    'Version' => '2010-12-01',
                ]);

                if ($response->successful()) {
                    Log::info("SES configuration set exists: {$this->configurationSetName}");
                    $this->configSetExists = true;
                    return true;
                }
            } catch (Throwable $e) {
                // It doesn't exist, continue to create it
            }

            // Create it
            $createResponse = $sesClient->post('/', [
                'Action' => 'CreateConfigurationSet',
                'ConfigurationSet' => [
                    'Name' => $this->configurationSetName,
                ],
                'Version' => '2010-12-01',
            ]);

            if ($createResponse->successful() ||
                str_contains($createResponse->body(), 'ConfigurationSetAlreadyExists')) {
                Log::info("SES configuration set created: {$this->configurationSetName}");
                $this->configSetExists = true;
                return true;
            }

            Log::error('Failed to create SES configuration set: ' . $createResponse->body());
            return false;
        } catch (Throwable $e) {
            Log::error('Error ensuring configuration set exists: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Extract UUID from webhook payload
     *
     * @param array $payload
     * @return string|null
     */
    public function getUuidFromPayload(array $payload): ?string
    {
        try {
            // Extract message from SNS notification
            $message = isset($payload['Message'])
                ? json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR)
                : $payload;

            if (! is_array($message)) {
                return null;
            }

            // Get mail data from the SES message
            $mail = $message['mail'] ?? null;

            if (! is_array($mail)) {
                return null;
            }

            // Look for the UUID in message tags
            $headerKey = config('mails.headers.uuid', 'uuid');

            // Check in tags (primary location)
            if (!empty($mail['tags'][$headerKey])) {
                $uuid = $mail['tags'][$headerKey][0] ?? null;
                if ($uuid) {
                    Log::debug('UUID extracted from SES payload tags', ['uuid' => $uuid]);
                    return $uuid;
                }
            }

            // Fallback: Check in headers as well
            if (!empty($mail['headers'])) {
                foreach ($mail['headers'] as $header) {
                    if (isset($header['name']) && $header['name'] === 'X-SES-MESSAGE-TAGS' &&
                        isset($header['value']) && str_contains($header['value'], $headerKey)) {

                        preg_match("/$headerKey=([^;]+)/", $header['value'], $matches);
                        if (isset($matches[1])) {
                            Log::debug('UUID extracted from SES payload headers', ['uuid' => $matches[1]]);
                            return $matches[1];
                        }
                    }
                }
            }

            Log::warning('UUID not found in SES payload', [
                'messageId' => $mail['messageId'] ?? 'unknown'
            ]);
            return null;
        } catch (Throwable $e) {
            Log::error('Error extracting UUID from payload: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return null;
        }
    }

    /**
     * Get timestamp from webhook payload
     *
     * @param array $payload
     * @return string
     */
    protected function getTimestampFromPayload(array $payload): string
    {
        try {
            // Extract message from SNS notification
            $message = isset($payload['Message'])
                ? json_decode($payload['Message'], true, 512, JSON_THROW_ON_ERROR)
                : $payload;

            if (! is_array($message)) {
                return now()->toIso8601String();
            }

            // Get timestamp based on the event type
            if (isset($message['eventType'])) {
                return match ($message['eventType']) {
                    'Delivery' => $message['delivery']['timestamp'] ?? now()->toIso8601String(),
                    'Bounce' => $message['bounce']['timestamp'] ?? now()->toIso8601String(),
                    'Complaint' => $message['complaint']['timestamp'] ?? now()->toIso8601String(),
                    'Open' => $message['open']['timestamp'] ?? now()->toIso8601String(),
                    'Click' => $message['click']['timestamp'] ?? now()->toIso8601String(),
                    default => $message['mail']['timestamp'] ?? now()->toIso8601String(),
                };
            }

            return $message['mail']['timestamp'] ?? now()->toIso8601String();
        } catch (Throwable $e) {
            Log::error('Error extracting timestamp from payload: ' . $e->getMessage(), [
                'exception' => get_class($e)
            ]);
            return now()->toIso8601String();
        }
    }

    /**
     * @throws Exception
     */
    public function getEventFromPayload(array $payload): string
    {
        // If this is an SNS notification, decode the Message
        $message = isset($payload['Message'])
            ? json_decode($payload['Message'], true)
            : $payload;

        // Ensure we have a valid array
        $message = is_array($message) ? $message : $payload;

        // Update event mapping to match the actual payload structure
        $eventMapping = $this->eventMapping();

        foreach ($eventMapping as $event => $mapping) {
            $matches = true;
            foreach ($mapping as $key => $value) {
                // Use nested data_get to handle dot notation
                $actualValue = data_get($message, $key);
                if ($actualValue !== $value) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return $event;
            }
        }

        // Log additional context for debugging
        Log::error('Unable to map SES event', [
            'message' => $message,
            'payload' => $payload
        ]);

        throw new Exception('Unknown event type');
    }

    /**
     * Get event mapping for SES events
     *
     * @return array
     */
    public function eventMapping(): array
    {
        return [
            EventType::CLICKED->value => ['eventType' => 'Click'],
            EventType::COMPLAINED->value => ['eventType' => 'Complaint'],
            EventType::DELIVERED->value => ['eventType' => 'Delivery'],
            EventType::HARD_BOUNCED->value => [
                'eventType' => 'Bounce',
                'bounce.bounceType' => 'Permanent'
            ],
            EventType::OPENED->value => ['eventType' => 'Open'],
            EventType::SOFT_BOUNCED->value => [
                'eventType' => 'Bounce',
                'bounce.bounceType' => 'Transient'
            ],
            EventType::UNSUBSCRIBED->value => [
                'eventType' => 'Complaint',
                'complaint.complaintFeedbackType' => 'not-spam'
            ],
        ];
    }

    /**
     * Get data mapping for SES events
     *
     * @return array
     */
    public function dataMapping(): array
    {
        return [
            'browser' => 'open.userAgent',
            'city' => 'mail.commonHeaders.from.0',  // Get the first 'from' value
            'country_code' => null,
            'ip_address' => 'open.ipAddress',
            'link' => 'click.link',
            'os' => null,
            'platform' => null,
            'tag' => 'mail.tags.X-Mails-UUID.0',  // Extract UUID from tags
            'user_agent' => 'open.userAgent',
        ];
    }

    /**
     * @throws Exception
     */
    public function getDataFromPayload(array $payload): array
    {
        // If this is an SNS notification, decode the Message
        $message = isset($payload['Message'])
            ? json_decode($payload['Message'], true)
            : $payload;

        // Ensure we have a valid array
        $message = is_array($message) ? $message : $payload;

        return collect($this->dataMapping())
            ->mapWithKeys(function ($value, $key) use ($message) {
                // Special handling for different types of data
                if ($value === null) {
                    return [$key => null];
                }

                $data = data_get($message, $value);

                // Additional transformations
                switch ($key) {
                    case 'tag':
                        // Ensure tag is a string
                        $data = is_array($data) ? implode(',', $data) : $data;
                        break;
                    case 'city':
                        // Extract city from email address if possible
                        $data = is_array($data) ? $data[0] : $data;
                        break;
                }

                return [$key => $data];
            })
            ->filter()
            ->merge([
                'payload' => json_encode($payload),
                'type' => $this->getEventFromPayload($payload),
                'occurred_at' => $this->getTimestampFromPayload($payload),
            ])
            ->toArray();
    }

    /**
     * Remove an email address from the suppression list
     *
     * @param string $address
     * @return Response
     * @throws ConnectionException If connection to AWS fails
     */
    public function unsuppressEmailAddress(string $address): Response
    {
        $region = config('services.ses.region', 'us-east-1');
        $key = config('services.ses.key');
        $secret = config('services.ses.secret');

        $client = Http::withBasicAuth($key, $secret)
            ->asJson()
            ->baseUrl("https://email.$region.amazonaws.com");

        try {
            Log::info('Removing email address from SES suppression list', ['email' => $address]);

            // Using the correct API for removing from suppression list
            return $client->post('/', [
                'Action' => 'DeleteSuppressedDestination',
                'EmailAddress' => $address,
                'Version' => '2010-12-01',
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to remove email from suppression list: ' . $e->getMessage(), [
                'email' => $address,
                'exception' => get_class($e)
            ]);

            // Re-throw ConnectionException but wrap other exceptions
            if ($e instanceof ConnectionException) {
                throw $e;
            }

            throw new ConnectionException($e->getMessage(), 0, $e);
        }
    }
}

<?php

namespace Vormkracht10\Mails\Drivers;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Vormkracht10\Mails\Models\Mail;

abstract class MailDriver
{
    protected string $mailModel;

    protected string $mailEventModel;

    protected string $uuidHeaderName;

    public function __construct()
    {
        $this->mailModel = config('mails.models.mail');
        $this->mailEventModel = config('mails.models.event');
        $this->uuidHeaderName = config('mails.headers.uuid');
    }

    abstract protected function getUuidFromPayload(array $payload): ?string;

    abstract protected function dataMapping(): array;

    abstract protected function getTimestampFromPayload(array $payload): string;

    abstract protected function eventMapping(): array;

    public function getMailFromPayload(array $payload): ?Mail
    {
        return $this->mailModel::query()
            ->firstWhere('uuid', $this->getUuidFromPayload($payload));
    }

    /**
     * @throws Exception
     */
    public function getDataFromPayload(array $payload): array
    {
        return collect($this->dataMapping())
            ->mapWithKeys(fn ($value, $key) => [$key => data_get($payload, $value)])
            ->filter()
            ->merge([
                'payload' => $payload,
                'type' => $this->getEventFromPayload($payload),
                'occurred_at' => $this->getTimestampFromPayload($payload),
            ])
            ->toArray();
    }

    /**
     * @throws Exception
     */
    public function getEventFromPayload(array $payload): string
    {
        foreach ($this->eventMapping() as $event => $mapping) {
            if (collect($mapping)->every(fn ($value, $key) => data_get($payload, $key) === $value)) {
                return $event;
            }
        }

        throw new Exception('Unknown event type');
    }

    /**
     * @throws Exception
     */
    public function logMailEvent($payload): void
    {
        $mail = $this->getMailFromPayload($payload);

        if (is_null($mail)) {
            return;
        }

        $data = $this->getDataFromPayload($payload);
        $method = Str::camel($data['type']);

        if (method_exists($this, $method)) {
            // log mail event
//            Log::info('data from payload to store in db');
//            Log::info($data);
            $mail->events()->create($data);

            // update mail record with timestamp
            $this->{$method}($mail, $this->getTimestampFromPayload($payload));
        }
    }

    public function accepted(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'accepted_at' => $timestamp,
        ]);
    }

    public function clicked(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'last_clicked_at' => $timestamp,
            'clicks' => $mail->clicks + 1,
        ]);
    }

    public function complained(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'complained_at' => $timestamp,
        ]);
    }

    public function delivered(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'delivered_at' => $timestamp,
        ]);
    }

    public function hardBounced(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'hard_bounced_at' => $timestamp,
        ]);
    }

    public function softBounced(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'soft_bounced_at' => $timestamp,
        ]);
    }

    public function opened(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'last_opened_at' => $timestamp,
            'opens' => $mail->opens + 1,
        ]);
    }

    public function unsubscribed(Mail $mail, string $timestamp): void
    {
        $mail->update([
            'unsubscribed_at' => $timestamp,
        ]);
    }
}

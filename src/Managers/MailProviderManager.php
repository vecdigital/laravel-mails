<?php

namespace Vormkracht10\Mails\Managers;

use Illuminate\Support\Manager;
use InvalidArgumentException;
use Vormkracht10\Mails\Contracts\MailDriverContract;
use Vormkracht10\Mails\Contracts\MailProviderContract;
use Vormkracht10\Mails\Drivers\MailgunDriver;
use Vormkracht10\Mails\Drivers\NullDriver;
use Vormkracht10\Mails\Drivers\PostmarkDriver;
use Vormkracht10\Mails\Drivers\SesDriver;

class MailProviderManager extends Manager implements MailProviderContract
{
    /**
     * Get a driver instance.
     *
     * @param string|null $driver
     * @return MailDriverContract|mixed
     */
    public function with($driver): mixed
    {
        return $this->driver($driver);
    }

    /**
     * Check if a driver is supported.
     *
     * @param  string  $driver
     * @return bool
     */
    public function supports(string $driver): bool
    {
        $method = 'create'.ucfirst($driver).'Driver';

        return method_exists($this, $method) && $method !== 'createNullDriver';
    }

    /**
     * Create an instance of the Postmark driver.
     *
     * @return PostmarkDriver
     */
    protected function createPostmarkDriver(): PostmarkDriver
    {
        return new PostmarkDriver;
    }

    /**
     * Create an instance of the Mailgun driver.
     *
     * @return MailgunDriver
     */
    protected function createMailgunDriver(): MailgunDriver
    {
        return new MailgunDriver;
    }

    /**
     * Create an instance of the Amazon SES driver.
     *
     * @return SesDriver
     */
    protected function createSesDriver(): SesDriver
    {
        return new SesDriver;
    }

    /**
     * Create a null/no-op driver when the requested driver isn't supported.
     *
     * @return NullDriver
     */
    protected function createNullDriver(): NullDriver
    {
        return new NullDriver;
    }

    /**
     * Get the default driver name.
     *
     * @return string|null
     */
    public function getDefaultDriver(): ?string
    {
        return config('mail.default', null);
    }

    /**
     * Create a new driver instance.
     *
     * @param string $driver
     * @return MailDriverContract|NullDriver
     */
    protected function createDriver($driver): MailDriverContract|NullDriver
    {
        try {
            return parent::createDriver($driver);
        } catch (InvalidArgumentException) {
            // If the driver is not supported, return a null driver
            // instead of throwing an exception
            return $this->createNullDriver();
        }
    }
}

<?php

namespace Vormkracht10\Mails\Contracts;

interface MailProviderContract
{
    /**
     * Get a driver instance.
     *
     * @param  string|null  $driver
     * @return mixed
     */
    public function driver(?string $driver = null);

    /**
     * Get a driver instance.
     *
     * @param  string|null  $driver
     * @return mixed
     */
    public function with($driver);

    /**
     * Check if a driver is supported.
     *
     * @param  string  $driver
     * @return bool
     */
    public function supports(string $driver): bool;

    /**
     * Get the default driver name.
     *
     * @return string|null
     */
    public function getDefaultDriver(): ?string;
}

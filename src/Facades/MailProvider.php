<?php

namespace Vormkracht10\Mails\Facades;

use Illuminate\Support\Facades\Facade;
use Vormkracht10\Mails\Contracts\MailProviderContract;

/**
 * @method static mixed driver(?string $driver = null)
 * @method static mixed with(?string $driver = null)
 * @method static bool supports(string $driver)
 *
 * @see \Vormkracht10\Mails\Managers\MailProviderManager
 */
class MailProvider extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return MailProviderContract::class;
    }
}

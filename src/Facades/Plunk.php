<?php

namespace SohaibIlyas\PlunkLaravel\Facades;

use Illuminate\Support\Facades\Facade;
use SohaibIlyas\PlunkLaravel\Services\PlunkService;

/**
 * @method static array sendEmail(string|array $to, string $subject, string $body, array $options = [])
 * @method static array sendTemplate(string|array $to, string $templateId, array $data = [], array $options = [])
 * @method static array trackEvent(string $email, string $event, array $data = [], bool $subscribed = true)
 * @method static array verifyEmail(string $email)
 *
 * @see PlunkService
 */
class Plunk extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PlunkService::class;
    }
}

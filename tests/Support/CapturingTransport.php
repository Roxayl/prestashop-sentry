<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Support;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Sentry\Event;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\Transport\TransportInterface;

final class CapturingTransport implements TransportInterface
{
    /** @var Event[] */
    public static array $events = [];

    public function send(Event $event): PromiseInterface
    {
        self::$events[] = $event;

        return new FulfilledPromise(new Response(ResponseStatus::success(), $event));
    }

    public function close(?int $timeout = null): PromiseInterface
    {
        return new FulfilledPromise(true);
    }
}

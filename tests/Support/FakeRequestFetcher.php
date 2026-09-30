<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Support;

use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcherInterface;

/**
 * The SDK binds RequestIntegration's event processor to the first instance of the process:
 * the request is read from shared state so that each test can set its own.
 */
final class FakeRequestFetcher implements RequestFetcherInterface
{
    public static ?ServerRequestInterface $request = null;

    public function fetchRequest(): ?ServerRequestInterface
    {
        return self::$request;
    }
}

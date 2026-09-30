<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Tests\Support\SentryTestCase;
use Extalion\Sentry\Tracing\RequestTransaction;
use Extalion\Sentry\Tracing\TransactionNameSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TransactionNameSubscriberTest extends SentryTestCase
{
    public function testTheMasterRequestRouteNamesTheTransaction(): void
    {
        $this->startRequestTransaction();
        (new TransactionNameSubscriber())->onKernelRequest($this->requestEvent('admin_orders_index', HttpKernelInterface::MASTER_REQUEST));
        RequestTransaction::close();

        self::assertSame('POST admin_orders_index', $this->lastEvent()->getTransaction());
    }

    public function testSubRequestsAndMissingRoutesAreIgnored(): void
    {
        $this->startRequestTransaction();
        $subscriber = new TransactionNameSubscriber();
        $subscriber->onKernelRequest($this->requestEvent('_wdt', HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelRequest($this->requestEvent(null, HttpKernelInterface::MASTER_REQUEST));
        RequestTransaction::close();

        self::assertSame('POST /index.php', $this->lastEvent()->getTransaction());
    }

    private function requestEvent(?string $route, int $type): RequestEvent
    {
        $kernel = new class() implements HttpKernelInterface {
            public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
            {
                return new Response();
            }
        };
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new RequestEvent($kernel, $request, $type);
    }
}

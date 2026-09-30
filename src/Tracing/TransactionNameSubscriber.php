<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tracing;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TransactionNameSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(KernelEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (\is_string($route) && $route !== '') {
            RequestTransaction::nameFromRoute($route);
        }
    }
}

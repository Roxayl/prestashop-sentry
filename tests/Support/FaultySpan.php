<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Support;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * A span whose children fail to start for one operation, to reach the tracer's internal failure path.
 */
final class FaultySpan extends Span
{
    public static ?string $failingOp = null;

    /** @var FaultySpan[] */
    public static array $children = [];

    public function startChild(SpanContext $context): Span
    {
        if ($context->getOp() === self::$failingOp) {
            throw new \RuntimeException('Injected failure');
        }

        $context = clone $context;
        $context->setSampled($this->sampled);
        $context->setParentSpanId($this->spanId);
        $context->setTraceId($this->traceId);
        $span = new self($context);
        self::$children[] = $span;

        return $span;
    }
}

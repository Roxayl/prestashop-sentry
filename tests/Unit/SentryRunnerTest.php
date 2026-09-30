<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Helper\SentryRunner;
use PHPUnit\Framework\TestCase;
use Sentry\Options;

final class SentryRunnerTest extends TestCase
{
    public function testEmptyRatesAreLeftToTheSdkDefaults(): void
    {
        $config = SentryRunner::buildConfig(['sample_rate' => '', 'traces_sample_rate' => '']);

        self::assertArrayNotHasKey('sample_rate', $config);
        self::assertArrayNotHasKey('traces_sample_rate', $config);
        $options = new Options($config);
        self::assertSame(1.0, $options->getSampleRate());
        self::assertNull($options->getTracesSampleRate());
    }

    public function testRatesAreConvertedToFloats(): void
    {
        $options = new Options(SentryRunner::buildConfig(['sample_rate' => '0.5', 'traces_sample_rate' => '0.02']));

        self::assertSame(0.5, $options->getSampleRate());
        self::assertSame(0.02, $options->getTracesSampleRate());
    }

    public function testWithoutTracingTheSdkKeepsItsHttpTimeouts(): void
    {
        $defaults = new Options();

        foreach ([[], ['traces_sample_rate' => ''], ['traces_sample_rate' => '0']] as $sentryJson) {
            $options = new Options(SentryRunner::buildConfig($sentryJson));

            self::assertSame($defaults->getHttpConnectTimeout(), $options->getHttpConnectTimeout());
            self::assertSame($defaults->getHttpTimeout(), $options->getHttpTimeout());
        }
    }

    public function testWithTracingTheHttpTimeoutsAreShort(): void
    {
        $options = new Options(SentryRunner::buildConfig(['traces_sample_rate' => '0.02']));

        self::assertSame(1.0, $options->getHttpConnectTimeout());
        self::assertSame(2.0, $options->getHttpTimeout());
    }

    public function testAnEmptyErrorTypesFallsBackToTheSdkDefault(): void
    {
        self::assertNull(SentryRunner::buildConfig(['error_types' => ''])['error_types']);
    }
}

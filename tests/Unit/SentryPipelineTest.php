<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Helper\SentryRunner;
use Extalion\Sentry\Tests\Support\CapturingTransport;
use Extalion\Sentry\Tests\Support\FakeRequestFetcher;
use Extalion\Sentry\Tests\Support\SentryTestCase;
use Extalion\Sentry\Tracing\RequestTransaction;
use GuzzleHttp\Psr7\ServerRequest;
use Sentry\Event;
use Sentry\Integration\RequestIntegration;

/**
 * Runs the configuration built by SentryRunner through the SDK, with only the HTTP request and the transport faked.
 */
final class SentryPipelineTest extends SentryTestCase
{
    /**
     * @dataProvider refererHeaderNames
     */
    public function testTheTransactionKeepsTheRequestWithoutItsDataOrTokens(string $referer): void
    {
        $this->bindProductionClient([], $referer);
        $this->startRequestTransaction();
        RequestTransaction::close();
        $request = $this->lastEvent()->getRequest();

        self::assertSame('https://shop.test/fr/connexion', $request['url'] ?? null);
        self::assertSame('POST', $request['method'] ?? null);
        self::assertSame(['shop.test'], $request['headers']['Host'] ?? null);
        self::assertSame(['https://shop.test/fr/mot-de-passe-oublie'], $request['headers'][$referer] ?? null);
        self::assertSame(['[Filtered]'], $request['headers']['Cookie'] ?? null);
        self::assertArrayNotHasKey('data', $request);
        self::assertArrayNotHasKey('query_string', $request);
        self::assertArrayNotHasKey('cookies', $request);
    }

    /**
     * @return array<string, array{string}>
     */
    public function refererHeaderNames(): array
    {
        return ['capitalized' => ['Referer'], 'lower case' => ['referer']];
    }

    public function testTheTransactionLeavesOutCookiesAndEnvironmentWhenPersonalDataIsSent(): void
    {
        $this->bindProductionClient(['send_default_pii' => true]);
        $this->startRequestTransaction();
        RequestTransaction::close();
        $request = $this->lastEvent()->getRequest();

        self::assertArrayHasKey('headers', $request);
        self::assertArrayNotHasKey('cookies', $request);
        self::assertArrayNotHasKey('env', $request);
    }

    public function testNoEventCarriesTheListOfComposerPackages(): void
    {
        $this->bindProductionClient();
        $this->startRequestTransaction();
        \Sentry\captureException(new \RuntimeException('during the request'));
        RequestTransaction::close();

        self::assertCount(2, CapturingTransport::$events);

        foreach (CapturingTransport::$events as $event) {
            self::assertSame([], $event->getModules());
        }
    }

    public function testErrorEventsStillCarryTheRequestData(): void
    {
        $this->bindProductionClient();
        $this->startRequestTransaction();
        \Sentry\captureException(new \RuntimeException('during the request'));
        RequestTransaction::close();
        $errors = \array_values(\array_filter(CapturingTransport::$events, static function (Event $event): bool {
            return (string) $event->getType() !== 'transaction';
        }));

        self::assertCount(1, $errors);
        self::assertSame(['email' => 'jane@example.com', 'password' => 'secret'], $errors[0]->getRequest()['data'] ?? null);
    }

    /**
     * @param array<string, mixed> $sentryJson the settings saved in sentry.json
     */
    private function bindProductionClient(array $sentryJson = [], string $referer = 'Referer'): void
    {
        $request = (new ServerRequest(
            'POST',
            'https://shop.test/fr/connexion?back=my-account&token=0123456789abcdef',
            [
                'Host' => 'shop.test',
                $referer => 'https://shop.test/fr/mot-de-passe-oublie?reset_token=fedcba9876543210',
                'Cookie' => 'PrestaShop-abc=secret',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Content-Length' => '48',
            ],
            null,
            '1.1',
            ['REMOTE_ADDR' => '203.0.113.7']
        ))
            ->withQueryParams(['back' => 'my-account', 'token' => '0123456789abcdef'])
            ->withCookieParams(['PrestaShop-abc' => 'secret'])
            ->withParsedBody(['email' => 'jane@example.com', 'password' => 'secret']);

        $config = SentryRunner::buildConfig($sentryJson + [
            'dsn' => 'https://public@example.ingest.sentry.io/1',
            'traces_sample_rate' => '1',
        ]);
        FakeRequestFetcher::$request = $request;
        $keepIntegrations = $config['integrations'];
        $config['integrations'] = static function (array $defaults) use ($keepIntegrations): array {
            return \array_map(static function ($integration) {
                return $integration instanceof RequestIntegration ? new RequestIntegration(new FakeRequestFetcher()) : $integration;
            }, $keepIntegrations($defaults));
        };
        $this->bindCapturingClient($config);
    }
}

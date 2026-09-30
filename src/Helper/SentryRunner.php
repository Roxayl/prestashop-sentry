<?php

declare(strict_types=1);

namespace Extalion\Sentry\Helper;

use Extalion\Sentry\Tracing\RequestTransaction;
use Sentry\Event;
use Sentry\Integration\ModulesIntegration;

class SentryRunner
{
    public static function run(): void
    {
        $config = self::buildConfig(SettingsFile::read());

        \Sentry\init($config);

        RequestTransaction::start($config['traces_sample_rate'] ?? null);
    }

    /**
     * Every key of the returned array must be a SDK option: \Sentry\init() rejects unknown ones.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function buildConfig(array $config): array
    {
        $errorTypes = (string) ($config['error_types'] ?? '');
        $errorTypes = ErrorTypes::isValid($errorTypes) ? $errorTypes : '';
        $config['error_types'] = eval("return {$errorTypes};");
        $config['environment'] = $config['environment'] ?? null;

        foreach (['sample_rate', 'traces_sample_rate'] as $rate) {
            if (($config[$rate] ?? '') === '') {
                unset($config[$rate]);
            } else {
                $config[$rate] = (float) $config[$rate];
            }
        }

        // Disable HTTP compression: it loads php-http/message Encoding streams
        // whose typed signatures are incompatible with the psr/http-message
        // 1.0.1 bundled by PrestaShop 8.x, causing a fatal error on send.
        $config['enable_compression'] = false;

        // The transaction is sent synchronously at the end of each traced request.
        if (($config['traces_sample_rate'] ?? 0.0) > 0.0) {
            $config['http_connect_timeout'] = 1;
            $config['http_timeout'] = 2;
        }

        $config['integrations'] = static function (array $integrations): array {
            return \array_values(\array_filter($integrations, static function ($integration): bool {
                return !$integration instanceof ModulesIntegration;
            }));
        };

        $config['before_send_transaction'] = static function (Event $transaction): Event {
            $request = $transaction->getRequest();
            unset($request['data'], $request['query_string'], $request['cookies'], $request['env']);

            if (isset($request['url']) && \is_string($request['url'])) {
                $request['url'] = \explode('?', $request['url'], 2)[0];
            }

            foreach ($request['headers'] ?? [] as $name => $values) {
                if (\strtolower((string) $name) === 'referer' && \is_array($values)) {
                    $request['headers'][$name] = \array_map(static function ($value) {
                        return \is_string($value) ? \explode('?', $value, 2)[0] : $value;
                    }, $values);
                }
            }

            $transaction->setRequest($request);

            return $transaction;
        };

        return $config;
    }
}

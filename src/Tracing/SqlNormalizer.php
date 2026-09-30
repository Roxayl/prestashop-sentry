<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tracing;

final class SqlNormalizer
{
    private const MAX_BYTES = 500;

    // Longer queries are cut before normalizing: a cut literal is then replaced as an unclosed one.
    private const MAX_INPUT_BYTES = 65536;

    private const PATTERNS = [
        '/\'(?:[^\'\\\\]++|\\\\.|\'\')*+\'|"(?:[^"\\\\]++|\\\\.|"")*+"|[\'"].*$|\b0x[0-9a-f]+\b|\b\d+(?:\.\d+)?(?:e[+-]?\d+)?\b/is',
        '/\s+/',
        '/\(\s*\?(?:\s*,\s*\?)+\s*\)/',
        '/\(\?\)(?:\s*,\s*\(\?\))+/',
    ];

    private const REPLACEMENTS = ['?', ' ', '(?)', '(?)'];

    public static function normalize(string $sql): string
    {
        $normalized = \preg_replace(self::PATTERNS, self::REPLACEMENTS, \substr($sql, 0, self::MAX_INPUT_BYTES));

        if ($normalized === null) {
            return self::firstKeyword($sql);
        }

        return \mb_strcut(\trim($normalized), 0, self::MAX_BYTES, 'UTF-8');
    }

    /**
     * Uses no regex: it runs when the PCRE engine has just failed on this query.
     */
    private static function firstKeyword(string $sql): string
    {
        $sql = \ltrim(\substr($sql, 0, 64));
        $length = \strspn($sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');

        return $length > 0 ? \strtoupper(\substr($sql, 0, $length)) : 'QUERY';
    }
}

<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Tracing\SqlNormalizer;
use PHPUnit\Framework\TestCase;

final class SqlNormalizerTest extends TestCase
{
    public function testQuotedLiteralsAreReplacedIncludingPsqlEscapesAndDoubleQuotes(): void
    {
        self::assertSame(
            'SELECT * FROM `a1b2_customer` WHERE email = ? AND page = ?',
            SqlNormalizer::normalize("SELECT * FROM `a1b2_customer` WHERE email = 'jean.o\\'neil@example.com' AND page = \"/fr/?q=a\"")
        );
    }

    public function testDoubledQuotesStayInsideTheLiteral(): void
    {
        self::assertSame('UPDATE a SET b = ? WHERE c = ?', SqlNormalizer::normalize("UPDATE a SET b = 'it''s' WHERE c = 'd'"));
    }

    public function testNumbersAreReplacedAndTableNamesWithDigitsAreKept(): void
    {
        self::assertSame(
            'SELECT id_product FROM `a1b2_product` WHERE id_product = ? AND price > ? LIMIT ?',
            SqlNormalizer::normalize('SELECT id_product FROM `a1b2_product` WHERE id_product = 42 AND price > 3.5 LIMIT 10')
        );
    }

    public function testHexadecimalLiteralsAreReplaced(): void
    {
        self::assertSame('SELECT a FROM b WHERE h = ?', SqlNormalizer::normalize('SELECT a FROM b WHERE h = 0xDEADBEEF'));
    }

    public function testInListsCollapseAndWhitespaceIsSqueezed(): void
    {
        self::assertSame(
            'SELECT a FROM b WHERE id IN (?) AND c IN (?)',
            SqlNormalizer::normalize("SELECT a\n\t FROM b\n WHERE id IN (1, 2, 3) AND c IN ('x','y')")
        );
    }

    public function testAnUnclosedLiteralIsReplacedUpToTheEnd(): void
    {
        self::assertSame('SELECT * FROM a WHERE b = ?', SqlNormalizer::normalize("SELECT * FROM a WHERE b = 'secret-cut-her"));
    }

    public function testTheResultIsCutTo500BytesWithoutBreakingUtf8(): void
    {
        $normalized = SqlNormalizer::normalize('SELECT `' . \str_repeat('é', 300) . '` FROM a');

        self::assertLessThanOrEqual(500, \strlen($normalized));
        self::assertTrue(\mb_check_encoding($normalized, 'UTF-8'));
    }

    public function testALongValueKeepsTheShapeOfTheQuery(): void
    {
        $value = \str_repeat("a\\'b", 5000);

        self::assertSame(
            'UPDATE ps_configuration SET value = ? WHERE id_configuration = ?',
            SqlNormalizer::normalize("UPDATE ps_configuration SET value = '{$value}' WHERE id_configuration = 3")
        );
    }

    public function testAMultiMegabyteQueryStillHasAShape(): void
    {
        $values = \implode(', ', \array_fill(0, 200000, "(1, 'some text')"));

        self::assertStringStartsWith('INSERT INTO a (b, c) VALUES (?)', SqlNormalizer::normalize("INSERT INTO a (b, c) VALUES {$values}"));
    }

    public function testMultiRowInsertsShareOneShape(): void
    {
        self::assertSame('INSERT INTO t (a, b) VALUES (?)', SqlNormalizer::normalize("INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y'), (3, 'z')"));
        self::assertSame('INSERT INTO t (a, b) VALUES (?)', SqlNormalizer::normalize("INSERT INTO t (a, b) VALUES (1, 'x')"));
    }

    public function testNumbersInScientificNotationAreReplaced(): void
    {
        self::assertSame('SELECT a FROM t WHERE p > ? AND q = ?', SqlNormalizer::normalize('SELECT a FROM t WHERE p > 1.0E-5 AND q = 1e5'));
    }

    public function testAQueryTheRegexCannotHandleIsReducedToItsFirstKeyword(): void
    {
        $jit = \ini_get('pcre.jit');
        $limit = \ini_get('pcre.backtrack_limit');
        \ini_set('pcre.jit', '0');
        \ini_set('pcre.backtrack_limit', '1');

        try {
            self::assertSame('INSERT', SqlNormalizer::normalize("insert INTO a VALUES ('" . \str_repeat('x', 10000) . "')"));
        } finally {
            \ini_set('pcre.jit', (string) $jit);
            \ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }
}

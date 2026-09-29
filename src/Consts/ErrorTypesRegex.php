<?php

declare(strict_types=1);

namespace Extalion\Sentry\Consts;

final class ErrorTypesRegex
{
    public const CONSTANT_REGEX = 'E_[A-Z_]+';

    public const REGEX = '^ *~? *' . self::CONSTANT_REGEX . '( *[&|^] *~? *' . self::CONSTANT_REGEX . ')* *$';

    private function __construct()
    {
    }
}

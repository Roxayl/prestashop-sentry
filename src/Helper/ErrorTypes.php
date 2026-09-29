<?php

declare(strict_types=1);

namespace Extalion\Sentry\Helper;

use Extalion\Sentry\Consts\ErrorTypesRegex;

class ErrorTypes
{
    public static function isValid(string $errorTypes): bool
    {
        if (!\preg_match('/' . ErrorTypesRegex::REGEX . '/', $errorTypes)) {
            return false;
        }

        $constants = [];
        \preg_match_all('/' . ErrorTypesRegex::CONSTANT_REGEX . '/', $errorTypes, $constants);

        foreach ($constants[0] as $constant) {
            if (!\defined($constant) || !\is_int(\constant($constant))) {
                return false;
            }
        }

        return true;
    }
}

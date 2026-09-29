<?php

class PrestaShopException extends PrestaShopExceptionCore
{
    public function displayMessage()
    {
        if (\function_exists('Sentry\captureException')) {
            try {
                \Sentry\captureException($this);
            } catch (\Throwable $ex) {
                \error_log('[extsentry] Unable to report the exception to Sentry: ' . $ex->getMessage());
            }
        }

        parent::displayMessage();
    }
}

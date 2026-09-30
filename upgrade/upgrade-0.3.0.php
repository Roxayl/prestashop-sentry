<?php

declare(strict_types=1);

if (!\defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_0(ExtSentry $module): bool
{
    $legacySettings = $module->getLocalPath() . 'sentry.json';

    try {
        if (\is_file($legacySettings) && !\Extalion\Sentry\Helper\SettingsFile::importLegacyJson($legacySettings)) {
            PrestaShopLogger::addLog(
                '[extsentry] upgrade 0.3.0: the settings could not be moved from ' . $legacySettings . ' to ' . \Extalion\Sentry\Helper\SettingsFile::getPath(),
                PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );
        }
    } catch (Throwable $ex) {
        PrestaShopLogger::addLog(
            '[extsentry] upgrade 0.3.0: the settings could not be moved from ' . $legacySettings . ': ' . $ex->getMessage(),
            PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
        );
    }

    $isEnabled = (bool) Db::getInstance()->getValue(
        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . (int) $module->id
    );

    if (!$isEnabled) {
        return true;
    }

    try {
        $module->uninstallOverrides();
        $module->installOverrides();
    } catch (Throwable $ex) {
        PrestaShopLogger::addLog(
            '[extsentry] upgrade 0.3.0: the overrides could not be installed: ' . $ex->getMessage(),
            PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
        );
    }

    return true;
}

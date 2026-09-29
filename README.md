# Sentry for PrestaShop

PrestaShop module that reports PHP errors and exceptions to [Sentry](https://sentry.io) with the official PHP SDK (`sentry/sentry` 3.x). It is a fork of the `extsentry` module by eXtalion.

## Requirements

- PrestaShop 1.7.8 or later
- PHP 7.4 or later
- Composer, `rsync` and `zip` on the machine that builds the archive
- Write access for the web server user to `config/`, where the module edits `defines_custom.inc.php`, and to `override/`

## Build the archive

The repository does not include the `vendor/` directory, so the module has to be built before it is installed. The clone directory must be named `extsentry`, as the `Makefile` relies on it.

```bash
git clone https://github.com/Roxayl/prestashop-sentry.git extsentry
cd extsentry
make release
```

`make release` installs the production dependencies, copies the module into a clean `extsentry/` directory, adds the `index.php` files PrestaShop expects, and produces `extsentry.zip`.

## Install

From the back office, go to **Modules > Module Manager**, click **Upload a module** and select `extsentry.zip`.

From the command line, copy the built `extsentry/` directory into `modules/`, then run:

```bash
php bin/console prestashop:module install extsentry
```

Installation creates the `<prefix>extsentry_configuration` table, installs the `PrestaShopException` override and adds the Sentry bootstrap to `config/defines_custom.inc.php`. Nothing is sent to Sentry until a DSN is configured.

## Configure

In **Modules > Module Manager**, click **Configure** on the Sentry module.

| Field | Description |
|---|---|
| DSN | Required. The client key of your Sentry project (**Settings > Client Keys**). |
| Error types | PHP error types to report, written with `E_*` constants and the `\|`, `&`, `^` and `~` operators, for example `E_ERROR \| E_PARSE \| E_CORE_ERROR \| E_COMPILE_ERROR \| E_USER_ERROR \| E_RECOVERABLE_ERROR \| E_WARNING`. When empty, the SDK follows `error_reporting()`. |
| Sample rate | Share of error events sent, from `0` to `1`. Keep `1` to report every error. |
| Server name | Name attached to every event. |
| Environment | `production` or `development`. |

Saving stores the settings in the database and in `modules/extsentry/sentry.json` (mode `0600`), which is read on every request. On Apache, the bundled `.htaccess` denies web access to this file. On nginx, add an equivalent rule:

```nginx
location ~ /modules/extsentry/sentry\.json$ {
    deny all;
}
```

## How it works

- The module adds a block between `###> extsentry ###` markers to `config/defines_custom.inc.php`, which PrestaShop includes at the very start of every request: front office, back office and command line. The block loads the PrestaShop autoloader, then the module's, and initializes the SDK, which registers its error, exception and fatal error handlers.
- PrestaShop catches some exceptions itself and renders its error page (`error500.html` in production) through `PrestaShopException::displayMessage()`, which exits before any global handler runs. The module overrides this method to report the exception first.
- HTTP compression is disabled in the SDK: the compression streams of `php-http/message` are incompatible with the `psr/http-message` 1.0 interfaces bundled with PrestaShop 8.

## Upgrade

Upload the new archive from **Module Manager**: PrestaShop runs the upgrade scripts. From 0.1.0, the 0.2.0 script installs the `PrestaShopException` override when the module is enabled on at least one shop. If it fails, a warning is written in **Advanced Parameters > Logs**; disable then enable the module to install the override.

## Disable and uninstall

Disabling the module removes the block from `config/defines_custom.inc.php` and the override, so nothing is reported anymore. Uninstalling does the same and keeps the configuration table. After a reinstall, save the configuration page once to write `sentry.json` again.

## Limitations

- An exception caught by custom code that does not rethrow it, such as a `try`/`catch` in `index.php` that renders an error page, is not reported. Call `\Sentry\captureException($exception)` in that `catch` block.
- An exception thrown from an object destructor at the end of the request is not reported: PHP runs shutdown functions, including the SDK's fatal error handler, before destructors. It only appears in the PHP error log.
- Stack traces include function arguments unless `zend.exception_ignore_args` is `On`, which is the value of PHP's production `php.ini`. Keep it `On` so that values such as database credentials are not sent.
- Events are sent synchronously: when Sentry is slow to respond, the error page is delayed by the same amount.

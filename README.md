# Sentry for PrestaShop

PrestaShop module that reports PHP errors and exceptions to [Sentry](https://sentry.io) with the official PHP SDK (`sentry/sentry` 3.x). It is a fork of the `extsentry` module by eXtalion.

## Requirements

- PrestaShop 1.7.8 or later
- PHP 7.4 or later
- Docker Compose, Make, `rsync` and `zip` on the machine that builds the archive from source
- Write access for the web server user to `config/`, where the module edits `defines_custom.inc.php` and writes its settings, and to `override/`

## Build the archive

The repository does not include the `vendor/` directory, so the module has to be built before it is installed. The clone directory must be named `extsentry`, as the `Makefile` relies on it.

```bash
git clone https://github.com/Roxayl/prestashop-sentry.git extsentry
cd extsentry
make release
```

`make release` installs the production dependencies, copies the module into a clean `extsentry/` directory, adds the `index.php` files PrestaShop expects, and produces `extsentry.zip`.

## Development

The development environment requires Docker Compose and Make. It starts PrestaShop, MySQL and Adminer, mounts the repository as the `extsentry` module and installs its Composer dependencies automatically.

Copy the default environment configuration, start the containers and install the module:

```bash
cp .env.dist .env
make up
make console ARGS="prestashop:module install extsentry"
```

PrestaShop is available at [http://localhost](http://localhost), its back office at [http://localhost/admin-dev](http://localhost/admin-dev), and Adminer at [http://localhost:8080](http://localhost:8080). The default back-office credentials are `admin@prestashop.com` / `prestashop`. To connect through Adminer, use `db` as the server and `prestashop` as the database, username and password.

PrestaShop 1.7.8 is used by default. Pass another official image tag through `PS` to work with a different version:

```bash
make PS=8.1.7 up
make PS=8.1.7 console ARGS="prestashop:module install extsentry"
```

Each version has an independent Compose project, database volume and installation under `.prestashop/<version>`. Settings can be defined globally in `.env` and `.env.local`, or per version in `.env.<version>` and `.env.<version>.local`; later files override earlier ones. When running versions at the same time, assign different `PS_HTTP_PORT` and `ADMINER_PORT` values in their version-specific environment files.

The available development targets are:

| Target | Description |
|---|---|
| `make help` | Show the available Make targets. |
| `make build` | Build or rebuild the PrestaShop image. |
| `make up` | Build when necessary and start the environment in the background. |
| `make down` | Stop and remove the containers, preserving the database and installation. |
| `make down-hard` | Remove the containers, database volume and local PrestaShop installation. |
| `make logs` | Follow the container logs. |
| `make ps` | Show the container status. |
| `make shell` | Open a shell as `www-data` in the module directory. |
| `make console ARGS="<command>"` | Run a Symfony console command in the PrestaShop container. |
| `make phpcs` | Run PHP_CodeSniffer. |
| `make phpcs-fix` | Run PHP Code Beautifier and Fixer. |
| `make php-cs-fixer` | Run PHP CS Fixer. |
| `make test` | Install the test dependencies and run PHPUnit. |
| `make composer ARGS="<command>"` | Run a Composer command. |
| `make composer-dev` | Install the module development dependencies. |
| `make composer-prod` | Install the module production dependencies. |
| `make autoindex` | Add the `index.php` files expected by PrestaShop. |

All targets accept `PS=<version>`. For the container lifecycle targets (`build`, `up`, `down`, `down-hard`, `logs` and `ps`), `ARGS` contains extra Docker Compose arguments. For `console`, QA, test and Composer targets, it contains arguments for the command being run. For example:

```bash
make logs ARGS="prestashop"
make test ARGS="--filter ConfigurationTest"
make composer ARGS="update sentry/sentry --with-dependencies"
```

## Tests

The unit tests do not boot a PrestaShop installation. Make installs their dependencies and runs PHPUnit inside a temporary container, so PHP and Composer are not required on the host:

```bash
make test
make test ARGS="--filter ConfigurationTest"
make PS=8.1.7 test
```

`make test` installs the test dependencies in `tests/vendor/` and runs PHPUnit using the selected PrestaShop image. `tests/composer.json` is a separate Composer project: besides PHPUnit, it provides the packages that PrestaShop supplies to the module at runtime, such as the PSR interfaces, Guzzle promises and Symfony 4.4 components. The module's `composer.json` replaces them, so they cannot be installed as its development dependencies.

These Symfony 4.4 versions are affected by security advisories, and recent Composer versions refuse to install them. `tests/composer.json` accepts them because they only serve the tests, and `make release` leaves `tests/` out of the archive.

## Install

From the back office, go to **Modules > Module Manager**, click **Upload a module** and select `extsentry.zip`.

From the command line, copy the built `extsentry/` directory into `modules/`, then run:

```bash
php bin/console prestashop:module install extsentry
```

Installation creates the `<prefix>extsentry_configuration` table, installs the `PrestaShopException`, `DbPDO` and `Hook` overrides and adds the Sentry bootstrap to `config/defines_custom.inc.php`. Nothing is sent to Sentry until a DSN is configured.

## Configure

In **Modules > Module Manager**, click **Configure** on the Sentry module.

| Field | Description |
|---|---|
| DSN | Required. The client key of your Sentry project (**Settings > Client Keys**). |
| Error types | PHP error types to report, written with `E_*` constants and the `\|`, `&`, `^` and `~` operators, for example `E_ERROR \| E_PARSE \| E_CORE_ERROR \| E_COMPILE_ERROR \| E_USER_ERROR \| E_RECOVERABLE_ERROR \| E_WARNING`. When empty, the SDK follows `error_reporting()`. |
| Sample rate | Share of error events sent, from `0` to `1`. Empty sends every error. |
| Traces sample rate | Share of HTTP requests traced for performance monitoring, from `0` to `1`. Empty or `0` disables tracing. See [Performance monitoring](#performance-monitoring). |
| Server name | Name attached to every event. |
| Environment | `production` or `development`. |

Saving stores the settings in the database and in `config/extsentry.yml` (mode `0600`), which is read on every request. On Apache, the `.htaccess` that PrestaShop ships in `config/` denies web access to this directory. On nginx, make sure your server configuration denies it too:

```nginx
location ^~ /config/ {
    deny all;
}
```

## How it works

- The module adds a block between `###> extsentry ###` markers to `config/defines_custom.inc.php`, which PrestaShop includes at the very start of every request: front office, back office and command line. The block loads the PrestaShop autoloader, then the module's, initializes the SDK, which registers its error, exception and fatal error handlers, and starts the request transaction when tracing is enabled.
- PrestaShop catches some exceptions itself and renders its error page (`error500.html` in production) through `PrestaShopException::displayMessage()`, which exits before any global handler runs. The module overrides this method to report the exception first.
- HTTP compression is disabled in the SDK: the compression streams of `php-http/message` are incompatible with the `psr/http-message` 1.0 interfaces bundled with PrestaShop 8.
- The SDK does not attach the list of Composer packages to events (`ModulesIntegration`): it would list the module's packages, not the shop's.

## Performance monitoring

When **Traces sample rate** is set, the module sends a Sentry transaction for that share of HTTP requests. Command-line runs are never traced.

- The transaction starts with the request and is sent at the end of the script. It is named after the Symfony route on Symfony back-office pages, after the controller class elsewhere (`GET ProductController`, `POST CartController (ajax)`), and after the script when no controller ran.
- A span is created for every hook call and SQL query that lasts 1 ms or more, nested as they ran. Faster ones only count in the transaction data: `db.query_count`, `db.duration_ms`, `db.error_count`, `hooks.count`, and `hooks.top` and `db.top`, the 15 hooks and query shapes with the highest total time. A PrestaShop page often runs thousands of sub-millisecond queries: the totals show them where individual spans would not.
- SQL is sent without its values: quoted strings and numbers are replaced with `?`.
- Transactions are sent without the request body and query string, which may hold customer data or tokens.
- Incoming `sentry-trace` and `baggage` headers are ignored, so that a client cannot force its requests to be traced.

Hooks and SQL queries are measured through overrides of `Hook::coreCallHook()` and `DbPDO::_query()`. When another module already overrides one of them, the module still installs, without that measurement, and writes a warning in **Advanced Parameters > Logs**.

Not measured: hooks rendered as widgets (`renderWidget()`), SQL queries that do not go through `Db` (Doctrine in Symfony pages), and outgoing HTTP calls.

### Performance impact

On a non-traced request, the overrides add about 30 ns per SQL query. A traced request adds a few milliseconds of CPU time, plus the time Sentry takes to receive the transaction, since the SDK sends it synchronously: the visitor waits for that round trip. Start with a low rate, such as `0.02`.

## Upgrade

Upload the new archive from **Module Manager**: PrestaShop runs the upgrade scripts. From 0.1.0, the 0.2.0 script installs the `PrestaShopException` override when the module is enabled on at least one shop. If it fails, a warning is written in **Advanced Parameters > Logs**; disable then enable the module to install the override. The 0.3.0 script moves the settings from `modules/extsentry/sentry.json` to `config/extsentry.yml` and installs the `DbPDO` and `Hook` overrides used by tracing. Tracing stays disabled until **Traces sample rate** is set.

## Disable and uninstall

Disabling the module removes the block from `config/defines_custom.inc.php` and the overrides, so nothing is reported anymore. Uninstalling does the same and keeps the configuration table and `config/extsentry.yml`.

## Limitations

- An exception caught by custom code that does not rethrow it, such as a `try`/`catch` in `index.php` that renders an error page, is not reported. Call `\Sentry\captureException($exception)` in that `catch` block.
- An exception thrown from an object destructor at the end of the request is not reported: PHP runs shutdown functions, including the SDK's fatal error handler, before destructors. It only appears in the PHP error log.
- Stack traces include function arguments unless `zend.exception_ignore_args` is `On`, which is the value of PHP's production `php.ini`. Keep it `On` so that values such as database credentials are not sent.
- Events are sent synchronously: when Sentry is slow to respond, the error page is delayed by the same amount.

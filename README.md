# PactTrace Shared Resources

Namespace: `PactTraceSDK\SharedResources\` → `src/`

```
src/
  Modules/     # feature modules (empty — scaffold with `php artisan module:create`)
  SDK/         # framework layer: console generators, repository layer, exceptions, stubs
  TestCase/    # Testbench base test, DB snapshot command, RefreshDatabase trait
  SharedResourceServiceProvider.php
```

Mounted into the backend container at `/var/www/shared-resources` and consumed by
the Laravel app as a path repository.

## Adding a module

Scaffold under `src/Modules/<Name>`, then register its provider in
`SharedResourceServiceProvider::$providers`. `loadModules()` auto-wires each
module's `routes/`, `Database/Migrations`, `resources/views`, `config/`, and
`resources/lang`.

## Test database snapshot

Tests restore from a MySQL dump instead of re-running migrations. Create it once:

```shell
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec backend php artisan testdb:snapshot
```

Or dump directly:

```shell
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec -T mysql \
  sh -lc "MYSQL_PWD='root' mysqldump -u 'root' app_db --single-transaction --routines --triggers --events --no-tablespaces --set-gtid-purged=OFF" \
  > shared-resources/src/TestCase/sqldumps/pacttrace.mysql.sql
```

## Run tests

Run every module test class one class at a time:

```shell
composer test
```

Or:

```shell
./bin/phpunit-by-class
```

Run a specific class through PHPUnit:

```shell
./vendor/bin/phpunit --filter=SomeTest
```

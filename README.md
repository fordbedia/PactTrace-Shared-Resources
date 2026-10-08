# PactTrack Shared Resources

Namespace: `PactTrackSDK\SharedResources\` → `src/`

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
  > shared-resources/src/TestCase/sqldumps/pacttrack.mysql.sql
```

## Run tests

Tests run inside the `backend` container from `/var/www/shared-resources`
(MySQL host `mysql`, database `pacttrack_test`). The easiest way is the
repo-root helper, run from the host:

```shell
./test.sh                                        # whole suite
./test.sh src/Modules/Signature                  # one module
./test.sh --filter=EnvelopeDetailControllerTest  # one class / method
ISOLATED=1 ./test.sh                             # one PHPUnit process per class
```

`./test.sh` keeps the Mac awake (`caffeinate`), tees output to
`test-run.log` at the repo root, and exits with PHPUnit's exit code.

Inside the container:

```shell
composer test                              # whole suite, single PHPUnit process
composer test -- src/Modules/Signature     # args after -- go to PHPUnit
composer test -- --filter=SomeTest
composer test:isolated                     # one process per test class (bin/phpunit-by-class)
./vendor/bin/phpunit --filter=SomeTest     # plain PHPUnit works too
```

The snapshot dump (`src/TestCase/sqldumps/pacttrack.mysql.sql`) is restored
once per PHP process; each test then runs in a transaction that is rolled
back, so the database is clean after every test and after the run. Set
`PACTTRACK_TEST_RESTORE_EACH_TEST=1` in the shell to restore before every test
instead (slow, for chasing state leaks). A test running longer than 60s is
aborted and fails the run. See the top-level `CLAUDE.md`, "Unit testing", for
the rules this imposes on tests (no DDL, no second DB connection, no
hard-coded auto-increment ids).
### Running Reconcile Stale Envelopes:
```shell
docker compose -f docker-compose.yml -f                    
  docker-compose.dev.yml exec backend php artisan            
  signature:reconcile-stale-envelopes -v
```
```shell
docker compose -f docker-compose.yml -f                    
  docker-compose.dev.yml exec backend php artisan            
  signature:reconcile-stale-envelopes --now -v 
```
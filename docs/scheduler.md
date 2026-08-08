# Scheduler

How to verify and manually test scheduled jobs in the local Docker environment.

The `scheduler` service runs `php artisan schedule:run` in a loop (once a minute)
and the `queue-worker` service drains the `default` queue. Scheduled commands
should dispatch jobs rather than doing slow work inline.

Register schedules in `backend/routes/console.php`:

```php
Schedule::command('some-command:run')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
```

The application timezone is UTC in Docker, so use UTC when setting test
timestamps.

## Check the schedule

```shell
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec backend \
  php artisan schedule:list
```

## Run the scheduler once, by hand

```shell
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec backend \
  php artisan schedule:run --no-interaction
```

## Watch the worker

```shell
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f queue-worker
```

## Run a single queued job synchronously

```shell
docker compose -f docker-compose.yml -f docker-compose.dev.yml exec backend \
  php artisan queue:work --once --queue=default
```

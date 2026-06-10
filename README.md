# Hirale Queue

A queue module for **OpenMage and Maho**, built on Symfony Messenger. Backend-agnostic by design: Redis Streams ships in v3.0, with Doctrine / AMQP / SQS as drop-in transports later.

## Which version do I need?

| Your platform | Package | Constraint |
| --- | --- | --- |
| **Maho** 26.5+ | `hirale/queue` | `^3.0` |
| **OpenMage** 20.17+ (PHP 8.3+) | `hirale/queue` | `^3.0` |
| Older OpenMage / legacy installs | `hirale/openmage-redis-queue` | `^1.0` (frozen, fixes only) |

One codebase serves both platforms: the module uses OpenMage-era class
names that Maho aliases natively, and the only platform difference you
will notice is the worker entry point (Maho CLI vs `shell/` scripts).

> v3 was previously published as `hirale/openmage-redis-queue`; the rename
> to `hirale/queue` reflects the rewrite. Upgrading a v1 install is a
> breaking change — see *Migrating from v1.x* below.

## What's new in v3

- **Symfony Messenger as the bus.** v2's custom `Worker` / `Backoff` / `DeadLetter` / `HandlerRegistry` / `QueueRouter` are gone. v3 plugs into Messenger's middleware pipeline, retry strategy interface, and transport abstraction.
- **Backend-agnostic.** Admin picks Redis / Doctrine / AMQP / SQS from a dropdown; the module assembles the DSN. v3.0 ships Redis; the other transports are wired in v3.x as `composer require symfony/<backend>-messenger` adds the needed factory.
- **Dual-platform.** One codebase for OpenMage 20.17+ and Maho 26.5+ (PHP 8.3+); composer conflicts reject older platforms with a clear error.
- **Producer API is typed.** Downstream code dispatches typed message classes:
  ```php
  use Hirale\Queue\Bus;
  Bus::dispatch(new DrainEventsMessage(reason: 'index_events'));
  ```
  Handlers implement `__invoke(MessageClass $message): void`.
- **Per-store retry policy.** Captured at dispatch from the dispatching store's config and travels with the envelope so a worker serving many stores doesn't have to look up live config per message.
- **Save-time validation.** Saving a bad backend config refuses to persist — connection is probed in the admin form's predispatch event and a clear error banner appears.

## Requirements

- Maho 26.5+ **or** OpenMage 20.17+
- PHP 8.3+
- ext-json, ext-redis (for the Redis backend)
- A Redis server reachable from the workers (TCP or Unix socket)

## Install

```bash
composer require hirale/queue
```

Create the four queue tables (`hirale_queue_job`, `hirale_queue_job_event`, `hirale_queue_job_archive`, `hirale_queue_audit`):

- **Maho**: `./maho migrate`
- **OpenMage**: setup scripts run on the next request, as usual

## Configure

Open **System → Configuration → Hirale → Queue** in admin.

| Section | Scope | What it controls |
| --- | --- | --- |
| Backend | Global | Backend picker (Redis / Doctrine / AMQP / SQS) plus per-backend connection fields (host, port, password, socket path, etc.). |
| Queues | Global | Comma-separated list of logical queue names. The `default` queue is always present. |
| Operational | Per-website | Retry policy (`retry_max_attempts`, `retry_backoff_base_seconds`, `retry_backoff_cap_seconds`), payload size cap, redacted fields, audit toggle. |
| Retention | Per-website | Days to keep successful / failed / archived jobs; nightly archive batch size. |

Backend connection is global (infrastructure does not vary by store). Operational and retention values can be overridden per-website using the native admin scope selector.

When you click **Save Config**, the module assembles the DSN and tries to connect to the backend. If the probe fails, the form refuses to persist and shows the error — your previous values stay intact.

## Producer API

Downstream modules dispatch typed messages:

```php
<?php
use Hirale\Queue\Bus;

class Hirale_AsyncIndex_Helper_Data extends Mage_Core_Helper_Abstract
{
    public function scheduleDrain(string $reason): void
    {
        Bus::dispatch(new Hirale_AsyncIndex_Message_DrainEventsMessage(
            reason: $reason,
            entity: 'catalog_product',
        ));
    }
}
```

Variants:

```php
// Route this dispatch to a non-default queue.
Bus::dispatchOnQueue($message, 'full_reindex');

// Delay the first attempt by N seconds.
Bus::dispatchDelayed($message, 60);
```

The current store context is captured automatically. Don't pass store_id in the message — Bus attaches a `StoreScopeStamp` for you, and the per-store retry policy is read at dispatch time.

## Handler registration

In your downstream module's `etc/config.xml`, under `<global>`:

```xml
<hirale_queue>
    <handlers>
        <Hirale_AsyncIndex_Message_DrainEventsMessage>hirale_asyncindex/drainEventsHandler</Hirale_AsyncIndex_Message_DrainEventsMessage>
        <Hirale_AsyncIndex_Message_FullReindexBatchMessage>hirale_asyncindex/fullReindexBatchHandler</Hirale_AsyncIndex_Message_FullReindexBatchMessage>
    </handlers>
    <routing>
        <Hirale_AsyncIndex_Message_FullReindexBatchMessage>full_reindex</Hirale_AsyncIndex_Message_FullReindexBatchMessage>
    </routing>
</hirale_queue>
```

The element name is the MessageClass FQCN (use underscored class names — XML element names cannot contain backslashes, so PSR-4 namespaced messages are not supported on the routing side; declare them as legacy `Vendor_Module_*` classes).

Handler:

```php
<?php

class Hirale_AsyncIndex_Model_DrainEventsHandler
{
    public function __invoke(Hirale_AsyncIndex_Message_DrainEventsMessage $message): void
    {
        // Do the work. Throwing reschedules per the retry policy.
        Mage::getModel('hirale_asyncindex/runner')->drain($message->reason);
    }
}
```

Unmapped messages route to the `default` queue.

## Consumer

Long-running worker — Maho:

```bash
./maho hirale:queue:consume default
```

OpenMage (same engine, shell entry point):

```bash
php shell/hirale_queue_worker.php --queues default
```

With options (Maho shown; the shell worker takes the same options as `--queues a,b --time-limit ... --limit ... --sleep ... --memory-limit ...`):

```bash
./maho hirale:queue:consume default full_reindex \
    --time-limit=3600 \
    --limit=10000 \
    --sleep=1 \
    --memory-limit=512 \
    --consumer=hirale_worker_01
```

Run under systemd or Supervisor so the process restarts after `--time-limit` or `--limit`.

### systemd example

```ini
[Unit]
Description=Hirale Queue worker %i
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/maho
User=www-data
ExecStart=/usr/bin/php ./maho hirale:queue:consume default --consumer=hirale_worker_%i --time-limit=3600 --limit=10000
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

### Cron / Ofelia example

Without systemd, run the worker from cron with `flock` (or Ofelia's `no-overlap`) guaranteeing a single instance; the worker exits after `--time-limit` and is relaunched on the next tick:

```cron
* * * * * www-data flock -n /var/lock/hirale-queue.lock php /var/www/maho/maho hirale:queue:consume default --time-limit=3540 --memory-limit=256 >> /var/www/maho/var/log/hirale_queue_worker.log 2>&1
```

```ini
[job-exec "maho-queue-worker"]
schedule = @every 1m
container = php-fpm
command = php /var/www/maho/maho hirale:queue:consume default --time-limit=3540 --memory-limit=256
user = www-data
tty = false
no-overlap = true
```

Maho's own cron (`./maho cron:run default` / `always`) must also be scheduled — it runs the nightly `hirale_queue_archive` job (archive move + retention purges, including the event and audit tables).

## CLI commands

| Command | Purpose |
| --- | --- |
| `hirale:queue:test [--queue=] [--timeout=]` | End-to-end self-test: dispatches a built-in ping, consumes it inline, asserts the job reached `succeeded`. Run this first on a fresh install. |
| `hirale:queue:consume [<queue>...]` | Run a worker against the listed queues. |
| `hirale:queue:stats [--format=text\|json]` | Per-status totals and per-queue depth. JSON output suits the Prometheus textfile collector. |
| `hirale:queue:health [--max-age=N]` | Liveness probe: pings the backend and fails if any active job is older than `--max-age` seconds (default 300). Exit 0 healthy, 1 unhealthy. |
| `hirale:queue:retry-failed [--queue=] [--since=] [--limit=]` | Bulk re-dispatch of `failed` jobs from the DB (`--since` accepts strtotime syntax, e.g. `"-2 hours"`). Each retry is a fresh dispatch; the old row is marked superseded so reruns never double-dispatch. |

## Admin operations

**System → Tools → Hirale Queue** shows:

- Status tiles per state (queued, running, retry_wait, succeeded, failed, canceled).
- A grid of recent jobs with `job_id`, message class, queue, status, attempt counters, last error excerpt, timestamps.
- Page-level buttons: **Test Connection** (probes the backend), **Purge Finished** (applies retention to the archive).
- Per-row actions: **View**, **Retry** (reconstructs the message and re-dispatches as a new job; a failed source row is marked superseded), **Cancel** (cooperative for running jobs; immediate for queued / retry_wait).
- Clicking a row opens the **job detail page**: full field list, the payload pretty-printed with configured fields redacted, metadata, and the complete state-transition timeline from `hirale_queue_job_event`.

A **Test Connection** button also sits below the backend fields in *System → Configuration → Hirale → Queue* — it probes the values currently entered in the form, before saving.

All admin actions are recorded in `hirale_queue_audit` when audit logging is enabled; audit rows are purged by the nightly cron after `audit_retention_days` (default 90).

## Migrating from v1.x

v3 intentionally breaks API compatibility with the v1 line
(`hirale/openmage-redis-queue ^1.0`):

- v1.x producer: `Mage::getModel('hirale_queue/task')->addTask($handler, $data, ...)`
- v3 producer: `\Hirale\Queue\Bus::dispatch(new YourMessage(...))`

The handler interface, payload format, DB schema, and config paths all
changed. Upgrading an existing OpenMage install:

1. `composer remove hirale/openmage-redis-queue && composer require hirale/queue`
2. Setup scripts create the v3 tables automatically (v1 tables are left
   untouched; drop them once you no longer need the history).
3. Reconfigure the backend once under **System → Configuration → Hirale →
   Queue** (config paths changed; save-time validation checks the connection).
4. Migrate every producer and handler to typed messages and `__invoke`
   handlers (see *Producer API* and *Handler registration* above), or update
   the consuming modules to their v3-compatible releases.
5. Run the self-test: `php shell/hirale_queue_test.php`.

## Development

```bash
composer install
composer test       # unit tests (pure PHP, no platform bootstrap)
composer phpstan
composer cs-check
```

The unit suite covers DSN assembly per backend, message routing (SendersLocator), retry strategy semantics (including the unrecoverable/recoverable exception markers), message reconstruction, and stamp construction. For a live end-to-end check against a real install, use `./maho hirale:queue:test`.

## License

Open Software License v. 3.0 (OSL-3.0). See [LICENSE.md](LICENSE.md).

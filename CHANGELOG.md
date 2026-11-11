# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-11-11

### Added
- Consumer side for the broker transports: `RabbitMqTransport::receive(AMQPMessage)` and `RedisStreamsTransport::receive($stream, $fields)` return a `ReceivedMessage` (CloudEvents attributes, headers, body, decoded payload); messages without the attributes are refused with `UnexpectedValueException`.

### Changed
- Kafka transport and the `docker compose` demo moved to the 0.3.0 roadmap.

## [0.1.0] - 2026-11-11

### Added
- `Outbox::publish()` / `publishEvent()` (`ShouldBeOutboxed`): messages stored in the business transaction; `NotInTransaction` outside one in strict mode.
- Per-aggregate sequence numbers from an `outbox_aggregates` counter, assigned in commit order (PostgreSQL / SQLite upsert with `RETURNING`, MySQL `LAST_INSERT_ID(expr)`).
- `outbox:relay`: `FOR UPDATE SKIP LOCKED` claims, leases with an owner token, compare-and-set when marking published, takeover of expired leases; graceful stop on SIGTERM / SIGINT; `--once` for cron.
- Strict order within an aggregate with parallel relay workers; a failed message blocks only its aggregate; `outbox.ordering = none` to turn it off.
- Retries with exponential backoff and jitter, dead letter after `max_attempts` (also for leases that expire on the last attempt), `outbox:retry`, `outbox:prune`.
- CloudEvents 1.0 binary mode with the payload bytes unchanged; transports: RabbitMQ (publisher confirms, mandatory routing), Redis Streams (phpredis and predis), Laravel queue (`DeliverOutboxMessage` + type handlers), log, null; `TransportManager::extend()` for custom ones.
- Inbox: `Inbox::handle(consumer, messageId, handler)` in one transaction with the handler, `DeduplicateWithInbox` job middleware, `inbox:prune`.
- `Outbox::fake()` with `assertPublished()`, `assertPublishedTimes()`, `assertNotPublished()`, `assertNothingPublished()`.
- Events: `MessageStored` (after commit), `MessagePublished`, `MessagePublishFailed`, `MessageFailed`, `AggregateBlocked`, `LeaseLost`, `InboxDuplicateSkipped`, `UnhandledOutboxMessage`.
- Support for PHP 8.2–8.5 and Laravel 12–13; MySQL 8, PostgreSQL; SQLite for tests.

### Known limitations
- At-least-once delivery; consumers must be idempotent (use the inbox).
- Publishing several aggregates in one transaction in different orders can deadlock.

### Planned (now 0.3.0)
- Kafka transport (ext-rdkafka, key = aggregate id).
- `docker compose` demo: MySQL + RabbitMQ + relay + a consumer with the inbox.

[Unreleased]: https://github.com/azhimov/laravel-outbox/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/azhimov/laravel-outbox/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/azhimov/laravel-outbox/releases/tag/v0.1.0

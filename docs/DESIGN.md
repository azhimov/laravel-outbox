# laravel-outbox — спецификация v0.1

`composer require azhimov/laravel-outbox`

## Проблема
Сервис сохраняет заказ в БД и публикует событие `order.paid` в брокер. Если упасть между этими шагами, будет либо заказ без события, либо событие без заказа (dual write). Transactional outbox решает это так: событие пишется в таблицу `outbox_messages` **в той же транзакции**, что и бизнес-изменение, а отдельный relay доставляет его в брокер с гарантией **at-least-once**. На стороне получателя inbox убирает дубли, и в итоге получается effectively-once.

## Аналоги (предварительно, 11.11.2026 — перепроверить на Packagist перед стартом)
| Пакет | Что известно |
|---|---|
| ludovicose/transaction-outbox | ~21k загрузок, pub-sub, последний релиз 2024 |
| webrek/laravel-outbox | relay с retries и backoff |
| dnakitare/laravel-outbox | события и jobs, row-level locks, dead-letter; beta |
| gpalyan/laravel-outbox | outbox + inbox, broker-agnostic, PHP 8.4+ |
| khandroider/laravel-eventbus | outbox + idempotent inbox поверх RabbitMQ |
| sumantasam1990/phpoutbox | Laravel + Symfony + vanilla PHP |
| bugfix666, mohamedhekal, urfysoft, egensolve | небольшие пакеты |

Явного лидера нет, все маленькие. Сами по себе outbox, retries, backoff, inbox и dead-letter **не** являются отличиями. Отличаемся качеством гарантий и тем, насколько они проверены:
1. **Упорядоченность по агрегату.** Сообщения одного `aggregate_id` уходят строго по порядку даже при нескольких воркерах relay, а разные агрегаты обрабатываются параллельно.
2. **Безопасный параллельный relay.** `FOR UPDATE SKIP LOCKED` (MySQL 8 / PostgreSQL) и lease с owner-token. Упавший воркер не держит сообщения вечно, сообщение не публикуется дважды одновременно.
3. **Inbox с той же строгостью, что laravel-idempotency.** Дедупликация по `message_id` в транзакции обработчика: отметка «обработано» и бизнес-изменение коммитятся вместе.
4. **Транспорты проверены на настоящих брокерах в CI**: RabbitMQ и Redis Streams как service containers, Kafka опционально. Плюс транспорт «Laravel queue» для тех, у кого брокера нет.
5. **Связка с laravel-idempotency и payments-outbox**: один и тот же сценарий платежа end-to-end.

## API
```php
DB::transaction(function () use ($order) {
    $order->markPaid();

    Outbox::publish(
        topic: 'orders',
        type: 'order.paid',
        payload: ['order_id' => $order->id, 'amount' => $order->amount],
        aggregateId: (string) $order->id,     // упорядоченность внутри агрегата
        headers: ['tenant' => 'demo'],         // опционально
    );
});
```
- `Outbox::publish()` вне транзакции на подключении outbox → **исключение** `NotInTransaction` (настраивается: `strict` / `allow`). Иначе гарантия молча теряется.
- Возвращает `MessageId` (UUIDv7, сортируется по времени).
- Хелпер `Outbox::publishEvent(object $event)` для классов, реализующих `ShouldBeOutboxed` (`topic()`, `type()`, `payload()`, `aggregateId()`).

## Хранение
Таблица `outbox_messages` (миграция публикуется):
`id` (UUIDv7, PK), `topic`, `type`, `aggregate_id` (nullable, индекс), `payload` (JSON), `headers` (JSON), `status` (`pending|processing|published|failed`), `attempts`, `available_at` (для backoff), `lease_owner`, `lease_until`, `last_error`, `created_at`, `published_at`. Индекс `(status, available_at)`.

## Relay
- `php artisan outbox:relay` — долгоживущий воркер: batch, sleep, graceful shutdown по SIGTERM, `--once` для cron и тестов.
- Захват batch: `SELECT … WHERE status='pending' AND available_at<=now ORDER BY id FOR UPDATE SKIP LOCKED LIMIT n`, затем ставим lease (`processing`, `lease_owner`, `lease_until`) и коммитим. Публикация идёт уже вне транзакции.
- Упорядоченность: из агрегата берётся только самое раннее непубликованное сообщение. Следующее ждёт, пока предыдущее не станет `published`. Если предыдущее ушло в `failed`, агрегат блокируется и срабатывает событие `AggregateBlocked`, пропускать нельзя.
- Успех: `published` + `published_at` через compare-and-set по `lease_owner`.
- Ошибка: `attempts++`, экспоненциальный backoff с jitter, после `max_attempts` — `failed` (dead-letter), событие `MessageFailed`.
- Истёкший lease забирает другой воркер. Поэтому доставка at-least-once, и это написано в README честно.
- `outbox:retry {id|--all-failed}`, `outbox:prune --published-older-than=7d`.
- SQLite поддерживается только для тестов, один воркер. В README это предупреждение.

## Транспорты
Контракт `Transport::publish(OutboxMessage $m): void` бросает исключение при неудаче.
- `rabbitmq` (php-amqplib, publisher confirms, persistent delivery mode, `message_id` в свойствах)
- `redis` (Redis Streams, `XADD`, `message_id` в полях)
- `kafka` (опционально, ext-rdkafka, ключ = aggregate_id) — в `suggest`
- `queue` (dispatch Laravel job, без внешнего брокера)
- `log` и `null` для разработки
Зависимости брокеров — в `suggest`, не в `require`.

## Inbox
```php
Inbox::handle($messageId, function () { /* бизнес-изменение */ });
```
- В одной транзакции: `insertOrIgnore` в `inbox_messages (message_id PK, consumer, processed_at)`, потом обработчик. Если строка уже есть, обработчик не вызывается, возвращается `AlreadyProcessed`.
- Scope дедупликации: `consumer` + `message_id`, чтобы разные потребители одного сообщения не мешали друг другу.
- `inbox:prune`.

## Конфиг `config/outbox.php`
`connection`, `table`, `inbox_table`, `transport` (+ настройки каждого), `batch_size`, `sleep_ms`, `lease_seconds`, `max_attempts`, `backoff` (base, multiplier, max, jitter), `ordering` (`aggregate` | `none`), `strict_transaction` (bool), env-переменные для основных ключей.

## События
`MessageStored`, `MessagePublished`, `MessagePublishFailed` (каждая попытка), `MessageFailed` (dead-letter), `AggregateBlocked`, `LeaseLost`, `InboxDuplicateSkipped`.

## Тесты
- Атомарность: rollback бизнес-транзакции — сообщения нет; commit — есть.
- `publish()` вне транзакции в strict-режиме → исключение.
- Relay: публикует, ставит `published`; ошибка транспорта → backoff и `attempts`; после max — `failed`.
- Два воркера параллельно (два подключения к MySQL и к PostgreSQL): ни одно сообщение не опубликовано дважды одновременно, порядок внутри агрегата сохранён.
- Истёкший lease забирается; запоздавший воркер не может отметить чужое сообщение (CAS).
- Упорядоченность: failed-сообщение блокирует агрегат, другие агрегаты идут дальше.
- Inbox: дубль не вызывает обработчик; исключение в обработчике откатывает отметку.
- Контрактный набор для транспортов; интеграционные тесты RabbitMQ и Redis на настоящих брокерах в CI.
- Команды retry, prune, graceful shutdown (`--once`, SIGTERM).

## CI
Матрица PHP 8.2–8.5 × Laravel 12–13 (+ prefer-lowest), service containers MySQL 8.4, PostgreSQL 17, RabbitMQ 4, Redis 7. Coverage pcov `--min=90`, Larastan max, Pint.

## Порядок шагов (один шаг — один коммит)
1. Скелет (по образцу laravel-idempotency).
2. CI.
3. `OutboxMessage` DTO, `MessageId` (UUIDv7), миграция.
4. `Outbox::publish()` + strict-транзакция.
5. Транспорт-контракт + `log` / `null` / `queue` + контрактный набор тестов.
6. Relay: захват batch с SKIP LOCKED и lease, публикация, CAS.
7. Retries, backoff, dead-letter, команды retry / prune.
8. Упорядоченность по агрегату.
9. Транспорт RabbitMQ + интеграционные тесты.
10. Транспорт Redis Streams + интеграционные тесты.
11. Inbox.
12. События.
13. Kafka (опционально; если не успеваем — в roadmap v0.2).
14. README (Mermaid: dual-write проблема, поток outbox → relay → broker → inbox, конкурирующие воркеры), CHANGELOG, честное сравнение, Limitations.

## Definition of Done v0.1.0
Чеклист готовности репозитория, зелёный CI на всех БД и брокерах, README с quick start через `docker compose` (MySQL + RabbitMQ + relay), честные Limitations: at-least-once, SQLite только для тестов, поведение при `failed` в упорядоченном агрегате.

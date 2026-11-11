# laravel-outbox — спецификация v0.1

`composer require azhimov/laravel-outbox`

## Проблема
Сервис сохраняет заказ в БД и публикует событие `order.paid` в брокер. Если упасть между этими шагами, будет либо заказ без события, либо событие без заказа (dual write). Transactional outbox решает это так: событие пишется в таблицу `outbox_messages` **в той же транзакции**, что и бизнес-изменение, а отдельный relay доставляет его в брокер с гарантией **at-least-once**. На стороне получателя inbox убирает дубли, и в итоге получается effectively-once.

## Аналоги (проверено по README и исходникам, 11.11.2026)
| Пакет | Загрузки | Параллельный relay | Порядок по агрегату | Inbox в транзакции обработчика | Брокеры в CI |
|---|---|---|---|---|---|
| ecotone/laravel + ecotone/dbal | ~355k | `SKIP LOCKED` + reservation | только в event-sourced потоке и через ключи Kafka | да (message_id + consumer endpoint) | да: RabbitMQ, Kafka, SQS, Redis |
| eventsauce/message-outbox-for-illuminate | ~36k | нет, один relay | глобальный FIFO одного relay, сбой блокирует всё | нет inbox | нет CI |
| ianfoxdev/outbox + ianfoxdev/inbox | единицы | один активный relay (`pg_try_advisory_lock`), relay на Go | да, но с одним relay (ADR: параллельные воркеры ломают порядок) | да (consumer + key) | RabbitMQ и Kafka для Go-relay |
| mykholy/laravel-flowguard | единицы | `SKIP LOCKED` + lease-токен (sha256) + fenced ack | нет | webhook-inbox, scope tenant + event id | только MySQL/PG |
| rabbitevents/outbox | единицы | `SKIP LOCKED`, блокировка на время отправки | coalescing по ключу объекта | нет (дедуп через кэш) | MySQL, PG, RabbitMQ |
| khandroider, dnakitare, webrek, sumantasam1990, gpalyan, ludovicose, kafka/workflow | ≤22k | от «нет» до `SKIP LOCKED` без reclaim | нет | нет | нет |

Лидер по загрузкам — Ecotone, но это большой CQRS-фреймворк, outbox в нём — часть messaging-слоя. Среди отдельных Laravel-пакетов явного лидера нет.

**Не являются отличиями** (уже есть у других): outbox, retries/backoff/dead-letter, `SKIP LOCKED` (Ecotone, flowguard, rabbitevents и др.), lease с owner-token и fencing (flowguard), inbox в транзакции обработчика со scope consumer + message_id (ianfoxdev/inbox, Ecotone), RabbitMQ в CI (Ecotone, rabbitevents, ianfoxdev), связка outbox + idempotency как идея (webrek, flowguard).

**Отличия, которые реально проверяемы:**
1. **Строгий порядок внутри агрегата при нескольких параллельных воркерах relay.** У ianfoxdev порядок держится одним активным relay, у Ecotone — партициями Kafka. Здесь воркеры захватывают «голову» агрегата, разные агрегаты идут параллельно, failed-сообщение блокирует только свой агрегат. Доказывается тестами с конкурирующими воркерами на MySQL и PostgreSQL.
2. **Всё в одном Laravel-нативном пакете:** outbox + параллельный relay с lease и fencing + inbox + транспорты RabbitMQ, Redis Streams и Laravel queue, без отдельного бинаря и без фреймворка.
3. **Транспорты проверены на настоящих брокерах в CI**, включая Redis Streams (ни у одного из проверенных пакетов не нашёл тестов Redis Streams на живом сервере).
4. **Конкретный end-to-end сценарий платежа** вместе с laravel-idempotency и payments-outbox, с тестами, а не только идея связки.

## Решения перед реализацией (11.11.2026)
- **A. Порядок коммита ≠ порядок id.** Таблица `outbox_aggregates(aggregate_id PK, last_seq)`; `publish()` с `aggregateId` атомарно инкрементирует счётчик в той же транзакции (PG: `INSERT … ON CONFLICT … DO UPDATE … RETURNING`, MySQL: `ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)`), сообщение получает `aggregate_seq`. Писатели одного агрегата сериализуются на строке счётчика, поэтому seq совпадает с порядком коммитов. Relay упорядочивает агрегат по `aggregate_seq`, не по `id`. Уникальный индекс `(aggregate_id, aggregate_seq)`. Без `aggregateId` — без счётчика и без порядка. Несколько агрегатов в одной транзакции дают риск deadlock при разном порядке; в Limitations: публикуйте агрегаты в стабильном порядке.
- **B. Формат — CloudEvents 1.0, binary mode.** Тело = payload JSON. Атрибуты: `specversion=1.0`, `id`, `type`, `source` (конфиг, по умолчанию `app.name`), `subject` = aggregate_id, `time`, `datacontenttype=application/json` + свои headers. RabbitMQ — properties/headers `ce_*`, Redis Streams — поля `ce_*`.
- **C. Транспорт `queue`** — job `DeliverOutboxMessage`, карта `type => handler` в конфиге. Неизвестный type — событие `UnhandledOutboxMessage` и лог, не исключение.
- **D. Inbox:** `Inbox::handle(string $consumer, string $messageId, Closure $handler)`, consumer явно. Middleware для job берёт consumer из свойства job или `outbox.inbox.consumer`.
- **E. Kafka** — roadmap v0.2.
- `id` — `$table->uuid()` (PG: нативный uuid, MySQL: char(36)), UUIDv7 через `Str::uuid7()` (нужен `ramsey/uuid ^4.7` в require). Payload — только JSON-массив. Facade + контракт. Published хранятся до `outbox:prune`. `strict_transaction=true` по умолчанию.
- `payload` и `headers` — текстовые столбцы с JSON, не тип `json`: MySQL переставляет ключи, `jsonb` переформатирует, а потребителям, которые хэшируют или подписывают тело, нужны точные байты. Времена — unix-миллисекунды.
- **`Outbox::fake()`** с `assertPublished()` / `assertNothingPublished()` для тестов приложения: с `RefreshDatabase` транзакция открыта всегда, strict-проверка там ничего не ловит — это в README.

## Решения по ходу реализации
- `attempts` растёт при захвате (claim), а не при ошибке: истёкший lease тоже считается попыткой. Сообщение, у которого lease истёк на последней попытке, уходит в `failed`, а не захватывается снова: воркер мог умереть именно на нём.
- RabbitMQ: exchange = topic, routing key = type, `mandatory` + publisher confirms — немаршрутизируемое сообщение считается ошибкой.
- Redis Streams: поток `stream_prefix + topic`, поля `ce_*`, `content-type`, свои headers, `data`. Имена headers `ce_*`, `data`, `content-type` зарезервированы для всех транспортов.
- Синглтоны (`DatabaseOutbox`, `Inbox`) получают dispatcher при каждом событии, чтобы `Event::fake()` в тестах приложения работал после первого вызова.
- MySQL при REPEATABLE READ на время транзакции захвата блокирует и просмотренные-отфильтрованные строки: соседний воркер может получить пустой batch. На корректность не влияет, описано в Limitations.
- Inbox — отдельная миграция и тег `outbox-inbox-migrations`: сервису-потребителю не нужны таблицы outbox.
- `docker compose` с демо-приложением (MySQL + RabbitMQ + relay) перенесён в roadmap 0.2; сейчас compose поднимает только серверы для интеграционных тестов.

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
Inbox::handle('billing', $messageId, function () { /* бизнес-изменение */ });
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
13. Kafka — перенесено в roadmap v0.2 (решение E).
14. README (Mermaid: dual-write проблема, поток outbox → relay → broker → inbox, конкурирующие воркеры), CHANGELOG, честное сравнение, Limitations.

## Definition of Done v0.1.0
Чеклист готовности репозитория, зелёный CI на всех БД и брокерах, README с quick start через `docker compose` (MySQL + RabbitMQ + relay), честные Limitations: at-least-once, SQLite только для тестов, поведение при `failed` в упорядоченном агрегате.

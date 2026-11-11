<?php

declare(strict_types=1);

use Azhimov\Outbox\Support\MessageId;

it('generates a version 7 UUID', function (): void {
    $id = MessageId::generate();

    expect($id->value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('sorts by generation time', function (): void {
    $ids = [];
    for ($i = 0; $i < 50; $i++) {
        $ids[] = MessageId::generate()->value;
        usleep(1000);
    }

    $sorted = $ids;
    sort($sorted, SORT_STRING);

    expect($sorted)->toBe($ids);
});

it('accepts an existing UUID and normalizes it to lower case', function (): void {
    $id = MessageId::fromString('0193A2C4-6F1E-7B3A-9C2D-1E2F3A4B5C6D');

    expect($id->value)->toBe('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d')
        ->and((string) $id)->toBe('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d');
});

it('rejects strings that are not UUIDs', function (string $value): void {
    MessageId::fromString($value);
})->throws(InvalidArgumentException::class)->with([
    'empty' => '',
    'garbage' => 'not-a-uuid',
    'too short' => '0193a2c4-6f1e-7b3a-9c2d',
]);

it('compares by value', function (): void {
    $a = MessageId::fromString('0193a2c4-6f1e-7b3a-9c2d-1e2f3a4b5c6d');

    expect($a->equals(MessageId::fromString('0193A2C4-6F1E-7B3A-9C2D-1E2F3A4B5C6D')))->toBeTrue()
        ->and($a->equals(MessageId::generate()))->toBeFalse();
});

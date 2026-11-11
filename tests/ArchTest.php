<?php

declare(strict_types=1);

arch('source files declare strict types')
    ->expect('Azhimov\Outbox')
    ->toUseStrictTypes();

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

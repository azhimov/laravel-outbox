<?php

declare(strict_types=1);

use Azhimov\Outbox\Tests\TestCase;

require_once __DIR__.'/Support/Database.php';

pest()->extend(TestCase::class)->in('Feature');

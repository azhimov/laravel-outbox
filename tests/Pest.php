<?php

declare(strict_types=1);

use Azhimov\Outbox\Tests\TestCase;

require_once __DIR__.'/Support/Database.php';
require_once __DIR__.'/Support/Messages.php';
require_once __DIR__.'/Support/TransportContract.php';

pest()->extend(TestCase::class)->in('Feature');

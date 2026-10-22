<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Blipit\Blipit;

Blipit::init(['key' => 'blipit_pk_abc', 'environment' => 'test', 'endpoint' => (string) getenv('BLIPIT_TEST_INGEST'), 'traces_sample_rate' => 1.0]);
Blipit::addBreadcrumb('page opened', 'navigation');
if (($_GET['mode'] ?? '') === 'warning') {
    echo $undefinedVariable;
    echo 'ok';

    return;
}
throw new RuntimeException('checkout broke');

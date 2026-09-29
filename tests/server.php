<?php

declare(strict_types=1);

$body = (string) file_get_contents('php://input');
if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
    $body = (string) gzdecode($body);
}
file_put_contents(
    (string) getenv('BLIPIT_TEST_LOG'),
    json_encode(['path' => $_SERVER['REQUEST_URI'], 'body' => $body]) . "\n",
    FILE_APPEND
);
header('Content-Type: application/json');
echo '{}';

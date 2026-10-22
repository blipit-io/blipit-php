<?php

declare(strict_types=1);

$body = (string) file_get_contents('php://input');
$log = (string) getenv('BLIPIT_TEST_LOG');
file_put_contents($log, json_encode([
    'path' => $_SERVER['REQUEST_URI'],
    'key' => $_SERVER['HTTP_X_BLIPIT_KEY'] ?? null,
    'body' => json_decode($body, true),
]) . "\n", FILE_APPEND);
$replies = $log . '.replies';
$reply = [202, [], ['id' => 'x']];
if (is_file($replies)) {
    $queue = json_decode((string) file_get_contents($replies), true) ?: [];
    if ($queue) {
        $reply = array_shift($queue);
        file_put_contents($replies, json_encode($queue));
    }
}
http_response_code($reply[0]);
foreach ($reply[1] as $name => $value) {
    header("{$name}: {$value}");
}
header('Content-Type: application/json');
echo json_encode($reply[2]);

<?php

declare(strict_types=1);

// A minimal "application": calls Sample methods the ordinary way and reports
// what happened. Used from the CLI and behind PHP's built-in web server.

use Forja\Runtime;
use Forja\Tests\Fixtures\Sample;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$logs = [];
Runtime::configure(logger: new class($logs) extends Psr\Log\AbstractLogger {
    public function __construct(private array &$logs)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = $level . ': ' . strtr((string) $message, ['{key}' => $context['key'] ?? '', '{reason}' => $context['reason'] ?? '']);
    }
});

$calls = json_decode((string) file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input'), true);
$results = [];
foreach ($calls as [$method, $args]) {
    try {
        $results[] = ['value' => (new ReflectionMethod(Sample::class, $method))->isStatic()
            ? Sample::$method(...$args)
            : (new Sample())->$method(...$args)];
    } catch (Throwable $e) {
        $results[] = ['error' => get_class($e)];
    }
}

header('content-type: application/json');
echo json_encode(['results' => $results, 'stats' => Runtime::stats(), 'logs' => $logs, 'sapi' => PHP_SAPI]);

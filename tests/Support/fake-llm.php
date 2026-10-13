<?php

declare(strict_types=1);

// Router for PHP's built-in server that imitates the Anthropic Messages API
// and an OpenAI-compatible chat API. It answers with the hand-written C
// fixture for the method named in the prompt.

$body = json_decode((string) file_get_contents('php://input'), true);
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isAnthropic = str_ends_with($path, '/v1/messages');

$prompt = $isAnthropic ? $body['messages'][0]['content'] : $body['messages'][1]['content'];
preg_match('/PHP method \([\w\\\\]+::(\w+)\)/', $prompt, $method);
preg_match('/accel_[0-9a-f]{16}/', $prompt, $symbol);
$code = str_replace('{{symbol}}', $symbol[0], (string) file_get_contents(dirname(__DIR__) . "/c/{$method[1]}.c"));

file_put_contents((string) getenv('FAKE_LLM_LOG'), json_encode([
    'path' => $path,
    'model' => $body['model'],
    'apiKey' => $_SERVER['HTTP_X_API_KEY'] ?? null,
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'hasContext' => str_contains($prompt, 'helper methods of the same class'),
]) . "\n", FILE_APPEND);

header('content-type: application/json');
if ($isAnthropic) {
    echo json_encode(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => "```c\n$code```"]]]);
} else {
    $reply = "<think>\nLet me translate this.\n</think>\n\nHere is the code:\n\n```c\n$code```\n\nUsage:\n```c\nint x;\n```\n";
    echo json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $reply]]]]);
}

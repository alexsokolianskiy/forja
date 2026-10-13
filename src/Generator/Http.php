<?php

declare(strict_types=1);

namespace Forja\Generator;

final class Http
{
    private const RETRY_DELAYS = [1, 4];

    /**
     * POSTs JSON and returns the decoded JSON response, retrying on overload and transport errors.
     *
     * @param list<string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function postJson(string $url, array $headers, array $body, int $timeout): array
    {
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['content-type: application/json', ...$headers],
                CURLOPT_POSTFIELDS => $payload,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            $refused = curl_errno($ch) === CURLE_COULDNT_CONNECT;
            curl_close($ch);

            $data = is_string($raw) ? json_decode($raw, true) : null;
            if ($status === 200 && is_array($data)) {
                return $data;
            }

            $retryable = $raw === false ? !$refused : in_array($status, [408, 429, 500, 502, 503, 529], true);
            if ($retryable && isset(self::RETRY_DELAYS[$attempt])) {
                sleep(self::RETRY_DELAYS[$attempt]);
                continue;
            }
            if ($raw === false) {
                throw new GenerationException("request to $url failed: $error");
            }
            $message = is_array($data) ? ($data['error']['message'] ?? (is_string($data['error'] ?? null) ? $data['error'] : null)) : null;

            throw new GenerationException(sprintf('%s returned HTTP %d: %s', $url, $status, $message ?? substr((string) $raw, 0, 200)));
        }
    }
}

<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * The live transport: curl, bearer token, bounded timeouts.
 *
 * Bounded per call on purpose: a single dispatch makes several sequential Samsara calls inside a
 * browser request's time budget, and one unbounded call used to hold the PHP worker until the
 * server's max execution time.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private string $apiToken,
        private string $baseUrl = 'https://api.samsara.com',
        private int $connectTimeout = 5,
        private int $timeout = 20,
    ) {
    }

    public function request(string $method, string $url, string $body = ''): array
    {
        $full = str_starts_with($url, 'http') ? $url : rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
        $ch = curl_init($full);
        $headers = ['accept: application/json', 'authorization: Bearer ' . $this->apiToken];
        if (in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $headers[] = 'content-type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return ['ok' => false, 'status' => null, 'body' => null, 'error' => $err ?: 'transport error'];
        }

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'body' => (string) $resp,
            'error' => ($status >= 200 && $status < 300) ? null : "HTTP {$status}",
        ];
    }
}

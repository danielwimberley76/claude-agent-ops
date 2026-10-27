<?php

declare(strict_types=1);

namespace AgentOps\Samsara;

/**
 * The one seam every outbound Samsara call goes through. Small on purpose: the write gate wraps it,
 * tests fake it, and swapping the transport (curl, Guzzle) never touches calling code.
 *
 * Result shape, always — callers never catch transport exceptions:
 *   ['ok' => bool, 'status' => ?int, 'body' => ?string, 'error' => ?string]
 */
interface HttpClient
{
    /** @return array{ok: bool, status: ?int, body: ?string, error: ?string} */
    public function request(string $method, string $url, string $body = ''): array;
}

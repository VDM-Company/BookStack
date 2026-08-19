<?php

namespace BookStackAiChat\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed SSE response that re-applies anti-buffer headers at send time.
 *
 * BookStack's global PreventResponseCaching middleware overwrites
 * Cache-Control after the controller returns, which would otherwise strip
 * no-transform and let Cloudflare/nginx gzip the stream (and therefore
 * buffer it until the assistant finishes).
 */
class SseResponse extends StreamedResponse
{
    public function __construct(callable $callback, int $status = 200, array $headers = [])
    {
        parent::__construct($callback, $status, array_merge(EventStream::responseHeaders(), $headers));
    }

    public function prepare(Request $request): static
    {
        parent::prepare($request);
        $this->applyUnbufferedHeaders();

        return $this;
    }

    public function sendHeaders(?int $statusCode = null): static
    {
        EventStream::disableBuffering();
        $this->applyUnbufferedHeaders();

        return parent::sendHeaders($statusCode);
    }

    public function applyUnbufferedHeaders(): void
    {
        foreach (EventStream::responseHeaders() as $name => $value) {
            $this->headers->set($name, $value);
        }

        $this->headers->remove('Content-Length');
    }
}

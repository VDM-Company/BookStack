<?php

namespace BookStackAiChat\Http;

/**
 * Writes server-sent events straight to the client, unbuffered.
 */
class EventStream
{
    /**
     * Bytes of SSE-comment padding flushed before the first real event.
     *
     * 8KB matches nginx's default proxy/fastcgi buffer, so a single 8KB pad
     * can sit in a full buffer until the connection closes. 32KB is past
     * that and past Cloudflare's typical first-packet hold (2–16KB).
     */
    public const PRIME_BYTES = 32768;

    protected bool $aborted = false;

    public function __construct()
    {
        // Keep the script alive after the browser leaves so we can notice
        // the abort and stop the agent between tool steps, instead of
        // PHP dying on the next echo mid-request.
        ignore_user_abort(true);

        self::disableBuffering();
    }

    /**
     * Headers that tell nginx, Apache, Cloudflare and PHP not to gzip or
     * buffer. Re-applied in SseResponse::sendHeaders() because BookStack's
     * PreventResponseCaching middleware overwrites Cache-Control on the way
     * out and would strip no-transform.
     *
     * Content-Encoding must be the RFC token `identity`. `none` is invalid
     * and some edges strip it, then gzip/brotli the body (which cannot
     * stream — the client sees nothing until the stream ends).
     *
     * @return array<string, string>
     */
    public static function responseHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, no-transform, private',
            'X-Accel-Buffering' => 'no',
            'Content-Encoding' => 'identity',
        ];
    }

    /**
     * Drop every PHP/Apache buffering layer. Must run before the first body
     * byte: ini_set('zlib.output_compression') is ignored after output starts,
     * and zlib will hold the whole reply regardless of flush().
     */
    public static function disableBuffering(): void
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'Off');
        @ini_set('implicit_flush', '1');

        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        ob_implicit_flush(true);
    }

    /**
     * Cloudflare, gzip and some nginx defaults hold the first 1–8KB (or the
     * whole body if it never reaches that). Local Docker has none of those, so
     * tokens appear as they are written. An SSE comment is ignored by the
     * browser parser and by chat.js.
     *
     * Written as several flushed comments so an 8k proxy buffer cannot hold
     * one exact-sized pad and wait for close.
     */
    public function prime(): void
    {
        $remaining = self::PRIME_BYTES;

        while ($remaining > 0) {
            $n = min(4096, $remaining);
            $this->writeRaw(':' . str_repeat(' ', $n) . "\n\n");
            $remaining -= $n;
        }
    }

    public function send(string $event, array $data): void
    {
        if ($this->aborted) {
            return;
        }

        $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            return;
        }

        $this->writeRaw("event: {$event}\ndata: {$payload}\n\n");
        $this->refreshAborted();
    }

    /**
     * Whether the browser has gone away, so the agent can stop working.
     */
    public function aborted(): bool
    {
        return $this->aborted || $this->refreshAborted();
    }

    /**
     * Write an SSE comment so `connection_aborted()` is current even when
     * no token has been sent for a while (e.g. during a wiki lookup).
     */
    public function check(): bool
    {
        if ($this->aborted()) {
            return true;
        }

        $this->writeRaw(": \n\n");

        return $this->refreshAborted();
    }

    protected function writeRaw(string $bytes): void
    {
        echo $bytes;

        if (ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    protected function refreshAborted(): bool
    {
        if (connection_aborted() === 1) {
            $this->aborted = true;
        }

        return $this->aborted;
    }
}

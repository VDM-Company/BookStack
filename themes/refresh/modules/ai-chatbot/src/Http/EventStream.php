<?php

namespace BookStackAiChat\Http;

/**
 * Writes server-sent events straight to the client, unbuffered.
 */
class EventStream
{
    protected bool $aborted = false;

    public function __construct()
    {
        // Keep the script alive after the browser leaves so we can notice
        // the abort and stop the agent between tool steps, instead of
        // PHP dying on the next echo mid-request.
        ignore_user_abort(true);

        // Any buffering layer between here and the socket defeats streaming.
        // gzip has to go first: it holds output back regardless of flush().
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        ob_implicit_flush(true);
    }

    /**
     * Cloudflare, gzip and some nginx defaults hold the first 1–8KB (or the
     * whole body if it never reaches that). Local Docker has none of those, so
     * tokens appear as they are written. An SSE comment is ignored by the
     * browser parser and by chat.js.
     */
    public function prime(): void
    {
        echo ':' . str_repeat(' ', 8192) . "\n\n";
        flush();
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

        echo "event: {$event}\n";
        echo "data: {$payload}\n\n";

        flush();

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

        echo ": \n\n";
        flush();

        return $this->refreshAborted();
    }

    protected function refreshAborted(): bool
    {
        if (connection_aborted() === 1) {
            $this->aborted = true;
        }

        return $this->aborted;
    }
}

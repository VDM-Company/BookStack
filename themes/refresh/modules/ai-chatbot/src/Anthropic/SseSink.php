<?php

namespace BookStackAiChat\Anthropic;

use Psr\Http\Message\StreamInterface;

/**
 * A write-only PSR-7 stream used as the Guzzle `sink` for streaming requests.
 *
 * Guzzle's cURL handler wires the sink up to CURLOPT_WRITEFUNCTION, so write()
 * is called as bytes arrive off the wire. Parsing server-sent events here is
 * what makes token-by-token streaming possible; reading the response body
 * afterwards would only ever yield the completed message.
 */
class SseSink implements StreamInterface
{
    protected string $buffer = '';
    protected string $raw = '';
    protected bool $captureOnly = false;
    protected int $written = 0;

    /**
     * @param callable(string, array): void $onEvent Receives (eventName, decodedData).
     */
    public function __construct(protected $onEvent)
    {
    }

    /**
     * Switch to plain buffering, for error responses whose body is a single
     * JSON document rather than an event stream.
     */
    public function captureRawOnly(): void
    {
        $this->captureOnly = true;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function write(string $string): int
    {
        $length = strlen($string);
        $this->written += $length;

        if ($this->captureOnly) {
            // Bound the buffer so a misbehaving endpoint cannot exhaust memory.
            if (strlen($this->raw) < 65536) {
                $this->raw .= $string;
            }

            return $length;
        }

        $this->buffer .= $string;
        $this->drain();

        return $length;
    }

    /**
     * Pull every complete event out of the buffer. Events are separated by a
     * blank line; a trailing partial event stays buffered for the next chunk.
     */
    protected function drain(): void
    {
        while (($breakPos = $this->findEventBreak()) !== null) {
            [$offset, $length] = $breakPos;
            $block = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + $length);
            $this->handleBlock($block);
        }
    }

    /**
     * @return array{0: int, 1: int}|null Offset of the separator and its length.
     */
    protected function findEventBreak(): ?array
    {
        $lf = strpos($this->buffer, "\n\n");
        $crlf = strpos($this->buffer, "\r\n\r\n");

        if ($lf === false && $crlf === false) {
            return null;
        }

        if ($crlf !== false && ($lf === false || $crlf < $lf)) {
            return [$crlf, 4];
        }

        return [$lf, 2];
    }

    protected function handleBlock(string $block): void
    {
        $event = '';
        $data = '';

        foreach (preg_split('/\r\n|\r|\n/', $block) as $line) {
            if ($line === '' || $line[0] === ':') {
                continue;
            }

            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data .= ($data === '' ? '' : "\n") . ltrim(substr($line, 5), ' ');
            }
        }

        if ($event === '' || $data === '') {
            return;
        }

        $decoded = json_decode($data, true);

        if (is_array($decoded)) {
            ($this->onEvent)($event, $decoded);
        }
    }

    public function getSize(): ?int
    {
        return $this->written;
    }

    public function tell(): int
    {
        return $this->written;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function eof(): bool
    {
        return false;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('AI chatbot response stream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('AI chatbot response stream is not seekable');
    }

    public function read(int $length): string
    {
        throw new \RuntimeException('AI chatbot response stream is not readable');
    }

    public function getContents(): string
    {
        return $this->raw;
    }

    public function __toString(): string
    {
        return $this->raw;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}

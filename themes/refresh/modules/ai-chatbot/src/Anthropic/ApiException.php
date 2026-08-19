<?php

namespace BookStackAiChat\Anthropic;

class ApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $type = '',
    ) {
        parent::__construct($message);
    }

    /**
     * Build an exception from an Anthropic error response body.
     */
    public static function fromResponse(int $status, string $body): self
    {
        $decoded = json_decode($body, true);
        $message = $decoded['error']['message'] ?? '';
        $type = $decoded['error']['type'] ?? '';

        if ($message === '') {
            $message = trim($body) === '' ? "Anthropic API returned HTTP {$status}" : trim($body);
        }

        return new self($message, $status, $type);
    }

    /**
     * A short, non-sensitive description safe to show an end user. API error
     * text can echo request content, so only well-understood cases are mapped
     * to specifics and everything else stays generic.
     */
    public function userMessage(): string
    {
        return match (true) {
            $this->status === 401, $this->status === 403 => 'The AI service rejected this instance\'s API key.',
            $this->status === 404 && str_contains($this->getMessage(), 'model') => 'The configured AI model is not available for this API key.',
            $this->status === 429 => 'The AI service is rate limiting requests. Please try again shortly.',
            $this->status === 400 && $this->looksLikeLengthError() => 'The AI service rejected the request. It may be too long — try starting a new chat.',
            $this->status === 400 => 'The AI service rejected the request. Please try again, or start a new chat.',
            $this->status >= 500 => 'The AI service is temporarily unavailable. Please try again shortly.',
            default => 'Could not reach the AI service.',
        };
    }

    /**
     * True only when the API text itself talks about length or tokens.
     * Validation errors (wrong types, missing fields) are also HTTP 400.
     */
    protected function looksLikeLengthError(): bool
    {
        $message = strtolower($this->getMessage());

        foreach ([
            'too long',
            'too many tokens',
            'context length',
            'context window',
            'maximum context',
            'prompt is too',
            'token limit',
            'exceeds the maximum',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}

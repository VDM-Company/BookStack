<?php

namespace BookStackAiChat;

use Dotenv\Dotenv;

/**
 * Module configuration, sourced from environment variables.
 *
 * Theme modules cannot add files to app/Config, so values are read straight
 * from the environment rather than through Laravel's config repository.
 */
class Config
{
    protected static ?self $instance = null;

    /** @var array<string, string>|null */
    protected static ?array $envFileValues = null;

    protected function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function enabled(): bool
    {
        return $this->bool('AI_CHATBOT_ENABLED', true);
    }

    public function apiKey(): string
    {
        return trim($this->string('ANTHROPIC_API_KEY', ''));
    }

    /**
     * Whether the module has everything it needs to run. Routes and the widget
     * are only registered when this is true, so an unconfigured install behaves
     * exactly like a stock BookStack.
     */
    public function configured(): bool
    {
        return $this->enabled() && $this->apiKey() !== '';
    }

    public function model(): string
    {
        return $this->string('AI_CHATBOT_MODEL', 'claude-haiku-4-5');
    }

    public function apiBase(): string
    {
        return rtrim($this->string('AI_CHATBOT_API_BASE', 'https://api.anthropic.com'), '/');
    }

    public function maxTokens(): int
    {
        return $this->int('AI_CHATBOT_MAX_TOKENS', 1024, 256, 32000);
    }

    /**
     * Per-request wall-clock budget, in seconds, for the whole exchange
     * including any tool round-trips.
     */
    public function timeout(): int
    {
        return $this->int('AI_CHATBOT_TIMEOUT', 120, 10, 600);
    }

    /**
     * Maximum number of assistant turns in one exchange. Each search or page
     * read the assistant performs costs one turn, so this bounds both latency
     * and token spend on a single question.
     */
    public function maxSteps(): int
    {
        return $this->int('AI_CHATBOT_MAX_STEPS', 6, 1, 15);
    }

    public function searchResultLimit(): int
    {
        return $this->int('AI_CHATBOT_SEARCH_RESULTS', 5, 1, 30);
    }

    /**
     * Character ceiling applied to any single page handed to the model.
     */
    public function pageCharLimit(): int
    {
        return $this->int('AI_CHATBOT_PAGE_CHARS', 4000, 500, 200000);
    }

    /**
     * How many prior messages of client-held history to trust and replay.
     */
    public function historyLimit(): int
    {
        return $this->int('AI_CHATBOT_HISTORY_TURNS', 6, 0, 60);
    }

    /**
     * Messages allowed per user per minute. Zero disables the limit.
     */
    public function rateLimit(): int
    {
        return $this->int('AI_CHATBOT_RATE_LIMIT', 20, 0, 1000);
    }

    /**
     * On a public instance the guest user passes the `auth` middleware, so
     * anonymous visitors would otherwise be able to spend API credits.
     */
    public function allowGuests(): bool
    {
        return $this->bool('AI_CHATBOT_ALLOW_GUESTS', false);
    }

    /**
     * Role IDs or display names permitted to use the chatbot.
     * Empty means every non-guest user.
     *
     * @return string[]
     */
    public function allowedRoles(): array
    {
        $raw = $this->string('AI_CHATBOT_ROLES', '');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Extra instructions appended to the built-in system prompt.
     */
    public function extraInstructions(): string
    {
        return trim($this->string('AI_CHATBOT_SYSTEM_PROMPT', ''));
    }

    protected function string(string $key, string $default): string
    {
        $value = $this->raw($key);

        return ($value === null || $value === '') ? $default : $value;
    }

    protected function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->raw($key);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    protected function bool(string $key, bool $default): bool
    {
        $value = $this->raw($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    protected function raw(string $key): ?string
    {
        $value = env($key);

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        return $this->fromEnvFile($key);
    }

    /**
     * When `php artisan config:cache` has been run, Laravel skips loading the
     * .env file entirely and env() always returns null. Parse the file
     * ourselves so a cached-config install still picks up module settings.
     */
    protected function fromEnvFile(string $key): ?string
    {
        if (self::$envFileValues === null) {
            self::$envFileValues = $this->loadEnvFile();
        }

        $value = self::$envFileValues[$key] ?? null;

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    protected function loadEnvFile(): array
    {
        $path = base_path();

        if (!is_file($path . DIRECTORY_SEPARATOR . '.env')) {
            return [];
        }

        try {
            // Array-backed so parsing does not mutate $_ENV, $_SERVER or getenv().
            return Dotenv::createArrayBacked($path)->safeLoad();
        } catch (\Throwable) {
            return [];
        }
    }
}

<?php

namespace BookStackGoogleAutoLink;

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

    /**
     * Whether an existing user account may be linked to a social account that
     * carries the same verified email address, and logged in on the spot.
     */
    public function enabled(): bool
    {
        return $this->bool('GOOGLE_AUTO_LINK', true);
    }

    /**
     * Forget any cached lookup. Only needed by tests, which change the
     * environment between cases.
     */
    public static function flush(): void
    {
        self::$instance = null;
        self::$envFileValues = null;
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

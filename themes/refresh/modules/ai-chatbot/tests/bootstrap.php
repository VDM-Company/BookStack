<?php

/**
 * Shared harness for the module's tests.
 *
 * These are plain scripts rather than PHPUnit cases so they can be run against
 * a live install without touching BookStack's own test configuration.
 */

$root = __DIR__;
while (!is_file($root . '/vendor/autoload.php')) {
    $parent = dirname($root);
    if ($parent === $root) {
        fwrite(STDERR, "Could not locate the BookStack root from " . __DIR__ . "\n");
        exit(2);
    }
    $root = $parent;
}

define('AIC_ROOT', $root);

require $root . '/vendor/autoload.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Laravel's console exception handler renders and returns without a failing
// exit code, which would let a crashed suite look like a passing one. Take the
// handler back after bootstrapping so the runner sees the failure.
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, sprintf(
        "\n    \033[31mUNCAUGHT\033[0m  %s: %s\n              at %s:%d\n",
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
    ));

    exit(1);
});

final class Checks
{
    private int $failures = 0;
    private int $total = 0;

    public function __construct(private string $suite)
    {
        echo "\n\033[1m{$suite}\033[0m\n";
    }

    public function section(string $name): void
    {
        echo "\n  {$name}\n";
    }

    public function that(string $label, bool $ok, string $detail = ''): void
    {
        $this->total++;

        if (!$ok) {
            $this->failures++;
        }

        $mark = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
        echo "    {$mark}  {$label}" . ($detail !== '' ? "  \033[2m({$detail})\033[0m" : '') . "\n";
    }

    public function same(string $label, mixed $expected, mixed $actual): void
    {
        $ok = $expected === $actual;
        $this->that($label, $ok, $ok ? '' : 'got ' . json_encode($actual));
    }

    public function finish(): never
    {
        echo "\n  " . ($this->failures === 0
            ? "\033[32mAll {$this->total} checks passed.\033[0m"
            : "\033[31m{$this->failures} of {$this->total} checks failed.\033[0m") . "\n";

        exit($this->failures === 0 ? 0 : 1);
    }
}

/**
 * Replace the settings service so tests do not need a database.
 */
function aic_stub_settings(array $values = []): void
{
    app()->instance(BookStack\Settings\SettingService::class, new class ($values) {
        public function __construct(private array $values)
        {
        }

        public function get(string $key, mixed $default = null): mixed
        {
            return $this->values[$key] ?? $default;
        }
    });
}

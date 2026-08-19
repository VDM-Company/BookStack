<?php

namespace BookStackAiChat;

use BookStack\Users\Models\User;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;

/**
 * Writes chatbot failures to the Laravel log and, when a DSN is set, to Sentry.
 *
 * The production "Something went wrong" banner often never reaches the stream
 * catch — the browser gets an HTML/proxy status instead. Those cases come in
 * through clientError() so they still land here.
 */
class ErrorReport
{
    /**
     * @param array<string, mixed> $context
     */
    public static function exception(Throwable $exception, array $context = []): void
    {
        $detail = self::brief($exception->getMessage());

        self::writeLog('AI chatbot failure: ' . $detail, $context + [
            'exception' => $exception::class,
        ]);

        if (!self::ready()) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($exception, $context): void {
            self::enrich($scope, $context);
            \Sentry\captureException($exception);
        });
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function message(string $message, array $context = []): void
    {
        $detail = self::brief($message);

        self::writeLog('AI chatbot: ' . $detail, $context);

        if (!self::ready()) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($detail, $context): void {
            self::enrich($scope, $context);
            \Sentry\captureMessage($detail, Severity::error());
        });
    }

    public static function ready(): bool
    {
        if (!function_exists('\\Sentry\\captureException')) {
            return false;
        }

        try {
            $dsn = (string) (config('sentry.dsn') ?: '');
        } catch (Throwable) {
            $dsn = '';
        }

        return trim($dsn) !== '';
    }

    /**
     * @param array<string, mixed> $context
     */
    protected static function enrich(Scope $scope, array $context): void
    {
        $scope->setTag('module', 'ai-chatbot');

        foreach ($context as $key => $value) {
            $name = self::tagKey((string) $key);

            if (is_bool($value)) {
                $scope->setTag($name, $value ? 'true' : 'false');
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $scope->setTag($name, self::brief((string) $value, 180));
                continue;
            }

            if (is_array($value)) {
                $scope->setContext($name, $value);
            }
        }

        try {
            /** @var User|null $user */
            $user = user();
        } catch (Throwable) {
            $user = null;
        }

        if ($user instanceof User && !$user->isGuest()) {
            $scope->setUser(['id' => (string) $user->id]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected static function writeLog(string $message, array $context): void
    {
        try {
            logger()->error($message, $context);
        } catch (Throwable) {
        }
    }

    protected static function brief(string $text, int $limit = 400): string
    {
        $text = trim($text);

        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit) . '…';
    }

    protected static function tagKey(string $key): string
    {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $key) ?? $key);

        return substr($key !== '' ? $key : 'ctx', 0, 32);
    }
}

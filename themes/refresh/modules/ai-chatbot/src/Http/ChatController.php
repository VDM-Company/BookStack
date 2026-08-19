<?php

namespace BookStackAiChat\Http;

use BookStack\Http\Controller;
use BookStack\Http\HttpRequestService;
use BookStack\Entities\Queries\EntityQueries;
use BookStack\Search\SearchRunner;
use BookStack\Users\Models\User;
use BookStackAiChat\Access;
use BookStackAiChat\Anthropic\Client;
use BookStackAiChat\Chat\ChatAgent;
use BookStackAiChat\Chat\History;
use BookStackAiChat\Chat\Prompt;
use BookStackAiChat\Config;
use BookStackAiChat\ErrorReport;
use BookStackAiChat\Knowledge\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function message(Request $request): StreamedResponse|JsonResponse
    {
        try {
            return $this->handleMessage($request);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?: $exception->getMessage()
                ?: 'The request was invalid.';

            return $this->refuse((string) $message, 422);
        } catch (\Throwable $exception) {
            $this->logRequestFailure($exception);

            return $this->refuse('The assistant hit an unexpected error.', 500);
        }
    }

    protected function handleMessage(Request $request): StreamedResponse|JsonResponse
    {
        $config = Config::instance();

        if (!$config->configured()) {
            return $this->refuse('The AI assistant is not enabled on this instance.', 503);
        }

        /** @var User|null $user */
        $user = user();

        if (!Access::allows($user, $config)) {
            return $this->refuse('You do not have access to the AI assistant.', 403);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
            'history' => ['nullable', 'array', 'max:100'],
            'context' => ['nullable', 'array'],
            'context.title' => ['nullable', 'string', 'max:500'],
            'context.url' => ['nullable', 'string', 'max:2000'],
        ]);

        $limitResponse = $this->applyRateLimit($user, $config);

        if ($limitResponse !== null) {
            return $limitResponse;
        }

        $question = trim($validated['message']);
        $history = History::normalise($validated['history'] ?? [], $config);
        $system = Prompt::build($config, $user, $validated['context'] ?? []);

        $this->releaseSession($request);

        return $this->streamAnswer($config, $history, $question, $system);
    }

    /**
     * Browser-side failures (HTML 502/419, empty stream, JS throw) never hit
     * the SSE catch, so the widget reports them here. GET so a CSRF mismatch
     * on /message can still be recorded.
     */
    public function clientError(Request $request): JsonResponse
    {
        $config = Config::instance();
        $user = user();

        if (!$config->configured() || !Access::allows($user, $config)) {
            return $this->refuse('You do not have access to the AI assistant.', 403);
        }

        $key = 'ai-chatbot-client-error:' . ($user?->id ?? '0');

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['ok' => true]);
        }

        RateLimiter::hit($key, 60);

        $payload = [
            'kind' => 'client',
            'status' => $request->integer('status') ?: null,
            'message' => $this->clip((string) $request->query('message', ''), 400),
            'content_type' => $this->clip((string) $request->query('content_type', ''), 120),
            'body_preview' => $this->clip((string) $request->query('body_preview', ''), 400),
            'page' => $this->clip((string) $request->query('page', ''), 200),
        ];

        ErrorReport::message(
            'AI chatbot client error'
            . ($payload['status'] ? ' HTTP ' . $payload['status'] : '')
            . ($payload['message'] !== '' ? ': ' . $payload['message'] : ''),
            array_filter($payload, static fn ($value) => $value !== null && $value !== ''),
        );

        return response()->json(['ok' => true]);
    }

    protected function streamAnswer(Config $config, array $history, string $question, string $system): StreamedResponse
    {
        // Resolved before the response is returned, while the container and
        // request context are still fully intact.
        $agent = new ChatAgent(
            $config,
            new Client($config, app()->make(HttpRequestService::class)),
            new KnowledgeBase(
                $config,
                app()->make(SearchRunner::class),
                app()->make(EntityQueries::class),
            ),
        );

        return response()->stream(function () use ($agent, $config, $history, $question, $system): void {
            // The default 30s limit would kill a long research run mid-answer.
            @set_time_limit($config->timeout() + 30);

            $stream = new EventStream();

            try {
                $agent->run(
                    $history,
                    $question,
                    $system,
                    function (string $event, array $data) use ($stream): void {
                        $stream->send($event, $data);
                    },
                    fn (): bool => $stream->check(),
                );
            } catch (\Throwable $exception) {
                $this->logRequestFailure($exception);

                try {
                    $stream->send('error', ['message' => 'The assistant hit an unexpected error.']);
                    $stream->send('done', ['ok' => true]);
                } catch (\Throwable) {
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, private',
            'Connection' => 'keep-alive',
            // Stops nginx buffering the response into uselessness.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    protected function applyRateLimit(User $user, Config $config): ?JsonResponse
    {
        $limit = $config->rateLimit();

        if ($limit <= 0) {
            return null;
        }

        $key = 'ai-chatbot:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->refuse("Too many messages. Try again in {$seconds} seconds.", 429);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    protected function refuse(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    protected function logRequestFailure(\Throwable $exception): void
    {
        ErrorReport::exception($exception, ['source' => 'request']);
    }

    protected function clip(string $value, int $limit): string
    {
        $value = trim($value);

        return strlen($value) <= $limit ? $value : substr($value, 0, $limit) . '…';
    }

    /**
     * Persist session data and drop any native session lock before the
     * streamed body starts. Laravel 12's file driver only flocks during
     * `FileSessionHandler::write()`, and `StartSession` already calls
     * `Store::save()` after the controller returns (before the stream
     * callback). This flush makes the same guarantee explicit, and covers
     * a native PHP session if one is active.
     */
    protected function releaseSession(Request $request): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $session = $request->session();
        $session->save();

        $handler = $session->getHandler();

        if (method_exists($handler, 'close')) {
            $handler->close();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}

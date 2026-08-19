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
use BookStackAiChat\Knowledge\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function message(Request $request): StreamedResponse|JsonResponse
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
                logger()->error('AI chatbot stream failed: ' . $exception->getMessage(), [
                    'exception' => get_class($exception),
                ]);

                $stream->send('error', ['message' => 'The assistant hit an unexpected error.']);
                $stream->send('done', []);
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

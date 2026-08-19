<?php

namespace BookStackAiChat\Chat;

use BookStackAiChat\Config;

/**
 * Normalises the conversation history supplied by the browser.
 *
 * History is held client-side so the module needs no database table. That
 * means it is user-controlled input: it is length-capped and shape-checked
 * here before being replayed to the API. There is no trust boundary crossed
 * by a forged history — retrieval is still permission-scoped to the caller —
 * but it does need to be well-formed and bounded.
 */
class History
{
    protected const MAX_MESSAGE_CHARS = 8000;

    /**
     * @param mixed $raw
     *
     * @return array<int, array{role: string, content: string}>
     */
    public static function normalise($raw, Config $config): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $messages = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $role = $entry['role'] ?? '';
            $content = $entry['content'] ?? '';

            if (!in_array($role, ['user', 'assistant'], true) || !is_string($content)) {
                continue;
            }

            $content = trim($content);

            if ($content === '') {
                continue;
            }

            // Collapse consecutive same-role turns; the API requires alternation.
            $previous = end($messages);
            if ($previous !== false && $previous['role'] === $role) {
                array_pop($messages);
                $content = $previous['content'] . "\n\n" . $content;
            }

            $messages[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, self::MAX_MESSAGE_CHARS),
            ];
        }

        $messages = array_slice($messages, -$config->historyLimit());

        // A conversation must open with a user turn.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }

        // ...and the incoming question is appended after this, so it must not
        // already end on one.
        while ($messages !== [] && end($messages)['role'] === 'user') {
            array_pop($messages);
        }

        return array_values($messages);
    }
}

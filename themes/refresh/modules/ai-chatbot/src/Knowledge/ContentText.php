<?php

namespace BookStackAiChat\Knowledge;

use BookStack\Entities\Models\Entity;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Tools\Markdown\HtmlToMarkdown;

/**
 * Turns stored entity content into plain text suitable for a model prompt.
 */
class ContentText
{
    /**
     * A short single-line summary of an entity, for search result listings.
     */
    public static function preview(Entity $entity, int $chars): string
    {
        $raw = $entity instanceof Page
            ? (string) ($entity->text ?? '')
            : (string) ($entity->description ?? '');

        return self::truncate(self::collapse($raw), $chars);
    }

    /**
     * The full body of a page as Markdown, so headings, lists, tables and code
     * blocks survive into the prompt rather than being flattened to prose.
     */
    public static function pageBody(Page $page, int $limit): string
    {
        $markdown = trim((string) ($page->markdown ?? ''));

        if ($markdown === '') {
            $html = (string) ($page->html ?? '');

            if (trim($html) !== '') {
                try {
                    $markdown = trim((new HtmlToMarkdown($html))->convert());
                } catch (\Throwable) {
                    $markdown = '';
                }
            }
        }

        if ($markdown === '') {
            $markdown = trim((string) ($page->text ?? ''));
        }

        // Draw.io blocks survive HtmlToMarkdown as raw <img> tags; turn those
        // (and any other leftover HTML images) into markdown the model can cite.
        $markdown = PageImages::replaceHtmlImages($markdown);

        // Long runs of blank lines are pure token cost.
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        if (mb_strlen($markdown) <= $limit) {
            return $markdown;
        }

        return mb_substr($markdown, 0, $limit)
            . "\n\n[Content truncated. This page is longer than the assistant's per-page limit.]";
    }

    protected static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    protected static function truncate(string $text, int $chars): string
    {
        if (mb_strlen($text) <= $chars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $chars)) . '…';
    }
}

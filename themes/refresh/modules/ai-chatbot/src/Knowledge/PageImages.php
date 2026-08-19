<?php

namespace BookStackAiChat\Knowledge;

use BookStack\Entities\Models\Page;
use BookStack\Uploads\Attachment;
use BookStack\Uploads\Image;

/**
 * Finds images that already belong to a visible wiki page.
 *
 * URLs are reduced to same-origin BookStack paths the asking user can already
 * request in the browser. Nothing here invents public links or bypasses page
 * permissions: callers must have loaded the page through a visible* query.
 */
class PageImages
{
    public const PER_PAGE = 4;
    public const PER_ANSWER = 8;

    /** @var string[] */
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'avif'];

    /**
     * Images from a page the current user is already allowed to read.
     *
     * @return array<int, array{url: string, alt: string, caption: string, page_title: string, page_url: string}>
     */
    public static function fromPage(Page $page, int $limit = self::PER_PAGE): array
    {
        try {
            $limit = max(0, $limit);
            if ($limit === 0) {
                return [];
            }

            $found = self::extract((string) ($page->html ?? ''), (string) ($page->markdown ?? ''), $limit);
            $found = self::appendAttachments($page, $found, $limit);
            $found = self::appendGallery($page, $found, $limit);

            $pageUrl = '';
            try {
                $pageUrl = (string) $page->getUrl();
            } catch (\Throwable) {
                $pageUrl = '';
            }

            $out = [];
            foreach ($found as $image) {
                $out[] = [
                    'url' => $image['url'],
                    'alt' => $image['alt'],
                    'caption' => $image['alt'] !== '' ? $image['alt'] : ($image['name'] ?? ''),
                    'page_title' => (string) $page->name,
                    'page_url' => $pageUrl,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Pull image URLs out of stored page HTML and Markdown. No database.
     *
     * @return array<int, array{url: string, alt: string, name?: string}>
     */
    public static function extract(string $html, string $markdown = '', int $limit = self::PER_PAGE): array
    {
        $found = [];
        $seen = [];

        foreach (self::fromHtml($html) as $image) {
            self::push($found, $seen, $image, $limit);
        }

        foreach (self::fromMarkdown($markdown) as $image) {
            self::push($found, $seen, $image, $limit);
        }

        return $found;
    }

    /**
     * Accept only a same-origin BookStack image or attachment path.
     */
    public static function sanitiseUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($url === '' || str_contains($url, '..') || str_contains($url, '\\')) {
            return null;
        }

        if (preg_match('/^(javascript|data|vbscript|file):/i', $url) || str_starts_with($url, '//')) {
            return null;
        }

        $path = $url;
        $query = '';

        if (preg_match('#^https?://#i', $url)) {
            $parts = parse_url($url);
            $app = parse_url(url('/'));

            if ($parts === false || $app === false) {
                return null;
            }

            $host = strtolower((string) ($parts['host'] ?? ''));
            $appHost = strtolower((string) ($app['host'] ?? ''));

            if ($host === '' || $appHost === '' || $host !== $appHost) {
                return null;
            }

            $path = (string) ($parts['path'] ?? '/');
            $query = (string) ($parts['query'] ?? '');
        } elseif (str_starts_with($url, '/')) {
            $split = explode('?', $url, 2);
            $path = $split[0];
            $query = $split[1] ?? '';
        } elseif (str_starts_with(strtolower($url), 'uploads/images/')) {
            $path = '/' . explode('?', $url, 2)[0];
        } else {
            return null;
        }

        if (str_contains($path, '..') || str_contains($path, '\\') || $path === '') {
            return null;
        }

        $appPrefix = rtrim((string) (parse_url((string) url('/'), PHP_URL_PATH) ?? ''), '/');
        $relative = $appPrefix !== '' && str_starts_with($path, $appPrefix . '/')
            ? substr($path, strlen($appPrefix))
            : $path;

        if (preg_match('#^/uploads/images/[A-Za-z0-9._/-]+$#', $relative)) {
            return $path;
        }

        if (preg_match('#^/attachments/[0-9]+$#', $relative) && ($query === '' || $query === 'open=true')) {
            return $query === 'open=true' ? $path . '?open=true' : $path;
        }

        return null;
    }

    /**
     * Turn leftover HTML <img> tags (draw.io blocks, etc.) into markdown.
     */
    public static function replaceHtmlImages(string $text): string
    {
        $replaced = preg_replace_callback(
            '/<img\b[^>]*>/i',
            static function (array $match): string {
                $tag = $match[0];
                if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $srcMatch)) {
                    return '';
                }

                $url = self::sanitiseUrl($srcMatch[2]);
                if ($url === null) {
                    return '';
                }

                $alt = '';
                if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/i', $tag, $altMatch)) {
                    $alt = self::plainAlt($altMatch[2]);
                }

                return '![' . $alt . '](' . $url . ')';
            },
            $text,
        );

        return is_string($replaced) ? $replaced : $text;
    }

    /**
     * @param array<int, array{url: string, alt: string, caption?: string, page_title?: string, page_url?: string}> $images
     */
    public static function formatForModel(array $images): string
    {
        if ($images === []) {
            return '';
        }

        $lines = [
            'Images on this page (embed relevant ones with this exact markdown; do not invent URLs):',
        ];

        foreach ($images as $image) {
            $url = (string) ($image['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $alt = (string) ($image['alt'] ?? '');
            if ($alt === '') {
                $alt = (string) ($image['caption'] ?? '');
            }

            $line = '- ![' . $alt . '](' . $url . ')';
            $pageTitle = trim((string) ($image['page_title'] ?? ''));
            if ($pageTitle !== '') {
                $line .= '  (from "' . $pageTitle . '")';
            }

            $lines[] = $line;
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    /**
     * @return array<int, array{url: string, alt: string}>
     */
    protected static function fromHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
            return [];
        }

        $found = [];

        foreach ($tags[0] as $tag) {
            if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/i', $tag, $srcMatch)) {
                continue;
            }

            $url = self::sanitiseUrl($srcMatch[2]);
            if ($url === null) {
                continue;
            }

            $alt = '';
            if (preg_match('/\balt\s*=\s*(["\'])(.*?)\1/i', $tag, $altMatch)) {
                $alt = self::plainAlt($altMatch[2]);
            }

            $found[] = ['url' => $url, 'alt' => $alt];
        }

        return $found;
    }

    /**
     * @return array<int, array{url: string, alt: string}>
     */
    protected static function fromMarkdown(string $markdown): array
    {
        if (trim($markdown) === '') {
            return [];
        }

        if (!preg_match_all('/!\[([^\]\n]*)]\(([^)\s]+)\)/', $markdown, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            $url = self::sanitiseUrl($match[2]);
            if ($url === null) {
                continue;
            }

            $found[] = ['url' => $url, 'alt' => self::plainAlt($match[1])];
        }

        return $found;
    }

    /**
     * @param array<int, array{url: string, alt: string, name?: string}> $found
     * @return array<int, array{url: string, alt: string, name?: string}>
     */
    protected static function appendAttachments(Page $page, array $found, int $limit): array
    {
        if (count($found) >= $limit) {
            return $found;
        }

        $seen = [];
        foreach ($found as $image) {
            $seen[$image['url']] = true;
        }

        try {
            $page->loadMissing('attachments');
        } catch (\Throwable) {
            return $found;
        }

        foreach ($page->attachments as $attachment) {
            if (!$attachment instanceof Attachment || $attachment->external) {
                continue;
            }

            $extension = strtolower((string) $attachment->extension);
            if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }

            try {
                $url = self::sanitiseUrl($attachment->getUrl(true));
            } catch (\Throwable) {
                continue;
            }

            if ($url === null) {
                continue;
            }

            self::push($found, $seen, [
                'url' => $url,
                'alt' => self::plainAlt((string) $attachment->name),
                'name' => (string) $attachment->name,
            ], $limit);
        }

        return $found;
    }

    /**
     * Gallery / draw.io images uploaded to this page, if the HTML missed them.
     *
     * @param array<int, array{url: string, alt: string, name?: string}> $found
     * @return array<int, array{url: string, alt: string, name?: string}>
     */
    protected static function appendGallery(Page $page, array $found, int $limit): array
    {
        if (count($found) >= $limit) {
            return $found;
        }

        $seen = [];
        foreach ($found as $image) {
            $seen[$image['url']] = true;
        }

        try {
            $records = Image::query()
                ->scopes(['visible'])
                ->where('uploaded_to', $page->id)
                ->orderBy('id')
                ->limit($limit)
                ->get();
        } catch (\Throwable) {
            return $found;
        }

        foreach ($records as $record) {
            $url = self::sanitiseUrl((string) $record->url);
            if ($url === null && is_string($record->path) && $record->path !== '') {
                $path = str_starts_with($record->path, '/') ? $record->path : '/' . ltrim($record->path, '/');
                $url = self::sanitiseUrl($path);
            }

            if ($url === null) {
                continue;
            }

            self::push($found, $seen, [
                'url' => $url,
                'alt' => self::plainAlt((string) $record->name),
                'name' => (string) $record->name,
            ], $limit);
        }

        return $found;
    }

    /**
     * @param array<int, array{url: string, alt: string, name?: string}> $found
     * @param array<string, true>                                       $seen
     * @param array{url: string, alt: string, name?: string}            $image
     */
    protected static function push(array &$found, array &$seen, array $image, int $limit): void
    {
        if (count($found) >= $limit) {
            return;
        }

        $url = $image['url'];
        if ($url === '' || isset($seen[$url])) {
            return;
        }

        $seen[$url] = true;
        $found[] = $image;
    }

    protected static function plainAlt(string $alt): string
    {
        $alt = html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?? $alt);

        return str_replace(['[', ']'], '', $alt);
    }
}

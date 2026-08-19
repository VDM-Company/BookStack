<?php

namespace BookStackAiChat\Http;

use BookStack\Http\Controller;
use BookStack\Uploads\Image;
use BookStack\Uploads\ImageService;
use BookStackAiChat\Access;
use BookStackAiChat\Config;
use BookStackAiChat\Knowledge\PageImages;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a wiki image the current user can already see, from whatever disk
 * BookStack is using (local or S3). The widget uses this so a private bucket
 * is never opened in the browser.
 */
class ImageController extends Controller
{
    public function show(string $path, ImageService $images): StreamedResponse
    {
        if (!Access::allows(user(), Config::instance())) {
            abort(403);
        }

        $safe = PageImages::sanitiseUrl('/' . ltrim($path, '/'));
        if ($safe === null) {
            abort(404);
        }

        $relative = $this->uploadsRelative($safe);
        if ($relative === null) {
            abort(404);
        }

        $record = Image::query()
            ->scopes(['visible'])
            ->where(function ($query) use ($relative): void {
                $query->whereIn('path', PageImages::imageLookupPaths($relative));
            })
            ->first();

        if ($record === null) {
            abort(404);
        }

        try {
            return $images->streamImageFromStorageResponse((string) $record->type, (string) $record->path);
        } catch (\Throwable) {
            abort(404);
        }
    }

    protected function uploadsRelative(string $path): ?string
    {
        $appPrefix = rtrim((string) (parse_url((string) url('/'), PHP_URL_PATH) ?? ''), '/');
        if ($appPrefix !== '' && str_starts_with($path, $appPrefix . '/')) {
            $path = substr($path, strlen($appPrefix));
        }

        if (!preg_match('#^/uploads/images/[A-Za-z0-9._/-]+$#', $path)) {
            return null;
        }

        return $path;
    }
}

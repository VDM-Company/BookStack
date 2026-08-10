<?php

/**
 * Theme translation overrides.
 *
 * BookStack merges these over the core strings (see app/Translation/FileLoader.php),
 * so only the keys being changed need to be listed — everything else falls
 * through to core untouched.
 *
 * These fix copy that reads as machine-generated: Title Case on a sentence, a
 * stray capital mid-sentence, and an apology where a plain statement is clearer.
 */

return [

    '404_page_not_found'      => 'Page not found',
    'sorry_page_not_found'    => 'We could not find the page you were looking for.',

    'image_not_found'         => 'Image not found',
    'image_not_found_subtitle' => 'We could not find the image file you were looking for.',

    'error_occurred'          => 'Something went wrong',

];

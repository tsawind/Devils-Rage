<?php

declare(strict_types=1);

/*
 * Settings live in ~/seat-docker/.env on the server (no quotes needed):
 *
 *   DR_FIT_LIBRARY_CHARACTERS=Some Pilot, 2112345678
 *       Characters whose personal in-game fittings are the corp fit library
 *       (names or character IDs, comma separated). Empty = the CEO.
 *   DR_FIT_PREFIX=[DR]
 *       Only import fits whose name starts with this; the prefix is removed from the
 *       name in SeAT. Empty = import every personal fit of the library characters.
 */

return [
    'fit_library_characters' => env('DR_FIT_LIBRARY_CHARACTERS', ''),
    'fit_prefix' => env('DR_FIT_PREFIX', ''),
];

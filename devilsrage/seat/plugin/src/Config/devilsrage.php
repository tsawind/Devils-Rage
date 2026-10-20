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
 *   DR_CORPORATION_ID=98xxxxxxx
 *       The corp these pages are for. Empty = the corporation most SeAT pilots are in.
 *   DR_BOT_API_KEY=<64 random characters>
 *       Shared with the mapper (SEAT_API_KEY there) so its Discord bot can ask SeAT
 *       about a linked pilot's capitals, assets and fits. Empty = bot API switched off.
 *   DR_MAPPER_URL=https://devilsrage.duckdns.org
 *       The mapper. My accounts asks it (with DR_BOT_API_KEY) which characters are
 *       logged in there, and its "add to the mapper" buttons go there.
 */

return [
    'fit_library_characters' => env('DR_FIT_LIBRARY_CHARACTERS', ''),
    'fit_prefix' => env('DR_FIT_PREFIX', ''),
    'corporation_id' => env('DR_CORPORATION_ID', ''),
    'bot_api_key' => env('DR_BOT_API_KEY', ''),
    'mapper_url' => env('DR_MAPPER_URL', 'https://devilsrage.duckdns.org'),
];

<?php

declare(strict_types=1);

return [
    /*
    | Cameras (Patch 62): live EVE window video through the MediaMTX relay on the VPS.
    | CAMERAS_SECRET signs the short passes the relay checks; CAMERAS_RTC_URL is where
    | browsers send WHIP (publish) / WHEP (watch) requests. Both are written by
    | devilsrage/cameras/setup-cameras.sh. Without them the cameras feature stays hidden.
    */
    'secret' => env('CAMERAS_SECRET'),
    'rtc_url' => env('CAMERAS_RTC_URL'),

    /* A pass is good for this many seconds (it only has to get the stream started). */
    'pass_seconds' => 120,

    /* A camera that hasn't checked in for this long counts as gone. */
    'stale_seconds' => 90,

    /* Clips and stills of hole activity are kept this long, then deleted. */
    'clip_hours' => (int) env('CAMERAS_CLIP_HOURS', 24),

    /* Biggest clip / still a camera may upload (kilobytes). */
    'max_clip_kb' => 12_000,

    /* Patch 62e: all clips together may use this much of the server's disk; past it, events
       keep their still but not the clip (the disk is shared with the mapper and SeAT). */
    'max_storage_mb' => (int) env('CAMERAS_MAX_STORAGE_MB', 1500),
];

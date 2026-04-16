<?php

declare(strict_types=1);

/**
 * Optional production OAuth overrides (used when Admin → YouTube gate DB fields are empty).
 * Prefer setting secrets here on the server (outside git) or via environment variables.
 *
 * Google Cloud Console (Web client):
 * - Authorized redirect URIs: must match GOOGLE_REDIRECT_URI exactly (https, no trailing slash).
 * - Authorized JavaScript origins: https://trytest.manuelcode.info (your host; no path).
 *
 * Env vars (if you do not edit defines below):
 *   GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI,
 *   YOUTUBE_CHANNEL_ID, YOUTUBE_FALLBACK_CODE
 */

if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') !== false ? (string) getenv('GOOGLE_CLIENT_ID') : '');
}
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') !== false ? (string) getenv('GOOGLE_CLIENT_SECRET') : '');
}
if (!defined('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') !== false ? (string) getenv('GOOGLE_REDIRECT_URI') : '');
}
if (!defined('YOUTUBE_CHANNEL_ID')) {
    define('YOUTUBE_CHANNEL_ID', getenv('YOUTUBE_CHANNEL_ID') !== false ? (string) getenv('YOUTUBE_CHANNEL_ID') : '');
}
/** Optional: require this code (e.g. from a video) after manual “I subscribed” when API is unreliable. Leave empty to only require the checkbox. */
if (!defined('YOUTUBE_FALLBACK_CODE')) {
    define('YOUTUBE_FALLBACK_CODE', getenv('YOUTUBE_FALLBACK_CODE') !== false ? (string) getenv('YOUTUBE_FALLBACK_CODE') : '');
}

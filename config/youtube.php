<?php

declare(strict_types=1);

/**
 * YouTube subscription gate for PDF downloads.
 *
 * WHAT YOU MUST PROVIDE (one-time setup in Google Cloud + YouTube):
 *
 * 1) Google Cloud project
 *    - https://console.cloud.google.com/
 *    - APIs & Services → Enable "YouTube Data API v3"
 *
 * 2) OAuth consent screen (External or Internal)
 *    - Add scope: .../auth/youtube.readonly
 *    - Add your Google account as a test user while app is in "Testing"
 *
 * 3) OAuth 2.0 Client ID (type: Web application)
 *    - Create credentials → OAuth client ID → Web application
 *    - Authorized redirect URI must match YOUTUBE_OAUTH_REDIRECT_URI exactly, e.g.:
 *      http://localhost/trytest/youtube_oauth_callback
 *      https://yourdomain.com/trytest/youtube_oauth_callback
 *
 * 4) Your YouTube channel ID (starts with UC)
 *    - YouTube Studio → Settings → Channel → Advanced settings → Channel ID
 *    - Or from channel URL: youtube.com/channel/UC...
 *
 * 5) Put the values below (or use environment variables on production).
 *
 * When client_id or channel_id is empty, the download gate is OFF so the site still works until you configure it.
 */
return [
    'client_id' => getenv('YOUTUBE_OAUTH_CLIENT_ID') ?: '',
    'client_secret' => getenv('YOUTUBE_OAUTH_CLIENT_SECRET') ?: '',
    /** Must match the redirect URI registered in Google Cloud exactly */
    'redirect_uri' => getenv('YOUTUBE_OAUTH_REDIRECT_URI') ?: 'http://localhost/trytest/youtube_oauth_callback',
    /** Your channel ID (UC…) — API checks the signed-in user is subscribed to THIS channel */
    'channel_id' => getenv('YOUTUBE_CHANNEL_ID') ?: '',
];

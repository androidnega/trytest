<?php

declare(strict_types=1);

/**
 * Live “in a quiz” presence (distinct students with a recent ping).
 */
function trytest_presence_stale_cutoff_unix(): int
{
    return time() - 75;
}

function trytest_presence_ping_upsert(PDO $db, int $userId, int $quizId): void
{
    if ($userId < 1 || $quizId < 1) {
        return;
    }
    $now = time();
    $st = $db->prepare(
        'INSERT INTO quiz_presence_ping (user_id, quiz_id, last_seen) VALUES (?, ?, ?)
         ON CONFLICT(user_id, quiz_id) DO UPDATE SET last_seen = excluded.last_seen'
    );
    $st->execute([$userId, $quizId, $now]);
}

function trytest_presence_ping_delete(PDO $db, int $userId, int $quizId): void
{
    if ($userId < 1 || $quizId < 1) {
        return;
    }
    $st = $db->prepare('DELETE FROM quiz_presence_ping WHERE user_id = ? AND quiz_id = ?');
    $st->execute([$userId, $quizId]);
}

function trytest_presence_live_student_count(PDO $db): int
{
    $cut = trytest_presence_stale_cutoff_unix();
    $st = $db->prepare('SELECT COUNT(DISTINCT user_id) FROM quiz_presence_ping WHERE last_seen >= ?');
    $st->execute([$cut]);

    return (int) $st->fetchColumn();
}

/** Optional ws:// or wss:// URL for Node presence relay (see realtime/presence-server.mjs). */
function trytest_presence_ws_url(): string
{
    $env = getenv('TRYTEST_PRESENCE_WS');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $file = __DIR__ . '/../config/app.php';
    if (!is_file($file)) {
        return '';
    }
    /** @var array{presence_ws_url?: string} $cfg */
    $cfg = require $file;
    $u = trim((string) ($cfg['presence_ws_url'] ?? ''));

    return $u;
}

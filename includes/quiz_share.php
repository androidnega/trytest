<?php

declare(strict_types=1);

require_once __DIR__ . '/trytest_urls.php';

/**
 * Readable short code: 4 chars + hyphen + 4 chars (no ambiguous 0/O, 1/l/I).
 */
function trytest_quiz_normalize_share_code(string $raw): string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('/[^a-z0-9-]/', '', $s) ?? '';

    return $s;
}

function trytest_quiz_generate_share_code(): string
{
    $chars = '23456789abcdefghjkmnpqrstuvwxyz';
    $len = strlen($chars);
    $p = '';
    for ($i = 0; $i < 8; $i++) {
        $p .= $chars[random_int(0, $len - 1)];
    }

    return substr($p, 0, 4) . '-' . substr($p, 4, 4);
}

/**
 * Ensure the quiz has a unique share_code (idempotent).
 */
function trytest_quiz_ensure_share_code(PDO $db, int $quizId): string
{
    if ($quizId < 1) {
        return '';
    }
    $st = $db->prepare('SELECT share_code FROM quizzes WHERE id = ?');
    $st->execute([$quizId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $existing = trim((string) ($row['share_code'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    for ($attempt = 0; $attempt < 48; $attempt++) {
        $code = trytest_quiz_generate_share_code();
        try {
            $up = $db->prepare('UPDATE quizzes SET share_code = ? WHERE id = ? AND (share_code IS NULL OR TRIM(share_code) = \'\')');
            $up->execute([$code, $quizId]);
            if ($up->rowCount() > 0) {
                return $code;
            }
            $st2 = $db->prepare('SELECT share_code FROM quizzes WHERE id = ?');
            $st2->execute([$quizId]);
            $r2 = $st2->fetch(PDO::FETCH_ASSOC);
            $e2 = trim((string) ($r2['share_code'] ?? ''));
            if ($e2 !== '') {
                return $e2;
            }
        } catch (Throwable $e) {
            // collision on unique index — retry
        }
    }

    return '';
}

function trytest_quiz_id_from_share_code(PDO $db, string $raw): int
{
    $code = trytest_quiz_normalize_share_code($raw);
    if ($code === '' || strlen(str_replace('-', '', $code)) < 8) {
        return 0;
    }
    $st = $db->prepare('SELECT id FROM quizzes WHERE LOWER(share_code) = ? LIMIT 1');
    $st->execute([$code]);
    $id = (int) ($st->fetchColumn() ?: 0);

    return $id > 0 ? $id : 0;
}

/** Public path: /base/q/xxxx-xxxx */
function trytest_quiz_share_public_path(string $shareCode): string
{
    $c = trytest_quiz_normalize_share_code($shareCode);

    return $c === '' ? '' : trytest_url('q/' . $c);
}

function trytest_quiz_share_absolute_url(string $shareCode): string
{
    $p = trytest_quiz_share_public_path($shareCode);
    if ($p === '') {
        return '';
    }
    $tail = ltrim($p, '/');

    return trytest_absolute_url($tail);
}

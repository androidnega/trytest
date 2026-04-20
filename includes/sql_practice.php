<?php

declare(strict_types=1);

/**
 * SQL practical tasks: student writes SELECT-only queries; we grade against a hidden reference query.
 *
 * How it works:
 * - Admin stores JSON in questions.sql_practice: setup_sql (creates seed data), reference_sql (model query),
 *   optional hints[], thresholds (similarity_correct, similarity_partial).
 * - Each grade request opens a fresh :memory: SQLite DB, runs setup_sql, then runs reference_sql and student_sql.
 * - We compare result *sets* (multiset of normalized rows), not raw SQL text — so equivalent queries can pass.
 * - Marking is intentionally lenient: uses multiset overlap (F1-style score). Defaults: ≥0.88 → correct,
 *   ≥0.55 → partial, else wrong. Extra/missing rows reduce the score smoothly.
 * - Student SQL must be a single SELECT or WITH … SELECT; destructive keywords are rejected.
 */

/** @param array<string,mixed> $public */
function trytest_sql_practice_strip_secrets(array $public): array
{
    unset($public['reference_sql']);

    return $public;
}

/**
 * @param array<string,mixed>|null $decoded
 * @return array{ok:bool,error?:string,setup?:string,reference?:string,hints:list<string>,simCorrect:float,simPartial:float}
 */
function trytest_sql_practice_parse_config(?array $decoded): array
{
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid SQL practice configuration.'];
    }
    $setup = trim((string) ($decoded['setup_sql'] ?? ''));
    $ref = trim((string) ($decoded['reference_sql'] ?? ''));
    if ($ref === '') {
        return ['ok' => false, 'error' => 'sql_practice JSON must include reference_sql (and optional setup_sql).'];
    }
    $hints = [];
    if (isset($decoded['hints']) && is_array($decoded['hints'])) {
        foreach ($decoded['hints'] as $h) {
            if (is_array($h)) {
                continue;
            }
            $t = trim((string) $h);
            $t = trytest_strip_ai_citation_noise($t);
            if ($t !== '') {
                $hints[] = strlen($t) > 500 ? substr($t, 0, 500) : $t;
            }
        }
    }
    $simCorrect = isset($decoded['similarity_correct']) ? (float) $decoded['similarity_correct'] : 0.88;
    $simPartial = isset($decoded['similarity_partial']) ? (float) $decoded['similarity_partial'] : 0.55;
    $simCorrect = max(0.5, min(0.999, $simCorrect));
    $simPartial = max(0.2, min(0.998, $simPartial));
    if ($simPartial >= $simCorrect) {
        $simPartial = max(0.2, $simCorrect - 0.08);
    }

    return [
        'ok' => true,
        'setup' => $setup,
        'reference' => $ref,
        'hints' => $hints,
        'simCorrect' => $simCorrect,
        'simPartial' => $simPartial,
    ];
}

function trytest_sql_student_query_allowed(string $sql): ?string
{
    $t = trim($sql);
    if ($t === '') {
        return 'Write a SELECT query (you can start with WITH for common table expressions).';
    }
    if (strlen($t) > 12000) {
        return 'Query is too long.';
    }
    // Single statement: allow one trailing semicolon only.
    $core = rtrim($t, " \t\n\r\v\0;");
    if (str_contains($core, ';')) {
        return 'Use a single SQL statement only (no multiple statements separated by ;).';
    }
    $san = trytest_sql_strip_sql_comments($core);
    if ($san === '') {
        return 'After removing comments, the query is empty.';
    }
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|ATTACH|DETACH|VACUUM|REINDEX|REPLACE|PRAGMA|TRUNCATE|GRANT|REVOKE|CALL|EXEC|EXECUTE)\b/i', $san) === 1) {
        return 'Only read-only SELECT queries are allowed here (no DDL/DML).';
    }
    if (preg_match('/^\s*(WITH|SELECT)\b/is', $san) !== 1) {
        return 'Start your answer with SELECT or WITH … SELECT.';
    }

    return null;
}

/**
 * Strip citation junk AI tools inject ([cite_start], [cite: …]) — hint lines or a full JSON paste.
 */
function trytest_strip_ai_citation_noise(string $text): string
{
    $t = preg_replace('/\[cite_start\]/i', '', $text) ?? $text;
    $t = preg_replace('/\[cite\s*:[^\]]*\]/i', '', $t) ?? $t;

    return trim($t);
}

function trytest_sql_strip_sql_comments(string $sql): string
{
    $s = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql) ?? $sql;
    $lines = preg_split('/\R/', $s) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $p = strpos($line, '--');
        if ($p !== false) {
            $line = substr($line, 0, $p);
        }
        $out[] = $line;
    }

    return trim(implode("\n", $out));
}

/**
 * @return array{0:PDO,1:?string} [pdo, error]
 */
function trytest_sql_new_sandbox(): array
{
    try {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');

        return [$pdo, null];
    } catch (Throwable $e) {
        return [null, 'Could not start SQL sandbox.'];
    }
}

/** @return ?string error message */
function trytest_sql_run_setup(PDO $pdo, string $setupSql): ?string
{
    try {
        $pdo->exec($setupSql);
    } catch (Throwable $e) {
        return 'Setup error (contact instructor): ' . $e->getMessage();
    }

    return null;
}

/**
 * @return array{rows:list<array<string,mixed>>,error:?string}
 */
function trytest_sql_run_select(PDO $pdo, string $sql, int $maxRows = 500): array
{
    try {
        $st = $pdo->query($sql);
        if ($st === false) {
            return ['rows' => [], 'error' => 'Query failed.'];
        }
        $rows = [];
        $n = 0;
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $r;
            $n++;
            if ($n >= $maxRows) {
                break;
            }
        }

        return ['rows' => $rows, 'error' => null];
    } catch (Throwable $e) {
        return ['rows' => [], 'error' => $e->getMessage()];
    }
}

/** @return array<string,string|float|int> */
function trytest_sql_normalize_cell(mixed $v): array
{
    if ($v === null) {
        return ['t' => 'null', 'v' => ''];
    }
    if (is_bool($v)) {
        return ['t' => 'bool', 'v' => $v ? '1' : '0'];
    }
    if (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1)) {
        return ['t' => 'int', 'v' => (string) (int) $v];
    }
    if (is_float($v) || (is_string($v) && is_numeric($v) && str_contains((string) $v, '.'))) {
        $f = (float) $v;

        return ['t' => 'float', 'v' => sprintf('%.8f', $f)];
    }
    $s = strtolower(trim((string) $v));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return ['t' => 'text', 'v' => $s];
}

/**
 * Canonical multiset key per row for comparison (column-order independent).
 */
function trytest_sql_row_signature(array $row): string
{
    $cells = [];
    foreach ($row as $k => $v) {
        $key = strtolower(trim((string) $k));
        $key = preg_replace('/[^a-z0-9_]/', '_', $key) ?? $key;
        $norm = trytest_sql_normalize_cell($v);
        $cells[$key] = $norm['t'] . ':' . $norm['v'];
    }
    ksort($cells);

    return json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/**
 * Multiset overlap similarity in [0,1]: balanced precision/recall on row counts.
 *
 * @param list<array<string,mixed>> $expected
 * @param list<array<string,mixed>> $actual
 * @return array{f1:float,precision:float,recall:float,expected_rows:int,actual_rows:int,matched:int}
 */
function trytest_sql_compare_result_sets(array $expected, array $actual): array
{
    $e = count($expected);
    $a = count($actual);
    if ($e === 0 && $a === 0) {
        return [
            'f1' => 1.0,
            'precision' => 1.0,
            'recall' => 1.0,
            'expected_rows' => 0,
            'actual_rows' => 0,
            'matched' => 0,
        ];
    }

    $expCounts = [];
    foreach ($expected as $r) {
        $sig = trytest_sql_row_signature($r);
        $expCounts[$sig] = ($expCounts[$sig] ?? 0) + 1;
    }
    $actCounts = [];
    foreach ($actual as $r) {
        $sig = trytest_sql_row_signature($r);
        $actCounts[$sig] = ($actCounts[$sig] ?? 0) + 1;
    }
    $matched = 0;
    foreach ($actCounts as $sig => $n) {
        if (!isset($expCounts[$sig])) {
            continue;
        }
        $matched += min($n, $expCounts[$sig]);
    }
    $precision = $a > 0 ? $matched / $a : 0.0;
    $recall = $e > 0 ? $matched / $e : ($a === 0 ? 1.0 : 0.0);
    $f1 = ($precision + $recall) > 0 ? (2 * $precision * $recall) / ($precision + $recall) : 0.0;

    return [
        'f1' => $f1,
        'precision' => $precision,
        'recall' => $recall,
        'expected_rows' => $e,
        'actual_rows' => $a,
        'matched' => $matched,
    ];
}

/**
 * Map result-set similarity to 0–10 marks. Full marks when F1 reaches the quiz's "correct"
 * threshold (not requiring a perfect 100% overlap).
 */
function trytest_sql_marks_from_similarity(float $f1, float $simCorrect): int
{
    $den = max($simCorrect, 1e-9);
    $ratio = min(1.0, max(0.0, $f1 / $den));
    return max(0, min(10, (int) round(10.0 * $ratio)));
}

/**
 * @return list<string>
 */
function trytest_sql_feedback_lines(array $cmp, float $f1, array $hints): array
{
    $lines = [];
    $lines[] = sprintf(
        'Row overlap score: %d%% (matched %d of ~expected %d rows, got %d rows).',
        (int) round(100 * $f1),
        (int) ($cmp['matched'] ?? 0),
        (int) ($cmp['expected_rows'] ?? 0),
        (int) ($cmp['actual_rows'] ?? 0)
    );
    if ($f1 >= 0.92) {
        $lines[] = 'Your result set matches the expected shape closely — well done.';
    } elseif (($cmp['actual_rows'] ?? 0) > ($cmp['expected_rows'] ?? 0) * 1.2) {
        $lines[] = 'Tip: you may be returning too many rows — try adding filters (WHERE), grouping, or DISTINCT.';
    } elseif (($cmp['actual_rows'] ?? 0) < ($cmp['expected_rows'] ?? 0) * 0.8 && ($cmp['expected_rows'] ?? 0) > 0) {
        $lines[] = 'Tip: some expected rows are missing — check JOIN types, NULL handling, or filters that are too strict.';
    } elseif (($cmp['recall'] ?? 0) < ($cmp['precision'] ?? 0)) {
        $lines[] = 'Tip: recall is lower than precision — widen conditions or joins so you include all required rows.';
    } elseif (($cmp['precision'] ?? 0) < ($cmp['recall'] ?? 0)) {
        $lines[] = 'Tip: precision is lower — tighten WHERE / HAVING so extra rows drop out.';
    }
    foreach ($hints as $h) {
        $lines[] = 'Hint: ' . $h;
    }

    return $lines;
}

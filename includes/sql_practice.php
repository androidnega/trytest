<?php

declare(strict_types=1);

/**
 * SQL practical tasks: student SQL runs in a fresh in-memory SQLite sandbox; grading compares result sets.
 *
 * How it works:
 * - Admin stores JSON in questions.sql_practice: setup_sql, reference_sql (usually a SELECT to compare),
 *   optional hints[], thresholds, optional golden_sql (see below).
 * - SELECT / WITH … SELECT answers: after setup, we run reference_sql then student_sql on the same DB (read-only).
 * - INSERT/UPDATE/DELETE/DDL answers: after setup we run the student statement, then reference_sql on that DB.
 *   Expected rows come from a second sandbox: setup → optional golden_sql (instructor solution) → reference_sql.
 *   For DML tasks, configure golden_sql with the canonical INSERT/UPDATE/etc. so outcomes can be compared.
 * - We compare result *sets* (multiset of normalized rows). Lenient F1-style overlap; configurable thresholds.
 */

/** @param array<string,mixed> $public */
function trytest_sql_practice_strip_secrets(array $public): array
{
    unset($public['reference_sql'], $public['golden_sql'], $public['compare_sql'], $public['verify_sql']);

    return $public;
}

/**
 * Remove lines that contain only paste artifact line numbers (e.g. PDF exports).
 */
function trytest_sql_strip_line_number_only_lines(string $sql): string
{
    $sql = trim($sql);
    $lines = preg_split('/\R/', $sql) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*\d+\s*$/', $line) === 1) {
            continue;
        }
        $kept[] = $line;
    }

    return trim(implode("\n", $kept));
}

/**
 * Keep the first INSERT/REPLACE/UPDATE/DELETE … statement (through optional ';').
 * INSERT may appear after stray SELECT/line-number junk; plain SELECT/WITH queries are left intact.
 */
function trytest_sql_extract_first_dml_statement(string $sql): string
{
    $sql = trim($sql);
    $insertPatterns = [
        '/\bINSERT\s+OR\s+REPLACE\s+INTO\b/is',
        '/\bINSERT\s+INTO\b/is',
        '/\bREPLACE\s+INTO\b/is',
    ];
    foreach ($insertPatterns as $re) {
        if (preg_match($re, $sql, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $rest = substr($sql, (int) $start);
            $semi = strpos($rest, ';');
            if ($semi !== false) {
                $rest = substr($rest, 0, $semi + 1);
            }

            return trim($rest);
        }
    }

    $head = trytest_sql_strip_sql_comments(rtrim($sql, ';'));
    if ($head !== '' && preg_match('/^\s*(WITH|SELECT)\b/is', $head) === 1) {
        return $sql;
    }

    if (preg_match('/^\s*UPDATE\s+/is', $sql)) {
        $semi = strpos($sql, ';');

        return $semi !== false ? trim(substr($sql, 0, $semi + 1)) : $sql;
    }

    if (preg_match('/^\s*DELETE\s+FROM\b/is', $sql)) {
        $semi = strpos($sql, ';');

        return $semi !== false ? trim(substr($sql, 0, $semi + 1)) : $sql;
    }

    return $sql;
}

/**
 * Infer SELECT * FROM … for grading when the model answer is a single-table DML statement.
 */
function trytest_sql_infer_compare_select_from_model_dml(string $sql): ?string
{
    $san = trytest_sql_strip_sql_comments(rtrim(trim($sql), ';'));
    if ($san === '') {
        return null;
    }
    $insertForms = [
        '/\bINSERT\s+OR\s+REPLACE\s+INTO\s+(?:[`"]?(\w+)[`"]?\.)?[`"]?(\w+)[`"]?\s*(?:\(|\s+VALUES\b|\s+DEFAULT\s+VALUES\b|\s+SELECT\b|\s+WITH\b)/is',
        '/\bINSERT\s+INTO\s+(?:[`"]?(\w+)[`"]?\.)?[`"]?(\w+)[`"]?\s*(?:\(|\s+VALUES\b|\s+DEFAULT\s+VALUES\b|\s+SELECT\b|\s+WITH\b)/is',
        '/\bREPLACE\s+INTO\s+(?:[`"]?(\w+)[`"]?\.)?[`"]?(\w+)[`"]?\s*(?:\(|\s+VALUES\b|\s+DEFAULT\s+VALUES\b|\s+SELECT\b|\s+WITH\b)/is',
    ];
    foreach ($insertForms as $formRe) {
        if (preg_match($formRe, $san, $m) === 1) {
            return 'SELECT * FROM ' . $m[2];
        }
    }
    if (preg_match('/\bUPDATE\s+(?:[`"]?(\w+)[`"]?\.)?[`"]?(\w+)[`"]?\s+SET\b/is', $san, $m) === 1) {
        return 'SELECT * FROM ' . $m[2];
    }
    if (preg_match('/\bDELETE\s+FROM\s+(?:[`"]?(\w+)[`"]?\.)?[`"]?(\w+)[`"]?\b/is', $san, $m) === 1) {
        return 'SELECT * FROM ' . $m[2];
    }

    return null;
}

/**
 * @param array<string,mixed>|null $decoded
 * @return array{ok:bool,error?:string,setup?:string,reference?:string,golden?:string,hints:list<string>,simCorrect:float,simPartial:float}
 */
function trytest_sql_practice_parse_config(?array $decoded): array
{
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid SQL practice configuration.'];
    }
    $setup = trim((string) ($decoded['setup_sql'] ?? ''));
    $ref = trim((string) ($decoded['reference_sql'] ?? ''));
    $golden = trim((string) ($decoded['golden_sql'] ?? ''));
    $compare = trim((string) ($decoded['compare_sql'] ?? $decoded['verify_sql'] ?? ''));

    $ref = preg_replace('/^\xEF\xBB\xBF/', '', $ref);
    $golden = preg_replace('/^\xEF\xBB\xBF/', '', $golden);
    $compare = preg_replace('/^\xEF\xBB\xBF/', '', $compare);

    $ref = trytest_sql_extract_first_dml_statement(trytest_sql_strip_line_number_only_lines($ref));
    $golden = trytest_sql_strip_line_number_only_lines($golden);
    if ($golden !== '') {
        $golden = trytest_sql_extract_first_dml_statement($golden);
    }
    $compare = trytest_sql_strip_line_number_only_lines($compare);

    if ($ref === '') {
        return ['ok' => false, 'error' => 'sql_practice JSON must include reference_sql (and optional setup_sql).'];
    }

    $coreR = trytest_sql_strip_sql_comments(rtrim(trim($ref), ';'));
    $coreG = trytest_sql_strip_sql_comments(rtrim(trim($golden), ';'));
    if (
        $golden !== ''
        && $ref !== ''
        && trytest_sql_student_answer_is_select($coreG)
        && !trytest_sql_student_answer_is_select($coreR)
        && preg_match('/\b(INSERT|REPLACE|UPDATE|DELETE)\b/is', $coreR) === 1
    ) {
        [$ref, $golden] = [$golden, $ref];
    }

    $coreRefBeforeSwap = trytest_sql_strip_sql_comments(rtrim(trim($ref), ';'));
    if (
        $golden === ''
        && $compare === ''
        && $coreRefBeforeSwap !== ''
        && !trytest_sql_student_answer_is_select($coreRefBeforeSwap)
    ) {
        $inferred = trytest_sql_infer_compare_select_from_model_dml($ref);
        if ($inferred !== null) {
            $golden = $ref;
            $ref = $inferred;
        }
    }

    $coreForSwap = trytest_sql_strip_sql_comments(rtrim(trim($ref), ';'));
    if (
        $golden === ''
        && $compare !== ''
        && $coreForSwap !== ''
        && !trytest_sql_student_answer_is_select($coreForSwap)
    ) {
        $golden = $ref;
        $ref = $compare;
    }

    $coreRef = trytest_sql_strip_sql_comments(rtrim(trim($ref), ';'));
    if ($coreRef === '' || !trytest_sql_student_answer_is_select($coreRef)) {
        return [
            'ok' => false,
            'error' => 'reference_sql must be a SELECT (for comparing rows). For INSERT/UPDATE homework you can either: (1) reference_sql = SELECT … and golden_sql = model statement, or (2) reference_sql = model INSERT/UPDATE and compare_sql (or verify_sql) = the SELECT that checks the result.',
        ];
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
        'golden' => $golden,
        'hints' => $hints,
        'simCorrect' => $simCorrect,
        'simPartial' => $simPartial,
    ];
}

/**
 * Basic safety checks only (sandbox is in-memory SQLite). Allows SELECT, DML, DDL.
 *
 * @return string|null Error message or null if OK.
 */
function trytest_sql_student_query_allowed(string $sql): ?string
{
    $t = trim($sql);
    if ($t === '') {
        return 'Write a SQL statement.';
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
    // Avoid attaching external databases from the student script.
    if (preg_match('/\b(ATTACH|DETACH)\b/i', $san) === 1) {
        return 'ATTACH and DETACH are not allowed here.';
    }

    return null;
}

/**
 * True if the statement is treated as a read-only SELECT (same-sandbox grading as before).
 * Pasted scaffolding like "SELECT" + blank lines + INSERT INTO counts as mutating (INSERT path).
 */
function trytest_sql_student_answer_is_select(string $sanitizedSql): bool
{
    $t = trim($sanitizedSql);
    if ($t === '') {
        return false;
    }
    if (preg_match('/\bINSERT\s+INTO\b/is', $t) === 1) {
        return false;
    }
    if (preg_match('/\bREPLACE\s+INTO\b/is', $t) === 1) {
        return false;
    }
    if (preg_match('/\bUPDATE\s+/is', $t) === 1) {
        return false;
    }
    if (preg_match('/\bDELETE\s+FROM\b/is', $t) === 1) {
        return false;
    }
    if (preg_match('/\b(CREATE|DROP|ALTER|TRUNCATE)\s+/is', $t) === 1) {
        return false;
    }

    return preg_match('/^\s*(WITH|SELECT)\b/is', $t) === 1;
}

/**
 * Run a mutating or DDL statement (not a row-returning SELECT).
 *
 * @return string|null SQLite error message or null on success.
 */
function trytest_sql_exec_statement(PDO $pdo, string $sql): ?string
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        return $e->getMessage();
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
 * Clean pasted noise (line numbers) then isolate the first DML statement where applicable.
 */
function trytest_sql_normalize_student_sql(string $sql): string
{
    return trytest_sql_extract_first_dml_statement(trytest_sql_strip_line_number_only_lines($sql));
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
 * @param list<string> $hints Author hints from JSON (shown only when $includeConfiguredHints is true).
 *
 * @return list<string>
 */
function trytest_sql_feedback_lines(array $cmp, float $f1, array $hints, bool $includeConfiguredHints = true): array
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
    if ($includeConfiguredHints) {
        foreach ($hints as $h) {
            $lines[] = 'Hint: ' . $h;
        }
    }

    return $lines;
}

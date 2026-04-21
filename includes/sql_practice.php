<?php

declare(strict_types=1);

/**
 * SQL practical tasks: student SQL runs only in a fresh in-memory SQLite sandbox (`sqlite::memory:`).
 * It never uses or mutates the application quiz database (config/db.php); each grade request opens a new PDO sandbox.
 *
 * Flow: new in-memory DB → run setup_sql (CREATE/INSERT, etc.) → run student + reference queries → compare rows.
 *
 * How it works:
 * - Admin stores JSON in questions.sql_practice: setup_sql, reference_sql (usually a SELECT to compare),
 *   optional hints[], thresholds, optional golden_sql (see below).
 * - SELECT / WITH … SELECT answers: after setup, we run reference_sql then student_sql on the same DB (read-only).
 * - INSERT/UPDATE answers (and similar): after setup we run the student statement, then reference_sql on that DB.
 *   Student submissions of DELETE are rejected by trytest_sql_student_query_allowed (sandbox safety); model DELETE may still run as golden_sql server-side.
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
    if ($golden === '') {
        $golden = trim((string) ($decoded['canonical_dml'] ?? $decoded['model_dml'] ?? $decoded['model_insert'] ?? ''));
    }
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
 * Basic safety checks for student SQL in the in-memory sandbox (not the production DB).
 * Blocks schema/destructive patterns; INSERT/UPDATE/REPLACE still allowed for graded DML tasks.
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
    // Destructive / schema-changing in the practice DB (narrow patterns to avoid matching inside strings).
    if (preg_match('/\bDELETE\s+FROM\b/i', $san) === 1) {
        return 'DELETE is not allowed in the practice sandbox.';
    }
    if (preg_match('/\bDROP\s+(?:TABLE|VIEW|INDEX|TRIGGER|DATABASE|SCHEMA)\b/i', $san) === 1) {
        return 'DROP is not allowed in the practice sandbox.';
    }
    if (preg_match('/\bALTER\s+(?:TABLE|VIEW|INDEX)\b/i', $san) === 1) {
        return 'ALTER is not allowed in the practice sandbox.';
    }
    if (preg_match('/\bTRUNCATE\s+/i', $san) === 1) {
        return 'TRUNCATE is not allowed in the practice sandbox.';
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

/**
 * Split SQL into statements on `;` outside of single-quoted strings (SQLite '...' with '' escapes).
 *
 * @return list<string>
 */
function trytest_sql_split_into_statements(string $sql): array
{
    $sql = trim($sql);
    if ($sql === '') {
        return [];
    }
    $parts = [];
    $buf = '';
    $len = strlen($sql);
    $inString = false;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inString) {
            $buf .= $c;
            if ($c === "'" && ($i + 1 < $len && $sql[$i + 1] === "'")) {
                $buf .= $sql[++$i];
            } elseif ($c === "'") {
                $inString = false;
            }
        } elseif ($c === "'") {
            $inString = true;
            $buf .= $c;
        } elseif ($c === ';') {
            $t = trim($buf);
            if ($t !== '') {
                $parts[] = $t;
            }
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    $t = trim($buf);
    if ($t !== '') {
        $parts[] = $t;
    }

    return $parts;
}

/** @return ?string error message */
function trytest_sql_run_setup(PDO $pdo, string $setupSql): ?string
{
    $setupSql = trim($setupSql);
    if ($setupSql === '') {
        return null;
    }
    $parts = trytest_sql_split_into_statements($setupSql);
    if ($parts === []) {
        return null;
    }
    foreach ($parts as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (Throwable $e) {
            return 'Setup error (contact instructor): ' . $e->getMessage();
        }
    }

    return null;
}

/**
 * Extract bare table name from SQLite "no such table" errors (e.g. main.Products).
 */
function trytest_sql_parse_no_such_table_name(string $msg): ?string
{
    if (preg_match('/no such table:\s*(?:\w+\.)?[`"\[]?(\w+)[`"\]]?/i', $msg, $m) === 1) {
        return $m[1];
    }

    return null;
}

/**
 * Content between balanced parentheses starting at $openIdx (must point at '(').
 */
function trytest_sql_extract_between_parens(string $s, int $openIdx): ?string
{
    if (!isset($s[$openIdx]) || $s[$openIdx] !== '(') {
        return null;
    }
    $depth = 1;
    $inStr = false;
    $len = strlen($s);
    $start = $openIdx + 1;
    for ($i = $openIdx + 1; $i < $len; $i++) {
        $c = $s[$i];
        if ($inStr) {
            if ($c === "'" && $i + 1 < $len && $s[$i + 1] === "'") {
                $i++;
            } elseif ($c === "'") {
                $inStr = false;
            }
        } elseif ($c === "'") {
            $inStr = true;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
            if ($depth === 0) {
                return substr($s, $start, $i - $start);
            }
        }
    }

    return null;
}

/** Commas separating top-level SQL value expressions (depth 0, outside strings). */
function trytest_sql_count_value_commas(string $tupleInner): int
{
    $depth = 0;
    $inStr = false;
    $commas = 0;
    $len = strlen($tupleInner);
    for ($i = 0; $i < $len; $i++) {
        $c = $tupleInner[$i];
        if ($inStr) {
            if ($c === "'" && $i + 1 < $len && $tupleInner[$i + 1] === "'") {
                $i++;
            } elseif ($c === "'") {
                $inStr = false;
            }
        } elseif ($c === "'") {
            $inStr = true;
        } elseif ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth = max(0, $depth - 1);
        } elseif ($c === ',' && $depth === 0) {
            $commas++;
        }
    }

    return $commas;
}

/**
 * Build CREATE TABLE ... (col TEXT, ...) from INSERT INTO t (cols) VALUES ...
 */
function trytest_sql_infer_create_from_insert_columns(string $wantTable, string $sql): ?string
{
    $san = trytest_sql_strip_sql_comments($sql);
    if (
        preg_match(
            '/\bINSERT\s+(?:OR\s+\w+\s+)?INTO\s+(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(\w+)[`"\]]?\s*\(([^)]+)\)\s*VALUES/is',
            $san,
            $m
        ) !== 1
    ) {
        return null;
    }
    if (strcasecmp($m[1], $wantTable) !== 0) {
        return null;
    }
    $tname = $m[1];
    $rawCols = trim($m[2]);
    if ($rawCols === '') {
        return null;
    }
    $parts = array_map('trim', explode(',', $rawCols));
    $defs = [];
    foreach ($parts as $col) {
        if ($col === '') {
            continue;
        }
        $col = preg_replace('/^[`"\[]+|[`"\]]+$/', '', $col);
        if ($col === '') {
            continue;
        }
        $defs[] = $col . ' TEXT';
    }
    if ($defs === []) {
        return null;
    }

    return 'CREATE TABLE IF NOT EXISTS ' . $tname . ' (' . implode(', ', $defs) . ')';
}

/**
 * INSERT INTO t VALUES (...) without column list — infer arity only (all TEXT columns).
 */
function trytest_sql_infer_create_from_insert_values(string $wantTable, string $sql): ?string
{
    $san = trytest_sql_strip_sql_comments($sql);
    if (
        preg_match(
            '/\bINSERT\s+(?:OR\s+\w+\s+)?INTO\s+(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(\w+)[`"\]]?\s+VALUES\s*\(/is',
            $san,
            $m,
            PREG_OFFSET_CAPTURE
        ) !== 1
    ) {
        return null;
    }
    if (strcasecmp($m[1][0], $wantTable) !== 0) {
        return null;
    }
    $tname = $m[1][0];
    $openIdx = $m[0][1] + strlen($m[0][0]) - 1;
    $inner = trytest_sql_extract_between_parens($san, $openIdx);
    if ($inner === null || trim($inner) === '') {
        return null;
    }
    $n = trytest_sql_count_value_commas($inner) + 1;
    if ($n < 1) {
        return null;
    }
    $defs = [];
    for ($i = 0; $i < $n; $i++) {
        $defs[] = 'c' . ($i + 1) . ' TEXT';
    }

    return 'CREATE TABLE IF NOT EXISTS ' . $tname . ' (' . implode(', ', $defs) . ')';
}

/**
 * SELECT a, b FROM table — build minimal schema (skips SELECT *).
 */
function trytest_sql_infer_create_from_select_list(string $wantTable, string $sql): ?string
{
    $san = trytest_sql_strip_sql_comments($sql);
    if (
        preg_match(
            '/\bSELECT\s+(?:DISTINCT\s+)?(.+?)\s+FROM\s+(?:[`"\[]?\w+[`"\]]?\.)?[`"\[]?(\w+)\b/is',
            $san,
            $m
        ) !== 1
        || strcasecmp($m[2], $wantTable) !== 0
    ) {
        return null;
    }
    $tname = $m[2];
    $list = trim($m[1]);
    if ($list === '' || preg_match('/^\s*\*\s*$/', $list) === 1) {
        return null;
    }
    $parts = array_map('trim', explode(',', $list));
    $defs = [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        if (preg_match('/\bas\s+([`"\[]?\w+[`"\]]?)\s*$/i', $p, $am) === 1) {
            $name = trim($am[1], '`"[]');
        } elseif (preg_match('/^[`"\[]?(\w+)[`"\]]?$/', $p, $bm) === 1) {
            $name = $bm[1];
        } elseif (preg_match('/\b(\w+)\s*$/', $p, $cm) === 1) {
            $name = $cm[1];
        } else {
            continue;
        }
        if (strcasecmp($name, $wantTable) === 0) {
            continue;
        }
        $defs[] = $name . ' TEXT';
    }
    if ($defs === []) {
        return null;
    }

    return 'CREATE TABLE IF NOT EXISTS ' . $tname . ' (' . implode(', ', $defs) . ')';
}

/**
 * When setup fails with "no such table", infer a minimal CREATE from instructor/student SQL (quiz sandbox only).
 */
function trytest_sql_infer_create_for_missing_table(
    string $missingTable,
    string $goldenSql,
    string $setupSql,
    string $studentSql,
    string $referenceSql
): ?string {
    $sources = [$goldenSql, $setupSql, $studentSql, $referenceSql];
    foreach ($sources as $src) {
        $src = trim($src);
        if ($src === '') {
            continue;
        }
        $c = trytest_sql_infer_create_from_insert_columns($missingTable, $src);
        if ($c !== null) {
            return $c;
        }
        $c = trytest_sql_infer_create_from_insert_values($missingTable, $src);
        if ($c !== null) {
            return $c;
        }
    }
    foreach ($sources as $src) {
        $src = trim($src);
        if ($src === '') {
            continue;
        }
        $c = trytest_sql_infer_create_from_select_list($missingTable, $src);
        if ($c !== null) {
            return $c;
        }
    }

    return null;
}

/**
 * Open in-memory DB and apply setup; on "no such table", prepend inferred CREATE and retry on a fresh DB.
 *
 * @return array{0: ?PDO, 1: ?string, 2: string} PDO, error message or null, setup string actually applied
 */
function trytest_sql_prepare_sandbox(
    string $setup,
    string $goldenSql,
    string $referenceSql,
    string $studentSql
): array {
    [$pdo, $initErr] = trytest_sql_new_sandbox();
    if ($initErr !== null || !$pdo instanceof PDO) {
        return [null, $initErr ?? 'Could not start SQL sandbox.', $setup];
    }
    $err = trytest_sql_run_setup($pdo, $setup);
    if ($err === null) {
        return [$pdo, null, $setup];
    }
    $missing = trytest_sql_parse_no_such_table_name($err);
    $prepend = null;
    if ($missing !== null) {
        $prepend = trytest_sql_infer_create_for_missing_table($missing, $goldenSql, $setup, $studentSql, $referenceSql);
    }
    if ($prepend === null) {
        return [$pdo, $err, $setup];
    }
    $combined = trim($prepend) . "\n" . $setup;
    [$pdo2, $e2] = trytest_sql_new_sandbox();
    if ($e2 !== null || !$pdo2 instanceof PDO) {
        return [null, $e2 ?? 'Could not start SQL sandbox.', $setup];
    }
    $err3 = trytest_sql_run_setup($pdo2, $combined);
    if ($err3 !== null) {
        return [$pdo2, $err3, $combined];
    }

    return [$pdo2, null, $combined];
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
 * Quiz-only: always return HTTP 200 with graded payload so the client can record marks (often 0) and continue.
 *
 * @param list<string> $feedback
 */
function trytest_sql_emit_graded_wrong(
    array $feedback,
    ?string $sqliteError = null,
    ?int $expectedRows = null,
    ?int $actualRows = null
): never {
    echo json_encode(
        [
            'ok' => true,
            'graded' => true,
            'verdict' => 'wrong',
            'similarity' => 0.0,
            'marks' => 0,
            'marks_max' => 10,
            'feedback' => $feedback,
            'sqlite_error' => $sqliteError,
            'expected_rows' => $expectedRows,
            'actual_rows' => $actualRows,
        ],
        JSON_THROW_ON_ERROR
    );
    exit;
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

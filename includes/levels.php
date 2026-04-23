<?php

declare(strict_types=1);

/**
 * Canonical level token for matching (e.g. "Level 100", "Lv200" → "100", "200").
 */
function trytest_level_canon(string $level): string
{
    $t = trim($level);
    if ($t === '') {
        return '';
    }
    if (preg_match('/(\d{1,4})\b/', $t, $m) === 1) {
        return (string) (int) $m[1];
    }

    return strtolower($t);
}

/**
 * Admin-managed `levels` rows merged with levels still present on courses, users, quizzes, and PDFs.
 *
 * @return list<array{value:string,label:string}>
 */
function trytest_level_dropdown_options(PDO $db): array
{
    $out = [];
    $seen = [];
    try {
        $stmt = $db->query(
            'SELECT value FROM levels WHERE trim(COALESCE(value, \'\')) != \'\'
             ORDER BY sort_order ASC, CAST(value AS INTEGER) ASC, value COLLATE NOCASE'
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $rows = [];
    }
    foreach ($rows as $v) {
        $s = trim((string) $v);
        if ($s === '') {
            continue;
        }
        $k = strtolower($s);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = ['value' => $s, 'label' => $s];
    }

    try {
        $stmt2 = $db->query(
            "SELECT DISTINCT trim(x.l) AS l FROM (
                SELECT trim(level) AS l FROM courses WHERE trim(COALESCE(level, '')) != ''
                UNION
                SELECT trim(level) AS l FROM users WHERE trim(COALESCE(level, '')) != ''
                UNION
                SELECT trim(level) AS l FROM quizzes WHERE trim(COALESCE(level, '')) != ''
                UNION
                SELECT trim(level) AS l FROM student_documents WHERE trim(COALESCE(level, '')) != ''
            ) AS x WHERE trim(x.l) != ''"
        );
        $extra = $stmt2 ? $stmt2->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $extra = [];
    }
    foreach ($extra as $v) {
        $s = trim((string) $v);
        if ($s === '') {
            continue;
        }
        $k = strtolower($s);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = ['value' => $s, 'label' => $s];
    }

    usort(
        $out,
        static function (array $a, array $b): int {
            $va = (int) preg_replace('/\D/', '', $a['value']);
            $vb = (int) preg_replace('/\D/', '', $b['value']);
            if ($va > 0 && $vb > 0 && $va !== $vb) {
                return $va <=> $vb;
            }

            return strcasecmp($a['value'], $b['value']);
        }
    );

    return $out;
}

/**
 * @param list<array{value:string,label:string}> $levelOptions
 */
function trytest_resolve_level_for_save(string $raw, array $levelOptions): ?string
{
    if ($levelOptions === []) {
        return null;
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $want = trytest_level_canon($raw);
    foreach ($levelOptions as $opt) {
        $v = trim((string) ($opt['value'] ?? ''));
        if ($v === '') {
            continue;
        }
        if (strcasecmp($v, $raw) === 0) {
            return $v;
        }
        if ($want !== '' && trytest_level_canon($v) === $want) {
            return $v;
        }
    }

    return null;
}

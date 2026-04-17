<?php

declare(strict_types=1);

/**
 * Distinct program/department labels for student dropdowns and admin UI.
 * Merges admin-managed `departments` rows with values still present on `courses`.
 *
 * @return list<array{value:string,label:string}>
 */
function trytest_department_dropdown_options(PDO $db): array
{
    try {
        $stmt = $db->query(
            "SELECT DISTINCT trim(x.d) AS d FROM (
                SELECT trim(name) AS d FROM departments WHERE trim(COALESCE(name, '')) != ''
                UNION
                SELECT trim(department) AS d FROM courses WHERE trim(COALESCE(department, '')) != ''
            ) AS x
            WHERE trim(x.d) != ''
            ORDER BY d COLLATE NOCASE"
        );
        $vals = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Throwable $e) {
        $vals = [];
    }
    $out = [];
    foreach ($vals as $v) {
        $s = trim((string) $v);
        if ($s === '') {
            continue;
        }
        $out[] = ['value' => $s, 'label' => $s];
    }
    return $out;
}

/**
 * Normalize a posted department against the allowed list.
 * When there are no program options, students save an empty department (legacy behaviour).
 * When options exist, the value must match one option (case-insensitive).
 *
 * @param list<array{value:string,label:string}> $departmentOptions
 * @return string|null canonical department, empty string when no options list, or null if invalid / missing when required
 */
function trytest_resolve_department_for_save(string $raw, array $departmentOptions): ?string
{
    if ($departmentOptions === []) {
        return '';
    }
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach ($departmentOptions as $opt) {
        $v = trim((string) ($opt['value'] ?? ''));
        if ($v !== '' && strcasecmp($v, $raw) === 0) {
            return $v;
        }
    }

    return null;
}

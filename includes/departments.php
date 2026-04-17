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

<?php

declare(strict_types=1);

/**
 * Distinct program/department labels for student dropdowns and admin UI.
 * Merges admin-managed `departments` rows with values still present on `courses`.
 *
 * @return list<array{value:string,label:string}>
 */
/** Count of distinct department labels (same merge as trytest_department_dropdown_options, without building rows). */
function trytest_department_dropdown_option_count(PDO $db): int
{
    try {
        $stmt = $db->query(
            "SELECT COUNT(*) FROM (
                SELECT DISTINCT trim(x.d) AS d FROM (
                    SELECT trim(name) AS d FROM departments WHERE trim(COALESCE(name, '')) != ''
                    UNION
                    SELECT trim(department) AS d FROM courses WHERE trim(COALESCE(department, '')) != ''
                ) AS x
                WHERE trim(x.d) != ''
            ) AS t"
        );

        return $stmt ? (int) $stmt->fetchColumn() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

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

/**
 * Whether a course/document department target matches the student's program.
 * Empty course department = visible to all programs (same rule as PDFs).
 */
function trytest_department_matches(?string $targetDepartment, string $userDepartment): bool
{
    $target = trim((string) $targetDepartment);
    $user = trim($userDepartment);
    if ($target === '') {
        return true;
    }

    return $user !== '' && strcasecmp($target, $user) === 0;
}

/**
 * True when the student must (re)pick a program: empty, or no longer in the live presets
 * (deleted/renamed department), so quizzes can line up again.
 *
 * @param list<array{value:string,label:string}> $departmentOptions
 */
function trytest_student_department_needs_refresh(string $userDepartment, array $departmentOptions): bool
{
    if ($departmentOptions === []) {
        return false;
    }
    $userDepartment = trim($userDepartment);
    if ($userDepartment === '') {
        return true;
    }

    return trytest_resolve_department_for_save($userDepartment, $departmentOptions) === null;
}

/**
 * Soft prompt: program is set but still no quizzes for this cohort (wrong program / level).
 *
 * @param list<array{value:string,label:string}> $departmentOptions
 * @param list<array<string,mixed>> $coursesWithQuizzes
 */
function trytest_student_should_offer_department_change(
    string $userDepartment,
    array $departmentOptions,
    array $coursesWithQuizzes
): bool {
    if ($departmentOptions === []) {
        return false;
    }
    if (trytest_student_department_needs_refresh($userDepartment, $departmentOptions)) {
        return true;
    }
    $quizCount = 0;
    foreach ($coursesWithQuizzes as $course) {
        $quizCount += count((array) ($course['quizzes'] ?? []));
        if ($quizCount > 0) {
            return false;
        }
    }

    return trim($userDepartment) !== '';
}

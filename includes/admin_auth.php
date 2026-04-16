<?php

declare(strict_types=1);

/**
 * Database-backed administrator authentication (no hardcoded credentials).
 */

function trytest_admin_username_valid(string $username): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9._-]{2,64}$/', $username);
}

function trytest_admin_count(PDO $db): int
{
    return (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
}

function trytest_admin_verify_login(PDO $db, string $username, string $password): bool
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return false;
    }
    $key = strtolower($username);
    $stmt = $db->prepare('SELECT password_hash FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false || empty($row['password_hash'])) {
        return false;
    }
    return password_verify($password, (string) $row['password_hash']);
}

function trytest_admin_attempt_login(PDO $db, string $username, string $password): bool
{
    if (!trytest_admin_verify_login($db, $username, $password)) {
        return false;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION['is_admin'] = true;
    $_SESSION['admin_username'] = strtolower(trim($username));
    return true;
}

/**
 * @return string empty on success, or error message
 */
function trytest_admin_create_first(PDO $db, string $username, string $password, string $passwordConfirm): string
{
    if (trytest_admin_count($db) > 0) {
        return 'An administrator account already exists. Sign in instead.';
    }
    $username = trim($username);
    if (!trytest_admin_username_valid($username)) {
        return 'Username must be 2–64 characters: letters, digits, dot, underscore, or hyphen.';
    }
    if ($password !== $passwordConfirm) {
        return 'Passwords do not match.';
    }
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    $key = strtolower($username);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $db->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)')->execute([$key, $hash]);
    } catch (Throwable $e) {
        return 'Could not create account. Try a different username.';
    }
    return '';
}

/**
 * @return string empty on success, or error message
 */
function trytest_admin_change_password(
    PDO $db,
    string $username,
    string $currentPassword,
    string $newPassword,
    string $newPasswordConfirm
): string {
    $username = strtolower(trim($username));
    if ($username === '') {
        return 'Not signed in.';
    }
    if (!trytest_admin_verify_login($db, $username, $currentPassword)) {
        return 'Current password is incorrect.';
    }
    if ($newPassword !== $newPasswordConfirm) {
        return 'New passwords do not match.';
    }
    if (strlen($newPassword) < 10) {
        return 'New password must be at least 10 characters.';
    }
    $stmt = $db->prepare('SELECT password_hash FROM admin_users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        return 'Account not found.';
    }
    if (password_verify($newPassword, (string) ($row['password_hash'] ?? ''))) {
        return 'Choose a password different from your current one.';
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $db->prepare('UPDATE admin_users SET password_hash = ? WHERE username = ?')->execute([$hash, $username]);
    return '';
}

function trytest_admin_logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    unset($_SESSION['is_admin'], $_SESSION['admin_username']);
}

<?php
/**
 * Simple shared login helpers for the local demo website.
 */

require_once __DIR__ . '/session.php';

function sign_in_user(array $user): void
{
    $_SESSION['user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['username'] = (string)($user['username'] ?? '');
    $_SESSION['is_admin'] = (int)($user['is_admin'] ?? 0);

    // Keep the existing names used by the games and chat pages.
    $_SESSION['games_user_id'] = $_SESSION['user_id'];
    $_SESSION['games_username'] = $_SESSION['username'];
    $_SESSION['games_is_admin'] = $_SESSION['is_admin'];
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['games_user_id'] ?? 0);
}

function current_user_is_admin(): bool
{
    return (int)($_SESSION['is_admin'] ?? $_SESSION['games_is_admin'] ?? 0) === 1;
}

function valid_username(string $username): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $username);
}

function sign_out_user(): void
{
    $_SESSION = [];
    session_destroy();
}
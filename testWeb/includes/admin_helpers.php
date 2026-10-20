<?php
require_once __DIR__ . '/contact_columns.php';

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($_SESSION['csrf']) . '">';
}

function csrfValid(): bool
{
    return isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

function setFlash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function takeFlash(): string
{
    $message = $_SESSION['flash'] ?? '';
    unset($_SESSION['flash']);
    return $message;
}

function fullName(array $user): string
{
    $initial = trim((string) ($user['middleInitial'] ?? ''));
    $middle  = $initial !== '' ? rtrim($initial, '.') . '. ' : '';
    return trim($user['firstName'] . ' ' . $middle . $user['lastName']);
}
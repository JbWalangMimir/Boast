<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$stmt = $conn->prepare(
    'SELECT id, firstName, middleInitial, lastName, email, occupation, role, status FROM boastusers WHERE id = ?'
);
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$currentUser = $stmt->get_result()->fetch_assoc();

if (!$currentUser || $currentUser['status'] !== 'active') {
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId  = (int) $currentUser['id'];
$isAdmin = $currentUser['role'] === 'admin';

if ($isAdmin && !defined('ADMIN_AREA')) {
    header('Location: ' . ADMIN_URL . '/dashboard.php');
    exit;
}

if (!$isAdmin && defined('ADMIN_AREA')) {
    header('Location: ' . BASE_URL . '/user.php');
    exit;
}
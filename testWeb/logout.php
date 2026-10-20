<?php
    session_start();
    $_SESSION = [];
    session_destroy();

    require_once __DIR__ . '/includes/config.php';
    header('Location: ' . BASE_URL . '/login.php');
exit;
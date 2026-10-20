<?php
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/../db_connect.php';
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - Boast</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/sources/css/user.css">
    <?php if (!empty($pageCss)): ?>
        <link rel="stylesheet" href="<?= BASE_URL ?>/sources/css/<?= htmlspecialchars($pageCss) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>/sources/css/theme.css">
    <script src="<?= BASE_URL ?>/sources/js/theme.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">

</head>
<body>
    <div class="grid-container">
        <header class="header">
            <div class="menu-icon" onclick="openSidebar()">
                <span class="material-symbols-outlined">menu</span>
            </div>
            <div class="header-left">

            </div>
            <div class="header-right">
                <!--<span class="material-symbols-outlined">settings</span>
                <span class="material-symbols-outlined">notifications</span>-->
            </div>
        </header>

        <?php 
            include __DIR__ . '/sidebar.php';
        ?>
        <main class="main-container">
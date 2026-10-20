<?php
require_once __DIR__ . '/../includes/auth.php';

$pageTitle = 'Settings';
$pageCss   = 'settings.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Settings</h1>

<section class="settings-card">
    <h3>Theme</h3>

    <div class="mode-switch">
        <button type="button" class="mode-btn" data-mode="light">Light</button>
        <button type="button" class="mode-btn" data-mode="dark">Dark</button>
    </div>

    <div id="swatch-grid" class="swatch-grid"></div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
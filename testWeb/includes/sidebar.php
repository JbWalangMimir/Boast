<?php
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    $inAdmin     = defined('ADMIN_AREA');

    if ($inAdmin) {
        $sidebarContact = contactColumns($conn);
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM contact_messages WHERE " . $sidebarContact['status'] . " = 'new'");
        $stmt->execute();
        $newMessages = (int) $stmt->get_result()->fetch_assoc()['n'];

        $navItems = [
            'dashboard' => ['label' => 'Dashboard', 'icon' => 'dashboard', 'url' => ADMIN_URL . '/dashboard.php'],
            'users'     => ['label' => 'User Management', 'icon' => 'group', 'url' => ADMIN_URL . '/users.php'],
            'messages'  => ['label' => 'User Reports', 'icon' => 'mail', 'url' => ADMIN_URL . '/messages.php', 'badge' => $newMessages],
        ];
        $homeUrl   = ADMIN_URL . '/dashboard.php';
        $roleLabel = 'Administrator';
    } else {
        $navItems = [
            'user'      => ['label' => 'Dashboard', 'icon' => 'dashboard', 'url' => BASE_URL . '/user.php'],
            'income' => ['label' => 'Income', 'icon' => 'payments'],
            'expenses' => ['label' => 'Expenses', 'icon' => 'shopping_cart'],
            'savings' => ['label' => 'Savings', 'icon' => 'savings'],
            'budgeting' => ['label' => 'Budgeting', 'icon' => 'receipt_long'],
            'reports' => ['label' => 'Reports', 'icon' => 'bar_chart'],
            'settings' => ['label' => 'Settings', 'icon' => 'settings'],
        ];
        $homeUrl   = BASE_URL . '/index.php';
        $roleLabel = 'Logged in';
    }

    $fullName = $_SESSION['username'] ?? 'Guest';
    $initial  = strtoupper(mb_substr($fullName, 0, 1));
?>

    <aside id="sidebar">
        <div class="sidebar-title">
            <div class="sidebar-brand">
                <a href="<?= $homeUrl ?>"><img src="<?= BASE_URL ?>/sources/images/Boast.png" alt="Boast" class="logo-website" ><h2>Boast</h2></a>
            </div>
        </div>

        <ul class="sidebar-list">
            <?php foreach ($navItems as $key => $item): ?>
                <li class="sidebar-list-item <?= $currentPage === $key ? 'active' : '' ?>">
                    <a href="<?= $item['url'] ?? PAGES_URL . '/' . $key . '.php' ?>">
                        <span class="material-symbols-outlined"><?= $item['icon'] ?></span>
                        <?= htmlspecialchars($item['label']) ?>
                        <?php if (!empty($item['badge'])): ?>
                            <span class="nav-badge"><?= (int) $item['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-user">
            <div class="user-info">
                <div class="user-avatar"><?= htmlspecialchars($initial) ?></div>
                <div class="user-text">
                    <span class="user-name"><?= htmlspecialchars($fullName) ?></span>
                    <span class="user-role"><?= $roleLabel ?></span>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" class="logout-link" title="Log out">
                <span class="material-symbols-outlined">logout</span>
            </a>
        </div>
    </aside>
<?php
define('ADMIN_AREA', true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

$monthStart = date('Y-m-01');

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'active'), 0) AS active,
            COALESCE(SUM(created_at >= ?), 0) AS new_month
       FROM boastusers"
);
$stmt->bind_param('s', $monthStart);
$stmt->execute();
$userStats = $stmt->get_result()->fetch_assoc();

$contact = contactColumns($conn);

$stmt = $conn->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(" . $contact['status'] . " = 'new'), 0) AS unread FROM contact_messages");
$stmt->execute();
$messageStats = $stmt->get_result()->fetch_assoc();

$stmt = $conn->prepare(
    'SELECT id, firstName, middleInitial, lastName, email, role, status, created_at
       FROM boastusers
      ORDER BY created_at DESC, id DESC
      LIMIT 5'
);
$stmt->execute();
$recentUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$recentMessages = [];
if ($contact['ok']) {
    $stmt = $conn->prepare(
        'SELECT ' . $contact['select'] . '
           FROM contact_messages
          ORDER BY ' . $contact['created'] . ' DESC, ' . $contact['id'] . ' DESC
          LIMIT 5'
    );
    $stmt->execute();
    $recentMessages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$pageTitle = 'Admin Dashboard';
$pageCss   = 'admin.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Admin Dashboard</h1>

<?php if (!$contact['ok']): ?>
    <p class="error-message"><?= htmlspecialchars(contactProblem($contact)) ?></p>
<?php endif; ?>

<div class="admin-stats">
    <div class="stat-card">
        <span>Total Users</span>
        <strong><?= (int) $userStats['total'] ?></strong>
    </div>
    <div class="stat-card">
        <span>Active Users</span>
        <strong><?= (int) $userStats['active'] ?></strong>
    </div>
    <div class="stat-card">
        <span>New This Month</span>
        <strong><?= (int) $userStats['new_month'] ?></strong>
    </div>
    <div class="stat-card">
        <span>Unread Messages</span>
        <strong><?= (int) $messageStats['unread'] ?></strong>
    </div>
</div>

<div class="admin-columns">
    <div class="admin-card">
        <div class="card-head">
            <h2>Recent Users</h2>
            <a href="<?= ADMIN_URL ?>/users.php" class="link-small">View all</a>
        </div>
        <?php if (count($recentUsers) === 0): ?>
            <p class="empty-note">No users yet.</p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($recentUsers as $u): ?>
                    <li>
                        <div>
                            <strong><?= htmlspecialchars(fullName($u)) ?></strong>
                            <span class="sub"><?= htmlspecialchars($u['email']) ?></span>
                        </div>
                        <div class="list-right">
                            <span class="badge badge-<?= htmlspecialchars($u['status']) ?>"><?= ucfirst($u['status']) ?></span>
                            <span class="sub"><?= date('M j, Y', strtotime($u['created_at'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <div class="card-head">
            <h2>Recent Messages</h2>
            <a href="<?= ADMIN_URL ?>/messages.php" class="link-small">View all</a>
        </div>
        <?php if (count($recentMessages) === 0): ?>
            <p class="empty-note">No messages yet.</p>
        <?php else: ?>
            <ul class="admin-list">
                <?php foreach ($recentMessages as $m): ?>
                    <li>
                        <div>
                            <strong><?= htmlspecialchars($m['name']) ?></strong>
                            <span class="sub"><?= htmlspecialchars($m['subject'] !== '' ? $m['subject'] : '(no subject)') ?></span>
                        </div>
                        <div class="list-right">
                            <span class="badge badge-<?= htmlspecialchars($m['status']) ?>"><?= ucfirst($m['status']) ?></span>
                            <span class="sub"><?= date('M j, Y', strtotime($m['created_at'])) ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
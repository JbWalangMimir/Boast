<?php
define('ADMIN_AREA', true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $targetId = (int) ($_POST['user_id'] ?? 0);

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($targetId === $userId) {
        $error = 'You cannot change your own account from here.';
    } else {
        $stmt = $conn->prepare('SELECT id FROM boastusers WHERE id = ?');
        $stmt->bind_param('i', $targetId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();

        $newStatus = $_POST['status'] ?? '';
        $newRole   = $_POST['role'] ?? '';

        if (!$exists) {
            $error = 'User not found.';
        } elseif ($action === 'set_status' && in_array($newStatus, ['active', 'inactive'], true)) {
            $stmt = $conn->prepare('UPDATE boastusers SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $newStatus, $targetId);
            $stmt->execute();
            setFlash($newStatus === 'active' ? 'User activated.' : 'User deactivated.');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } elseif ($action === 'set_role' && in_array($newRole, ['user', 'admin'], true)) {
            $stmt = $conn->prepare('UPDATE boastusers SET role = ? WHERE id = ?');
            $stmt->bind_param('si', $newRole, $targetId);
            $stmt->execute();
            setFlash('Role updated.');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } elseif ($action === 'delete_user') {
            $stmt = $conn->prepare('DELETE FROM boastusers WHERE id = ?');
            $stmt->bind_param('i', $targetId);
            $stmt->execute();
            setFlash('User and all of their data were deleted.');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = 'Invalid request.';
        }
    }
}

$flash = takeFlash();

$stmt = $conn->prepare(
    'SELECT id, firstName, middleInitial, lastName, email, mobileNumber, occupation, role, status, created_at
       FROM boastusers
      ORDER BY created_at DESC, id DESC
      LIMIT 1000'
);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'User Management';
$pageCss   = 'admin.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">User Management</h1>

<?php if ($flash): ?>
    <p class="flash"><?= htmlspecialchars($flash) ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p class="error-message"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-toolbar">
        <input type="text" id="userSearch" placeholder="Search name, email or occupation" autocomplete="off">
        <select id="roleFilter" aria-label="Role">
            <option value="">All roles</option>
            <option value="user">Users</option>
            <option value="admin">Admins</option>
        </select>
        <select id="statusFilter" aria-label="Status">
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <span class="count" id="userCount"></span>
    </div>

    <div class="table-scroll">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Occupation</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="userRows">
                <?php foreach ($users as $u): ?>
                    <?php
                        $name = fullName($u);
                        $self = (int) $u['id'] === $userId;
                        $hay  = mb_strtolower($name . ' ' . $u['email'] . ' ' . $u['occupation']);
                    ?>
                    <tr data-search="<?= htmlspecialchars($hay) ?>"
                        data-role="<?= htmlspecialchars($u['role']) ?>"
                        data-status="<?= htmlspecialchars($u['status']) ?>">
                        <td><?= htmlspecialchars($name) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= htmlspecialchars($u['occupation'] ?? '') ?></td>
                        <td><span class="badge badge-<?= htmlspecialchars($u['role']) ?>"><?= ucfirst($u['role']) ?></span></td>
                        <td><span class="badge badge-<?= htmlspecialchars($u['status']) ?>"><?= ucfirst($u['status']) ?></span></td>
                        <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                        <td>
                            <?php if ($self): ?>
                                <span class="sub">You</span>
                            <?php else: ?>
                                <div class="row-actions">
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="set_status">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'inactive' : 'active' ?>">
                                        <button type="submit" class="btn-small"><?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Change this user\'s role?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="set_role">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <input type="hidden" name="role" value="<?= $u['role'] === 'admin' ? 'user' : 'admin' ?>">
                                        <button type="submit" class="btn-small"><?= $u['role'] === 'admin' ? 'Make user' : 'Make admin' ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Delete this user and all of their data? This cannot be undone.')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="btn-small btn-danger">Delete</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const rows      = Array.from(document.querySelectorAll('#userRows tr'));
    const search    = document.getElementById('userSearch');
    const roleSel   = document.getElementById('roleFilter');
    const statusSel = document.getElementById('statusFilter');
    const counter   = document.getElementById('userCount');

    function applyFilters() {
        const q      = search.value.trim().toLowerCase();
        const role   = roleSel.value;
        const status = statusSel.value;
        let shown    = 0;

        rows.forEach(function (row) {
            const ok = (!q || row.dataset.search.indexOf(q) !== -1)
                && (!role || row.dataset.role === role)
                && (!status || row.dataset.status === status);
            row.hidden = !ok;
            if (ok) shown++;
        });

        counter.textContent = 'Showing ' + shown + ' of ' + rows.length;
    }

    search.addEventListener('input', applyFilters);
    roleSel.addEventListener('change', applyFilters);
    statusSel.addEventListener('change', applyFilters);
    applyFilters();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
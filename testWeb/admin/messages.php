<?php
define('ADMIN_AREA', true);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_helpers.php';

$error   = '';
$contact = contactColumns($conn);

if (!$contact['ok']) {
    $error = contactProblem($contact);
}

if ($contact['ok'] && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $messageId = (int) ($_POST['message_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';

    if (!csrfValid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($action === 'set_status' && in_array($newStatus, ['new', 'read', 'resolved'], true)) {
        $stmt = $conn->prepare('UPDATE contact_messages SET ' . $contact['status'] . ' = ? WHERE ' . $contact['id'] . ' = ?');
        $stmt->bind_param('si', $newStatus, $messageId);
        $stmt->execute();
        setFlash('Message updated.');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($action === 'delete_message') {
        $stmt = $conn->prepare('DELETE FROM contact_messages WHERE ' . $contact['id'] . ' = ?');
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        setFlash('Message deleted.');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = 'Invalid request.';
    }
}

$flash = takeFlash();

$messages = [];
if ($contact['ok']) {
    $stmt = $conn->prepare(
        'SELECT ' . $contact['select'] . '
           FROM contact_messages
          ORDER BY ' . $contact['created'] . ' DESC, ' . $contact['id'] . ' DESC
          LIMIT 500'
    );
    $stmt->execute();
    $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$counts = ['all' => count($messages), 'new' => 0, 'read' => 0, 'resolved' => 0];
foreach ($messages as $m) {
    $counts[$m['status']]++;
}

$pageTitle = 'Messages';
$pageCss   = 'admin.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Messages</h1>

<?php if ($flash): ?>
    <p class="flash"><?= htmlspecialchars($flash) ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p class="error-message"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="admin-card">
    <div class="msg-tabs">
        <?php foreach (['all' => 'All', 'new' => 'New', 'read' => 'Read', 'resolved' => 'Resolved'] as $key => $label): ?>
            <button type="button" class="msg-tab <?= $key === 'all' ? 'active' : '' ?>" data-filter="<?= $key ?>">
                <?= $label ?> <span class="tab-count"><?= $counts[$key] ?></span>
            </button>
        <?php endforeach; ?>
    </div>

    <?php if (count($messages) === 0): ?>
        <p class="empty-note">No messages yet.</p>
    <?php else: ?>
        <div class="msg-list" id="msgList">
            <?php foreach ($messages as $m): ?>
                <?php $validEmail = filter_var($m['email'], FILTER_VALIDATE_EMAIL); ?>
                <details class="msg" data-status="<?= htmlspecialchars($m['status']) ?>">
                    <summary>
                        <span class="msg-from">
                            <strong><?= htmlspecialchars($m['name']) ?></strong>
                            <span class="sub"><?= htmlspecialchars($m['subject'] !== '' ? $m['subject'] : '(no subject)') ?></span>
                        </span>
                        <span class="msg-meta">
                            <span class="badge badge-<?= htmlspecialchars($m['status']) ?>"><?= ucfirst($m['status']) ?></span>
                            <span class="sub"><?= date('M j, Y g:i A', strtotime($m['created_at'])) ?></span>
                        </span>
                    </summary>

                    <div class="msg-body">
                        <p class="sub"><?= htmlspecialchars($m['email']) ?></p>
                        <p class="msg-text"><?= nl2br(htmlspecialchars($m['message'])) ?></p>

                        <div class="row-actions">
                            <?php if ($validEmail): ?>
                                <a class="btn-small"
                                   href="mailto:<?= htmlspecialchars($m['email']) ?>?subject=<?= rawurlencode('Re: ' . $m['subject']) ?>">Reply</a>
                            <?php endif; ?>

                            <?php if ($m['status'] === 'new'): ?>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="status" value="read">
                                    <button type="submit" class="btn-small">Mark read</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($m['status'] !== 'resolved'): ?>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="status" value="resolved">
                                    <button type="submit" class="btn-small">Resolve</button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_status">
                                    <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                                    <input type="hidden" name="status" value="new">
                                    <button type="submit" class="btn-small">Reopen</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" onsubmit="return confirm('Delete this message?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_message">
                                <input type="hidden" name="message_id" value="<?= (int) $m['id'] ?>">
                                <button type="submit" class="btn-small btn-danger">Delete</button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    const tabs     = Array.from(document.querySelectorAll('.msg-tab'));
    const messages = Array.from(document.querySelectorAll('.msg'));

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            const filter = tab.dataset.filter;

            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            messages.forEach(function (msg) {
                msg.hidden = filter !== 'all' && msg.dataset.status !== filter;
            });
        });
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
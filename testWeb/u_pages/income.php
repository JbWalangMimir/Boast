<?php
require_once __DIR__ . '/../includes/auth.php';

$error          = '';
$allowedSources = ['Allowance', 'Salary', 'Other'];
$allowedRanges  = ['Daily', 'Weekly', 'Monthly', 'Other'];

function validDate(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

function sumMap(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $map = [];
    foreach ($stmt->get_result() as $row) {
        $map[$row['k']] = (float) $row['total'];
    }
    return $map;
}

function readIncomeForm(array $allowedSources, array $allowedRanges): array
{
    $in = [
        'source' => trim($_POST['source'] ?? ''),
        'range'  => $_POST['range'] ?? '',
        'amount' => round((float) ($_POST['amount'] ?? 0), 2),
        'date'   => $_POST['date'] ?? '',
        'time'   => $_POST['time'] ?? '',
        'notes'  => trim($_POST['description'] ?? ''),
    ];

    if ($in['source'] === '' || mb_strlen($in['source']) > 50
        || !in_array($in['range'], $allowedRanges, true)) {
        return ['Please enter a source (max 50 characters) and choose a valid range.', $in];
    }
    if ($in['amount'] <= 0) {
        return ['Amount must be greater than zero.', $in];
    }
    if (!validDate($in['date'])) {
        return ['Please enter a valid date.', $in];
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in['time'])) {
        return ['Please enter a valid time.', $in];
    }
    return ['', $in];
}

function findIncomeAmount(mysqli $conn, int $id, int $userId): ?float
{
    $stmt = $conn->prepare('SELECT amount FROM income WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (float) $row['amount'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_income') {
        [$error, $in] = readIncomeForm($allowedSources, $allowedRanges);

        if ($error === '') {
            $stmt = $conn->prepare(
                'INSERT INTO income (user_id, source, frequency, amount, date_received, time_received, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('issdsss', $userId, $in['source'], $in['range'], $in['amount'], $in['date'], $in['time'], $in['notes']);
            $stmt->execute();

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    if ($action === 'update_income') {
        $incomeId = (int) ($_POST['income_id'] ?? 0);
        [$error, $in] = readIncomeForm($allowedSources, $allowedRanges);

        if ($error === '') {
            $oldAmount = findIncomeAmount($conn, $incomeId, $userId);

            if ($oldAmount === null) {
                $error = 'Income entry not found.';
            } else {
                $bal          = getBalances($conn, $userId);
                $newRemaining = round($bal['remaining_income'] - $oldAmount + $in['amount'], 2);

                if ($newRemaining < 0) {
                    $minimum = max(0, $oldAmount - $bal['remaining_income']);
                    $error   = 'You can\'t lower this below ' . peso($minimum)
                             . ' because the rest was already moved to savings or spent.';
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE income
                            SET source = ?, frequency = ?, amount = ?, date_received = ?, time_received = ?, notes = ?
                          WHERE id = ? AND user_id = ?'
                    );
                    $stmt->bind_param('ssdsssii', $in['source'], $in['range'], $in['amount'], $in['date'], $in['time'], $in['notes'], $incomeId, $userId);
                    $stmt->execute();

                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            }
        }
    }

    if ($action === 'delete_income') {
        $incomeId  = (int) ($_POST['income_id'] ?? 0);
        $oldAmount = findIncomeAmount($conn, $incomeId, $userId);

        if ($oldAmount === null) {
            $error = 'Income entry not found.';
        } else {
            $bal = getBalances($conn, $userId);

            if (round($bal['remaining_income'] - $oldAmount, 2) < 0) {
                $error = 'You can\'t delete this entry: it would make your remaining income negative. '
                       . 'Remaining income is ' . peso($bal['remaining_income']) . ' but this entry is ' . peso($oldAmount)
                       . '. Remove the savings or expenses taken from income first.';
            } else {
                $stmt = $conn->prepare('DELETE FROM income WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ii', $incomeId, $userId);
                $stmt->execute();

                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }

    if ($action === 'transfer_savings') {
        $amount = round((float) ($_POST['amount'] ?? 0), 2);
        $date   = $_POST['date'] ?? '';
        $notes  = trim($_POST['notes'] ?? '');
        $bal    = getBalances($conn, $userId);

        if ($amount <= 0) {
            $error = 'Transfer amount must be greater than zero.';
        } elseif (!validDate($date)) {
            $error = 'Please enter a valid date.';
        } elseif ($amount > $bal['remaining_income']) {
            $error = 'You only have ' . peso($bal['remaining_income']) . ' of remaining income.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO savings (user_id, amount, source, saved_on, notes)
                 VALUES (?, ?, 'income', ?, ?)"
            );
            $stmt->bind_param('idss', $userId, $amount, $date, $notes);
            $stmt->execute();

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}

$bal = getBalances($conn, $userId);

$stmt = $conn->prepare(
    "SELECT 'Savings' AS destination,
            COALESCE(NULLIF(notes, ''), 'Moved to savings') AS label,
            amount, saved_on AS txn_date
       FROM savings
      WHERE user_id = ? AND source = 'income'
     UNION ALL
     SELECT 'Expenses', title, amount, expense_date
       FROM expenses
      WHERE user_id = ? AND paid_from = 'income'
     ORDER BY txn_date DESC
     LIMIT 5"
);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
$transfers = $stmt->get_result();

$stmt = $conn->prepare(
    "SELECT id, source, frequency, amount, date_received, time_received, notes
       FROM income
      WHERE user_id = ?
      ORDER BY date_received DESC, time_received DESC, id DESC
      LIMIT 500"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$history = $stmt->get_result();

$pageTitle = 'Income';
$pageCss   = 'income.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Income</h1>

<?php if ($error): ?>
    <p class="error-message"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="top-data-container">

    <div class="total-income">
        <div class="top-data-top">
            <div class="tdt">
                <h2 class="tdt-first">Total Income</h2>
                <div class="tdt-button">
                    <button onclick="document.getElementById('id01').style.display='block'" class="edit-income">Add Income</button>

                    <div id="id01" class="modal">
                        <span onclick="document.getElementById('id01').style.display='none'" class="close" title="close-iface">&times;</span>
                        <form class="iface-content animate" method="post">
                            <input type="hidden" name="action" value="add_income">

                            <div class="income-top-field">
                                <div class="income-source-bundle">
                                    <label for="sources">Source</label>
                                    <input list="income-sources" id="sources" name="source" required autocomplete="off">
                                    <datalist id="income-sources">
                                        <option value="Allowance">Allowance</option>
                                        <option value="Salary">Salary</option>
                                        <option value="Freelancing">Freelancing</option>
                                    </datalist>
                                </div>
                                <div class="income-range-bundle">
                                    <label for="range">Range</label>
                                    <select id="range" name="range" required>
                                        <option value="Daily">Daily</option>
                                        <option value="Weekly">Weekly</option>
                                        <option value="Monthly">Monthly</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="income-fmedian-field">
                                <div class="income-amount-bundle">
                                    <label for="amount">Amount</label>
                                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="Enter the income amount" required>
                                </div>
                            </div>

                            <div class="income-lmedian-field">
                                <div class="income-date-bundle">
                                    <label for="date">Date</label>
                                    <input type="date" id="date" name="date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="income-time-bundle">
                                    <label for="time">Time</label>
                                    <input type="time" id="time" name="time" value="<?= date('H:i') ?>" required>
                                </div>
                            </div>

                            <div class="income-bottom-field">
                                <label for="description">Description</label>
                                <input type="text" id="description" name="description" maxlength="255" required>
                            </div>

                            <div class="button-field">
                                <div class="income-submit-button">
                                    <button type="submit" class="income-button">Set Income</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <h1 class="total-amount"><?= peso($bal['total_income']) ?></h1>
        </div>

        <h2>Remaining Income</h2>
        <h2 class="remaining-amount"><?= peso($bal['remaining_income']) ?></h2>
    </div>

    <div class="transfer-list">
        <div class="top-data-median">
            <div class="tdm">
                <h2 class="tdm-first">Transfers</h2>
                <div class="tdm-button">
                    <button onclick="document.getElementById('id02').style.display='block'" class="edit-transfers">New Transfer</button>

                    <div id="id02" class="modal">
                        <span onclick="document.getElementById('id02').style.display='none'" class="close" title="close-iface">&times;</span>
                        <form class="iface-content animate" method="post">
                            <input type="hidden" name="action" value="transfer_savings">

                            <p>Move remaining income (<?= peso($bal['remaining_income']) ?>) to savings.</p>

                            <div class="income-fmedian-field">
                                <div class="income-amount-bundle">
                                    <label for="t-amount">Amount</label>
                                    <input type="number" id="t-amount" name="amount" step="0.01" min="0.01"
                                           max="<?= max(0, $bal['remaining_income']) ?>" placeholder="Amount to move" required>
                                </div>
                            </div>

                            <div class="income-lmedian-field">
                                <div class="income-date-bundle">
                                    <label for="t-date">Date</label>
                                    <input type="date" id="t-date" name="date" value="<?= date('Y-m-d') ?>" required>
                                </div>
                            </div>

                            <div class="income-bottom-field">
                                <label for="t-notes">Note (optional)</label>
                                <input type="text" id="t-notes" name="notes" maxlength="255">
                            </div>

                            <div class="button-field">
                                <div class="income-submit-button">
                                    <button type="submit" class="income-button">Transfer to Savings</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($transfers->num_rows === 0): ?>
            <p class="empty-note">No transfers yet.</p>
        <?php else: ?>
            <ul class="transfer-items">
                <?php while ($t = $transfers->fetch_assoc()): ?>
                    <li class="transfer-item">
                        <div class="transfer-info">
                            <span class="transfer-dest"><?= htmlspecialchars($t['destination']) ?></span>
                            <span class="transfer-meta">
                                <?= htmlspecialchars($t['label']) ?> ·
                                <?= date('M d, Y', strtotime($t['txn_date'])) ?>
                            </span>
                        </div>
                        <span class="transfer-amount">-<?= peso($t['amount']) ?></span>
                    </li>
                <?php endwhile; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="median-data-container">
    
</div>

<div class="bottom-data-container">
    <div class="income-history">
        <div class="card-head">
            <h2>Income History</h2>
            <div class="history-controls">
                <span class="history-count" id="historyCount"></span>
                <select id="historyLimit" class="history-select" aria-label="How many entries to show">
                    <option value="10">Latest 10</option>
                    <option value="20" selected>Latest 20</option>
                    <option value="50">Latest 50</option>
                    <option value="all">All</option>
                </select>
            </div>
        </div>

        <?php if ($history->num_rows === 0): ?>
            <p class="empty-note">No income yet.</p>
        <?php else: ?>
            <div class="history-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Source</th>
                            <th>Description</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody id="historyBody">
                        <?php while ($r = $history->fetch_assoc()): ?>
                            <?php $timeVal = substr($r['time_received'] ?? '00:00:00', 0, 5); ?>
                            <tr class="history-row" onclick="openEdit(this)"
                                data-id="<?= (int) $r['id'] ?>"
                                data-source="<?= htmlspecialchars($r['source']) ?>"
                                data-range="<?= htmlspecialchars($r['frequency']) ?>"
                                data-amount="<?= htmlspecialchars($r['amount']) ?>"
                                data-date="<?= htmlspecialchars($r['date_received']) ?>"
                                data-time="<?= htmlspecialchars($timeVal) ?>"
                                data-notes="<?= htmlspecialchars($r['notes'] ?? '') ?>">
                                <td>
                                    <?= date('M d, Y', strtotime($r['date_received'])) ?>
                                    <?php if ($r['time_received'] !== null): ?>
                                        <span class="muted"><?= date('g:i A', strtotime($timeVal)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= ucfirst(htmlspecialchars($r['source'])) ?>
                                    <span class="muted">· <?= htmlspecialchars($r['frequency']) ?></span>
                                </td>
                                <td><?= ($r['notes'] !== null && $r['notes'] !== '') ? htmlspecialchars($r['notes']) : '—' ?></td>
                                <td class="num is-plus">+<?= peso($r['amount']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="id03" class="modal">
    <span onclick="document.getElementById('id03').style.display='none'" class="close" title="close-iface">&times;</span>
    <form class="iface-content animate" method="post" id="editForm">
        <input type="hidden" name="income_id" id="edit-id">

        <div class="income-top-field">
            <div class="income-source-bundle">
                <label for="edit-source">Source</label>
                <input list="income-sources" id="edit-source" name="source" required autocomplete="off">
            </div>
            <div class="income-range-bundle">
                <label for="edit-range">Range</label>
                <select id="edit-range" name="range" required>
                    <option value="Daily">Daily</option>
                    <option value="Weekly">Weekly</option>
                    <option value="Monthly">Monthly</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        </div>

        <div class="income-fmedian-field">
            <div class="income-amount-bundle">
                <label for="edit-amount">Amount</label>
                <input type="number" id="edit-amount" name="amount" step="0.01" min="0.01" required>
            </div>
        </div>

        <div class="income-lmedian-field">
            <div class="income-date-bundle">
                <label for="edit-date">Date</label>
                <input type="date" id="edit-date" name="date" required>
            </div>
            <div class="income-time-bundle">
                <label for="edit-time">Time</label>
                <input type="time" id="edit-time" name="time" required>
            </div>
        </div>

        <div class="income-bottom-field">
            <label for="edit-description">Description</label>
            <input type="text" id="edit-description" name="description" maxlength="255" required>
        </div>

        <div class="button-field modal-actions">
            <div class="income-submit-button">
                <button type="submit" name="action" value="update_income" class="income-button">Update</button>
            </div>
            <div class="income-submit-button">
                <button type="submit" name="action" value="delete_income" formnovalidate
                        class="income-button delete-button"
                        onclick="return confirm('Delete this income entry?')">Delete</button>
            </div>
        </div>
    </form>
</div>

<script>
    window.addEventListener('click', function (event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    });

    function openEdit(row) {
        document.getElementById('edit-id').value          = row.dataset.id;
        document.getElementById('edit-source').value      = row.dataset.source;
        document.getElementById('edit-range').value       = row.dataset.range;
        document.getElementById('edit-amount').value      = row.dataset.amount;
        document.getElementById('edit-date').value        = row.dataset.date;
        document.getElementById('edit-time').value        = row.dataset.time;
        document.getElementById('edit-description').value = row.dataset.notes;
        document.getElementById('id03').style.display     = 'block';
    }

    (function () {
        const select = document.getElementById('historyLimit');
        const body   = document.getElementById('historyBody');
        if (!select || !body) return;

        const rows = Array.from(body.rows);

        try {
            const saved = localStorage.getItem('historyLimit');
            if (saved && Array.from(select.options).some(function (o) { return o.value === saved; })) {
                select.value = saved;
            }
        } catch (e) {}

        function apply() {
            const limit = select.value === 'all' ? rows.length : parseInt(select.value, 10);
            rows.forEach(function (row, i) { row.style.display = i < limit ? '' : 'none'; });
            document.getElementById('historyCount').textContent =
                'Showing ' + Math.min(limit, rows.length) + ' of ' + rows.length;
        }

        select.addEventListener('change', function () {
            try { localStorage.setItem('historyLimit', select.value); } catch (e) {}
            apply();
        });

        apply();
    })();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
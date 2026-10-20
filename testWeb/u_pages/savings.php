<?php
require_once __DIR__ . '/../includes/auth.php';

$error = '';

$savingCategories = ['needs' => 'Needs', 'wants' => 'Wants', 'savings' => 'Savings'];

$sourceLabels = ['budget' => 'Available Budget'] + $savingCategories;

$splitModes = [
    '60-20-20' => [60, 20, 20],
    '50-30-20' => [50, 30, 20],
];

function validDate(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

function splitAmount(float $total, array $pct): array
{
    $needs = round($total * $pct[0] / 100, 2);
    $wants = round($total * $pct[1] / 100, 2);
    return [$needs, $wants, round($total - $needs - $wants, 2)];
}

function savingsSummary(mysqli $conn, int $userId, array $keys): array
{
    $totals = array_fill_keys($keys, 0.0);

    $stmt = $conn->prepare('SELECT category, SUM(amount) AS total FROM savings WHERE user_id = ? GROUP BY category');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        if (array_key_exists($row['category'], $totals)) {
            $totals[$row['category']] = round((float) $row['total'], 2);
        }
    }

    return [
        'categories' => $totals,
        'total'      => round(array_sum($totals), 2),
    ];
}

function allocateAmount(float $amount, array $balances, array $selected): ?array
{
    $alloc = [];
    $left  = round($amount, 2);

    foreach ($balances as $key => $available) {
        if ($left <= 0) {
            break;
        }
        if (!in_array($key, $selected, true) || $available <= 0) {
            continue;
        }
        $take        = round(min($left, $available), 2);
        $alloc[$key] = $take;
        $left        = round($left - $take, 2);
    }

    return $left > 0 ? null : $alloc;
}

function parseDeadline(string $limitType, array $post, ?string $existing, string &$error): ?string
{
    if ($limitType === 'none') {
        return null;
    }

    if ($limitType === 'date') {
        $limitDate = $post['limit_date'] ?? '';
        if (!validDate($limitDate)) {
            $error = 'Please choose a valid deadline.';
            return null;
        }
        if ($limitDate < date('Y-m-d') && $limitDate !== $existing) {
            $error = 'Please choose a deadline that is today or later.';
            return null;
        }
        return $limitDate;
    }

    if ($limitType === 'days') {
        $days = (int) ($post['limit_days'] ?? 0);
        if ($days < 1 || $days > 3650) {
            $error = 'Days must be between 1 and 3650.';
            return null;
        }
        return date('Y-m-d', strtotime('+' . $days . ' days'));
    }

    $error = 'Please choose a valid time limit.';
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_savings') {
        $mode  = $_POST['split'] ?? '';
        $date  = $_POST['date'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        $bal   = getBalances($conn, $userId);
        $parts = null;

        if ($mode === 'manual') {
            $parts = [
                round((float) ($_POST['manual_needs'] ?? 0), 2),
                round((float) ($_POST['manual_wants'] ?? 0), 2),
                round((float) ($_POST['manual_savings'] ?? 0), 2),
            ];
        } elseif (isset($splitModes[$mode])) {
            $parts = splitAmount(round((float) ($_POST['amount'] ?? 0), 2), $splitModes[$mode]);
        }

        if ($parts === null) {
            $error = 'Please choose a valid split.';
        } elseif (min($parts) < 0) {
            $error = 'Amounts cannot be negative.';
        } elseif (round(array_sum($parts), 2) <= 0) {
            $error = 'Amount must be greater than zero.';
        } elseif (!validDate($date)) {
            $error = 'Please enter a valid date.';
        } elseif (round(array_sum($parts), 2) > $bal['remaining_income']) {
            $error = 'You only have ' . peso($bal['remaining_income']) . ' of remaining income.';
        } else {
            $saved = false;
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO savings (user_id, amount, source, category, saved_on, notes)
                     VALUES (?, ?, 'income', ?, ?, ?)"
                );
                foreach (array_keys($savingCategories) as $i => $key) {
                    $partAmount = $parts[$i];
                    if ($partAmount > 0) {
                        $stmt->bind_param('idsss', $userId, $partAmount, $key, $date, $notes);
                        $stmt->execute();
                    }
                }
                $conn->commit();
                $saved = true;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Could not save. Please try again.';
            }

            if ($saved) {
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }

    if ($action === 'add_goal') {
        $name      = trim($_POST['name'] ?? '');
        $target    = round((float) ($_POST['target'] ?? 0), 2);
        $limitType = $_POST['limit_type'] ?? 'none';

        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'Please enter a goal name (up to 100 characters).';
        } elseif ($target <= 0) {
            $error = 'The goal amount must be greater than zero.';
        }

        $targetDate = null;
        if ($error === '') {
            $targetDate = parseDeadline($limitType, $_POST, null, $error);
        }

        if ($error === '') {
            $stmt = $conn->prepare('INSERT INTO goals (user_id, name, target_amount, target_date) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('isds', $userId, $name, $target, $targetDate);
            $stmt->execute();

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    if ($action === 'update_goal') {
        $goalId    = (int) ($_POST['goal_id'] ?? 0);
        $target    = round((float) ($_POST['target'] ?? 0), 2);
        $limitType = $_POST['limit_type'] ?? 'none';

        $stmt = $conn->prepare('SELECT saved_amount, target_date FROM goals WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $goalId, $userId);
        $stmt->execute();
        $goal = $stmt->get_result()->fetch_assoc();

        if (!$goal) {
            $error = 'Goal not found.';
        } elseif ($target <= 0) {
            $error = 'The goal amount must be greater than zero.';
        } elseif ($target < (float) $goal['saved_amount']) {
            $error = 'The goal amount cannot be lower than the ' . peso((float) $goal['saved_amount']) . ' already saved.';
        }

        $targetDate = null;
        if ($error === '') {
            $targetDate = parseDeadline($limitType, $_POST, $goal['target_date'], $error);
        }

        if ($error === '') {
            $stmt = $conn->prepare('UPDATE goals SET target_amount = ?, target_date = ? WHERE id = ? AND user_id = ?');
            $stmt->bind_param('dsii', $target, $targetDate, $goalId, $userId);
            $stmt->execute();

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    if ($action === 'fund_goal') {
        $goalId   = (int) ($_POST['goal_id'] ?? 0);
        $amount   = round((float) ($_POST['amount'] ?? 0), 2);
        $selected = array_values(array_intersect((array) ($_POST['sources'] ?? []), array_keys($sourceLabels)));

        $stmt = $conn->prepare('SELECT name, target_amount, saved_amount FROM goals WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $goalId, $userId);
        $stmt->execute();
        $goal = $stmt->get_result()->fetch_assoc();

        $bal      = getBalances($conn, $userId);
        $summary  = savingsSummary($conn, $userId, array_keys($savingCategories));
        $balances = ['budget' => round(max(0, $bal['remaining_income']), 2)];
        foreach ($summary['categories'] as $key => $value) {
            $balances[$key] = round(max(0, $value), 2);
        }

        $alloc = [];

        if (!$goal) {
            $error = 'Goal not found.';
        } elseif ($amount <= 0) {
            $error = 'Amount must be greater than zero.';
        } elseif (round((float) $goal['saved_amount'] + $amount, 2) > (float) $goal['target_amount']) {
            $left  = round((float) $goal['target_amount'] - (float) $goal['saved_amount'], 2);
            $error = 'That is more than this goal needs (' . peso($left) . ' left).';
        } elseif (count($selected) === 0) {
            $error = 'Please choose where the funds should come from.';
        } else {
            $alloc = allocateAmount($amount, $balances, $selected);
            if ($alloc === null) {
                $error = 'The sources you selected cannot cover ' . peso($amount) . '.';
            }
        }

        if ($error === '') {
            $saved = false;
            $conn->begin_transaction();
            try {
                $today = date('Y-m-d');
                $note  = 'Goal: ' . $goal['name'];

                $savStmt = $conn->prepare(
                    'INSERT INTO savings (user_id, amount, source, category, saved_on, notes)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );

                foreach ($alloc as $key => $take) {
                    if ($key === 'budget') {
                        $savAmount = $take;
                        $savSource = 'income';
                        $savCat    = 'savings';
                        $savStmt->bind_param('idssss', $userId, $savAmount, $savSource, $savCat, $today, $note);
                        $savStmt->execute();
                    }

                    $savAmount = -$take;
                    $savSource = 'goal';
                    $savCat    = $key === 'budget' ? 'savings' : $key;
                    $savStmt->bind_param('idssss', $userId, $savAmount, $savSource, $savCat, $today, $note);
                    $savStmt->execute();
                }

                $stmt = $conn->prepare('UPDATE goals SET saved_amount = saved_amount + ? WHERE id = ? AND user_id = ?');
                $stmt->bind_param('dii', $amount, $goalId, $userId);
                $stmt->execute();

                $conn->commit();
                $saved = true;
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Could not add funds. Please try again.';
            }

            if ($saved) {
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }

    if ($action === 'delete_goal') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT name, saved_amount FROM goals WHERE id = ? AND user_id = ? FOR UPDATE');
            $stmt->bind_param('ii', $goalId, $userId);
            $stmt->execute();
            $goal = $stmt->get_result()->fetch_assoc();

            if ($goal) {
                $refund = round((float) $goal['saved_amount'], 2);

                if ($refund > 0) {
                    $today     = date('Y-m-d');
                    $note      = 'Goal deleted: ' . $goal['name'];
                    $savSource = 'goal';
                    $savCat    = 'savings';
                    $stmt = $conn->prepare(
                        'INSERT INTO savings (user_id, amount, source, category, saved_on, notes)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('idssss', $userId, $refund, $savSource, $savCat, $today, $note);
                    $stmt->execute();
                }

                $stmt = $conn->prepare('DELETE FROM goals WHERE id = ? AND user_id = ?');
                $stmt->bind_param('ii', $goalId, $userId);
                $stmt->execute();
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
        }

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$bal        = getBalances($conn, $userId);
$summary    = savingsSummary($conn, $userId, array_keys($savingCategories));
$totalSaved = $summary['total'];

$sourceBalances = ['budget' => round(max(0, $bal['remaining_income']), 2)];
foreach ($summary['categories'] as $key => $value) {
    $sourceBalances[$key] = round(max(0, $value), 2);
}

$stmt = $conn->prepare(
    'SELECT id, name, target_amount, saved_amount, target_date
       FROM goals
      WHERE user_id = ?
      ORDER BY (saved_amount >= target_amount) ASC, target_date IS NULL ASC, target_date ASC, id ASC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$goals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$today = date('Y-m-d');

$pageTitle = 'Savings';
$pageCss   = 'savings.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Savings</h1>

<?php if ($error): ?>
    <p class="error-message"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="top-data-container">
    <div class="avlbl-budg">
        <h2>Available Budget</h2>
        <h1 class="big-amount <?= $bal['remaining_income'] < 0 ? 'is-minus' : '' ?>"><?= peso($bal['remaining_income']) ?></h1>
    </div>

    <div class="savings-section">
        <div class="total-savings">
            <h2>Total Savings</h2>
            <h1 class="big-amount"><?= peso($totalSaved) ?></h1>
        </div>

        <div class="saving-categories">
            <div class="card-head">
                <h2>Saving Categories</h2>
                <div class="split-controls">
                    <select id="splitMode" aria-label="Split">
                        <option value="60-20-20">60/20/20</option>
                        <option value="50-30-20">50/30/20</option>
                        <option value="manual">Manual</option>
                    </select>
                    <button type="button" id="openSavings" class="green-btn">+ Add</button>
                </div>
            </div>

            <div class="split-list">
                <?php foreach ($savingCategories as $key => $label): ?>
                    <div class="split-item">
                        <span class="split-label"><?= htmlspecialchars($label) ?></span>
                        <strong class="split-amount"><?= peso($summary['categories'][$key]) ?></strong>
                        <small class="split-share"><?= $totalSaved > 0 ? round($summary['categories'][$key] / $totalSaved * 100) : 0 ?>%</small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="median-data-container">
    <div class="goals-section">
        <div class="goals-head">
            <div>
                <h2 class="goal-text">Goals</h2>
                <h4 class="goal-text-desc">Set and track financial goals</h4>
            </div>
            <button type="button" id="openGoal" class="green-btn">+ Add goal</button>
        </div>

        <div class="goal-list-section">
            <?php if (count($goals) === 0): ?>
                <p class="empty-note">No goals yet.</p>
            <?php else: ?>
                <?php foreach ($goals as $g): ?>
                    <?php
                        $targetAmt = (float) $g['target_amount'];
                        $savedAmt  = (float) $g['saved_amount'];
                        $pct       = $targetAmt > 0 ? min(100, $savedAmt / $targetAmt * 100) : 0;
                        $pctLabel  = rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.');
                        $left      = max(0, round($targetAmt - $savedAmt, 2));
                        $complete  = $pct >= 100;
                        $late      = !$complete && $g['target_date'] !== null && $g['target_date'] < $today;
                    ?>
                    <div class="goal-item">
                        <div class="goal-top">
                            <div class="goal-title">
                                <span class="goal-name"><?= htmlspecialchars($g['name']) ?></span>
                                <div class="goal-actions">
                                    <?php if (!$complete): ?>
                                        <button type="button" class="icon-btn goal-fund" title="Add funds"
                                                data-id="<?= (int) $g['id'] ?>"
                                                data-name="<?= htmlspecialchars($g['name']) ?>"
                                                data-left="<?= number_format($left, 2, '.', '') ?>">
                                            <span class="material-symbols-outlined">add_circle</span>
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="icon-btn goal-edit" title="Update"
                                            data-id="<?= (int) $g['id'] ?>"
                                            data-name="<?= htmlspecialchars($g['name']) ?>"
                                            data-target="<?= number_format($targetAmt, 2, '.', '') ?>"
                                            data-saved="<?= number_format($savedAmt, 2, '.', '') ?>"
                                            data-date="<?= htmlspecialchars((string) $g['target_date']) ?>">
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <form method="post" onsubmit="return confirm('Delete this goal? Any funds saved in it return to your Savings category.')">
                                        <input type="hidden" name="action" value="delete_goal">
                                        <input type="hidden" name="goal_id" value="<?= (int) $g['id'] ?>">
                                        <button type="submit" class="icon-btn danger" title="Delete">
                                            <span class="material-symbols-outlined">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="goal-figures">
                                <strong class="goal-saved"><?= peso($savedAmt) ?></strong>
                                <small>of <?= peso($targetAmt) ?></small>
                            </div>
                        </div>

                        <div class="goal-bar <?= $complete ? 'is-complete' : '' ?>">
                            <span style="width: <?= number_format($pct, 2, '.', '') ?>%"></span>
                        </div>

                        <div class="goal-bottom">
                            <span><?= $pctLabel ?> %</span>
                            <span class="<?= $late ? 'is-late' : '' ?>">
                                <?= $g['target_date'] !== null ? 'by ' . date('M j, Y', strtotime($g['target_date'])) : '' ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="savingsModal" class="savings-modal">
    <form class="savings-form" method="post">
        <span class="savings-close" data-close>&times;</span>
        <h3>Add to Savings</h3>
        <input type="hidden" name="action" value="add_savings">
        <input type="hidden" name="split" id="splitInput" value="60-20-20">

        <p class="form-hint">Available: <?= peso($bal['remaining_income']) ?></p>
        <p class="form-hint" id="splitHint"></p>

        <div id="presetFields">
            <div class="form-field">
                <label for="sv-amount">Amount</label>
                <input type="number" id="sv-amount" name="amount" step="0.01" min="0.01"
                       max="<?= max(0, $bal['remaining_income']) ?>" placeholder="Enter the amount">
            </div>
            <p class="split-preview" id="splitPreview"></p>
        </div>

        <div id="manualFields" hidden>
            <div class="form-row">
                <?php foreach ($savingCategories as $key => $label): ?>
                    <div class="form-field">
                        <label for="sv-<?= $key ?>"><?= htmlspecialchars($label) ?></label>
                        <input type="number" id="sv-<?= $key ?>" name="manual_<?= $key ?>" step="0.01" min="0" value="0">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-field">
            <label for="sv-date">Date</label>
            <input type="date" id="sv-date" name="date" value="<?= $today ?>" required>
        </div>

        <div class="form-field">
            <label for="sv-notes">Note (optional)</label>
            <input type="text" id="sv-notes" name="notes" maxlength="255">
        </div>

        <button type="submit" class="form-submit">Add to Savings</button>
    </form>
</div>

<div id="goalModal" class="savings-modal">
    <form class="savings-form" method="post">
        <span class="savings-close" data-close>&times;</span>
        <h3>Add Goal</h3>
        <input type="hidden" name="action" value="add_goal">

        <div class="form-field">
            <label for="goal-name">Goal name</label>
            <input type="text" id="goal-name" name="name" maxlength="100" placeholder="e.g. Emergency Fund" required>
        </div>

        <div class="form-field">
            <label for="goal-target">Amount to reach</label>
            <input type="number" id="goal-target" name="target" step="0.01" min="0.01" placeholder="Enter the amount" required>
        </div>

        <div class="form-field">
            <label for="goal-limit">Time limit</label>
            <select id="goal-limit" name="limit_type">
                <option value="none">No limit</option>
                <option value="date">By date</option>
                <option value="days">Number of days</option>
            </select>
        </div>

        <div class="form-field" id="limitDateField" hidden>
            <label for="goal-date">Deadline</label>
            <input type="date" id="goal-date" name="limit_date" min="<?= $today ?>" disabled>
        </div>

        <div class="form-field" id="limitDaysField" hidden>
            <label for="goal-days">Days from today</label>
            <input type="number" id="goal-days" name="limit_days" min="1" max="3650" step="1" disabled>
        </div>

        <button type="submit" class="form-submit">Add Goal</button>
    </form>
</div>

<div id="updateModal" class="savings-modal">
    <form class="savings-form" method="post">
        <span class="savings-close" data-close>&times;</span>
        <h3>Update Goal</h3>
        <input type="hidden" name="action" value="update_goal">
        <input type="hidden" name="goal_id" id="updGoalId">

        <p class="fund-name" id="updName"></p>
        <p class="form-hint" id="updHint"></p>

        <div class="form-field">
            <label for="updTarget">Amount to reach</label>
            <input type="number" id="updTarget" name="target" step="0.01" min="0.01" required>
        </div>

        <div class="form-field">
            <label for="updLimit">Time limit</label>
            <select id="updLimit" name="limit_type">
                <option value="none">No limit</option>
                <option value="date">By date</option>
                <option value="days">Number of days</option>
            </select>
        </div>

        <div class="form-field" id="updDateField" hidden>
            <label for="updDate">Deadline</label>
            <input type="date" id="updDate" name="limit_date" disabled>
        </div>

        <div class="form-field" id="updDaysField" hidden>
            <label for="updDays">Days from today</label>
            <input type="number" id="updDays" name="limit_days" min="1" max="3650" step="1" disabled>
        </div>

        <button type="submit" class="form-submit">Save Changes</button>
    </form>
</div>

<div id="fundModal" class="savings-modal">
    <form class="savings-form" method="post" id="fundForm">
        <span class="savings-close" data-close>&times;</span>
        <h3>Add Funds</h3>
        <input type="hidden" name="action" value="fund_goal">
        <input type="hidden" name="goal_id" id="fundGoalId">

        <p class="fund-name" id="fundName"></p>
        <p class="form-hint" id="fundHint"></p>

        <div class="form-field">
            <label for="fundAmount">Amount</label>
            <input type="number" id="fundAmount" name="amount" step="0.01" min="0.01" placeholder="Enter the amount" required>
        </div>

        <div class="form-field">
            <label>Deduct from</label>
            <ul class="source-list">
                <?php foreach ($sourceLabels as $key => $label): ?>
                    <li>
                        <label>
                            <input type="checkbox" class="fund-source" name="sources[]" value="<?= htmlspecialchars($key) ?>"
                                   <?= $sourceBalances[$key] <= 0 ? 'disabled' : '' ?>>
                            <span><?= htmlspecialchars($label) ?></span>
                            <span class="source-bal"><?= peso($sourceBalances[$key]) ?></span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <p class="split-preview" id="fundPreview"></p>

        <button type="submit" class="form-submit" id="fundSubmit" disabled>Add Funds</button>
    </form>
</div>

<script>
    const money          = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const sourceBalances = <?= json_encode($sourceBalances) ?>;
    const sourceLabels   = <?= json_encode($sourceLabels) ?>;
    const splitModes     = { '60-20-20': [60, 20, 20], '50-30-20': [50, 30, 20] };
    const catLabels      = <?= json_encode(array_values($savingCategories)) ?>;

    function r2(n) {
        return Math.round(n * 100) / 100;
    }

    function showModal(id) {
        document.getElementById(id).style.display = 'block';
    }

    document.addEventListener('click', function (event) {
        const target = event.target;
        if (target.classList.contains('savings-modal') || target.hasAttribute('data-close')) {
            target.closest('.savings-modal').style.display = 'none';
        }
    });

    const splitMode = document.getElementById('splitMode');

    try {
        const saved = localStorage.getItem('splitMode');
        if (saved && Array.from(splitMode.options).some(function (o) { return o.value === saved; })) {
            splitMode.value = saved;
        }
    } catch (e) {}

    splitMode.addEventListener('change', function () {
        try { localStorage.setItem('splitMode', splitMode.value); } catch (e) {}
    });

    function splitParts(total, pct) {
        const needs = Math.round(total * pct[0]) / 100;
        const wants = Math.round(total * pct[1]) / 100;
        const rest  = Math.round((total - needs - wants) * 100) / 100;
        return [needs, wants, rest];
    }

    function updatePreview() {
        const mode = splitMode.value;
        if (mode === 'manual') {
            document.getElementById('splitPreview').textContent = '';
            return;
        }
        const total = parseFloat(document.getElementById('sv-amount').value) || 0;
        const parts = splitParts(total, splitModes[mode]);
        document.getElementById('splitPreview').textContent = parts
            .map(function (p, i) { return catLabels[i] + ' ' + money.format(p); })
            .join(' · ');
    }

    document.getElementById('openSavings').addEventListener('click', function () {
        const mode   = splitMode.value;
        const manual = mode === 'manual';

        document.getElementById('splitInput').value = mode;
        document.getElementById('splitHint').textContent = manual
            ? 'Manual split: enter how much goes to each category.'
            : 'Split ' + mode.replace(/-/g, '/') + ' across ' + catLabels.join(', ') + '.';

        document.getElementById('presetFields').hidden = manual;
        document.getElementById('manualFields').hidden = !manual;

        const amount = document.getElementById('sv-amount');
        amount.disabled = manual;
        amount.required = !manual;

        document.querySelectorAll('#manualFields input').forEach(function (input) {
            input.disabled = !manual;
        });

        updatePreview();
        showModal('savingsModal');
    });

    document.getElementById('sv-amount').addEventListener('input', updatePreview);

    function bindLimit(selectId, dateFieldId, daysFieldId, dateInputId, daysInputId) {
        const select = document.getElementById(selectId);

        function apply() {
            const type      = select.value;
            const dateInput = document.getElementById(dateInputId);
            const daysInput = document.getElementById(daysInputId);

            document.getElementById(dateFieldId).hidden = type !== 'date';
            document.getElementById(daysFieldId).hidden = type !== 'days';

            dateInput.disabled = type !== 'date';
            dateInput.required = type === 'date';
            daysInput.disabled = type !== 'days';
            daysInput.required = type === 'days';
        }

        select.addEventListener('change', apply);
        return apply;
    }

    bindLimit('goal-limit', 'limitDateField', 'limitDaysField', 'goal-date', 'goal-days');
    const applyUpdateLimit = bindLimit('updLimit', 'updDateField', 'updDaysField', 'updDate', 'updDays');

    document.getElementById('openGoal').addEventListener('click', function () {
        showModal('goalModal');
        document.getElementById('goal-name').focus();
    });

    document.querySelectorAll('.goal-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const saved = parseFloat(btn.dataset.saved);
            const target = document.getElementById('updTarget');

            document.getElementById('updGoalId').value = btn.dataset.id;
            document.getElementById('updName').textContent = btn.dataset.name;
            document.getElementById('updHint').textContent = 'Already saved: ' + money.format(saved);

            target.value = btn.dataset.target;
            target.min   = Math.max(0.01, saved).toFixed(2);

            document.getElementById('updDays').value = '';
            document.getElementById('updDate').value = btn.dataset.date;
            document.getElementById('updLimit').value = btn.dataset.date ? 'date' : 'none';
            applyUpdateLimit();

            showModal('updateModal');
            target.focus();
        });
    });

    const fundAmount = document.getElementById('fundAmount');
    const fundSubmit = document.getElementById('fundSubmit');

    function allocate(amount, selected) {
        const alloc = {};
        let left = r2(amount);

        Object.keys(sourceBalances).forEach(function (key) {
            const available = sourceBalances[key];
            if (left <= 0 || selected.indexOf(key) === -1 || available <= 0) return;
            const take = r2(Math.min(left, available));
            alloc[key] = take;
            left = r2(left - take);
        });

        return { alloc: alloc, left: left };
    }

    function updateFund() {
        const amount   = parseFloat(fundAmount.value) || 0;
        const selected = Array.from(document.querySelectorAll('.fund-source:checked')).map(function (c) { return c.value; });
        const result   = allocate(amount, selected);
        const preview  = document.getElementById('fundPreview');

        if (amount <= 0 || selected.length === 0) {
            preview.textContent = '';
        } else if (result.left > 0) {
            preview.textContent = 'Selected sources are short by ' + money.format(result.left) + '.';
        } else {
            preview.textContent = Object.keys(result.alloc)
                .map(function (key) { return sourceLabels[key] + ' −' + money.format(result.alloc[key]); })
                .join(' · ');
        }

        fundSubmit.disabled = amount <= 0 || selected.length === 0 || result.left > 0;
    }

    fundAmount.addEventListener('input', updateFund);

    document.querySelectorAll('.fund-source').forEach(function (c) {
        c.addEventListener('change', updateFund);
    });

    document.querySelectorAll('.goal-fund').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const left = parseFloat(btn.dataset.left);

            document.getElementById('fundGoalId').value = btn.dataset.id;
            document.getElementById('fundName').textContent = btn.dataset.name;
            document.getElementById('fundHint').textContent = 'Left to goal: ' + money.format(left);

            fundAmount.value = '';
            fundAmount.max   = left.toFixed(2);

            document.querySelectorAll('.fund-source').forEach(function (c) { c.checked = false; });
            updateFund();

            showModal('fundModal');
            fundAmount.focus();
        });
    });
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
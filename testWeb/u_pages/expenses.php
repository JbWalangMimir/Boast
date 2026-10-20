<?php
require_once __DIR__ . '/../includes/auth.php';

$error = '';

$categories = [
    'Food'          => 'restaurant',
    'Transport'     => 'directions_bus',
    'Bills'         => 'receipt_long',
    'Shopping'      => 'shopping_bag',
    'Health'        => 'favorite',
    'Education'     => 'school',
    'Entertainment' => 'movie',
    'Other'         => 'more_horiz',
];

$paidFromAll = ['income'];

function validDate(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
    $title    = trim($_POST['title'] ?? '');
    $category = $_POST['category'] ?? '';
    $amount   = round((float) ($_POST['amount'] ?? 0), 2);
    $paidFrom = $_POST['paid_from'] ?? '';
    $date     = $_POST['date'] ?? '';
    $notes    = trim($_POST['notes'] ?? '');
    $bal      = getBalances($conn, $userId);

    if ($title === '' || mb_strlen($title) > 100) {
        $error = 'Please enter a title (up to 100 characters).';
    } elseif (!array_key_exists($category, $categories)) {
        $error = 'Please choose a valid category.';
    } elseif (!in_array($paidFrom, $paidFromAll, true)) {
        $error = 'Please choose where the money comes from.';
    } elseif ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } elseif (!validDate($date)) {
        $error = 'Please enter a valid date.';
    } elseif ($amount > $bal['remaining_income']) {
        $error = 'Not enough income. You only have ' . peso($bal['remaining_income']) . ' available.';
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO expenses (user_id, title, category, amount, paid_from, expense_date, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('issdsss', $userId, $title, $category, $amount, $paidFrom, $date, $notes);
        $stmt->execute();

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$bal = getBalances($conn, $userId);

$stmt = $conn->prepare(
    'SELECT title, category, amount, paid_from, expense_date, notes
       FROM expenses
      WHERE user_id = ?
      ORDER BY expense_date DESC, id DESC
      LIMIT 2000'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$thisMonth = date('Y-m');
$months    = [$thisMonth => true];
foreach ($expenses as $e) {
    $months[substr($e['expense_date'], 0, 7)] = true;
}
$months = array_keys($months);
rsort($months);

$monthOptions = '';
foreach ($months as $m) {
    $monthOptions .= '<option value="' . htmlspecialchars($m) . '"' . ($m === $thisMonth ? ' selected' : '') . '>'
                   . date('M Y', strtotime($m . '-01')) . '</option>';
}

$expenseData = [];
foreach ($expenses as $e) {
    $expenseData[] = [
        'title'    => $e['title'],
        'category' => $e['category'],
        'amount'   => (float) $e['amount'],
        'date'     => $e['expense_date'],
    ];
}

$historyRows = array_slice($expenses, 0, 100);

$pageTitle = 'Expenses';
$pageCss   = 'expenses.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Expenses</h1>

<?php if ($error): ?>
    <p class="error-message"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="expenses-container">
    <div class="left-expenses-section">
        <div class="avlbl-budg">
            <h2>Available Budget</h2>
            <h1 class="budget-amount <?= $bal['remaining_income'] < 0 ? 'is-minus' : '' ?>"><?= peso($bal['remaining_income']) ?></h1>
        </div>

        <div class="expense-list">
            <h2>Expense History</h2>
            <div class="expense-table-wrap">
                <table class="expense-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Amount</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($historyRows) === 0): ?>
                            <tr><td colspan="3" class="empty-note">No expenses yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($historyRows as $e): ?>
                                <tr title="<?= htmlspecialchars($e['notes'] ?? '') ?>">
                                    <td>
                                        <?= htmlspecialchars($e['title']) ?>
                                        <span class="muted"><?= htmlspecialchars($e['category']) ?></span>
                                    </td>
                                    <td><?= peso($e['amount']) ?></td>
                                    <td><?= date('M j, Y', strtotime($e['expense_date'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="right-expenses-section">
        <div class="total-expenses">
            <div class="card-head">
                <h2>Total Expenses</h2>
                <select id="totalMonth" class="month-select" aria-label="Month"><?= $monthOptions ?></select>
            </div>
            <h1 class="total-amount" id="totalAmount"></h1>
            <ul class="category-totals" id="categoryTotals"></ul>
        </div>

        <div class="expense-category">
            <div class="card-head">
                <h2>Expense Categories</h2>
                <div class="category-controls">
                    <select id="categorySelect" class="month-select" aria-label="Category">
                        <?php foreach ($categories as $name => $icon): ?>
                            <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="category-add" id="categoryAddBtn">+ Add</button>
                </div>
            </div>
            <ul class="category-history" id="categoryHistory"></ul>
        </div>
    </div>
</div>

<div id="id01" class="modal">
    <span onclick="document.getElementById('id01').style.display='none'" class="close" title="close-iface">&times;</span>
    <form class="iface-content animate" method="post">
        <input type="hidden" name="action" value="add_expense">

        <div class="expenses-title-field">
            <div class="expenses-title-bundle">
                <label for="e-title">Title</label>
                <input type="text" id="e-title" name="title" maxlength="100" placeholder="e.g. Lunch" required>
            </div>
            <div class="expenses-date-bundle">
                <label for="e-date">Date</label>
                <input type="date" id="e-date" name="date" value="<?= date('Y-m-d') ?>" required>
            </div>
        </div>

        <div class="expenses-top-field">
            <div class="expenses-category-bundle">
                <label for="e-category">Category</label>
                <select id="e-category" name="category" required>
                    <option value=""></option>
                    <?php foreach ($categories as $name => $icon): ?>
                        <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="expenses-source-bundle">
                <label for="e-paid">Paid from</label>
                <select id="e-paid" name="paid_from" required>
                    <option value="income">Income (<?= peso($bal['remaining_income']) ?>)</option>
                </select>
            </div>
            <div class="expenses-amount-bundle">
                <label for="e-amount">Amount</label>
                <input type="number" id="e-amount" name="amount" step="0.01" min="0.01" placeholder="Enter the amount" required>
            </div>
        </div>

        <div class="expenses-notes-field">
            <div class="expenses-notes-bundle">
                <label for="e-notes">Note (optional)</label>
                <input type="text" id="e-notes" name="notes" maxlength="255">
            </div>
        </div>

        <div class="button-field">
            <div class="expenses-submit-button">
                <button type="submit" class="expense-button">Add Expense</button>
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

    const expenses  = <?= json_encode($expenseData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const icons     = <?= json_encode($categories, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const money     = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function renderTotals() {
        const period = document.getElementById('totalMonth').value;
        const rows   = expenses.filter(function (e) { return e.date.slice(0, 7) === period; });
        const total  = rows.reduce(function (sum, e) { return sum + e.amount; }, 0);

        document.getElementById('totalAmount').textContent = money.format(total);

        const byCategory = {};
        rows.forEach(function (e) {
            byCategory[e.category] = (byCategory[e.category] || 0) + e.amount;
        });

        const entries = Object.keys(byCategory)
            .map(function (name) { return [name, byCategory[name]]; })
            .sort(function (a, b) { return b[1] - a[1]; });

        const list = document.getElementById('categoryTotals');
        list.replaceChildren();

        if (entries.length === 0) {
            list.appendChild(el('li', 'empty-note', 'No expenses for this month.'));
            return;
        }

        entries.forEach(function (pair) {
            const li     = el('li', 'category-item');
            const amount = el('span', 'category-amount', money.format(pair[1]));
            amount.appendChild(el('small', '', Math.round(pair[1] / total * 100) + '%'));

            li.appendChild(el('span', 'category-name', pair[0]));
            li.appendChild(amount);
            list.appendChild(li);
        });
    }

    function renderCategoryHistory() {
        const category = document.getElementById('categorySelect').value;
        const rows     = expenses.filter(function (e) { return e.category === category; });
        const list     = document.getElementById('categoryHistory');
        list.replaceChildren();

        if (rows.length === 0) {
            list.appendChild(el('li', 'empty-note', 'No expenses in this category yet.'));
            return;
        }

        rows.forEach(function (e) {
            const li   = el('li', 'history-item');
            const icon = el('span', 'history-icon material-symbols-outlined', icons[category]);
            const info = el('div', 'history-info');
            info.appendChild(el('span', 'history-title', e.title));
            info.appendChild(el('small', 'history-date', e.date));

            li.appendChild(icon);
            li.appendChild(info);
            li.appendChild(el('span', 'history-amount', money.format(e.amount)));
            list.appendChild(li);
        });
    }

    function openAdd(category) {
        document.getElementById('e-category').value = category || '';
        document.getElementById('id01').style.display = 'block';
        document.getElementById('e-title').focus();
    }

    document.getElementById('categoryAddBtn').addEventListener('click', function () {
        openAdd(document.getElementById('categorySelect').value);
    });

    document.getElementById('categorySelect').addEventListener('change', renderCategoryHistory);
    document.getElementById('totalMonth').addEventListener('change', renderTotals);

    renderTotals();
    renderCategoryHistory();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
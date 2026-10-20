<?php
require_once __DIR__ . '/../includes/auth.php';

class BudgetException extends Exception
{
}

$error = '';

$itemCategories = ['Food', 'Transport', 'Bills', 'Shopping', 'Health', 'Education', 'Entertainment', 'Other'];
$savingLabels   = ['needs' => 'Needs', 'wants' => 'Wants', 'savings' => 'Savings'];

function validDate(string $d): bool
{
    $x = DateTime::createFromFormat('Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

function categoryBalances(mysqli $conn, int $userId, array $keys): array
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

    return $totals;
}

function allocateShortfall(float $shortfall, array $balances, array $selected): ?array
{
    $alloc = [];
    $left  = round($shortfall, 2);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_budget' || $action === 'update_budget') {
        $editId   = $action === 'update_budget' ? (int) ($_POST['budget_id'] ?? 0) : 0;
        $name     = trim($_POST['name'] ?? '');
        $allotted = round((float) ($_POST['allotted'] ?? 0), 2);
        $start    = $_POST['start_date'] ?? '';
        $end      = $_POST['end_date'] ?? '';
        $itemsRaw = json_decode($_POST['items'] ?? '[]', true);
        $selected = array_values(array_intersect((array) ($_POST['deduct'] ?? []), array_keys($savingLabels)));
        $items    = [];
        $days     = 0;

        if (is_array($itemsRaw) && count($itemsRaw) <= 30) {
            foreach ($itemsRaw as $it) {
                if (!is_array($it)) {
                    $items = null;
                    break;
                }
                $itemName  = trim((string) ($it['name'] ?? ''));
                $itemCat   = (string) ($it['category'] ?? '');
                $itemPrice = round((float) ($it['price'] ?? 0), 2);

                if ($itemName === '' || mb_strlen($itemName) > 100 || !in_array($itemCat, $itemCategories, true) || $itemPrice <= 0) {
                    $items = null;
                    break;
                }
                $items[] = [$itemName, $itemCat, $itemPrice];
            }
        } else {
            $items = null;
        }

        if (validDate($start) && validDate($end) && $end >= $start) {
            $days = (new DateTime($start))->diff(new DateTime($end))->days + 1;
        }

        if ($name === '' || mb_strlen($name) > 100) {
            $error = 'Please enter a budget name (up to 100 characters).';
        } elseif ($allotted <= 0) {
            $error = 'The alloted balance must be greater than zero.';
        } elseif ($days < 1 || $days > 366) {
            $error = 'Please choose a valid range of up to 366 days.';
        } elseif ($items === null) {
            $error = 'Please check the expense items.';
        } elseif (count($items) * $days > 3000) {
            $error = 'That is too many expense entries. Shorten the range or use fewer items.';
        } else {
            $daily   = round(array_sum(array_column($items, 2)), 2);
            $total   = round($days * $daily, 2);
            $savings = round($allotted - $total, 2);
            $saved   = false;

            $conn->begin_transaction();
            try {
                if ($editId > 0) {
                    $stmt = $conn->prepare('SELECT id FROM budgets WHERE id = ? AND user_id = ? FOR UPDATE');
                    $stmt->bind_param('ii', $editId, $userId);
                    $stmt->execute();
                    if (!$stmt->get_result()->fetch_assoc()) {
                        throw new BudgetException('Budget not found.');
                    }

                    $stmt = $conn->prepare('DELETE FROM expenses WHERE budget_id = ? AND user_id = ?');
                    $stmt->bind_param('ii', $editId, $userId);
                    $stmt->execute();

                    $stmt = $conn->prepare('DELETE FROM savings WHERE budget_id = ? AND user_id = ?');
                    $stmt->bind_param('ii', $editId, $userId);
                    $stmt->execute();
                }

                $bal = getBalances($conn, $userId);
                if ($allotted > $bal['remaining_income']) {
                    throw new BudgetException('You only have ' . peso($bal['remaining_income']) . ' of remaining income.');
                }

                $alloc = [];
                if ($savings < 0) {
                    $cats  = categoryBalances($conn, $userId, array_keys($savingLabels));
                    $alloc = allocateShortfall(-$savings, $cats, $selected);
                    if ($alloc === null) {
                        throw new BudgetException('The savings you selected cannot cover ' . peso(-$savings) . '.');
                    }
                }

                if ($editId > 0) {
                    $stmt = $conn->prepare(
                        'UPDATE budgets
                            SET name = ?, allotted = ?, start_date = ?, end_date = ?, days = ?, daily_expense = ?, savings = ?
                          WHERE id = ? AND user_id = ?'
                    );
                    $stmt->bind_param('sdssiddii', $name, $allotted, $start, $end, $days, $daily, $savings, $editId, $userId);
                    $stmt->execute();
                    $budgetId = $editId;
                } else {
                    $stmt = $conn->prepare(
                        'INSERT INTO budgets (user_id, name, allotted, start_date, end_date, days, daily_expense, savings)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('isdssidd', $userId, $name, $allotted, $start, $end, $days, $daily, $savings);
                    $stmt->execute();
                    $budgetId = $conn->insert_id;
                }

                $note    = 'Budget: ' . $name;
                $expStmt = $conn->prepare(
                    "INSERT INTO expenses (user_id, budget_id, title, category, amount, paid_from, expense_date, notes)
                     VALUES (?, ?, ?, ?, ?, 'income', ?, ?)"
                );
                for ($i = 0; $i < $days; $i++) {
                    $day = date('Y-m-d', strtotime($start . ' +' . $i . ' days'));
                    foreach ($items as $it) {
                        $expName  = $it[0];
                        $expCat   = $it[1];
                        $expPrice = $it[2];
                        $expStmt->bind_param('iissdss', $userId, $budgetId, $expName, $expCat, $expPrice, $day, $note);
                        $expStmt->execute();
                    }
                }

                $savStmt = $conn->prepare(
                    'INSERT INTO savings (user_id, budget_id, amount, source, category, saved_on, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $today = date('Y-m-d');

                if ($savings > 0) {
                    $savAmount = $savings;
                    $savSource = 'income';
                    $savCat    = 'savings';
                    $savStmt->bind_param('iidssss', $userId, $budgetId, $savAmount, $savSource, $savCat, $today, $note);
                    $savStmt->execute();
                }

                foreach ($alloc as $key => $take) {
                    $savAmount = -$take;
                    $savSource = 'budget';
                    $savCat    = $key;
                    $savStmt->bind_param('iidssss', $userId, $budgetId, $savAmount, $savSource, $savCat, $today, $note);
                    $savStmt->execute();
                }

                $conn->commit();
                $saved = true;
            } catch (BudgetException $e) {
                $conn->rollback();
                $error = $e->getMessage();
            } catch (Throwable $e) {
                $conn->rollback();
                $error = 'Could not save the budget. Please try again.';
            }

            if ($saved) {
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }

    if ($action === 'delete_budget') {
        $budgetId = (int) ($_POST['budget_id'] ?? 0);
        $stmt     = $conn->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $budgetId, $userId);
        $stmt->execute();

        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

$bal            = getBalances($conn, $userId);
$savingBalances = categoryBalances($conn, $userId, array_keys($savingLabels));

$stmt = $conn->prepare(
    'SELECT id, name, allotted, start_date, end_date, days, daily_expense, savings
       FROM budgets
      WHERE user_id = ?
      ORDER BY id DESC
      LIMIT 100'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$budgets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$itemsByBudget = [];
$stmt = $conn->prepare(
    'SELECT e.budget_id, e.title, e.category, e.amount
       FROM expenses e
       JOIN budgets b ON b.id = e.budget_id AND e.expense_date = b.start_date
      WHERE b.user_id = ? AND e.user_id = ?
      ORDER BY e.id ASC'
);
$stmt->bind_param('ii', $userId, $userId);
$stmt->execute();
foreach ($stmt->get_result() as $row) {
    $itemsByBudget[(int) $row['budget_id']][] = [
        'name'     => $row['title'],
        'category' => $row['category'],
        'price'    => (float) $row['amount'],
    ];
}

$savingsByBudget = [];
$stmt = $conn->prepare(
    'SELECT budget_id, category, SUM(amount) AS total
       FROM savings
      WHERE user_id = ? AND budget_id IS NOT NULL
      GROUP BY budget_id, category'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
foreach ($stmt->get_result() as $row) {
    $savingsByBudget[(int) $row['budget_id']][$row['category']] = round((float) $row['total'], 2);
}

$editData = [];
foreach ($budgets as $b) {
    $id         = (int) $b['id'];
    $editData[] = [
        'id'        => $id,
        'name'      => $b['name'],
        'allotted'  => (float) $b['allotted'],
        'start'     => $b['start_date'],
        'end'       => $b['end_date'],
        'items'     => $itemsByBudget[$id] ?? [],
        'saving'    => (object) ($savingsByBudget[$id] ?? []),
        'available' => round($bal['remaining_income'] + (int) $b['days'] * (float) $b['daily_expense'] + max(0, (float) $b['savings']), 2),
    ];
}

$today = date('Y-m-d');

$pageTitle = 'Budgeting';
$pageCss   = 'budgeting.css';
include __DIR__ . '/../includes/header.php';
?>

<h1 class="current-page">Budgeting</h1>

<?php if ($error): ?>
    <p class="error-message"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>

<div class="budgeting-container">
    <form class="budgeting-section" id="budgetForm" method="post">
        <input type="hidden" name="action" id="actionInput" value="add_budget">
        <input type="hidden" name="budget_id" id="budgetIdInput" value="">
        <input type="hidden" name="items" id="itemsInput" value="[]">

        <p class="edit-note" id="editNote" hidden></p>

        <div class="budgeting-top">
            <div class="budgeting-name">
                <input type="text" id="name" name="name" maxlength="100" placeholder="Budget Name" aria-label="Budget Name" required>
            </div>

            <div class="budgeting-balance">
                <label>Balance</label>
                <strong id="balanceAmount" class="balance-amount <?= $bal['remaining_income'] < 0 ? 'is-negative' : '' ?>"><?= peso($bal['remaining_income']) ?></strong>
            </div>
        </div>

        <div class="budgeting-fields">
            <div class="budgeting-amount">
                <label for="amount">Alloted Balance</label>
                <input type="number" id="amount" name="allotted" step="0.01" min="0.01"
                       max="<?= max(0, $bal['remaining_income']) ?>" required>
            </div>

            <div class="budgeting-range">
                <label for="startDate">Range</label>
                <div class="range-inputs">
                    <input type="date" id="startDate" name="start_date" value="<?= $today ?>" required aria-label="Start date">
                    <input type="date" id="endDate" name="end_date" value="<?= $today ?>" required aria-label="End date">
                </div>
                <small id="rangeDays"></small>
            </div>

            <div class="budgeting-expense-pday">
                <label for="openExpenses">Expenses</label>
                <button type="button" id="openExpenses" class="field-box"></button>
            </div>

            <div class="budgeting-savings">
                <label>Savings</label>
                <div id="savingsBox" class="field-box"></div>
            </div>
        </div>

        <div class="budgeting-actions">
            <button type="button" id="cancelEdit" class="grey-btn" hidden>Cancel</button>
            <button type="submit" id="submitBudget" class="green-btn">Finish</button>
        </div>
    </form>
</div>

<div class="budgeting-records">
    <?php if (count($budgets) === 0): ?>
        <p class="empty-note">No budgets yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="budget-table">
                <thead>
                    <tr>
                        <th>Budget Name</th>
                        <th>Alloted Balance</th>
                        <th>Range</th>
                        <th>Expenses</th>
                        <th>Savings</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($budgets as $b): ?>
                        <tr>
                            <td><?= htmlspecialchars($b['name']) ?></td>
                            <td><?= peso($b['allotted']) ?></td>
                            <td>
                                <?= (int) $b['days'] ?> <?= (int) $b['days'] === 1 ? 'day' : 'days' ?>
                                <small><?= date('M j', strtotime($b['start_date'])) ?> – <?= date('M j, Y', strtotime($b['end_date'])) ?></small>
                            </td>
                            <td>
                                <?= peso($b['daily_expense']) ?>
                                <small>per day</small>
                            </td>
                            <td class="<?= (float) $b['savings'] < 0 ? 'is-negative' : '' ?>">
                                <?= peso($b['savings']) ?>
                                <?php if ((float) $b['savings'] < 0): ?>
                                    <small>covered from savings</small>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <div class="row-actions">
                                    <button type="button" class="icon-btn budget-edit" title="Update" data-id="<?= (int) $b['id'] ?>">
                                        <span class="material-symbols-outlined">edit</span>
                                    </button>
                                    <form method="post" onsubmit="return confirm('Delete this budget and everything it recorded?')">
                                        <input type="hidden" name="action" value="delete_budget">
                                        <input type="hidden" name="budget_id" value="<?= (int) $b['id'] ?>">
                                        <button type="submit" class="icon-btn danger" title="Delete">
                                            <span class="material-symbols-outlined">delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div id="expensesModal" class="budget-modal">
    <div class="budget-form">
        <span class="budget-close" data-close>&times;</span>
        <h3>Daily Expenses</h3>
        <p class="form-hint">Add each expense for one day. It repeats for every day in the range.</p>

        <div class="item-inputs">
            <div class="form-field">
                <label for="itemName">Name</label>
                <input type="text" id="itemName" maxlength="100">
            </div>
            <div class="form-field">
                <label for="itemCategory">Category</label>
                <select id="itemCategory">
                    <?php foreach ($itemCategories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="itemPrice">Price</label>
                <input type="number" id="itemPrice" step="0.01" min="0.01">
            </div>
            <button type="button" id="addItem" class="green-btn">Add</button>
        </div>

        <ul class="item-list" id="itemList"></ul>

        <p class="item-total">Total per day: <strong id="itemTotal"></strong></p>

        <button type="button" id="finishExpenses" class="form-submit">Finish</button>
    </div>
</div>

<div id="deductModal" class="budget-modal">
    <div class="budget-form">
        <span class="budget-close" data-close>&times;</span>
        <h3>Savings Shortfall</h3>
        <p class="form-hint" id="deductText"></p>

        <ul class="deduct-list">
            <?php foreach ($savingLabels as $key => $label): ?>
                <li>
                    <label>
                        <input type="checkbox" class="deduct-check" value="<?= htmlspecialchars($key) ?>"
                               <?= $savingBalances[$key] <= 0 ? 'disabled' : '' ?>>
                        <span><?= htmlspecialchars($label) ?></span>
                        <span class="deduct-bal" data-key="<?= htmlspecialchars($key) ?>"><?= peso($savingBalances[$key]) ?></span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="deduct-preview" id="deductPreview"></p>

        <button type="button" id="confirmDeduct" class="form-submit" disabled>Deduct and finish</button>
    </div>
</div>

<script>
    const money               = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const baseSavingBalances  = <?= json_encode($savingBalances) ?>;
    const savingBalances      = Object.assign({}, baseSavingBalances);
    const savingLabels        = <?= json_encode($savingLabels) ?>;
    const baseAvailable       = <?= json_encode(round($bal['remaining_income'], 2)) ?>;
    const budgetData          = <?= json_encode($editData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

    const form          = document.getElementById('budgetForm');
    const nameInput     = document.getElementById('name');
    const amountInput   = document.getElementById('amount');
    const startInput    = document.getElementById('startDate');
    const endInput      = document.getElementById('endDate');
    const itemsInput    = document.getElementById('itemsInput');
    const expenseBox    = document.getElementById('openExpenses');
    const savingsBox    = document.getElementById('savingsBox');
    const rangeDays     = document.getElementById('rangeDays');
    const actionInput   = document.getElementById('actionInput');
    const budgetIdInput = document.getElementById('budgetIdInput');
    const submitBtn     = document.getElementById('submitBudget');
    const cancelBtn     = document.getElementById('cancelEdit');
    const editNote      = document.getElementById('editNote');
    const balanceEl     = document.getElementById('balanceAmount');

    let items = [];
    let shortfall = 0;

    function r2(n) {
        return Math.round(n * 100) / 100;
    }

    function dailyTotal() {
        return r2(items.reduce(function (sum, item) { return sum + item.price; }, 0));
    }

    function dayCount() {
        if (!startInput.value || !endInput.value) return 0;
        const a = new Date(startInput.value + 'T00:00:00');
        const b = new Date(endInput.value + 'T00:00:00');
        const d = Math.round((b - a) / 86400000) + 1;
        return d > 0 ? d : 0;
    }

    function currentSavings() {
        return r2((parseFloat(amountInput.value) || 0) - dayCount() * dailyTotal());
    }

    function recalc() {
        const days    = dayCount();
        const savings = currentSavings();

        expenseBox.textContent = money.format(dailyTotal());
        savingsBox.textContent = money.format(savings);
        savingsBox.classList.toggle('is-negative', savings < 0);
        rangeDays.textContent  = days > 0 ? days + (days === 1 ? ' day' : ' days') : '';

        let message = '';
        if (startInput.value && endInput.value && days === 0) {
            message = 'End date must be on or after the start date.';
        } else if (days > 366) {
            message = 'The range can be at most 366 days.';
        }
        endInput.setCustomValidity(message);
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function renderItems() {
        const list = document.getElementById('itemList');
        list.replaceChildren();

        items.forEach(function (item, index) {
            const li = el('li', 'item-row');
            const info = el('div', 'item-info');
            info.appendChild(el('span', 'item-name', item.name));
            info.appendChild(el('small', '', item.category));

            const remove = el('button', 'icon-btn danger', '×');
            remove.type = 'button';
            remove.addEventListener('click', function () {
                items.splice(index, 1);
                renderItems();
            });

            li.appendChild(info);
            li.appendChild(el('strong', '', money.format(item.price)));
            li.appendChild(remove);
            list.appendChild(li);
        });

        document.getElementById('itemTotal').textContent = money.format(dailyTotal());
        itemsInput.value = JSON.stringify(items);
        recalc();
    }

    function addItem() {
        const nameField  = document.getElementById('itemName');
        const priceField = document.getElementById('itemPrice');
        const name       = nameField.value.trim();
        const price      = r2(parseFloat(priceField.value));

        if (name === '' || !(price > 0)) {
            (name === '' ? nameField : priceField).focus();
            return;
        }

        items.push({ name: name, category: document.getElementById('itemCategory').value, price: price });
        nameField.value  = '';
        priceField.value = '';
        renderItems();
        nameField.focus();
    }

    function showModal(id) {
        document.getElementById(id).style.display = 'block';
    }

    function setBalanceDisplay(value) {
        balanceEl.textContent = money.format(value);
        balanceEl.classList.toggle('is-negative', value < 0);
    }

    function refreshDeductList() {
        document.querySelectorAll('.deduct-check').forEach(function (c) {
            const bal = savingBalances[c.value];
            c.disabled = bal <= 0;
            document.querySelector('.deduct-bal[data-key="' + c.value + '"]').textContent = money.format(bal);
        });
    }

    function startEdit(id) {
        const data = budgetData.find(function (b) { return b.id === id; });
        if (!data) return;

        actionInput.value   = 'update_budget';
        budgetIdInput.value = data.id;
        nameInput.value     = data.name;
        amountInput.value   = data.allotted.toFixed(2);
        amountInput.max     = Math.max(0, data.available).toFixed(2);
        startInput.value    = data.start;
        endInput.value      = data.end;

        items = data.items.map(function (item) {
            return { name: item.name, category: item.category, price: item.price };
        });

        Object.keys(baseSavingBalances).forEach(function (key) {
            savingBalances[key] = r2(baseSavingBalances[key] - (data.saving[key] || 0));
        });

        setBalanceDisplay(data.available);
        editNote.textContent = 'Editing: ' + data.name;
        editNote.hidden      = false;
        cancelBtn.hidden     = false;
        submitBtn.textContent = 'Save Changes';

        delete form.dataset.confirmed;
        form.querySelectorAll('input[name="deduct[]"]').forEach(function (n) { n.remove(); });

        refreshDeductList();
        renderItems();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        nameInput.focus({ preventScroll: true });
    }

    function stopEdit() {
        form.reset();
        delete form.dataset.confirmed;
        form.querySelectorAll('input[name="deduct[]"]').forEach(function (n) { n.remove(); });

        actionInput.value   = 'add_budget';
        budgetIdInput.value = '';
        amountInput.max     = Math.max(0, baseAvailable).toFixed(2);
        items               = [];

        Object.keys(baseSavingBalances).forEach(function (key) {
            savingBalances[key] = baseSavingBalances[key];
        });

        setBalanceDisplay(baseAvailable);
        editNote.hidden       = true;
        cancelBtn.hidden      = true;
        submitBtn.textContent = 'Finish';

        refreshDeductList();
        renderItems();
    }

    document.querySelectorAll('.budget-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            startEdit(parseInt(btn.dataset.id, 10));
        });
    });

    cancelBtn.addEventListener('click', stopEdit);

    document.addEventListener('click', function (event) {
        const target = event.target;
        if (target.classList.contains('budget-modal') || target.hasAttribute('data-close')) {
            target.closest('.budget-modal').style.display = 'none';
        }
    });

    expenseBox.addEventListener('click', function () {
        showModal('expensesModal');
        document.getElementById('itemName').focus();
    });

    document.getElementById('addItem').addEventListener('click', addItem);

    ['itemName', 'itemPrice'].forEach(function (id) {
        document.getElementById(id).addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                addItem();
            }
        });
    });

    document.getElementById('finishExpenses').addEventListener('click', function () {
        document.getElementById('expensesModal').style.display = 'none';
    });

    [amountInput, startInput, endInput].forEach(function (input) {
        input.addEventListener('input', recalc);
    });

    function allocate(amount, selected) {
        const alloc = {};
        let left = r2(amount);

        Object.keys(savingBalances).forEach(function (key) {
            const available = savingBalances[key];
            if (left <= 0 || selected.indexOf(key) === -1 || available <= 0) return;
            const take = r2(Math.min(left, available));
            alloc[key] = take;
            left = r2(left - take);
        });

        return { alloc: alloc, left: left };
    }

    function updateDeduct() {
        const selected = Array.from(document.querySelectorAll('.deduct-check:checked')).map(function (c) { return c.value; });
        const result   = allocate(shortfall, selected);
        const preview  = document.getElementById('deductPreview');

        if (selected.length === 0) {
            preview.textContent = '';
        } else if (result.left > 0) {
            preview.textContent = 'Selected savings are short by ' + money.format(result.left) + '.';
        } else {
            preview.textContent = Object.keys(result.alloc)
                .map(function (key) { return savingLabels[key] + ' −' + money.format(result.alloc[key]); })
                .join(' · ');
        }

        document.getElementById('confirmDeduct').disabled = selected.length === 0 || result.left > 0;
    }

    function openDeduct(amount) {
        shortfall = amount;
        document.getElementById('deductText').textContent =
            'This budget is ' + money.format(amount) + ' over. Choose which savings to deduct it from.';
        document.querySelectorAll('.deduct-check').forEach(function (c) { c.checked = false; });
        updateDeduct();
        showModal('deductModal');
    }

    document.querySelectorAll('.deduct-check').forEach(function (c) {
        c.addEventListener('change', updateDeduct);
    });

    document.getElementById('confirmDeduct').addEventListener('click', function () {
        form.querySelectorAll('input[name="deduct[]"]').forEach(function (n) { n.remove(); });
        document.querySelectorAll('.deduct-check:checked').forEach(function (c) {
            const hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = 'deduct[]';
            hidden.value = c.value;
            form.appendChild(hidden);
        });
        form.dataset.confirmed = '1';
        form.submit();
    });

    form.addEventListener('submit', function (event) {
        const savings = currentSavings();
        if (savings < 0 && !form.dataset.confirmed) {
            event.preventDefault();
            openDeduct(-savings);
        }
    });

    renderItems();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
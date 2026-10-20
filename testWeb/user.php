<?php
require_once __DIR__ . '/includes/auth.php';

function periodTotal(mysqli $conn, string $table, string $column, int $userId, string $from, string $to): float
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS t FROM $table WHERE user_id = ? AND $column BETWEEN ? AND ?");
    $stmt->bind_param('iss', $userId, $from, $to);
    $stmt->execute();
    return (float) $stmt->get_result()->fetch_assoc()['t'];
}

function weekChange(float $now, float $prev): array
{
    if ($now == 0.0 && $prev == 0.0) {
        return ['dir' => 'flat', 'label' => '0%'];
    }
    if ($prev == 0.0) {
        return ['dir' => $now > 0 ? 'up' : 'down', 'label' => 'New'];
    }

    $pct = ($now - $prev) / abs($prev) * 100;
    $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');

    return ['dir' => $dir, 'label' => ($pct > 0 ? '+' : '') . round($pct) . '%'];
}

function monthRows(mysqli $conn, string $sql, int $userId): array
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $rows = [];
    foreach ($stmt->get_result() as $row) {
        $rows[$row['ym']] = $row;
    }
    return $rows;
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime($weekStart . ' +6 days'));
$prevStart = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$prevEnd   = date('Y-m-d', strtotime($weekStart . ' -1 day'));

$stmt = $conn->prepare(
    'SELECT COALESCE(SUM(allotted), 0) AS allotted, COUNT(*) AS n
       FROM budgets
      WHERE user_id = ? AND start_date <= ? AND end_date >= ?'
);
$stmt->bind_param('iss', $userId, $weekEnd, $weekStart);
$stmt->execute();
$allowanceRow = $stmt->get_result()->fetch_assoc();
$allowance    = round((float) $allowanceRow['allotted'], 2);
$budgetCount  = (int) $allowanceRow['n'];

$stmt = $conn->prepare(
    'SELECT COALESCE(SUM(e.amount), 0) AS spent
       FROM expenses e
       JOIN budgets b ON b.id = e.budget_id
      WHERE e.user_id = ? AND b.start_date <= ? AND b.end_date >= ? AND e.expense_date <= ?'
);
$stmt->bind_param('isss', $userId, $weekEnd, $weekStart, $today);
$stmt->execute();
$spentSoFar = round((float) $stmt->get_result()->fetch_assoc()['spent'], 2);
$remaining  = round($allowance - $spentSoFar, 2);

$summaryCards = [];
$summaryDefs  = [
    ['Total Income', 'dot-income', 'income', 'date_received', true],
    ['Total Expenses', 'dot-expense', 'expenses', 'expense_date', false],
    ['Total Savings', 'dot-saving', 'savings', 'saved_on', true],
];
foreach ($summaryDefs as [$label, $dot, $table, $column, $goodWhenUp]) {
    $now    = periodTotal($conn, $table, $column, $userId, $weekStart, $today);
    $prev   = periodTotal($conn, $table, $column, $userId, $prevStart, $prevEnd);
    $change = weekChange($now, $prev);

    if ($change['dir'] === 'flat') {
        $tone = 'trend-flat';
        $icon = 'do_not_disturb_on';
    } else {
        $tone = (($change['dir'] === 'up') === $goodWhenUp) ? 'trend-good' : 'trend-bad';
        $icon = $change['dir'] === 'up' ? 'arrow_circle_up' : 'arrow_circle_down';
    }

    $summaryCards[] = [
        'label'  => $label,
        'dot'    => $dot,
        'amount' => round($now, 2),
        'change' => $change['label'],
        'tone'   => $tone,
        'icon'   => $icon,
    ];
}

$stmt = $conn->prepare('SELECT name, target_amount, saved_amount FROM goals WHERE user_id = ? ORDER BY id ASC LIMIT 50');
$stmt->bind_param('i', $userId);
$stmt->execute();
$goals = [];
foreach ($stmt->get_result() as $g) {
    $target  = (float) $g['target_amount'];
    $saved   = (float) $g['saved_amount'];
    $goals[] = [
        'name' => $g['name'],
        'pct'  => $target > 0 ? round(min(100, $saved / $target * 100), 1) : 0,
    ];
}

$stmt = $conn->prepare(
    "SELECT 'income' AS type, source AS title, source AS category, COALESCE(NULLIF(notes, ''), '') AS note,
            amount, date_received AS d, time_received AS t, created_at
       FROM income
      WHERE user_id = ? AND date_received <= ?
     UNION ALL
     SELECT 'expense', title, category, COALESCE(NULLIF(notes, ''), ''),
            amount, expense_date, NULL, created_at
       FROM expenses
      WHERE user_id = ? AND expense_date <= ?
     UNION ALL
     SELECT 'savings',
            CASE WHEN amount < 0 THEN 'Savings deduction' ELSE 'Moved to savings' END,
            category, COALESCE(NULLIF(notes, ''), ''),
            amount, saved_on, NULL, created_at
       FROM savings
      WHERE user_id = ? AND saved_on <= ?
      ORDER BY d DESC, created_at DESC
      LIMIT 300"
);
$stmt->bind_param('isisis', $userId, $today, $userId, $today, $userId, $today);
$stmt->execute();

$activity = [];
foreach ($stmt->get_result() as $r) {
    $title    = $r['title'];
    $category = $r['category'];

    if ($r['type'] === 'income') {
        $title    = ucfirst($title);
        $category = ucfirst($category);
    } elseif ($r['type'] === 'savings') {
        $category = ucfirst($category);
    }

    $activity[] = [
        'type'     => $r['type'],
        'title'    => $title,
        'category' => $category,
        'note'     => $r['note'],
        'amount'   => round((float) $r['amount'], 2),
        'date'     => $r['d'],
        'time'     => $r['t'] !== null ? substr($r['t'], 0, 5) : null,
    ];
}

$incomeM = monthRows(
    $conn,
    "SELECT DATE_FORMAT(date_received, '%Y-%m') AS ym, SUM(amount) AS total
       FROM income
      WHERE user_id = ?
      GROUP BY ym",
    $userId
);

$expenseM = monthRows(
    $conn,
    "SELECT DATE_FORMAT(expense_date, '%Y-%m') AS ym, SUM(amount) AS total,
            SUM(CASE WHEN paid_from = 'income' THEN amount ELSE 0 END) AS from_income
       FROM expenses
      WHERE user_id = ?
      GROUP BY ym",
    $userId
);

$savingM = monthRows(
    $conn,
    "SELECT DATE_FORMAT(saved_on, '%Y-%m') AS ym, SUM(amount) AS net,
            SUM(CASE WHEN source = 'income' THEN amount ELSE 0 END) AS from_income,
            COALESCE(-SUM(CASE WHEN source = 'budget' THEN amount ELSE 0 END), 0) AS covered
       FROM savings
      WHERE user_id = ?
      GROUP BY ym",
    $userId
);

$graph = ['labels' => [], 'income' => [], 'expenses' => [], 'savings' => [], 'remaining' => []];
for ($i = 11; $i >= 0; $i--) {
    $ts  = strtotime('first day of -' . $i . ' month');
    $key = date('Y-m', $ts);

    $income        = (float) ($incomeM[$key]['total'] ?? 0);
    $expenses      = (float) ($expenseM[$key]['total'] ?? 0);
    $expFromIncome = (float) ($expenseM[$key]['from_income'] ?? 0);
    $savingsNet    = (float) ($savingM[$key]['net'] ?? 0);
    $savFromIncome = (float) ($savingM[$key]['from_income'] ?? 0);
    $covered       = (float) ($savingM[$key]['covered'] ?? 0);

    $graph['labels'][]    = date('M Y', $ts);
    $graph['income'][]    = round($income, 2);
    $graph['expenses'][]  = round($expenses, 2);
    $graph['savings'][]   = round($savingsNet, 2);
    $graph['remaining'][] = round($income - $expFromIncome - $savFromIncome + $covered, 2);
}

$dash = [
    'today'     => $today,
    'yesterday' => $yesterday,
    'goals'     => $goals,
    'activity'  => $activity,
    'graph'     => $graph,
];

$pageTitle = 'User Dashboard';
$pageCss   = 'dashboard.css';
include __DIR__ . '/includes/header.php';
?>
<h1 class="current-page">Overview</h1>

    <div class="top-data-container">
        <div class="data-balance">
            <div class="budgeting-allowance">
                <h2>Allowance for the Week</h2>
                <h1 class="allowance-amount"><?= peso($allowance) ?></h1>
                <?php if ($budgetCount === 0): ?>
                    <small class="card-note">No budget set for this week</small>
                <?php endif; ?>
            </div>
            <div class="remaining-allowance">
                <h2>Remaining</h2>
                <h1 class="remaining-amount <?= $remaining < 0 ? 'is-minus' : '' ?>"><?= peso($remaining) ?></h1>
            </div>
        </div>

        <div class="data-summary">
            <h2>Summary <small>This week</small></h2>
            <div class="summary-list">
                <?php foreach ($summaryCards as $card): ?>
                    <div class="summary-card">
                        <div class="summary-top">
                            <span class="summary-name">
                                <span class="dot <?= $card['dot'] ?>"></span>
                                <?= htmlspecialchars($card['label']) ?>
                            </span>
                            <span class="trend-badge <?= $card['tone'] ?>" title="Compared with last week">
                                <?= htmlspecialchars($card['change']) ?>
                                <span class="material-symbols-outlined"><?= $card['icon'] ?></span>
                            </span>
                        </div>
                        <p class="summary-amount"><?= peso($card['amount']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="data-goals">
            <div class="goals-head">
                <h2>Goals</h2>
                <button type="button" id="goalSort" class="sort-btn">
                    <span class="material-symbols-outlined">swap_vert</span>
                    <span id="goalSortLabel"></span>
                </button>
            </div>
            <ul class="goal-list" id="goalList"></ul>
            <a href="<?= PAGES_URL ?>/savings.php" class="more-link" id="goalMore" hidden></a>
        </div>
    </div>

    <div class="median-data-container">
        <div class="data-activity">
            <div class="activity-head">
                <h2>Recent Activity</h2>
                <label class="activity-search">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" id="activitySearch" placeholder="Search" autocomplete="off">
                </label>
                <button type="button" id="openFilter" class="filter-btn">
                    <span class="material-symbols-outlined">filter_list</span>
                    Filter
                    <span class="filter-count" id="filterCount" hidden></span>
                </button>
            </div>
            <ul class="activity-list" id="activityList"></ul>
        </div>

        <div class="data-graph">
            <div class="graph-head">
                <h2>Balance Trends</h2>
                <select id="graphRange" class="month-select" aria-label="Range">
                    <option value="6">Last 6 months</option>
                    <option value="12">Last 12 months</option>
                </select>
            </div>
            <div class="chart-box"><canvas id="balanceChart"></canvas></div>
        </div>
    </div>

    <div id="filterModal" class="filter-modal">
        <div class="filter-card">
            <h3 class="filter-title">Filter</h3>

            <label class="filter-toggle"><input type="checkbox" id="fType"> Type</label>
            <select id="fTypeSel" class="filter-field" disabled>
                <option value="income">Income</option>
                <option value="expense">Expense</option>
                <option value="savings">Savings</option>
            </select>

            <label class="filter-toggle"><input type="checkbox" id="fCat"> Category</label>
            <select id="fCatSel" class="filter-field" disabled></select>

            <label class="filter-toggle"><input type="checkbox" id="fAmt"> Amount</label>
            <div class="filter-amount">
                <label for="fMin">Min</label>
                <input type="number" id="fMin" class="filter-field" min="0" step="0.01" disabled>
                <label for="fMax">Max</label>
                <input type="number" id="fMax" class="filter-field" min="0" step="0.01" disabled>
            </div>

            <div class="filter-dates">
                <label>Date (From)<input type="date" id="fFrom" class="filter-field"></label>
                <label>Date (To)<input type="date" id="fTo" class="filter-field"></label>
            </div>

            <div class="filter-actions">
                <button type="button" id="clearFilter" class="filter-clear">Clear</button>
                <button type="button" id="applyFilter" class="filter-apply">Apply</button>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
<script>
    const dash    = <?= json_encode($dash, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const money   = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const palette = ['#f9a8d4', '#fb923c', '#22c55e', '#0d8bff', '#a78bfa', '#facc15'];

    const typeLabels = { income: 'Income', expense: 'Expense', savings: 'Savings' };
    const typeIcons  = { income: 'south_west', expense: 'north_east', savings: 'savings' };

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    let goalOrder = 'desc';
    try {
        const savedOrder = localStorage.getItem('goalOrder');
        if (savedOrder === 'asc' || savedOrder === 'desc') goalOrder = savedOrder;
    } catch (e) {}

    function fitGoals() {
        const list = document.getElementById('goalList');
        const more = document.getElementById('goalMore');
        const items = Array.from(list.children).filter(function (item) { return item.classList.contains('goal-row'); });

        if (items.length === 0) {
            more.hidden = true;
            return;
        }

        items.forEach(function (item) { item.hidden = false; });

        const limit = list.clientHeight;
        if (limit === 0) return;

        let shown = items.length;
        for (let i = 0; i < items.length; i++) {
            if (items[i].offsetTop + items[i].offsetHeight > limit) {
                shown = i;
                break;
            }
        }

        items.forEach(function (item, i) { item.hidden = i >= shown; });

        const hiddenCount = items.length - shown;
        more.hidden = hiddenCount <= 0;
        more.textContent = '+' + hiddenCount + ' more';
    }

    function renderGoals() {
        const list = document.getElementById('goalList');
        list.replaceChildren();

        document.getElementById('goalSortLabel').textContent = goalOrder === 'desc' ? 'Highest first' : 'Lowest first';

        if (dash.goals.length === 0) {
            list.appendChild(el('li', 'empty-note', 'No goals yet.'));
            document.getElementById('goalMore').hidden = true;
            return;
        }

        const goals = dash.goals.map(function (g, i) {
            return { name: g.name, pct: g.pct, color: palette[i % palette.length] };
        });

        goals.sort(function (a, b) { return goalOrder === 'desc' ? b.pct - a.pct : a.pct - b.pct; });

        goals.forEach(function (g) {
            const li    = el('li', 'goal-row');
            const body  = el('div', 'goal-body');
            const track = el('div', 'goal-track');
            const fill  = el('span');

            fill.style.width      = g.pct + '%';
            fill.style.background = g.color;
            track.appendChild(fill);

            body.appendChild(el('span', 'goal-name', g.name));
            body.appendChild(track);

            li.appendChild(el('span', 'goal-icon material-symbols-outlined', 'flag'));
            li.appendChild(body);
            li.appendChild(el('span', 'goal-pct', g.pct + '%'));
            list.appendChild(li);
        });

        fitGoals();
    }

    document.getElementById('goalSort').addEventListener('click', function () {
        goalOrder = goalOrder === 'desc' ? 'asc' : 'desc';
        try { localStorage.setItem('goalOrder', goalOrder); } catch (e) {}
        renderGoals();
    });

    window.addEventListener('resize', fitGoals);

    const filters = { type: '', category: '', min: null, max: null, from: '', to: '' };
    let query = '';

    const fType    = document.getElementById('fType');
    const fTypeSel = document.getElementById('fTypeSel');
    const fCat     = document.getElementById('fCat');
    const fCatSel  = document.getElementById('fCatSel');
    const fAmt     = document.getElementById('fAmt');
    const fMin     = document.getElementById('fMin');
    const fMax     = document.getElementById('fMax');
    const fFrom    = document.getElementById('fFrom');
    const fTo      = document.getElementById('fTo');

    Array.from(new Set(dash.activity.map(function (a) { return a.category; }))).sort().forEach(function (c) {
        const option = document.createElement('option');
        option.value       = c;
        option.textContent = c;
        fCatSel.appendChild(option);
    });

    function dateLabel(a) {
        let label;
        if (a.date === dash.today) {
            label = 'Today';
        } else if (a.date === dash.yesterday) {
            label = 'Yesterday';
        } else {
            label = new Date(a.date + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        if (a.time) {
            const parts = a.time.split(':');
            const d     = new Date();
            d.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10));
            label += ', ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        return label;
    }

    function matches(a) {
        if (query) {
            const haystack = (a.title + ' ' + a.category + ' ' + a.note + ' ' + typeLabels[a.type]).toLowerCase();
            if (haystack.indexOf(query) === -1) return false;
        }
        if (filters.type && a.type !== filters.type) return false;
        if (filters.category && a.category !== filters.category) return false;

        const size = Math.abs(a.amount);
        if (filters.min !== null && size < filters.min) return false;
        if (filters.max !== null && size > filters.max) return false;

        if (filters.from && a.date < filters.from) return false;
        if (filters.to && a.date > filters.to) return false;

        return true;
    }

    function activeFilterCount() {
        return (filters.type ? 1 : 0)
            + (filters.category ? 1 : 0)
            + (filters.min !== null || filters.max !== null ? 1 : 0)
            + (filters.from || filters.to ? 1 : 0);
    }

    function renderActivity() {
        const list = document.getElementById('activityList');
        list.replaceChildren();

        const rows = dash.activity.filter(matches);

        if (rows.length === 0) {
            list.appendChild(el('li', 'empty-note', 'No activity found.'));
        }

        rows.forEach(function (a) {
            const sign  = a.type === 'income' ? '+' : (a.type === 'expense' ? '-' : (a.amount >= 0 ? '+' : '-'));
            const tone  = a.type === 'income' ? 'amount-in' : (a.type === 'expense' ? 'amount-out' : 'amount-save');
            const meta  = a.category + (a.note ? ' · ' + a.note : '');

            const li    = el('li', 'activity-row');
            const icon  = el('span', 'activity-icon material-symbols-outlined', typeIcons[a.type]);
            const info  = el('div', 'activity-info');
            const right = el('div', 'activity-right');

            info.appendChild(el('span', 'activity-title', a.title));
            info.appendChild(el('span', 'activity-meta', meta));
            right.appendChild(el('span', 'activity-amount ' + tone, sign + money.format(Math.abs(a.amount))));
            right.appendChild(el('span', 'activity-date', dateLabel(a)));

            li.appendChild(icon);
            li.appendChild(info);
            li.appendChild(right);
            list.appendChild(li);
        });

        const count = activeFilterCount();
        const badge = document.getElementById('filterCount');
        badge.hidden      = count === 0;
        badge.textContent = count;
    }

    function syncFilterForm() {
        fType.checked = filters.type !== '';
        fTypeSel.disabled = !fType.checked;
        if (filters.type) fTypeSel.value = filters.type;

        fCat.checked = filters.category !== '';
        fCatSel.disabled = !fCat.checked;
        if (filters.category) fCatSel.value = filters.category;

        const hasAmount = filters.min !== null || filters.max !== null;
        fAmt.checked = hasAmount;
        fMin.disabled = !hasAmount;
        fMax.disabled = !hasAmount;
        fMin.value = filters.min !== null ? filters.min : '';
        fMax.value = filters.max !== null ? filters.max : '';

        fFrom.value = filters.from;
        fTo.value   = filters.to;
    }

    function closeFilter() {
        document.getElementById('filterModal').style.display = 'none';
    }

    document.getElementById('openFilter').addEventListener('click', function () {
        syncFilterForm();
        document.getElementById('filterModal').style.display = 'block';
    });

    fType.addEventListener('change', function () { fTypeSel.disabled = !fType.checked; });
    fCat.addEventListener('change', function () { fCatSel.disabled = !fCat.checked; });
    fAmt.addEventListener('change', function () {
        fMin.disabled = !fAmt.checked;
        fMax.disabled = !fAmt.checked;
    });

    document.getElementById('applyFilter').addEventListener('click', function () {
        filters.type     = fType.checked ? fTypeSel.value : '';
        filters.category = fCat.checked && fCatSel.value ? fCatSel.value : '';
        filters.min      = fAmt.checked && fMin.value !== '' ? parseFloat(fMin.value) : null;
        filters.max      = fAmt.checked && fMax.value !== '' ? parseFloat(fMax.value) : null;
        filters.from     = fFrom.value;
        filters.to       = fTo.value;

        if (filters.min !== null && filters.max !== null && filters.min > filters.max) {
            const swap  = filters.min;
            filters.min = filters.max;
            filters.max = swap;
        }

        renderActivity();
        closeFilter();
    });

    document.getElementById('clearFilter').addEventListener('click', function () {
        filters.type     = '';
        filters.category = '';
        filters.min      = null;
        filters.max      = null;
        filters.from     = '';
        filters.to       = '';

        syncFilterForm();
        renderActivity();
        closeFilter();
    });

    document.getElementById('filterModal').addEventListener('click', function (event) {
        if (event.target === event.currentTarget) closeFilter();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeFilter();
    });

    document.getElementById('activitySearch').addEventListener('input', function (event) {
        query = event.target.value.trim().toLowerCase();
        renderActivity();
    });

    const graph  = dash.graph;
    const series = [
        ['Income', 'income', '#0d8bff'],
        ['Expenses', 'expenses', '#ffaa1d'],
        ['Savings', 'savings', '#10e08f'],
        ['Remaining', 'remaining', '#9b5de5']
    ];

    let balanceChart = null;

    function renderGraph() {
        if (!balanceChart) return;

        const count = parseInt(document.getElementById('graphRange').value, 10);
        balanceChart.data.labels = graph.labels.slice(-count);
        series.forEach(function (s, i) {
            balanceChart.data.datasets[i].data = graph[s[1]].slice(-count);
        });
        balanceChart.update();
    }

    const hasGraphData = series.some(function (s) {
        return graph[s[1]].some(function (v) { return v !== 0; });
    });

    if (hasGraphData) {
        balanceChart = new Chart(document.getElementById('balanceChart'), {
            type: 'bar',
            data: {
                labels: [],
                datasets: series.map(function (s) {
                    return { label: s[0], data: [], backgroundColor: s[2], borderRadius: 4 };
                })
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + money.format(ctx.parsed.y); }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { grid: { color: 'rgba(0, 0, 0, 0.08)' } }
                }
            }
        });

        document.getElementById('graphRange').addEventListener('change', renderGraph);
        renderGraph();
    } else {
        const canvas = document.getElementById('balanceChart');
        canvas.parentNode.replaceChild(el('p', 'empty-note', 'No data yet.'), canvas);
    }

    renderGoals();
    renderActivity();
</script>
<?php
    include __DIR__ . '/includes/footer.php';
?>
<?php
require_once __DIR__ . '/../includes/auth.php';

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

function sliceData(mysqli $conn, string $sql, int $userId, int $paramCount, bool $capitalize = false): array
{
    $params = array_fill(0, $paramCount, $userId);
    $stmt   = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', $paramCount), ...$params);
    $stmt->execute();

    $labels = [];
    $totals = [];
    $counts = [];
    foreach ($stmt->get_result() as $row) {
        if ((int) $row['n'] === 0) {
            continue;
        }
        $labels[] = $capitalize ? ucfirst($row['label']) : $row['label'];
        $totals[] = round((float) $row['total'], 2);
        $counts[] = (int) $row['n'];
    }

    return ['labels' => $labels, 'totals' => $totals, 'counts' => $counts];
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

$budgetM = monthRows(
    $conn,
    "SELECT DATE_FORMAT(start_date, '%Y-%m') AS ym, SUM(allotted) AS planned
       FROM budgets
      WHERE user_id = ?
      GROUP BY ym",
    $userId
);

$thisMonth = date('Y-m');
$monthKeys = array_unique(array_merge(
    [$thisMonth],
    array_keys($incomeM),
    array_keys($expenseM),
    array_keys($savingM),
    array_keys($budgetM)
));
rsort($monthKeys);

$summary = [];
foreach ($monthKeys as $ym) {
    $income        = (float) ($incomeM[$ym]['total'] ?? 0);
    $expenses      = (float) ($expenseM[$ym]['total'] ?? 0);
    $expFromIncome = (float) ($expenseM[$ym]['from_income'] ?? 0);
    $savingsNet    = (float) ($savingM[$ym]['net'] ?? 0);
    $savFromIncome = (float) ($savingM[$ym]['from_income'] ?? 0);
    $covered       = (float) ($savingM[$ym]['covered'] ?? 0);

    $summary[$ym] = [
        'income'    => round($income, 2),
        'expenses'  => round($expenses, 2),
        'savings'   => round($savingsNet, 2),
        'remaining' => round($income - $expFromIncome - $savFromIncome + $covered, 2),
        'planned'   => round((float) ($budgetM[$ym]['planned'] ?? 0), 2),
    ];
}

$year         = (int) date('Y');
$incomeLabels = [];
$incomeData   = [];
for ($m = 1; $m <= 12; $m++) {
    $key            = sprintf('%d-%02d', $year, $m);
    $incomeLabels[] = date('M', mktime(0, 0, 0, $m, 1));
    $incomeData[]   = round((float) ($incomeM[$key]['total'] ?? 0), 2);
}

$expenseLabels = [];
$expenseData   = [];
for ($i = 4; $i >= 0; $i--) {
    $ts              = strtotime('first day of -' . $i . ' month');
    $key             = date('Y-m', $ts);
    $expenseLabels[] = date('M Y', $ts);
    $expenseData[]   = round((float) ($expenseM[$key]['total'] ?? 0), 2);
}

$incomeSources = sliceData(
    $conn,
    'SELECT source AS label, SUM(amount) AS total, COUNT(*) AS n
       FROM income
      WHERE user_id = ?
      GROUP BY source
      ORDER BY total DESC',
    $userId,
    1,
    true
);

$incomeUsage = sliceData(
    $conn,
    "SELECT 'Expenses' AS label, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
       FROM expenses
      WHERE user_id = ? AND paid_from = 'income' AND budget_id IS NULL
     UNION ALL
     SELECT 'Savings', COALESCE(SUM(amount), 0), COUNT(*)
       FROM savings
      WHERE user_id = ? AND source = 'income' AND budget_id IS NULL
     UNION ALL
     SELECT 'Budgeting', COALESCE(SUM(allotted), 0), COUNT(*)
       FROM budgets
      WHERE user_id = ?",
    $userId,
    3
);

$expenseSources = sliceData(
    $conn,
    "SELECT 'Income' AS label, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
       FROM expenses
      WHERE user_id = ? AND paid_from = 'income' AND budget_id IS NULL
     UNION ALL
     SELECT 'Savings', COALESCE(SUM(amount), 0), COUNT(*)
       FROM expenses
      WHERE user_id = ? AND paid_from = 'savings' AND budget_id IS NULL
     UNION ALL
     SELECT 'Budgeting', COALESCE(SUM(amount), 0), COUNT(*)
       FROM expenses
      WHERE user_id = ? AND budget_id IS NOT NULL",
    $userId,
    3
);

$spendCategories = sliceData(
    $conn,
    'SELECT category AS label, SUM(amount) AS total, COUNT(*) AS n
       FROM expenses
      WHERE user_id = ?
      GROUP BY category
      ORDER BY total DESC',
    $userId,
    1
);

$report = [
    'summary'         => $summary,
    'incomeSources'   => $incomeSources,
    'incomeUsage'     => $incomeUsage,
    'incomeTrend'     => ['labels' => $incomeLabels, 'data' => $incomeData],
    'expenseSources'  => $expenseSources,
    'spendCategories' => $spendCategories,
    'expenseTrend'    => ['labels' => $expenseLabels, 'data' => $expenseData],
];

$pageTitle = 'Reports';
$pageCss   = 'reports.css';
include __DIR__ . '/../includes/header.php';
?>
<div class="header-report">
    <h1 class="current-page">Reports</h1>
    <h3>Financial Analysis based on collected data</h3>
</div>
    <div class="reports-container">
        <div class="financial-summary-area">
            <div class="financial-summary">
                <div class="card-head">
                    <h2>Financial Summary</h2>
                    <select id="monthSelect" class="month-select" aria-label="Month">
                        <?php foreach ($monthKeys as $ym): ?>
                            <option value="<?= htmlspecialchars($ym) ?>" <?= $ym === $thisMonth ? 'selected' : '' ?>>
                                <?= date('M Y', strtotime($ym . '-01')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="summary-grid">
                    <div class="summary-tile">
                        <span>Total Income</span>
                        <strong class="is-income" id="sumIncome"></strong>
                    </div>
                    <div class="summary-tile">
                        <span>Total Expenses</span>
                        <strong class="is-expense" id="sumExpenses"></strong>
                    </div>
                    <div class="summary-tile">
                        <span>Total Savings</span>
                        <strong class="is-saving" id="sumSavings"></strong>
                    </div>
                    <div class="summary-tile">
                        <span>Remaining Balance</span>
                        <strong id="sumRemaining"></strong>
                    </div>
                </div>
            </div>

            <div class="budget-performance">
                <div class="card-head">
                    <h2>Budget Performance</h2>
                    <span class="perf-month" id="perfMonth"></span>
                </div>

                <div class="perf-figures">
                    <div>
                        <span>Planned budget</span>
                        <strong id="perfPlanned"></strong>
                    </div>
                    <div>
                        <span>Actual spending</span>
                        <strong id="perfActual"></strong>
                    </div>
                </div>

                <div class="perf-bar" id="perfBar"><span></span></div>
                <p class="perf-result" id="perfResult"></p>
            </div>
        </div>

        <div class="income-report-title">
            <h1>Income Report</h1>
        </div>
        <div class="income-area">
            <div class="income-sources">
                <h2>Income Sources</h2>
                <div class="chart-box"><canvas id="incomeSourcesChart"></canvas></div>
            </div>
            <div class="income-allocations">
                <h2>Income Allocations</h2>
                <div class="chart-box"><canvas id="incomeUsageChart"></canvas></div>
            </div>
            <div class="income-trend">
                <h2>Income Trend</h2>
                <div class="chart-box"><canvas id="incomeTrendChart"></canvas></div>
            </div>
        </div>

        <div class="Expense-report-title">
            <h1>Expenses Report</h1>
        </div>
        <div class="expense-area">
            <div class="expense-sources">
                <h2>Expense Source</h2>
                <div class="chart-box"><canvas id="expenseSourcesChart"></canvas></div>
            </div>
            <div class="expense-allocations">
                <h2>Spend Analytics</h2>
                <div class="chart-box"><canvas id="spendChart"></canvas></div>
            </div>
            <div class="expense-trend">
                <h2>Expenses Trend</h2>
                <div class="chart-box"><canvas id="expenseTrendChart"></canvas></div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0"></script>
<script>
    const report  = <?= json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const money   = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const palette = ['#0d8bff', '#10e08f', '#ffaa1d', '#9b5de5', '#f15bb5', '#00bbf9', '#fee440', '#8d99ae'];

    function renderSummary() {
        const select = document.getElementById('monthSelect');
        const s      = report.summary[select.value];

        document.getElementById('sumIncome').textContent    = money.format(s.income);
        document.getElementById('sumExpenses').textContent  = money.format(s.expenses);
        document.getElementById('sumSavings').textContent   = money.format(s.savings);
        document.getElementById('sumRemaining').textContent = money.format(s.remaining);
        document.getElementById('sumRemaining').classList.toggle('is-expense', s.remaining < 0);

        document.getElementById('perfMonth').textContent   = select.options[select.selectedIndex].text.trim();
        document.getElementById('perfPlanned').textContent = money.format(s.planned);
        document.getElementById('perfActual').textContent  = money.format(s.expenses);

        const bar    = document.getElementById('perfBar');
        const result = document.getElementById('perfResult');

        if (s.planned > 0) {
            const diff = Math.round((s.planned - s.expenses) * 100) / 100;
            const over = diff < 0;

            bar.hidden = false;
            bar.firstElementChild.style.width = Math.min(100, s.expenses / s.planned * 100) + '%';
            bar.classList.toggle('is-over', over);

            result.textContent = over ? 'Exceeded by ' + money.format(-diff) : 'Remaining ' + money.format(diff);
            result.classList.toggle('is-over-text', over);
        } else {
            bar.hidden = true;
            result.textContent = 'No budget set for this month.';
            result.classList.remove('is-over-text');
        }
    }

    function showEmpty(canvasId) {
        const canvas = document.getElementById(canvasId);
        const note   = document.createElement('p');
        note.className   = 'empty-note';
        note.textContent = 'No data yet.';
        canvas.parentNode.replaceChild(note, canvas);
    }

    function donut(canvasId, data) {
        const total = data.totals.reduce(function (a, b) { return a + b; }, 0);
        if (total <= 0) {
            showEmpty(canvasId);
            return;
        }

        new Chart(document.getElementById(canvasId), {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.totals,
                    backgroundColor: palette.slice(0, data.labels.length),
                    borderWidth: 0
                }]
            },
            options: {
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const pct   = Math.round(ctx.parsed / total * 1000) / 10;
                                const count = data.counts[ctx.dataIndex];
                                return ' ' + ctx.label + ': ' + money.format(ctx.parsed) + ' (' + pct + '%) · '
                                    + count + (count === 1 ? ' entry' : ' entries');
                            }
                        }
                    }
                }
            }
        });
    }

    function trendChart(canvasId, type, data, color) {
        const total = data.data.reduce(function (a, b) { return a + b; }, 0);
        if (total <= 0) {
            showEmpty(canvasId);
            return;
        }

        const isLine = type === 'line';

        new Chart(document.getElementById(canvasId), {
            type: type,
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.data,
                    borderColor: color,
                    backgroundColor: isLine ? 'rgba(13, 139, 255, 0.15)' : color,
                    fill: isLine,
                    tension: 0.3,
                    pointRadius: isLine ? 3 : 0,
                    borderRadius: isLine ? 0 : 6
                }]
            },
            options: {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) { return ' ' + money.format(ctx.parsed.y); } } }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.08)' } }
                }
            }
        });
    }

    document.getElementById('monthSelect').addEventListener('change', renderSummary);
    renderSummary();

    donut('incomeSourcesChart', report.incomeSources);
    donut('incomeUsageChart', report.incomeUsage);
    trendChart('incomeTrendChart', 'line', report.incomeTrend, '#0d8bff');

    donut('expenseSourcesChart', report.expenseSources);
    donut('spendChart', report.spendCategories);
    trendChart('expenseTrendChart', 'bar', report.expenseTrend, '#ffaa1d');
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
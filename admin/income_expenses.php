<?php
require "nav-head.php";

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not established.");
}

$selected_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

$query = "
    SELECT 
        m.month,
        COALESCE(p.total_income, 0) AS total_income,
        COALESCE(e.total_expenses, 0) AS expenses,
        (COALESCE(p.total_income, 0) - COALESCE(e.total_expenses, 0)) AS income
    FROM (
        SELECT 1 AS month UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL 
        SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL 
        SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL 
        SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12
    ) AS m
    LEFT JOIN (
        SELECT MONTH(datetime) AS month, SUM(amount) AS total_income
        FROM payments
        WHERE YEAR(datetime) = ?
        GROUP BY MONTH(datetime)
    ) AS p ON m.month = p.month
    LEFT JOIN (
        SELECT MONTH(expense_date) AS month, SUM(amount) AS total_expenses
        FROM expenses
        WHERE YEAR(expense_date) = ?
        GROUP BY MONTH(expense_date)
    ) AS e ON m.month = e.month
    ORDER BY m.month
";

$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Prepare failed: " . $conn->error);
}

$stmt->bind_param("ii", $selected_year, $selected_year);

if (!$stmt->execute()) {
    die("Execute failed: " . $stmt->error);
}

$result = $stmt->get_result();
if (!$result) {
    die("get_result() failed. Make sure mysqlnd is installed: " . $stmt->error);
}

$report_data = [];
$labels = [];
$incomes = [];
$expenses = [];

$total_income = 0;
$total_expenses = 0;

while ($row = $result->fetch_assoc()) {
    $month_num = (int)$row['month'];
    $month_name = date('F', mktime(0, 0, 0, $month_num, 10));
    $income = (float)$row['income'];
    $expense = (float)$row['expenses'];

    $report_data[] = [
        'month' => $month_name,
        'income' => $income,
        'expenses' => $expense
    ];

    $labels[] = $month_name;
    $incomes[] = $income;
    $expenses[] = $expense;

    $total_income += $income;
    $total_expenses += $expense;
}

$stmt->close();
?>



<div class="container py-5">
   <div class="d-flex justify-content-between align-items-end mb-4">
    <!-- Filter on the left -->
    <form method="GET" class="d-flex gap-3 align-items-end">
        <div>
            <label for="year" class="form-label">Year</label>
            <input type="number" name="year" id="year" class="form-control" value="<?= $selected_year ?>">
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>

    <!-- Buttons on the right -->
    <div class="d-flex gap-2">
        <button class="btn btn-info" onclick="printReport()">Print Report</button>
        <button class="btn btn-success" onclick="exportPDF()">Export to PDF</button>
    </div>
</div>

<div class="card mb-5">
    <div class="card-header">
        <h6>Monthly Income vs Expenses for <?= $selected_year ?></h6>
    </div>

        <div class="card-body px-0 pt-0 pb-2">
            <div class="table-responsive p-3">
                <table class="table align-items-center mb-0" id="report">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Income</th>
                            <th>Expenses</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['month']) ?></td>
                                <td><?= number_format($row['income'], 2) ?></td>
                                <td><?= number_format($row['expenses'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                     <tfoot>
                        <tr style="font-weight: bold;">
                            <td>Total</td>
                            <td><?= number_format($total_income, 2) ?></td>
                            <td><?= number_format($total_expenses, 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <canvas id="incomeExpenseChart" height="100"></canvas>
   

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    function toggleDateFields(reportType) {
        document.getElementById('month-field').style.display = reportType === 'monthly' ? 'block' : 'none';
        document.getElementById('year-field').style.display = reportType === 'yearly' ? 'block' : 'none';
        const isCustom = reportType === 'custom';
        document.getElementById('start-date-field').style.display = isCustom ? 'block' : 'none';
        document.getElementById('end-date-field').style.display = isCustom ? 'block' : 'none';
    }
    function exportPDF() {
        const report = document.getElementById('report');
        const reportTitle = document.querySelector('.card-header h6')?.innerText || 'Income vs Expense Report';
        const clonedTable = report.cloneNode(true);

        const wrapper = document.createElement('div');
        wrapper.innerHTML = `
            <h2 style="text-align: center;">${reportTitle}</h2>
            <p style="text-align: center;">Generated on: ${new Date().toLocaleDateString()}</p>
        `;
        wrapper.appendChild(clonedTable);

        const opt = {
            margin: 0.5,
            filename: `report_${new Date().toISOString().slice(0, 10)}.pdf`,
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 2 },
            jsPDF: { unit: 'in', format: 'letter', orientation: 'portrait' }
        };

        html2pdf().set(opt).from(wrapper).save();
    }

    // Print Report
    function printReport() {
        const reportTitle = document.querySelector('.card-header h6')?.innerText || 'Income vs Expense Report';
        const table = document.getElementById('report').cloneNode(true);
        const printWindow = window.open('', '', 'width=800,height=600');

        printWindow.document.write(`
            <html>
            <head>
                <title>${reportTitle}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h2 { text-align: center; margin-bottom: 10px; }
                    p { text-align: center; margin-top: 0; font-size: 12px; color: #555; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #000; padding: 8px; text-align: left; font-size: 12px; }
                    th { background-color: #f2f2f2; text-align: center; }
                </style>
            </head>
            <body>
                <h2>${reportTitle}</h2>
                <p>Generated on: ${new Date().toLocaleDateString()}</p>
                ${table.outerHTML}
            </body>
            </html>
        `);

        printWindow.document.close();
        printWindow.focus();

        printWindow.onload = function () {
            printWindow.print();
            printWindow.close();
        };
    }

    // Chart Initialization
    const ctx = document.getElementById('incomeExpenseChart').getContext('2d');
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [
                {
                    label: 'Income',
                    data: <?= json_encode($incomes) ?>,
                    backgroundColor: 'rgba(54, 162, 235, 0.7)'
                },
                {
                    label: 'Expenses',
                    data: <?= json_encode($expenses) ?>,
                    backgroundColor: 'rgba(255, 99, 132, 0.7)'
                }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Monthly Income vs Expenses - <?= $selected_year ?>',
                    font: {
                        size: 16
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: value => 'UGX. ' + value.toLocaleString()
                    }
                }
            }
        }
    });
</script>


<?php require "footer.php"; ?>

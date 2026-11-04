<?php
require "nav-head.php";
require "services_check.php";

if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

$firstName = $_SESSION['fname'] ?? '';
$lastName = $_SESSION['lname'] ?? '';
$username = htmlspecialchars(trim("$firstName $lastName"));

if (!isset($_GET['id'])) {
    die("Loan ID missing.");
}

$loan_id = (int)$_GET['id'];
$phead = "Repayment Schedule";

// Fetch loan details
$loan_query = "SELECT * FROM loan WHERE id = $loan_id AND status = 'OUTSTANDING' LIMIT 1";
$loan_result = mysqli_query($conn, $loan_query);

if (!$loan_result || mysqli_num_rows($loan_result) === 0) {
    die("Loan not found or not outstanding.");
}

$loan = mysqli_fetch_assoc($loan_result);
$client_id = (int)$loan['client_id'];

// Fetch client name
$cfect = [];
$sql1 = "SELECT u.fname, u.lname 
         FROM customers c 
         INNER JOIN user u ON c.user_id = u.id 
         WHERE c.user_id = $client_id LIMIT 1";
$result1 = mysqli_query($conn, $sql1);

if ($result1 && mysqli_num_rows($result1) > 0) {
    $cfect = mysqli_fetch_assoc($result1);
} else {
    $sql2 = "SELECT u.fname, u.lname 
             FROM members m 
             INNER JOIN user u ON m.user_id = u.id 
             WHERE m.user_id = $client_id LIMIT 1";
    $result2 = mysqli_query($conn, $sql2);
    if ($result2 && mysqli_num_rows($result2) > 0) {
        $cfect = mysqli_fetch_assoc($result2);
    }
}

$full_name = (!empty($cfect['fname']) && !empty($cfect['lname']))
    ? htmlspecialchars($cfect['fname'] . ' ' . $cfect['lname'])
    : 'N/A';

$amount = (float)$loan['amount'];
$interest_rate = (float)$loan['interest_rate'];
$loan_period = (int)$loan['loan_period'];

// ? Limit loan period to a maximum of 3 months
if ($loan_period > 3) {
    $loan_period = 3;
    $loan_period_capped = true;
} else {
    $loan_period_capped = false;
}

// ? Fixed (Flat) Interest Calculation
$total_interest = ($amount * $interest_rate) / 100;
$total_repayable = $amount + $total_interest;
$monthly_payment = $total_repayable / $loan_period;
$monthly_interest = $total_interest / $loan_period;
$monthly_principal = $amount / $loan_period;

$start_date = new DateTime($loan['datetime']);
$loanTitle = "Repayment Plan for $full_name";
?>

<div class="container-fluid my-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">
                        <?= $phead . ' for <strong>' . $full_name . '</strong>'; ?>
                    </h6>
                    <div>
                        <button class="btn bg-gradient-primary btn-sm me-1" onclick="printTable('report')">
                            <i class="fa fa-print me-1"></i> Print
                        </button>
                        <button class="btn bg-gradient-danger btn-sm" onclick="exportTableToPDF('report')">
                            <i class="fa fa-file-pdf me-1"></i> Export PDF
                        </button>
                    </div>
                </div>

                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-3">
                        <?php if ($loan_period_capped): ?>
                            <div class="alert alert-warning text-sm">
                                <strong>Note:</strong> The maximum loan period allowed is <strong>3 months</strong>. 
                                Schedule has been adjusted accordingly.
                            </div>
                        <?php endif; ?>

                        <table class="table align-items-center mb-0" id="report">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Payment Date</th>
                                    <th>Monthly Payment</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $balance = $amount;
                                for ($i = 1; $i <= $loan_period; $i++) {
                                    $balance -= $monthly_principal;
                                    $repayment_date = (clone $start_date)->modify("+$i month");

                                    echo "<tr>
                                            <td>$i</td>
                                            <td>" . $repayment_date->format('d M Y') . "</td>
                                            <td>" . number_format($monthly_payment, 2) . "</td>
                                            <td>" . number_format($monthly_principal, 2) . "</td>
                                            <td>" . number_format($monthly_interest, 2) . "</td>
                                            <td>" . number_format(max($balance, 0), 2) . "</td>
                                          </tr>";
                                }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="2" class="text-end">Total</td>
                                    <td><?= number_format($total_repayable, 2) ?></td>
                                    <td><?= number_format($amount, 2) ?></td>
                                    <td><?= number_format($total_interest, 2) ?></td>
                                    <td>-</td>
                                </tr>
                            </tfoot>
                        </table>

                        <p class="mt-3 text-muted">
                            <em>Generated by: <?= $username ?> on <?= date('d M Y, h:i A'); ?></em>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JS PDF Export & Print -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
<script>
function printTable(table_id) {
    const table = document.getElementById(table_id).outerHTML;
    const newWindow = window.open('', '', 'height=700,width=900');
    newWindow.document.write('<html><head><title><?= $loanTitle ?></title>');
    newWindow.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">');
    newWindow.document.write('</head><body>');
    newWindow.document.write('<h3 class="text-center mb-3"><?= $loanTitle ?></h3>');
    newWindow.document.write(table);
    newWindow.document.write('<p class="mt-3 text-muted"><em>Generated by: <?= $username ?> on <?= date("d M Y, h:i A"); ?></em></p>');
    newWindow.document.write('</body></html>');
    newWindow.document.close();
    newWindow.print();
}

function exportTableToPDF(table_id) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'pt');

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(14);
    doc.text("<?= $loanTitle ?>", 40, 40);

    doc.autoTable({
        html: '#' + table_id,
        startY: 60,
        styles: { fontSize: 10 },
        headStyles: { fillColor: [41, 128, 185] }
    });

    doc.setFont('helvetica', 'italic');
    doc.setFontSize(10);
    doc.text("Generated by: <?= $username ?> on <?= date('d M Y, h:i A'); ?>", 40, doc.lastAutoTable.finalY + 20);

    doc.save('repayment_plan.pdf');
}
</script>

<?php require "footer.php"; ?>

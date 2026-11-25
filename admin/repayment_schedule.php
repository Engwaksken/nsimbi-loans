<?php
require "nav-head.php";
require "services_check.php";
require_once dirname(__DIR__) . '/includes/loan_products.php';
loan_products_ensure_schema($conn);

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
// Loan officers may only open their own loans.
officer_require_loan($conn, $loan_id);
$client_id = (int)$loan['client_id'];

// Fetch client name (organisation loans by loan.organisation_id: organisation
// ids overlap user ids, so they must not be looked up as customers/members).
$cfect = [];
$organisationId = (int)($loan['organisation_id'] ?? 0);
$organisation = $organisationId > 0 ? organisation_find($conn, $organisationId) : null;
$sql1 = "SELECT u.fname, u.lname 
         FROM customers c 
         INNER JOIN user u ON c.user_id = u.id 
         WHERE c.user_id = $client_id LIMIT 1";
$result1 = mysqli_query($conn, $sql1);

if ($organisationId > 0) {
    // Organisation loan: no customer/member lookup.
} elseif ($result1 && mysqli_num_rows($result1) > 0) {
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
if ($organisationId > 0) {
    $full_name = htmlspecialchars((string)($organisation['company_name'] ?? '') ?: 'Organisation #' . $organisationId);
}

$amount = (float)$loan['amount'];
$interest_rate = (float)$loan['interest_rate'];
$loan_period = max(1, (int)$loan['loan_period']);
// Weekly terms (2 or 4 weeks) are paid weekly; month terms monthly.
$is_weekly = loan_term_unit((string)($loan['loan_period_unit'] ?? 'month')) === 'week';
$step_label = $is_weekly ? 'Week' : 'Month';


// ? Fixed (Flat) Interest Calculation
$total_interest = ($amount * $interest_rate) / 100;
$total_repayable = $amount + $total_interest;
$monthly_payment = $total_repayable / $loan_period;
$monthly_interest = $total_interest / $loan_period;
$monthly_principal = $amount / $loan_period;

// The term starts when the money is sent; older loans fall back to the request date.
$start_date = new DateTime(!empty($loan['disbursed_at']) ? $loan['disbursed_at'] : $loan['datetime']);

// Installment rows. Product loans follow the product's frequency and interest type.
$schedule_rows = [];
$product_terms = loan_product_terms_from_loan($loan);
if ($product_terms) {
    $schedule_rows = loan_product_schedule($product_terms, $amount, DateTimeImmutable::createFromMutable($start_date));
    $total_interest = array_sum(array_column($schedule_rows, 'interest'));
    $total_repayable = $amount + $total_interest;
    $step_label = ['daily' => 'Day', 'weekly' => 'Week', 'monthly' => 'Month', 'quarterly' => 'Quarter', 'yearly' => 'Year'][$product_terms['repayment_frequency']] ?? 'No.';
} else {
    $balance = $amount;
    for ($i = 1; $i <= $loan_period; $i++) {
        $balance -= $monthly_principal;
        $schedule_rows[] = [
            'number'    => $i,
            'due_date'  => (clone $start_date)->modify($is_weekly ? "+$i week" : "+$i month")->format('Y-m-d'),
            'payment'   => $monthly_payment,
            'principal' => $monthly_principal,
            'interest'  => $monthly_interest,
            'balance'   => max($balance, 0),
        ];
    }
}
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

                        <table class="table align-items-center mb-0" id="report">
                            <thead>
                                <tr>
                                    <th><?= $step_label ?></th>
                                    <th>Payment Date</th>
                                    <th>Payment</th>
                                    <th>Principal</th>
                                    <th>Interest</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($schedule_rows as $row) {
                                    echo "<tr>
                                            <td>" . (int)$row['number'] . "</td>
                                            <td>" . date('d M Y', strtotime($row['due_date'])) . "</td>
                                            <td>" . number_format($row['payment'], 2) . "</td>
                                            <td>" . number_format($row['principal'], 2) . "</td>
                                            <td>" . number_format($row['interest'], 2) . "</td>
                                            <td>" . number_format($row['balance'], 2) . "</td>
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

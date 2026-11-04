<?php
require "nav-head.php";
require "services_check.php";

if (!isset($_SESSION['logged_in'])) {
    exit("User not logged in.");
}

$client_id = mysqli_real_escape_string($conn, $_SESSION['logged_in']);
$phead = "Repayment Schedule";

$query = "SELECT amount, interest, interest_rate, loan_period, datetime FROM loan WHERE client_id='$client_id' AND status = 'OUTSTANDING' ORDER BY id DESC";
$loan_results = mysqli_query($conn, $query);
?>

<div class="container-fluid my-4">
    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header pb-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><?php echo $phead; ?></h6>
                    <!-- <button class="btn bg-gradient-success btn-sm" onclick="download_table_as_csv('report')">
                        <i class="fa fa-download me-1"></i> Export CSV
                    </button> -->
                </div>
                <div class="card-body px-0 pt-0 pb-2">
                    <div class="table-responsive p-3">
                        <table class="table align-items-center mb-0" id="report">
                            <thead>
                                <tr>
                                    <th>Amount</th>
                                    <th>Interest</th>
                                    <th>Loan Period (Months)</th>
                                    <th>Monthly Payment</th>
                                    <th>Repayment Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if (mysqli_num_rows($loan_results) > 0) {
                                    while ($loan = mysqli_fetch_assoc($loan_results)) {
                                        $amount = (float)$loan['amount'];
                                        $interest_rate = (float)$loan['interest_rate'];
                                        $loan_period = (int)$loan['loan_period'];

                                        $interest = ($amount * $interest_rate) / 100;
                                        $total_repayable = $amount + $interest;
                                        $monthly_payment = $total_repayable / $loan_period;

                                        $start_date = new DateTime($loan['datetime']);
for ($i = 0; $i < $loan_period; $i++) {
    $repayment_date = (clone $start_date)->modify('+' . ($i * 30) . ' days')->format('d M Y');
    echo "<tr>";

    if ($i == 0) {
        echo "<td rowspan='{$loan_period}'>" . number_format($amount) . "</td>";
        echo "<td rowspan='{$loan_period}'>" . number_format($interest, 2) . "</td>";
        echo "<td rowspan='{$loan_period}'>" . $loan_period . "</td>";
    }
    echo "<td>" . number_format($monthly_payment, 2) . "</td>";
    echo "<td>" . $repayment_date . "</td>";
    echo "</tr>";
}

                                    }
                                } else {
                                    echo '<tr><td colspan="5" class="text-center text-muted">No loan found.</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const rows = document.querySelectorAll(".loan-row");

    rows.forEach(row => {
        const amount = parseFloat(row.getAttribute("data-amount"));
        const interestRate = parseFloat(row.getAttribute("data-interest"));
        const period = parseInt(row.getAttribute("data-period"));

        const interestAmount = (amount * interestRate) / 100;
        const totalRepayable = amount + interestAmount;
        const monthlyPayment = totalRepayable / period;

        // Display monthly payment
        row.querySelector(".monthly-payment").innerText =
            "" + monthlyPayment.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });

        const today = new Date();
const repaymentDates = [];

for (let i = 0; i < period; i++) {
    const payDate = new Date(today.getTime() + ((i + 1) * 30 * 24 * 60 * 60 * 1000)); // +30 days
    repaymentDates.push(
        payDate.toLocaleDateString("en-GB", {
            day: "2-digit",
            month: "short",
            year: "numeric"
        })
    );
}


        row.querySelector(".repayment-dates").innerHTML = repaymentDates.join("<br>");
    });
});
</script>

<script>
function download_table_as_csv(table_id) {
    let table = document.getElementById(table_id);
    let rows = Array.from(table.querySelectorAll("tr"));
    let csv = [];

    rows.forEach(row => {
        let cols = row.querySelectorAll("td, th");
        let rowData = Array.from(cols).map(col => `"${col.innerText.trim()}"`);
        csv.push(rowData.join(","));
    });

    let csvFile = new Blob([csv.join("\n")], { type: "text/csv" });
    let downloadLink = document.createElement("a");

    downloadLink.download = "repayment_schedule.csv";
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";

    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>

<?php require "footer.php"; ?>

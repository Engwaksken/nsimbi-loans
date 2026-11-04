<?php
declare(strict_types=1);

require "nav-head.php";
?>

<link rel="stylesheet" href="assets/css/loanpro.css">

<style>
.calculator-header,
.calculator-card {
    background: #fff;
    border-radius: 18px;
    border: 1px solid #edf2f7;
    box-shadow: 0 8px 25px rgba(15,23,42,.06);
    overflow: hidden;
}

.calculator-header {
    padding: 22px;
}

.calc-stat {
    background: #fff;
    border: 1px solid #edf2f7;
    border-left: 6px solid #344767;
    border-radius: 18px;
    padding: 18px;
    min-height: 105px;
}

.calc-stat.primary { border-left-color: #0d6efd; }
.calc-stat.warning { border-left-color: #f59e0b; }
.calc-stat.success { border-left-color: #198754; }

.calc-stat-title {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: .05em;
    margin-bottom: 8px;
}

.calc-stat-value {
    font-size: 19px;
    font-weight: 800;
    color: #1e293b;
}

.calculator-card table thead th {
    background: #f8fafc;
    color: #475569;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .04em;
    padding: 14px;
    white-space: nowrap;
}

.calculator-card table tbody td {
    padding: 14px;
    vertical-align: middle;
    white-space: nowrap;
}

.form-control,
.form-select {
    border-radius: 12px;
    min-height: 45px;
}
</style>

<div class="container-fluid py-4">

    <div class="calculator-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h3 class="mb-1">
                    <i class="fas fa-calculator me-2 text-primary"></i>
                    Loan Calculator
                </h3>
                <p class="text-muted mb-0">
                    Estimate monthly repayments, interest, and remaining loan balance.
                </p>
            </div>

            <a href="requesting_loan.php" class="btn bg-gradient-primary">
                <i class="fas fa-paper-plane me-1"></i>
                Apply for Loan
            </a>
        </div>
    </div>

    <div id="alertMessage" class="alert alert-danger d-none"></div>

    <div class="row g-4">

        <div class="col-xl-4 col-lg-5">
            <div class="calculator-card p-4">

                <h5 class="mb-3">Calculator Inputs</h5>

                <div class="mb-3">
                    <label class="form-label">Loan Amount (UGX)</label>
                    <input
                        type="number"
                        class="form-control"
                        id="amount"
                        min="1"
                        step="0.01"
                        placeholder="Enter loan amount"
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">Loan Period</label>
                    <select class="form-select" id="period">
                        <option value="" selected disabled>Select loan period</option>
                        <option value="1">1 Month</option>
                        <option value="2">2 Months</option>
                        <option value="3">3 Months</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Interest Rate</label>
                    <input
                        type="text"
                        class="form-control"
                        value="10%"
                        readonly
                    >
                    <small class="text-muted">
                        Fixed flat interest rate used for calculation.
                    </small>
                </div>

                <button type="button" onclick="calculateLoan()" class="btn bg-gradient-primary w-100">
                    <i class="fas fa-calculator me-1"></i>
                    Calculate Loan
                </button>

                <button type="button" onclick="resetCalculator()" class="btn btn-light w-100 mt-2">
                    Reset
                </button>

            </div>
        </div>

        <div class="col-xl-8 col-lg-7">

            <div id="summaryCards" class="row g-3 mb-4 d-none">

                <div class="col-md-4">
                    <div class="calc-stat primary">
                        <div class="calc-stat-title">Monthly Payment</div>
                        <div class="calc-stat-value" id="monthlyPaymentCard">UGX 0.00</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="calc-stat warning">
                        <div class="calc-stat-title">Total Interest</div>
                        <div class="calc-stat-value" id="totalInterestCard">UGX 0.00</div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="calc-stat success">
                        <div class="calc-stat-title">Total Repayment</div>
                        <div class="calc-stat-value" id="totalRepaymentCard">UGX 0.00</div>
                    </div>
                </div>

            </div>

            <div id="resultCard" class="calculator-card d-none">

                <div class="p-4 border-bottom">
                    <h5 class="mb-1">Monthly Breakdown</h5>
                    <small class="text-muted">
                        Installment schedule based on selected amount and period.
                    </small>
                </div>

                <div class="table-responsive">
                    <table class="table mb-0" id="loanCalcTable">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Principal</th>
                                <th>Interest</th>
                                <th>Monthly Payment</th>
                                <th>Remaining Balance</th>
                            </tr>
                        </thead>

                        <tbody id="loanCalcBody"></tbody>
                    </table>
                </div>

            </div>

            <div id="emptyState" class="calculator-card p-5 text-center text-muted">
                <i class="fas fa-calculator fa-3x mb-3"></i>
                <h5>Calculate your loan repayment</h5>
                <p class="mb-0">
                    Enter the loan amount and period to view monthly repayment details.
                </p>
            </div>

        </div>

    </div>

</div>

<script>
function money(value) {
    const number = Number(value || 0);

    return "UGX " + number.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function showAlert(message, type = "danger") {
    const alertBox = document.getElementById("alertMessage");

    alertBox.className = "alert alert-" + type;
    alertBox.innerText = message;
    alertBox.classList.remove("d-none");

    setTimeout(function () {
        alertBox.classList.add("d-none");
    }, 4000);
}

function calculateLoan() {
    const amount = parseFloat(document.getElementById("amount").value || "0");
    const period = parseInt(document.getElementById("period").value || "0", 10);

    const interestRate = 10 / 100;

    if (amount <= 0) {
        showAlert("Please enter a valid loan amount.");
        return;
    }

    if (period <= 0) {
        showAlert("Please select a valid loan period.");
        return;
    }

    const principalPerMonth = amount / period;
    const interestPerMonth = amount * interestRate;
    const monthlyPayment = principalPerMonth + interestPerMonth;
    const totalInterest = interestPerMonth * period;
    const totalRepayment = amount + totalInterest;

    let remainingBalance = amount;
    let rows = "";

    for (let month = 1; month <= period; month++) {
        remainingBalance -= principalPerMonth;

        rows += `
            <tr>
                <td>${month}</td>
                <td>${money(principalPerMonth)}</td>
                <td>${money(interestPerMonth)}</td>
                <td class="fw-bold text-primary">${money(monthlyPayment)}</td>
                <td>${money(Math.max(remainingBalance, 0))}</td>
            </tr>
        `;
    }

    document.getElementById("loanCalcBody").innerHTML = rows;

    document.getElementById("monthlyPaymentCard").innerText = money(monthlyPayment);
    document.getElementById("totalInterestCard").innerText = money(totalInterest);
    document.getElementById("totalRepaymentCard").innerText = money(totalRepayment);

    document.getElementById("summaryCards").classList.remove("d-none");
    document.getElementById("resultCard").classList.remove("d-none");
    document.getElementById("emptyState").classList.add("d-none");
}

function resetCalculator() {
    document.getElementById("amount").value = "";
    document.getElementById("period").value = "";

    document.getElementById("loanCalcBody").innerHTML = "";
    document.getElementById("summaryCards").classList.add("d-none");
    document.getElementById("resultCard").classList.add("d-none");
    document.getElementById("emptyState").classList.remove("d-none");
    document.getElementById("alertMessage").classList.add("d-none");
}
</script>

<?php require "footer.php"; ?>
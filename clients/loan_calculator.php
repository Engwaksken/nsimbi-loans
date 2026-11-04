<?php 
require "nav-head.php";
?>
<div class="row justify-content-center mt-4">
    <div class="col-lg-6 col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-gradient-primary text-white">
                <h6 class="mb-0">Loan Calculator</h6>
            </div>
            
            <div class="card-body px-4 py-4">

                <div class="row justify-content-center">
                    <div class="col-lg-12 col-md-6">
                        <div id="alertMessage" class="alert alert-danger d-none" role="alert"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="amount" class="form-label">Loan Amount (UGX)</label>
                    <input type="number" class="form-control" id="amount" placeholder="e.g. 10000" required>
                </div>

                <div class="mb-3">
                    <label for="period" class="form-label">Select Loan Period</label>
                    <select class="form-select" id="period" required>
                        <option value="" selected disabled>Select Loan Period</option>
                        <option value="1">1 Month</option>
                        <option value="2">2 Months</option>
                        <option value="3">3 Months</option>
                    </select>
                </div>

                <div class="d-grid mt-4">
                    <button onclick="calculateLoan()" class="btn btn-primary">Calculate</button>
                </div>

            </div>
        </div>
    </div>
    <div id="result" class="mt-4" style="display:none;"></div>
</div>
<?php require "footer.php"; ?>
<script>
function showAlert(message, type = 'danger') {
    const alertBox = document.getElementById("alertMessage");
    alertBox.className = `alert alert-${type}`;
    alertBox.innerText = message;
    alertBox.classList.remove("d-none");

    setTimeout(() => {
        alertBox.classList.add("d-none");
    }, 5000);
}

function calculateLoan() {
    const amount = parseFloat(document.getElementById('amount').value);
    const period = parseInt(document.getElementById('period').value);
    
    let annualInterestRate = 0;
    
    if (period === 1) {
        annualInterestRate = 10;
    } else if (period >= 2) {
        annualInterestRate = 6.6;
    }

    const monthlyInterestRate = annualInterestRate / 100;

    document.getElementById("alertMessage").classList.add("d-none");

    if (isNaN(amount) || isNaN(period) || amount <= 0 || period <= 0) {
        showAlert("Please fill all fields with valid numbers.");
        return;
    }

    const months = period;
    const fixedPrincipal = amount / months;
    const fixedInterest = amount * monthlyInterestRate;
    const totalInterest = fixedInterest * months;
    const totalRepayment = amount + totalInterest;
    let remainingBalance = amount;

    let output = `
        <h5 class="mb-3">Monthly Breakdown</h5>
        <div class="table-responsive">
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Month</th>
                    <th>Principal (UGX)</th>
                    <th>Interest (UGX)</th>
                    <th>Monthly Payment (UGX)</th>
                    <th>Remaining Balance (UGX)</th>
                </tr>
            </thead>
            <tbody>`;

    for (let i = 1; i <= months; i++) {
        const totalPayment = fixedPrincipal + fixedInterest;
        remainingBalance -= fixedPrincipal;

        output += `
            <tr>
                <td>${i}</td>
                <td>${fixedPrincipal.toFixed(2)}</td>
                <td>${fixedInterest.toFixed(2)}</td>
                <td class="text-success fw-bold">${totalPayment.toFixed(2)}</td>
                <td>${remainingBalance > 0 ? remainingBalance.toFixed(2) : '0.00'}</td>
            </tr>`;
    }

    output += `
            </tbody>
        </table>
        </div>
        <p><strong>Total Interest Amount:</strong> UGX ${totalInterest.toFixed(2)}</p>
        <p><strong>Total Repayment Amount:</strong> UGX ${totalRepayment.toFixed(2)}</p>`;

    const resultDiv = document.getElementById("result");
    resultDiv.innerHTML = output;
    resultDiv.style.display = "block";
}

    </script>
    


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <link rel="icon" type="image/jpg" href="assets/img/favicon.png">
  <title>Nsimbi Impact | Signup</title>
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />
  <link href="assets/css/nucleo-icons.css" rel="stylesheet" />
  <link href="assets/css/nucleo-svg.css" rel="stylesheet" />
  <link href="assets/css/loanpro.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    /* -- Agreement Modal -- */
    .agreement-modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      backdrop-filter: blur(4px);
      z-index: 9000;
      align-items: center;
      justify-content: center;
      padding: 1rem;
    }
    .agreement-modal-overlay.show {
      display: flex;
    }
    .agreement-modal {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 24px 60px rgba(0,0,0,0.25);
      width: 100%;
      max-width: 720px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      animation: modalSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes modalSlideIn {
      from { opacity: 0; transform: translateY(30px) scale(0.97); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .agreement-modal-header {
      background: linear-gradient(135deg, #1a6b3a 0%, #2ea05a 100%);
      color: #fff;
      padding: 1.25rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-shrink: 0;
    }
    .agreement-modal-header h5 {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: 0.3px;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .agreement-modal-header button {
      background: rgba(255,255,255,0.2);
      border: none;
      color: #fff;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1rem;
      transition: background 0.2s;
    }
    .agreement-modal-header button:hover {
      background: rgba(255,255,255,0.35);
    }
    .agreement-modal-progress {
      background: #f0faf4;
      border-bottom: 1px solid #d6ede1;
      padding: 0.55rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.78rem;
      color: #555;
      flex-shrink: 0;
    }
    .progress-bar-wrap {
      flex: 1;
      height: 5px;
      background: #d6ede1;
      border-radius: 99px;
      margin: 0 1rem;
      overflow: hidden;
    }
    .progress-bar-fill {
      height: 100%;
      background: linear-gradient(90deg, #1a6b3a, #2ea05a);
      border-radius: 99px;
      width: 0%;
      transition: width 0.2s;
    }
    .agreement-modal-body {
      overflow-y: auto;
      padding: 1.5rem;
      flex: 1;
      font-size: 0.88rem;
      line-height: 1.75;
      color: #333;
      scroll-behavior: smooth;
    }
    .agreement-modal-body::-webkit-scrollbar { width: 6px; }
    .agreement-modal-body::-webkit-scrollbar-track { background: #f0faf4; }
    .agreement-modal-body::-webkit-scrollbar-thumb { background: #2ea05a; border-radius: 99px; }

    .agreement-section { margin-bottom: 1.4rem; }
    .agreement-section h6 {
      font-weight: 700;
      font-size: 0.9rem;
      color: #1a6b3a;
      margin-bottom: 0.35rem;
      display: flex;
      align-items: center;
      gap: 0.4rem;
    }
    .agreement-section h6::before {
      content: '';
      display: inline-block;
      width: 3px;
      height: 14px;
      background: #2ea05a;
      border-radius: 2px;
    }
    .agreement-parties-box {
      background: #f8fffe;
      border: 1px solid #d0eddc;
      border-radius: 8px;
      padding: 1rem 1.1rem;
      margin-bottom: 1.4rem;
      font-size: 0.85rem;
    }
    .agreement-parties-box p { margin: 0.25rem 0; }
    .agreement-highlight {
      background: #fff8e1;
      border-left: 3px solid #f5a623;
      padding: 0.6rem 0.9rem;
      border-radius: 0 6px 6px 0;
      margin: 0.6rem 0;
      font-size: 0.84rem;
    }
    .installment-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.82rem;
      margin: 0.5rem 0;
    }
    .installment-table th {
      background: #f0faf4;
      padding: 0.4rem 0.7rem;
      text-align: left;
      border: 1px solid #d6ede1;
      font-weight: 600;
      color: #1a6b3a;
    }
    .installment-table td {
      padding: 0.4rem 0.7rem;
      border: 1px solid #eee;
      color: #555;
    }
    .enforcement-list {
      list-style: none;
      padding: 0;
      margin: 0.3rem 0;
    }
    .enforcement-list li {
      padding: 0.2rem 0 0.2rem 1.4rem;
      position: relative;
      color: #444;
    }
    .enforcement-list li::before {
      content: '';
      position: absolute;
      left: 0.4rem;
      color: #2ea05a;
      font-weight: 700;
    }

    /* Read indicator */
    .read-indicator {
      background: #fff3cd;
      border: 1px solid #ffc107;
      border-radius: 8px;
      padding: 0.55rem 0.9rem;
      font-size: 0.8rem;
      color: #856404;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin: 0 1.5rem 0.75rem;
      flex-shrink: 0;
      transition: all 0.4s;
    }
    .read-indicator.done {
      background: #d4edda;
      border-color: #28a745;
      color: #155724;
    }

    .agreement-modal-footer {
      border-top: 1px solid #e8f5ec;
      padding: 1rem 1.5rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-shrink: 0;
      background: #fafffe;
    }
    .agreement-modal-footer .btn-close-modal {
      background: #6c757d;
      color: #fff;
      border: none;
      padding: 0.55rem 1.2rem;
      border-radius: 8px;
      font-size: 0.85rem;
      cursor: pointer;
      transition: background 0.2s;
    }
    .agreement-modal-footer .btn-close-modal:hover { background: #5a6268; }
    .agreement-modal-footer .btn-accept-modal {
      background: linear-gradient(135deg, #1a6b3a, #2ea05a);
      color: #fff;
      border: none;
      padding: 0.55rem 1.4rem;
      border-radius: 8px;
      font-size: 0.85rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 0.4rem;
      transition: opacity 0.2s, transform 0.15s;
      font-weight: 600;
    }
    .agreement-modal-footer .btn-accept-modal:disabled {
      opacity: 0.45;
      cursor: not-allowed;
      transform: none;
    }
    .agreement-modal-footer .btn-accept-modal:not(:disabled):hover {
      opacity: 0.9;
      transform: translateY(-1px);
    }

    /* -- Consent Checkbox Block -- */
    .consent-block {
      border: 1.5px solid #d6ede1;
      border-radius: 10px;
      padding: 1rem 1.1rem;
      margin: 1rem 0 0.5rem;
      background: #f8fffe;
      transition: border-color 0.3s, background 0.3s, box-shadow 0.3s;
    }
    .consent-block.consented {
      border-color: #2ea05a;
      background: #edfaf3;
      box-shadow: 0 0 0 3px rgba(46,160,90,0.1);
    }
    .consent-block.error-consent {
      border-color: #e74c3c;
      background: #fff5f5;
      box-shadow: 0 0 0 3px rgba(231,76,60,0.1);
    }
    .consent-row {
      display: flex;
      align-items: flex-start;
      gap: 0.75rem;
      cursor: pointer;
    }
    .consent-row input[type="checkbox"] {
      margin-top: 3px;
      width: 18px;
      height: 18px;
      accent-color: #1a6b3a;
      flex-shrink: 0;
      cursor: pointer;
    }
    .consent-label {
      font-size: 0.875rem;
      color: #333;
      line-height: 1.55;
      flex: 1;
    }
    .consent-label .view-link {
      color: #1a6b3a;
      font-weight: 600;
      text-decoration: underline;
      cursor: pointer;
      white-space: nowrap;
    }
    .consent-label .view-link:hover { color: #148034; }
    .consent-status-msg {
      font-size: 0.75rem;
      margin-top: 0.5rem;
      padding-left: 1.6rem;
      display: flex;
      align-items: center;
      gap: 0.3rem;
      min-height: 1.1rem;
      color: #888;
      transition: color 0.3s;
    }
    .consent-status-msg.ok  { color: #1a6b3a; }
    .consent-status-msg.err { color: #e74c3c; }
  </style>
</head>
<body>

  <!-- --------------------------------------
       LOAN AGREEMENT MODAL
  -------------------------------------- -->
  <div class="agreement-modal-overlay" id="agreementModal">
    <div class="agreement-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">

      <div class="agreement-modal-header">
        <h5 id="modalTitle" style="color:#ffffff;">
          <i class="fas fa-file-contract"></i>
          Staff Salary Loan Agreement - Nsimbi Impact
        </h5>
        <button onclick="closeAgreementModal()" aria-label="Close"><i class="fas fa-times"></i></button>
      </div>

      <div class="agreement-modal-progress">
        <span id="readLabel">Scroll to read the full agreement</span>
        <div class="progress-bar-wrap"><div class="progress-bar-fill" id="readProgress"></div></div>
        <span id="readPct">0%</span>
      </div>

      <div class="agreement-modal-body" id="agreementBody" onscroll="updateReadProgress()">

        <!-- Header -->
        <div style="text-align:center; margin-bottom:1.4rem; padding-bottom:1rem; border-bottom:2px solid #e8f5ec;">
          <p style="font-size:0.8rem; letter-spacing:1px; color:#888; text-transform:uppercase; margin:0 0 0.2rem;">The Republic of Uganda</p>
          <p style="font-size:0.78rem; color:#888; margin:0 0 0.5rem;">In the Matter of the Contracts Act No. 7 of 2010</p>
          <h4 style="font-size:1.1rem; font-weight:800; color:#1a6b3a; margin:0;">STAFF SALARY LOAN AGREEMENT</h4>
        </div>

        <!-- Parties -->
        <div class="agreement-section">
          <h6>Parties to This Agreement</h6>
          <div class="agreement-parties-box">
            <p><strong>Lender:</strong> Nsimbi Impact, a registered financial institution licensed to lend, with its principal offices located at Plot 12 Corporation Rise, Bukoto, Kampala, Uganda (hereinafter referred to as <em>"Nsimbi"</em>).</p>
            <p style="margin-top:0.5rem;"><strong>Borrower:</strong> The staff member whose details are provided in the loan application form submitted alongside this Agreement.</p>
          </div>
        </div>

        <!-- 1. Loan Amount -->
        <div class="agreement-section">
          <h6>1. Loan Amount and Disbursement</h6>
          <p>The Lender agrees to loan the Borrower the principal sum as specified in the approved loan application. Funds shall be disbursed directly to the Borrower's bank or mobile money account upon execution of this Agreement.</p>
        </div>

        <!-- 2. Purpose -->
        <div class="agreement-section">
          <h6>2. Purpose of the Loan</h6>
          <p>The Borrower agrees to use the Loan for <strong>lawful personal or household expenses only</strong>. Misuse of the funds shall constitute a default under this Agreement.</p>
        </div>

        <!-- 3. Interest -->
        <div class="agreement-section">
          <h6>3. Interest Rate</h6>
          <div class="agreement-highlight">
          
            The Loan shall carry a <strong>fixed interest rate of 10%</strong> for the entire loan term. This rate will not change regardless of external conditions.
          </div>
        </div>

        <!-- 4. Repayment -->
        <div class="agreement-section">
          <h6>4. Repayment Terms</h6>
          <p>The Borrower shall repay the Loan in weekly or monthly installments over the agreed period, as detailed in the loan schedule below. Repayment begins on the date agreed at disbursement.</p>
          <table class="installment-table">
            <thead>
              <tr>
                <th>Installment</th>
                <th>Amount (UGX)</th>
                <th>Expected Date</th>
              </tr>
            </thead>
            <tbody>
              <tr><td>1st Installment</td><td>As per schedule</td><td>Per agreement</td></tr>
              <tr><td>2nd Installment</td><td>As per schedule</td><td>Per agreement</td></tr>
              <tr><td>3rd Installment</td><td>As per schedule</td><td>Per agreement</td></tr>
              <tr><td>4th Installment</td><td>As per schedule</td><td>Per agreement</td></tr>
              <tr><td>5th Installment</td><td>As per schedule</td><td>Per agreement</td></tr>
              <tr><td>6th Installment</td><td>As per schedule</td><td>Per agreement</td></tr>
            </tbody>
          </table>
          <div class="agreement-highlight" style="margin-top:0.6rem;">
            <i class="fas fa-calendar-day" style="color:#f5a623;"></i>
            <strong>Payments must be received no later than the 25th day of each month.</strong>
          </div>
        </div>

        <!-- 5. Payroll Deduction -->
        <div class="agreement-section">
          <h6>5. Payroll Deduction Authorization</h6>
          <p>By accepting this Agreement, the Borrower authorizes their Employer (Hive Colab) to deduct the monthly loan repayment from their salary and remit it directly to Nsimbi. This authorization remains in effect until the loan is fully repaid or otherwise settled.</p>
        </div>

        <!-- 6. Optional Payment -->
        <div class="agreement-section">
          <h6>6. Optional Payment Methods</h6>
          <p>Borrowers may also repay directly via bank transfer or mobile money. However, if repayment is not received by the 25th of any month, Nsimbi may instruct the Employer to enforce payroll deduction <strong>without further notice</strong>.</p>
        </div>

        <!-- 7. Early Repayment -->
        <div class="agreement-section">
          <h6>7. Early Repayment</h6>
          <p>The Borrower may repay the Loan early <strong>without penalty</strong>. Early payments shall be applied first to interest, then to the outstanding principal balance.</p>
        </div>

        <!-- 8. Default -->
        <div class="agreement-section">
          <h6>8. Default</h6>
          <div class="agreement-highlight">
            <i class="fas fa-exclamation-triangle" style="color:#f5a623;"></i>
            Failure to pay any installment within <strong>10 days of the due date</strong> shall constitute a default. Upon default, the <strong>full remaining balance becomes immediately due</strong>, and the Lender may initiate recovery proceedings.
          </div>
        </div>

        <!-- 9. Enforcement -->
        <div class="agreement-section">
          <h6>9. Collection and Enforcement</h6>
          <p>In the event of default, the Borrower authorizes the Lender to pursue all lawful recovery options, including but not limited to:</p>
          <ul>
            <li>Engagement of debt collection agencies</li>
            <li>Institution of legal proceedings</li>
            <li>Attachment of salary</li>
            <li>Garnishment of bank accounts</li>
            <li>Seizure of assets as permitted by law</li>
          </ul>
        </div>

        <!-- 10. Confidentiality -->
        <div class="agreement-section">
          <h6>10. Confidentiality</h6>
          <p>The Lender agrees to maintain the confidentiality of the Borrower's personal and financial data, except as required for loan administration or as compelled by law.</p>
        </div>

        <!-- 11. Governing Law -->
        <div class="agreement-section">
          <h6>11. Governing Law</h6>
          <p>This Agreement shall be governed by and construed in accordance with the laws of the <strong>Republic of Uganda</strong>.</p>
        </div>

        <!-- 12. Termination -->
        <div class="agreement-section">
          <h6>12. Termination of Employment</h6>
          <p>In the event of the Borrower's termination or voluntary resignation, Nsimbi shall have the right to recover the <strong>full outstanding loan balance</strong> from the Borrower's final salary and/or terminal benefits. Any remaining balance after deduction shall be payable immediately upon separation, and Nsimbi reserves the right to enforce recovery as stipulated under Clause 9 of this Agreement.</p>
        </div>

        <!-- 13. Entire Agreement -->
        <div class="agreement-section">
          <h6>13. Entire Agreement</h6>
          <p>This Agreement constitutes the full understanding between the parties and supersedes any prior discussions or agreements regarding this loan. No amendment shall be valid unless made in writing and signed by both parties.</p>
        </div>

        <!-- Signature block (informational) -->
        <div style="border-top:2px solid #e8f5ec; margin-top:1.5rem; padding-top:1rem; font-size:0.82rem; color:#666; text-align:center;">
          <p><strong>IN WITNESS WHEREOF</strong>, the parties have executed this Loan Agreement on the day and year as recorded in the application.</p>
          <p style="margin-top:0.4rem;">Authorized Signatory: <strong>Nsimbi Impact</strong> &nbsp;|&nbsp; Borrower Signature collected digitally via application form.</p>
        </div>

      </div><!-- /body -->

      <!-- Read nudge -->
      <div class="read-indicator" id="readIndicator">
        <i class="fas fa-arrow-down"></i>
        <span id="readIndicatorText">Please scroll through the entire agreement before accepting.</span>
      </div>

      <div class="agreement-modal-footer">
        <button class="btn-close-modal" onclick="closeAgreementModal()">
          <i class="fas fa-times"></i> Close
        </button>
        <button class="btn-accept-modal" id="acceptBtn" disabled onclick="acceptAgreement()">
          <i class="fas fa-check-circle"></i> I Have Read &amp; Accept
        </button>
      </div>

    </div>
  </div>


  <!-- --------------------------------------
       ORIGINAL MESSAGE OVERLAY
  -------------------------------------- -->
  <div class="message-overlay" id="messageOverlay">
    <div class="message-box">
      <div class="message-icon" id="messageIcon"><i class="fas fa-check-circle"></i></div>
      <div class="message-title" id="messageTitle">Success</div>
      <div class="message-text" id="messageText">Operation completed successfully.</div>
      <button class="message-button" id="messageButton" onclick="hideMessage()">OK</button>
    </div>
  </div>


  <main class="main-content mt-0">
    <section>
      <div class="page-header min-vh-100">
        <div class="container">
          <div class="row">
            <div class="col-xl-8 col-lg-10 col-md-10 mx-auto">
              <div class="card mt-5">
                <div class="card-header text-white text-center">
                  <h3 class="font-weight-bold mb-0">SALARY LOAN SIGNUP</h3>
                </div>

                <div class="card-body">
                  <p>Please fill out this form to apply for a staff salary loan with Nsimbi Impact. All questions are required; an incomplete form will not be reviewed and will be automatically rejected.</p>

                  <form method="post" action="" enctype="multipart/form-data" id="signupForm">

                    <!-- -- STEP 1 -- -->
                    <div class="form-step active" id="step-1">
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="fname" placeholder="Your First Name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="lname" placeholder="Your Last Name" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="email" class="form-control" name="email" placeholder="Personal Email" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="phone" placeholder="Phone (e.g. 25677...)" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label>Select Your Gender</label>
                          <select name="gender" class="form-control" required>
                            <option value="">Select Your Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                          </select>
                        </div>
                        <div class="col-md-6 mb-3">
                          <label>Select Your Date of Birth</label>
                          <input type="date" class="form-control" name="dob" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="nin" placeholder="National ID (NIN)" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="home_address" placeholder="Home Address (Not employer address)" required>
                        </div>
                      </div>
                      <div class="btn-nav text-end">
                        <button type="button" class="btn bg-gradient-info" onclick="nextStep()">
                          <i class="fa fa-arrow-right"></i> Continue
                        </button>
                      </div>
                      <div class="card-footer text-center pt-0 px-lg-2 px-1">
                        <p class="mb-4 text-sm mx-auto">
                          Have an account?
                          <a href="../index.php" class="text-info text-gradient font-weight-bold">Login</a>
                        </p>
                      </div>
                    </div>

                    <!-- -- STEP 2 -- -->
                    <div class="form-step" id="step-2">
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="email" class="form-control" name="work_email" placeholder="Work Email" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="employer" placeholder="Employer" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="job_title" placeholder="Job Title" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="employee_no" placeholder="Employee No.">
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label>Select Your Employment Status</label>
                          <select name="employment_status" class="form-control" required>
                            <option value="">Select Employment Status</option>
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Consultant">Consultant</option>
                          </select>
                        </div>
                        <div class="col-md-6 mb-3">
                          <label>Employment Start Date</label>
                          <input type="date" class="form-control" name="date_employment" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="number" step="0.01" class="form-control" name="gross_salary" placeholder="Gross Salary (UGX)" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="number" step="0.01" class="form-control" name="net_salary" placeholder="Net Salary (UGX)" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="bank_name" placeholder="Bank Name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <input type="text" class="form-control" name="bank_account" placeholder="Bank Account No." required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label>Upload National ID Front (Image/PDF)</label>
                          <input type="file" class="form-control" name="id_front" accept="image/*,.pdf" required>
                        </div>
                        <div class="col-md-6 mb-3">
                          <label>Upload National ID Back (Image/PDF)</label>
                          <input type="file" class="form-control" name="id_back" accept="image/*,.pdf" required>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 mb-3">
                          <label for="department">Select Your Department</label>
                          <select name="department" id="department" class="form-control" required>
                            <option value="">Select Your Department</option>
                            <option value="Administration">Administration</option>
                            <option value="Accounts">Accounts</option>
                            <option value="Programs">Programs</option>
                            <option value="IT">IT</option>
                            <option value="M&E">M&amp;E</option>
                          </select>
                        </div>
                        <div class="col-md-6 mb-3">
                          <label for="signature-pad">Draw your Signature (Mouse or Touch)</label>
                          <div class="signature-wrapper">
                            <canvas id="signature-pad"></canvas>
                            <input type="hidden" name="applicant_signature" id="signature-data" required>
                          </div>
                          <div class="signature-status empty" id="signature-status">No signature drawn</div>
                          <button type="button" class="btn btn-sm btn-danger mt-2" onclick="clearSignature()">
                            Clear Signature
                          </button>
                        </div>
                      </div>
                      <div class="row">
                        <div class="col-md-6 position-relative mb-3">
                          <input type="password" class="form-control" id="password" name="password" placeholder="Create Password" required>
                          <i class="fa fa-eye-slash toggle-password" onclick="togglePassword('password', this)"></i>
                        </div>
                        <div class="col-md-6 position-relative mb-3">
                          <input type="password" class="form-control" id="password1" name="password1" placeholder="Confirm Password" required>
                          <i class="fa fa-eye-slash toggle-password" onclick="togglePassword('password1', this)"></i>
                        </div>
                      </div>

                      <!-- ------------------------------------
                           CONSENT CHECKBOX BLOCK  ? NEW
                      ------------------------------------ -->
                      <div class="consent-block" id="consentBlock">
                        <label class="consent-row" for="consentCheckbox">
                          <input type="checkbox" id="consentCheckbox" name="loan_agreement_consent" value="1" onchange="onConsentChange()">
                          <span class="consent-label">
                            I have read, understood, and agree to the
                            <span class="view-link" onclick="openAgreementModal(event)">
                              <i class="fas fa-file-contract" style="font-size:0.8em;"></i>
                              Nsimbi Impact Staff Salary Loan Agreement
                            </span>
                            , including the interest rate of <strong>10%</strong>, repayment obligations, payroll deduction authorization, default consequences, and termination provisions.
                          </span>
                        </label>
                        <div class="consent-status-msg" id="consentStatusMsg">
                          <i class="fas fa-info-circle"></i>
                          <span>Click the agreement link above to read the full terms before checking this box.</span>
                        </div>
                      </div>
                      <!-- -- END CONSENT BLOCK -- -->

                      <div id="messageBox" style="display:none; padding:10px; margin:10px 0;"></div>

                      <div class="btn-nav d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-secondary" onclick="prevStep()">
                          <i class="fa fa-arrow-left"></i> Back
                        </button>
                        <button type="submit" class="btn bg-gradient-success" id="submitBtn">
                          <i class="fa fa-user-plus"></i> Create Account
                        </button>
                      </div>
                    </div>

                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <br><br>
    </section>
  </main>


  <script>
  // -- State ------------------------------
  let currentStep = 1;
  let canvas, ctx;
  let isDrawing = false;
  let hasSignature = false;
  let lastX = 0, lastY = 0;

  // Consent / agreement state
  let agreementFullyRead = false;   // true once user scrolls to bottom
  let agreementAccepted  = false;   // true once user clicks "I Accept" in modal

  // -- Message overlay --------------------
  function showMessage(title, text, type = 'warning', callback = null) {
    const overlay = document.getElementById('messageOverlay');
    const icon    = document.getElementById('messageIcon');
    document.getElementById('messageTitle').textContent = title;
    document.getElementById('messageText').textContent  = text;
    icon.className = 'message-icon ' + type;
    document.getElementById('messageButton').className  = 'message-button ' + type;
    const icons = { success:'fa-check-circle', error:'fa-exclamation-circle', warning:'fa-exclamation-triangle' };
    icon.innerHTML = `<i class="fas ${icons[type] || 'fa-info-circle'}"></i>`;
    window.messageCallback = callback;
    overlay.classList.add('show');
  }
  function hideMessage() {
    document.getElementById('messageOverlay').classList.remove('show');
    if (typeof window.messageCallback === 'function') {
      window.messageCallback();
      window.messageCallback = null;
    }
  }

  // -- Step navigation --------------------
  function showStep(step) {
    document.querySelectorAll('.form-step').forEach(el => el.classList.remove('active'));
    const t = document.getElementById(`step-${step}`);
    if (t) t.classList.add('active');
  }
  function validateStep(stepNumber) {
    const currentEl = document.getElementById(`step-${stepNumber}`);
    const required  = currentEl.querySelectorAll('[required]');
    let isValid = true, firstInvalid = null;

    required.forEach(field => {
      field.classList.remove('input-error');
      if (field.id === 'signature-data') {
        if (!hasSignature) { isValid = false; firstInvalid = firstInvalid || field; }
      } else if (!field.value.trim()) {
        field.classList.add('input-error');
        isValid = false;
        firstInvalid = firstInvalid || field;
      }
    });

    if (stepNumber === 2) {
      // Password match
      const pw  = document.getElementById('password').value;
      const pw2 = document.getElementById('password1').value;
      if (pw !== pw2) {
        document.getElementById('password1').classList.add('input-error');
        showMessage('Password Mismatch', 'The passwords you entered do not match. Please try again.', 'error');
        return false;
      }
      // Consent check
      if (!document.getElementById('consentCheckbox').checked) {
        highlightConsentError();
        showMessage(
          'Agreement Required',
          'You must read and accept the Staff Salary Loan Agreement before submitting your application.',
          'warning'
        );
        return false;
      }
    }

    if (!isValid) {
      if (firstInvalid && firstInvalid.id !== 'signature-data') firstInvalid.focus();
      if (!hasSignature) {
        showMessage('Missing Signature', 'Please provide your signature before proceeding.', 'warning');
      } else {
        showMessage('Incomplete Form', 'Please fill in all required fields before proceeding.', 'error');
      }
    }
    return isValid;
  }
  function nextStep() {
    if (validateStep(currentStep)) {
      saveSignature();
      currentStep++;
      showStep(currentStep);
    }
  }
  function prevStep() {
    currentStep = Math.max(1, currentStep - 1);
    showStep(currentStep);
  }

  // -- Consent helpers --------------------
  function onConsentChange() {
    const cb    = document.getElementById('consentCheckbox');
    const block = document.getElementById('consentBlock');
    const msg   = document.getElementById('consentStatusMsg');

    if (cb.checked && !agreementAccepted) {
      // Force user to open and accept via modal first
      cb.checked = false;
      openAgreementModal();
      return;
    }

    block.classList.toggle('consented', cb.checked);
    block.classList.remove('error-consent');
    if (cb.checked) {
      msg.className = 'consent-status-msg ok';
      msg.innerHTML = '<i class="fas fa-check-circle"></i> <span>Agreement accepted. Thank you.</span>';
    } else {
      msg.className = 'consent-status-msg';
      msg.innerHTML = '<i class="fas fa-info-circle"></i> <span>Click the agreement link above to read the full terms before checking this box.</span>';
      agreementAccepted = false;
    }
  }
  function highlightConsentError() {
    const block = document.getElementById('consentBlock');
    const msg   = document.getElementById('consentStatusMsg');
    block.classList.add('error-consent');
    block.classList.remove('consented');
    msg.className = 'consent-status-msg err';
    msg.innerHTML = '<i class="fas fa-exclamation-circle"></i> <span>You must read and accept the agreement to continue.</span>';
    block.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // -- Agreement Modal --------------------
  function openAgreementModal(e) {
    if (e) e.stopPropagation();           // don't trigger checkbox
    agreementFullyRead = false;
    document.getElementById('acceptBtn').disabled = true;
    document.getElementById('readProgress').style.width = '0%';
    document.getElementById('readPct').textContent = '0%';
    document.getElementById('readLabel').textContent = 'Scroll to read the full agreement';
    const ind = document.getElementById('readIndicator');
    ind.className = 'read-indicator';
    ind.innerHTML = '<i class="fas fa-arrow-down"></i><span id="readIndicatorText">Please scroll through the entire agreement before accepting.</span>';
    document.getElementById('agreementBody').scrollTop = 0;
    document.getElementById('agreementModal').classList.add('show');
    document.body.style.overflow = 'hidden';
  }
  function closeAgreementModal() {
    document.getElementById('agreementModal').classList.remove('show');
    document.body.style.overflow = '';
  }
  function updateReadProgress() {
    const body   = document.getElementById('agreementBody');
    const scroll = body.scrollTop;
    const max    = body.scrollHeight - body.clientHeight;
    const pct    = max > 0 ? Math.min(100, Math.round((scroll / max) * 100)) : 100;

    document.getElementById('readProgress').style.width = pct + '%';
    document.getElementById('readPct').textContent = pct + '%';

    if (pct >= 95 && !agreementFullyRead) {
      agreementFullyRead = true;
      document.getElementById('acceptBtn').disabled = false;
      document.getElementById('readLabel').textContent = 'Agreement fully read ?';
      const ind = document.getElementById('readIndicator');
      ind.className = 'read-indicator done';
      ind.innerHTML = '<i class="fas fa-check-circle"></i><span id="readIndicatorText">You can now accept the agreement below.</span>';
    }
  }
  function acceptAgreement() {
    agreementAccepted = true;
    closeAgreementModal();

    // Auto-check and mark the checkbox
    const cb    = document.getElementById('consentCheckbox');
    const block = document.getElementById('consentBlock');
    const msg   = document.getElementById('consentStatusMsg');
    cb.checked  = true;
    block.classList.add('consented');
    block.classList.remove('error-consent');
    msg.className = 'consent-status-msg ok';
    msg.innerHTML = '<i class="fas fa-check-circle"></i> <span>Agreement accepted. Thank you.</span>';
  }

  // -- Password toggle --------------------
  function togglePassword(fieldId, icon) {
    const f = document.getElementById(fieldId);
    const show = f.type === 'password';
    f.type = show ? 'text' : 'password';
    icon.className = show ? 'fa fa-eye toggle-password' : 'fa fa-eye-slash toggle-password';
  }

  // -- Signature pad ----------------------
  function initSignaturePad() {
    canvas = document.getElementById('signature-pad');
    if (!canvas) return;
    ctx = canvas.getContext('2d');
    resizeCanvas();
    ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    canvas.addEventListener('mousedown',  startDrawing);
    canvas.addEventListener('mousemove',  draw);
    canvas.addEventListener('mouseup',    stopDrawing);
    canvas.addEventListener('mouseout',   stopDrawing);
    canvas.addEventListener('touchstart', handleTouch, { passive: false });
    canvas.addEventListener('touchmove',  handleTouch, { passive: false });
    canvas.addEventListener('touchend',   stopDrawing);
    window.addEventListener('resize',     resizeCanvas);
  }
  function resizeCanvas() {
    if (!canvas) return;
    const wrapper = canvas.parentElement;
    const rect = wrapper.getBoundingClientRect();
    const saved = hasSignature ? canvas.toDataURL() : null;
    canvas.width  = rect.width  - 4;
    canvas.height = rect.height - 4;
    ctx.strokeStyle = '#000'; ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    if (saved) { const img = new Image(); img.onload = () => ctx.drawImage(img, 0, 0, canvas.width, canvas.height); img.src = saved; }
  }
  function getPos(e) {
    const r = canvas.getBoundingClientRect();
    return { x: (e.clientX - r.left) * (canvas.width / r.width), y: (e.clientY - r.top) * (canvas.height / r.height) };
  }
  function getTouchPos(e) {
    const r = canvas.getBoundingClientRect();
    return { x: (e.touches[0].clientX - r.left) * (canvas.width / r.width), y: (e.touches[0].clientY - r.top) * (canvas.height / r.height) };
  }
  function startDrawing(e) {
    isDrawing = true;
    const p = getPos(e); lastX = p.x; lastY = p.y;
    ctx.beginPath(); ctx.moveTo(lastX, lastY);
    hasSignature = true; updateSignatureStatus();
  }
  function draw(e) {
    if (!isDrawing) return;
    const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); lastX = p.x; lastY = p.y;
  }
  function stopDrawing() { if (isDrawing) { isDrawing = false; ctx.beginPath(); } }
  function handleTouch(e) {
    e.preventDefault();
    if (e.type === 'touchstart') {
      isDrawing = true;
      const p = getTouchPos(e); lastX = p.x; lastY = p.y;
      ctx.beginPath(); ctx.moveTo(lastX, lastY);
      hasSignature = true; updateSignatureStatus();
    } else if (e.type === 'touchmove' && isDrawing) {
      const p = getTouchPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); lastX = p.x; lastY = p.y;
    }
  }
  function clearSignature() {
    if (ctx && canvas) {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      document.getElementById('signature-data').value = '';
      hasSignature = false; updateSignatureStatus();
      showMessage('Signature Cleared', 'Your signature has been cleared successfully.', 'success');
    }
  }
  function saveSignature() {
    if (canvas && hasSignature) {
      try { document.getElementById('signature-data').value = canvas.toDataURL('image/png'); }
      catch (e) { showMessage('Signature Error', 'There was an error saving your signature. Please try again.', 'error'); }
    }
  }
  function updateSignatureStatus() {
    const el = document.getElementById('signature-status');
    el.textContent = hasSignature ? 'Signature captured' : 'No signature drawn';
    el.className   = 'signature-status ' + (hasSignature ? 'signed' : 'empty');
  }

  // -- Form submit ------------------------
  async function handleFormSubmit(e) {
    e.preventDefault();
    if (!validateStep(1) || !validateStep(2)) {
      showMessage('Form Incomplete', 'Please ensure all required fields are correctly filled before submitting.', 'error');
      return;
    }
    saveSignature();
    try {
      const response = await fetch('create_staff.php', { method: 'POST', body: new FormData(e.target) });
      const result   = await response.json();
      const box = document.getElementById('messageBox');
      if (box) { box.style.display = 'block'; box.innerText = result.message; box.style.color = result.status === 'success' ? 'green' : 'red'; }
      if (result.status === 'success' && result.redirect) {
        setTimeout(() => { window.location.href = result.redirect; }, result.delay || 3000);
      }
    } catch (err) {
      showMessage('Submission Error', 'An unexpected error occurred while submitting the form.', 'error');
      console.error(err);
    }
  }

  // -- Init -------------------------------
  document.addEventListener('DOMContentLoaded', function () {
    showStep(currentStep);
    setTimeout(initSignaturePad, 100);

    const form = document.getElementById('signupForm');
    if (form) form.addEventListener('submit', handleFormSubmit);

    document.getElementById('messageOverlay').addEventListener('click', function (e) {
      if (e.target === this) hideMessage();
    });
    // Close modal on backdrop click
    document.getElementById('agreementModal').addEventListener('click', function (e) {
      if (e.target === this) closeAgreementModal();
    });
  });

  // Global exports
  window.nextStep           = nextStep;
  window.prevStep           = prevStep;
  window.clearSignature     = clearSignature;
  window.togglePassword     = togglePassword;
  window.hideMessage        = hideMessage;
  window.openAgreementModal = openAgreementModal;
  window.closeAgreementModal= closeAgreementModal;
  window.updateReadProgress = updateReadProgress;
  window.acceptAgreement    = acceptAgreement;
  window.onConsentChange    = onConsentChange;
  </script>

</body>
</html>
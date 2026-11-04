<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nsimbi Impact</title>
  <!-- Favicon -->
<link rel="apple-touch-icon" sizes="76x76" href="assets/img/favicon.png">
<link rel="icon" type="image/png" href="assets/img/favicon.png">

<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,600,700" rel="stylesheet" />

<!-- Bootstrap 5.3 CSS -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet" />

<!-- Font Awesome -->
<script src="https://kit.fontawesome.com/42d5adcbca.js" crossorigin="anonymous"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">


  <style>
    .form-step { 
      display: none; 
      animation: fadeIn 0.3s ease-in;
    }
    .form-step.active { 
      display: block; 
    }
    .is-invalid {
      border: 2px solid #dc3545 !important;
      background-color: #fff0f0;
    }
    .card {
      border: none;
      border-radius: 15px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }
    .card-header {
      background: linear-gradient(45deg, #17a2b8, #007bff);
      border-radius: 15px 15px 0 0;
      padding: 20px;
    }
    .form-control {
      border-radius: 8px;
      margin-bottom: 15px;
      padding: 12px;
    }
    .btn {
      border-radius: 8px;
      padding: 10px 20px;
      font-weight: 600;
    }
    .main-content {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .step-indicator {
      display: flex;
      justify-content: center;
      margin-bottom: 30px;
    }
    .step {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: #ddd;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 10px;
      font-weight: bold;
      transition: all 0.3s ease;
    }
    .step.active {
      background: #17a2b8;
      color: white;
    }
    .step.completed {
      background: #28a745;
      color: white;
    }
    
.signature-wrapper {
  border: 2px solid #dee2e6;
  height: 150px;
  position: relative;
  background-color: white;
  border-radius: 8px;
  overflow: hidden;
}

#signature-pad {
  display: block;
  cursor: crosshair;
  border-radius: 6px;
  width: 100%;
  height: 100%;
}
  </style>
</head>
<body>
  <main class="main-content mt-0">
    <section>
      <div class="page-header min-vh-100 d-flex align-items-center">
        <div class="container">
          <div class="row justify-content-center">
            <div class="col-xl-8 col-lg-9 col-md-10">
              <div class="card card-plain mt-4">
                <div class="card-header text-white text-center">
                  <h3 class="font-weight-bold mb-0">Create an Account</h3>
                  <p class="mb-0">Join Nsimbi Impact today</p>
                </div>
                <div class="card-body p-4">
                 
                  <div class="step-indicator">
                    <div class="step active" id="step-1">1</div>
                    <div class="step" id="step-2">2</div>
                    <div class="step" id="step-3">3</div>
                  </div>

               
                  <div id="messageArea" class="alert" style="display: none; margin-bottom: 20px;">
                    <div id="messageContent"></div>
                  </div>

                  <form method="post" action="added_client.php" enctype="multipart/form-data" id="multiStepForm">
                
                    <div class="form-step active" data-step="1">
                      <h5 class="text-info mb-4">Personal Details</h5>
                      <div class="row">
                        <div class="col-md-6">
                          <input type="text" class="form-control" name="fname" placeholder="First Name" required>
                        </div>
                        <div class="col-md-6">
                          <input type="text" class="form-control" name="lname" placeholder="Last Name" required>
                        </div>
                        <div class="col-md-6">
                          <input type="text" class="form-control" name="username" placeholder="Username" required>
                        </div>
                        <div class="col-md-6">
                          <input type="text" class="form-control" name="nin" placeholder="National ID (NIN)" required>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Upload National ID Front</label>
                          <input type="file" class="form-control" name="uploadid1" accept="image/*,application/pdf" required>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Upload National ID Back</label>
                          <input type="file" class="form-control" name="uploadid2" accept="image/*,application/pdf" required>
                        </div>
                        <div class="col-md-6">
                         <label class="form-label">Select Your Gender</label>
                          <select name="gender" class="form-control" required>
                            <option value="">Select Your Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Date of Birth</label>
                          <input type="date" class="form-control" name="dob" required>
                        </div>
                        <div class="col-md-6">
                          <input type="tel" class="form-control" name="phone" placeholder="Phone (e.g. 25677...)" required>
                        </div>
                        <div class="col-md-6">
                          <input type="email" class="form-control" name="email" placeholder="Email Address">
                        </div>
                        
                         <div class="col-md-6">
                          <input type="text" class="form-control" name="bank_name" placeholder="Bank Name" required>
                        </div>
                        <div class="col-md-6">
                          <input type="number" class="form-control" name="bank_account" placeholder="Bank Account">
                        </div>
                        
                      </div>
                      <div class="d-flex justify-content-end mt-4">
                      <button type="button" class="btn btn-info next-btn"> Next <i class="fas fa-arrow-right ms-2"></i></button>
                      </div>

                    </div>

                    <div class="form-step" data-step="2">
                      <h5 class="text-info mb-4">Business Information</h5>
                      <div class="row">
                        <div class="col-md-6">
  <select class="form-control" name="district" required>
    <option value="" disabled selected>Select District</option><option value="Abim">Abim</option><option value="Adjumani">Adjumani</option><option value="Agago">Agago</option><option value="Alebtong">Alebtong</option><option value="Amolatar">Amolatar</option><option value="Amudat">Amudat</option><option value="Amuria">Amuria</option><option value="Amuru">Amuru</option><option value="Apac">Apac</option>
<option value="Arua">Arua</option><option value="Budaka">Budaka</option><option value="Bududa">Bududa</option><option value="Bugiri">Bugiri</option><option value="Bugweri">Bugweri</option><option value="Buhweju">Buhweju</option><option value="Buikwe">Buikwe</option><option value="Bukedea">Bukedea</option><option value="Bukomansimbi">Bukomansimbi</option><option value="Bukwo">Bukwo</option><option value="Bulambuli">Bulambuli</option><option value="Buliisa">Buliisa</option><option value="Bundibugyo">Bundibugyo</option><option value="Bunyangabu">Bunyangabu</option><option value="Bushenyi">Bushenyi</option><option value="Busia">Busia</option><option value="Butaleja">Butaleja</option><option value="Butambala">Butambala</option><option value="Butebo">Butebo</option>
<option value="Buvuma">Buvuma</option><option value="Buyende">Buyende</option><option value="Dokolo">Dokolo</option><option value="Gomba">Gomba</option><option value="Gulu">Gulu</option><option value="Hoima">Hoima</option><option value="Ibanda">Ibanda</option><option value="Iganga">Iganga</option><option value="Isingiro">Isingiro</option><option value="Jinja">Jinja</option><option value="Kaabong">Kaabong</option><option value="Kabale">Kabale</option><option value="Kabarole">Kabarole</option><option value="Kaberamaido">Kaberamaido</option><option value="Kagadi">Kagadi</option><option value="Kakumiro">Kakumiro</option><option value="Kalangala">Kalangala</option><option value="Kaliro">Kaliro</option>
    <option value="Kalungu">Kalungu</option>
       <option value="Kampala">Kampala</option>
    <option value="Kamuli">Kamuli</option>
    <option value="Kamwenge">Kamwenge</option>
    <option value="Kanungu">Kanungu</option>
    <option value="Kapchorwa">Kapchorwa</option>
    <option value="Kapelebyong">Kapelebyong</option>
    <option value="Karenga">Karenga</option>
    <option value="Kasese">Kasese</option>
    <option value="Kassanda">Kassanda</option>
    <option value="Katakwi">Katakwi</option>
    <option value="Kayunga">Kayunga</option>
    <option value="Kazo">Kazo</option>
    <option value="Kibaale">Kibaale</option>
    <option value="Kiboga">Kiboga</option>
    <option value="Kibuku">Kibuku</option>
    <option value="Kikuube">Kikuube</option>
    <option value="Kiruhura">Kiruhura</option>
    <option value="Kiryandongo">Kiryandongo</option>
    <option value="Kisoro">Kisoro</option>
    <option value="Kitagwenda">Kitagwenda</option>
    <option value="Kitgum">Kitgum</option>
    <option value="Koboko">Koboko</option>
    <option value="Kole">Kole</option>
    <option value="Kotido">Kotido</option>
    <option value="Kumi">Kumi</option>
    <option value="Kwania">Kwania</option>
    <option value="Kween">Kween</option>
    <option value="Kyankwanzi">Kyankwanzi</option>
    <option value="Kyegegwa">Kyegegwa</option>
    <option value="Kyenjojo">Kyenjojo</option>
    <option value="Kyotera">Kyotera</option>
    <option value="Lamwo">Lamwo</option>
    <option value="Lira">Lira</option>
    <option value="Luuka">Luuka</option>
    <option value="Luweero">Luweero</option>
    <option value="Lwengo">Lwengo</option>
    <option value="Lyantonde">Lyantonde</option>
    <option value="Manafwa">Manafwa</option>
    <option value="Maracha">Maracha</option>
    <option value="Masaka">Masaka</option>
    <option value="Masindi">Masindi</option>
    <option value="Mayuge">Mayuge</option>
    <option value="Mbale">Mbale</option>
    <option value="Mbarara">Mbarara</option>
    <option value="Mitooma">Mitooma</option>
    <option value="Mityana">Mityana</option>
    <option value="Moroto">Moroto</option>
    <option value="Moyo">Moyo</option>
    <option value="Mpigi">Mpigi</option>
    <option value="Mubende">Mubende</option>
    <option value="Mukono">Mukono</option>
    <option value="Nabilatuk">Nabilatuk</option>
    <option value="Nakapiripirit">Nakapiripirit</option>
    <option value="Nakaseke">Nakaseke</option>
    <option value="Nakasongola">Nakasongola</option>
    <option value="Namayingo">Namayingo</option>
    <option value="Namisindwa">Namisindwa</option>
    <option value="Namutumba">Namutumba</option>
    <option value="Napak">Napak</option>
    <option value="Nebbi">Nebbi</option>
    <option value="Ngora">Ngora</option>
    <option value="Ntoroko">Ntoroko</option>
    <option value="Ntungamo">Ntungamo</option>
    <option value="Nwoya">Nwoya</option>
    <option value="Obongi">Obongi</option>
    <option value="Omoro">Omoro</option>
    <option value="Otuke">Otuke</option>
    <option value="Oyam">Oyam</option>
    <option value="Pader">Pader</option>
    <option value="Pakwach">Pakwach</option>
    <option value="Pallisa">Pallisa</option>
    <option value="Rakai">Rakai</option>
    <option value="Rubanda">Rubanda</option>
    <option value="Rubirizi">Rubirizi</option>
    <option value="Rukiga">Rukiga</option>
    <option value="Rukungiri">Rukungiri</option>
    <option value="Rwampara">Rwampara</option>
    <option value="Sembabule">Sembabule</option>
    <option value="Serere">Serere</option>
    <option value="Sheema">Sheema</option>
    <option value="Sironko">Sironko</option>
    <option value="Soroti">Soroti</option>
    <option value="Terego">Terego</option>
    <option value="Tororo">Tororo</option>
    <option value="Wakiso">Wakiso</option>
    <option value="Yumbe">Yumbe</option>
    <option value="Zombo">Zombo</option>
  </select>
</div>

                      <!-- Business Name -->
<div class="col-md-6">
    <input type="text"
           class="form-control"
           name="business_name"
           placeholder="Business Name"
           required>
</div>

<!-- Business Type Select -->
<div class="col-md-6">
    <select class="form-control"
            id="businessTypeSelect"
            required>
        <option value="">Select Type of Business</option>
        <option value="Beauty salon">Beauty salon</option>
        <option value="Education">Education</option>
        <option value="Electronics">Electronics</option>
        <option value="Farming">Farming</option>
        <option value="Healthcare">Healthcare</option>
        <option value="Market stall">Market stall</option>
        <option value="Poultry keeping">Poultry keeping</option>
        <option value="Restaurant">Restaurant</option>
        <option value="Retail shop">Retail shop</option>
        <option value="Sell second hand clothes">Sell second hand clothes</option>
        <option value="Tailoring">Tailoring</option>
        <option value="Transportation">Transportation</option>
        <option value="Other">Other</option>
    </select>
</div>

<!-- Other Business Type -->
<div class="col-md-6 d-none" id="otherBusinessTypeField">
    <input type="text" class="form-control" id="otherBusinessInput" placeholder="Please specify business type">
</div>

<!-- Final Business Type -->
<input type="hidden" name="business_type" id="finalBusinessType">

                        <div class="col-md-6">
                          <input type="text" class="form-control" name="location" placeholder="Business Location" required>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">How long have you been in business?</label>
                          <select name="business_period" class="form-control" required>
                            <option value="">Select Period</option>
                            <option value="Less than 6 months">Less than 6 months</option>
                            <option value="6 months - 1 year">6 months - 1 year</option>
                            <option value="1 - 2 years">1 - 2 years</option>
                            <option value="2 - 5 years">2 - 5 years</option>
                            <option value="More than 5 years">More than 5 years</option>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Upload Trading License (Optional)</label>
                          <input type="file" class="form-control" name="license" accept="image/*,application/pdf">
                        </div>
                        
                        <div class="col-md-6">
                          <label class="form-label">Sales Last Week (UGX)</label>
                          <select name="amount_last" class="form-control" required>
                            <option value="">Select Sales Range</option>
                            <option value="10,000 - 50,000">10,000 - 50,000</option>
                            <option value="50,000 - 100,000">50,000 - 100,000</option>
                            <option value="100,000 - 500,000">100,000 - 500,000</option>
                            <option value="500,000 - 1M">500,000 - 1M</option>
                            <option value="1 - 2M">1 - 2M</option>
                            <option value="Above 2M">Above 2M</option>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Sales Two Weeks Ago (UGX)</label>
                          <select name="amount_this" class="form-control" required>
                            <option value="">Select Sales Range</option>
                             <option value="10,000 - 50,000">10,000 - 50,000</option>
                            <option value="50,000 - 100,000">50,000 - 100,000</option>
                            <option value="100,000 - 500,000">100,000 - 500,000</option>
                            <option value="500,000 - 1M">500,000 - 1M</option>
                            <option value="1 - 2M">1 - 2M</option>
                            <option value="Above 2M">Above 2M</option>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Upload Business Photo</label>
                          <input type="file" class="form-control" name="stock" accept="image/*,application/pdf">
                        </div>
                      </div>
                      <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-secondary prev-btn"><i class="fa fa-arrow-left me-2"></i> Previous</button>
                        <button type="button" class="btn btn-info next-btn">Continue <i class="fa fa-arrow-right ms-2"></i></button>
                      </div>
                    </div>

                    <div class="form-step" data-step="3">
                      <h5 class="text-info mb-4">Next of Kin & Account Setup</h5>
                      <div class="row">
                        <div class="col-md-6">
                          <input type="text" class="form-control" name="nok_name" placeholder="Next of Kin Name" required>
                        </div>
                        <div class="col-md-6">
                          <input type="tel" class="form-control" name="nok_tel" placeholder="Next of Kin Phone" required>
                        </div>
                        <div class="col-md-6">
                          <input type="text" class="form-control" name="nok_rel" placeholder="Relationship to Next of Kin" required>
                        </div>
                         <div class="col-md-6">
                          <label>Upload Your Image (Image/PDF)</label>
                          <input type="file" class="form-control" name="profile_img" accept="image/*,.pdf" required>
                        </div>
                      </div>
                         <div class="col-md-6">
                          <label for="signature-pad">Draw your Signature (Mouse or Touch)</label>
                          <div class="signature-wrapper">
                            <canvas id="signature-pad"></canvas>
                            <input type="hidden" name="applicant_signature" id="signature-data" required>
                          </div>
                          <div class="signature-status empty" id="signature-status">
                            No signature drawn
                          </div>
                          <button type="button" class="btn btn-sm btn-danger mt-2" onclick="clearSignature()">
                            Clear Signature
                          </button>
                        </div>
                      
                        
                        <div class="row">
                        <div class="col-md-6 position-relative">
                        <input type="password" class="form-control" id="password" name="password" placeholder="Create Password" minlength="6" required>
                        <span toggle="#password" class="fa fa-fw fa-eye toggle-password" style="position:absolute; top:50%; right:15px; transform:translateY(-50%);                         cursor:pointer;"></span>
                        </div>

    <div class="col-md-6 position-relative">
    <input type="password" class="form-control" id="con_password" name="con_password" placeholder="Confirm Password" minlength="6" required>
    <span toggle="#con_password" class="fa fa-fw fa-eye toggle-password" style="position:absolute; top:50%; right:15px; transform:translateY(-50%); cursor:pointer;"></span>
  </div>
</div>
                      <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-secondary prev-btn"><i class="fas fa-arrow-left me-2"></i> Previous</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check me-2"></i> Register Now</button>
                      </div>
                    </div>
                  </form>

                </div>

                <div class="card-footer text-center pt-3">
                <p class="mb-0 text-sm">Have an account?
                <a href="../index.php" class="text-info font-weight-bold text-decoration-none">Login</a></p>
                </div>

              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>
<!-- Bootstrap JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const steps = document.querySelectorAll(".form-step");
    const nextBtns = document.querySelectorAll(".next-btn");
    const prevBtns = document.querySelectorAll(".prev-btn");
    const stepIndicators = document.querySelectorAll(".step");
    const form = document.getElementById("multiStepForm");

    let currentStep = 0;

    const businessTypeSelect = document.getElementById("businessTypeSelect");
    const otherBusinessTypeField = document.getElementById("otherBusinessTypeField");
    const otherBusinessInput = document.getElementById("otherBusinessInput");
    const finalBusinessType = document.getElementById("finalBusinessType");

    let canvas = document.getElementById("signature-pad");
    let ctx = null;
    let isDrawing = false;
    let hasSignature = false;
    let lastX = 0;
    let lastY = 0;

    showStep(currentStep);
    initBusinessType();
    initSignaturePad();
    initPasswordToggle();

    function showStep(stepIndex) {
        steps.forEach(step => step.classList.remove("active"));

        if (steps[stepIndex]) {
            steps[stepIndex].classList.add("active");
        }

        stepIndicators.forEach((indicator, index) => {
            indicator.classList.remove("active", "completed");

            if (index === stepIndex) {
                indicator.classList.add("active");
            } else if (index < stepIndex) {
                indicator.classList.add("completed");
            }
        });

        window.scrollTo({ top: 0, behavior: "smooth" });
    }

    function initBusinessType() {
        if (!businessTypeSelect || !otherBusinessTypeField || !otherBusinessInput || !finalBusinessType) {
            return;
        }

        businessTypeSelect.removeAttribute("name");

        businessTypeSelect.addEventListener("change", updateBusinessType);
        otherBusinessInput.addEventListener("input", updateBusinessType);

        updateBusinessType();
    }

    function updateBusinessType() {
        const selectedValue = businessTypeSelect.value;

        businessTypeSelect.classList.remove("is-invalid");
        otherBusinessInput.classList.remove("is-invalid");
        finalBusinessType.classList.remove("is-invalid");

        if (selectedValue === "Other" || selectedValue === "other") {
            otherBusinessTypeField.style.display = "block";
            otherBusinessInput.required = true;

            finalBusinessType.value = otherBusinessInput.value.trim();
        } else {
            otherBusinessTypeField.style.display = "none";
            otherBusinessInput.required = false;
            otherBusinessInput.value = "";

            finalBusinessType.value = selectedValue;
        }
    }

    function validateStep(stepIndex) {
        updateBusinessType();
        saveSignature();

        const currentStepElement = steps[stepIndex];
        const inputs = currentStepElement.querySelectorAll("input[required], select[required], textarea[required]");

        let isValid = true;
        let firstInvalidInput = null;

        inputs.forEach(input => {
            input.classList.remove("is-invalid");

            if (input.offsetParent === null && input.type !== "hidden") {
                return;
            }

            if (input.type === "file") {
                if (!input.files || input.files.length === 0) {
                    isValid = false;
                    input.classList.add("is-invalid");
                    if (!firstInvalidInput) firstInvalidInput = input;
                }
            } else if (input.type === "email" && input.value.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                if (!emailRegex.test(input.value.trim())) {
                    isValid = false;
                    input.classList.add("is-invalid");
                    if (!firstInvalidInput) firstInvalidInput = input;
                }
            } else if (!input.value.trim()) {
                isValid = false;
                input.classList.add("is-invalid");

                if (input.id === "finalBusinessType") {
                    if (businessTypeSelect.value === "Other" || businessTypeSelect.value === "other") {
                        otherBusinessInput.classList.add("is-invalid");
                        if (!firstInvalidInput) firstInvalidInput = otherBusinessInput;
                    } else {
                        businessTypeSelect.classList.add("is-invalid");
                        if (!firstInvalidInput) firstInvalidInput = businessTypeSelect;
                    }
                } else {
                    if (!firstInvalidInput) firstInvalidInput = input;
                }
            }
        });

        if (stepIndex === 2) {
            const password = currentStepElement.querySelector('input[name="password"]');
            const confirmPassword = currentStepElement.querySelector('input[name="con_password"]');

            if (password && confirmPassword && password.value !== confirmPassword.value) {
                isValid = false;
                confirmPassword.classList.add("is-invalid");
                if (!firstInvalidInput) firstInvalidInput = confirmPassword;
            }
        }

        if (!isValid && firstInvalidInput) {
            firstInvalidInput.focus();
            firstInvalidInput.scrollIntoView({ behavior: "smooth", block: "center" });
        }

        return isValid;
    }

    nextBtns.forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();

            if (validateStep(currentStep)) {
                if (currentStep < steps.length - 1) {
                    currentStep++;
                    showStep(currentStep);
                }
            } else {
                alert("Please fill all required fields correctly before proceeding.");
            }
        });
    });

    prevBtns.forEach(btn => {
        btn.addEventListener("click", function (e) {
            e.preventDefault();

            if (currentStep > 0) {
                currentStep--;
                showStep(currentStep);
            }
        });
    });

    if (form) {
        form.addEventListener("submit", function (e) {
            e.preventDefault();

            updateBusinessType();
            saveSignature();

            if (!validateStep(currentStep)) {
                showMessage("Please fill all required fields correctly before submitting.", "error");
                return;
            }

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Registering...';
            submitBtn.disabled = true;

            const formData = new FormData(form);

            fetch("added_client.php", {
                method: "POST",
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === "success") {
                    showMessage(data.message, "success");

                    if (data.redirect) {
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, data.delay || 2000);
                    }
                } else {
                    showMessage(data.message || "Registration failed.", "error");
                }
            })
            .catch(error => {
                console.error("Error:", error);
                showMessage("An error occurred during registration. Please try again.", "error");
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }

    function showMessage(message, type) {
        const messageArea = document.getElementById("messageArea");
        const messageContent = document.getElementById("messageContent");

        if (!messageArea || !messageContent) {
            alert(message);
            return;
        }

        messageArea.className = `alert alert-${type === "success" ? "success" : "danger"}`;
        messageContent.innerHTML = `<i class="fas fa-${type === "success" ? "check-circle" : "exclamation-triangle"} me-2"></i>${message}`;
        messageArea.style.display = "block";

        messageArea.scrollIntoView({ behavior: "smooth", block: "center" });

        if (type === "success") {
            setTimeout(() => {
                messageArea.style.display = "none";
            }, 5000);
        }
    }

    const allInputs = document.querySelectorAll("input, select, textarea");

    allInputs.forEach(input => {
        input.addEventListener("blur", function () {
            updateBusinessType();

            this.classList.remove("is-invalid");

            if (this.hasAttribute("required") || (this.type === "email" && this.value.trim())) {
                if (this.type === "file") {
                    if (this.hasAttribute("required") && (!this.files || this.files.length === 0)) {
                        this.classList.add("is-invalid");
                    }
                } else if (this.type === "email" && this.value.trim()) {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

                    if (!emailRegex.test(this.value.trim())) {
                        this.classList.add("is-invalid");
                    }
                } else if (this.hasAttribute("required") && !this.value.trim()) {
                    this.classList.add("is-invalid");
                }
            }
        });
    });

    function initPasswordToggle() {
        document.querySelectorAll(".toggle-password").forEach(function (toggle) {
            toggle.addEventListener("click", function () {
                const input = document.querySelector(this.getAttribute("toggle"));

                if (!input) return;

                const type = input.getAttribute("type") === "password" ? "text" : "password";
                input.setAttribute("type", type);

                this.classList.toggle("fa-eye");
                this.classList.toggle("fa-eye-slash");
            });
        });
    }

    function initSignaturePad() {
        if (!canvas) return;

        ctx = canvas.getContext("2d");
        resizeCanvas();

        ctx.strokeStyle = "#000000";
        ctx.lineWidth = 2;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";

        canvas.addEventListener("mousedown", startDrawing);
        canvas.addEventListener("mousemove", drawMouse);
        canvas.addEventListener("mouseup", stopDrawing);
        canvas.addEventListener("mouseout", stopDrawing);

        canvas.addEventListener("touchstart", startTouchDrawing, { passive: false });
        canvas.addEventListener("touchmove", drawTouch, { passive: false });
        canvas.addEventListener("touchend", stopDrawing, { passive: false });

        const clearBtn = document.getElementById("clear-signature");

        if (clearBtn) {
            clearBtn.addEventListener("click", clearSignature);
        }

        window.addEventListener("resize", resizeCanvas);

        updateSignatureStatus();
    }

    function resizeCanvas() {
        if (!canvas || !ctx) return;

        const wrapper = canvas.parentElement;
        const rect = wrapper.getBoundingClientRect();

        const oldSignature = hasSignature ? canvas.toDataURL("image/png") : null;

        canvas.width = rect.width;
        canvas.height = rect.height;

        ctx.strokeStyle = "#000000";
        ctx.lineWidth = 2;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";

        if (oldSignature) {
            const img = new Image();
            img.onload = function () {
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            };
            img.src = oldSignature;
        }
    }

    function getMousePos(e) {
        const rect = canvas.getBoundingClientRect();

        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }

    function getTouchPos(e) {
        const rect = canvas.getBoundingClientRect();

        return {
            x: e.touches[0].clientX - rect.left,
            y: e.touches[0].clientY - rect.top
        };
    }

    function startDrawing(e) {
        if (!ctx) return;

        isDrawing = true;

        const pos = getMousePos(e);

        lastX = pos.x;
        lastY = pos.y;

        ctx.beginPath();
        ctx.moveTo(lastX, lastY);

        hasSignature = true;
        updateSignatureStatus();
    }

    function drawMouse(e) {
        if (!isDrawing || !ctx) return;

        const pos = getMousePos(e);

        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();

        lastX = pos.x;
        lastY = pos.y;

        saveSignature();
    }

    function startTouchDrawing(e) {
        e.preventDefault();

        if (!ctx) return;

        isDrawing = true;

        const pos = getTouchPos(e);

        lastX = pos.x;
        lastY = pos.y;

        ctx.beginPath();
        ctx.moveTo(lastX, lastY);

        hasSignature = true;
        updateSignatureStatus();
    }

    function drawTouch(e) {
        e.preventDefault();

        if (!isDrawing || !ctx) return;

        const pos = getTouchPos(e);

        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();

        lastX = pos.x;
        lastY = pos.y;

        saveSignature();
    }

    function stopDrawing() {
        if (!isDrawing) return;

        isDrawing = false;
        saveSignature();

        if (ctx) {
            ctx.beginPath();
        }
    }

    function saveSignature() {
        const signatureData = document.getElementById("signature-data");

        if (!canvas || !signatureData || !hasSignature) return;

        try {
            signatureData.value = canvas.toDataURL("image/png");
        } catch (error) {
            console.error("Signature save error:", error);
        }
    }

    function clearSignature() {
        const signatureData = document.getElementById("signature-data");

        if (!ctx || !canvas) return;

        ctx.clearRect(0, 0, canvas.width, canvas.height);

        if (signatureData) {
            signatureData.value = "";
        }

        hasSignature = false;
        updateSignatureStatus();
    }

    function updateSignatureStatus() {
        const statusEl = document.getElementById("signature-status");

        if (!statusEl) return;

        if (hasSignature) {
            statusEl.textContent = "Signature captured";
            statusEl.className = "signature-status signed";
        } else {
            statusEl.textContent = "No signature drawn";
            statusEl.className = "signature-status empty";
        }
    }

});
</script>

</body>
</html>
<?php require 'nav-head.php'; ?>

<div class="container-fluid py-4">

    <!-- -- PAGE HEADER -------------------------------------------- -->
    <div class="page-header mb-4">
        <div>
            <h4 class="title">Add New Client</h4>
            <p class="sub">Complete all three steps to register a new borrower.</p>
        </div>
        <a href="clients.php" class="btn btn-ghost btn-sm">
            <i class="fas fa-arrow-left me-2"></i>Back to Clients
        </a>
    </div>

    <!-- -- ALERT AREA --------------------------------------------- -->
    <div id="ac-message" class="alert mb-4" style="display:none" role="alert">
        <span id="ac-message-text"></span>
    </div>

    <form method="post"
          action="added_client.php"
          id="form-add-client"
          enctype="multipart/form-data"
          novalidate>

        <!-- -- TAB NAV -------------------------------------------- -->
        <div class="loan-tabs-wrapper mb-4">
            <ul class="nav nav-pills loan-tabs" id="clientTabs" role="tablist">

                <li class="nav-item" role="presentation">
                    <button class="nav-link active" type="button" role="tab"
                            data-tab="pane-personal" aria-selected="true">
                        <i class="fas fa-user me-2"></i>Personal Details
                        <span class="ac-step-badge">1</span>
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" role="tab"
                            data-tab="pane-business" aria-selected="false">
                        <i class="fas fa-store me-2"></i>Business Info
                        <span class="ac-step-badge">2</span>
                    </button>
                </li>

                <li class="nav-item" role="presentation">
                    <button class="nav-link" type="button" role="tab"
                            data-tab="pane-account" aria-selected="false">
                        <i class="fas fa-lock me-2"></i>NOK &amp; Account
                        <span class="ac-step-badge">3</span>
                    </button>
                </li>

            </ul>
        </div>

        <!-- -- TAB PANES ------------------------------------------ -->
        <div id="clientTabsContent">

            <!-- --------------------------------------------------
                 PANE 1 - Personal Details
            ------------------------------------------------------ -->
            <div class="ac-pane show active" id="pane-personal" role="tabpanel">
                <div class="loan-tab-card">
                    <div class="loan-tab-header">
                        <div>
                            <h5><i class="fas fa-user text-primary me-2"></i>Personal Details</h5>
                            <p>Identity, contact information, and bank details.</p>
                        </div>
                        <span class="badge badge-soft-primary px-3 py-2">Step 1 of 3</span>
                    </div>

                    <div class="ac-form-body">

                        <!-- Name row -->
                        <div class="ac-section-label">Full Name</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="fname">First Name</label>
                                <input type="text" class="form-control" id="fname" name="fname"
                                       placeholder="e.g. John" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="lname">Last Name</label>
                                <input type="text" class="form-control" id="lname" name="lname"
                                       placeholder="e.g. Doe" required>
                            </div>
                        </div>

                        <!-- Identity -->
                        <div class="ac-section-label">Identity</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="username">Username</label>
                                <input type="text" class="form-control" id="username" name="username"
                                       placeholder="Choose a username" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="nin">NIN Number</label>
                                <input type="text" class="form-control" id="nin" name="nin"
                                       placeholder="e.g. CM90012345XXXXX" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="uploadid1">National ID - Front</label>
                                <input type="file" class="form-control ac-file" id="uploadid1" name="uploadid1"
                                       accept="image/*,application/pdf" required>
                                <p class="form-help">Image or PDF</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="uploadid2">National ID - Back</label>
                                <input type="file" class="form-control ac-file" id="uploadid2" name="uploadid2"
                                       accept="image/*,application/pdf" required>
                                <p class="form-help">Image or PDF</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="gender">Gender</label>
                                <select class="form-select" id="gender" name="gender" required>
                                    <option value="" disabled selected>Select gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="dob">Date of Birth</label>
                                <input type="date" class="form-control" id="dob" name="dob" required>
                            </div>
                        </div>

                        <!-- Contact -->
                        <div class="ac-section-label">Contact</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="phone">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone"
                                       placeholder="e.g. 25677*******" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email">Email Address <span class="text-muted fw-400">(optional)</span></label>
                                <input type="email" class="form-control" id="email" name="email"
                                       placeholder="client@example.com">
                            </div>
                        </div>

                        <!-- Bank -->
                        <div class="ac-section-label">Bank Details</div>
                        <div class="info-grid mb-2">
                            <div class="form-group">
                                <label class="form-label" for="bank_name">Bank Name</label>
                                <input type="text" class="form-control" id="bank_name" name="bank_name"
                                       placeholder="e.g. Stanbic Bank" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="bank_account">Bank Account Number <span class="text-muted fw-400">(optional)</span></label>
                                <input type="number" class="form-control" id="bank_account" name="bank_account"
                                       placeholder="Account number">
                            </div>
                        </div>

                        <div class="ac-pane-footer">
                            <span></span>
                            <button type="button" class="btn btn-primary btn-ac-next" data-next="pane-business">
                                Next: Business Info <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div><!-- /pane-personal -->


            <!-- --------------------------------------------------
                 PANE 2 - Business Information
            ------------------------------------------------------ -->
            <div class="ac-pane" id="pane-business" role="tabpanel">
                <div class="loan-tab-card">
                    <div class="loan-tab-header">
                        <div>
                            <h5><i class="fas fa-store text-primary me-2"></i>Business Information</h5>
                            <p>Business location, type, sales performance, and documents.</p>
                        </div>
                        <span class="badge badge-soft-primary px-3 py-2">Step 2 of 3</span>
                    </div>

                    <div class="ac-form-body">

                        <!-- Location -->
                        <div class="ac-section-label">Location</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="district">District</label>
                                <select class="form-select" id="district" name="district" required>
                                    <option value="" disabled selected>Select district</option>
                                    <option value="Abim">Abim</option>
                                    <option value="Adjumani">Adjumani</option>
                                    <option value="Agago">Agago</option>
                                    <option value="Alebtong">Alebtong</option>
                                    <option value="Amolatar">Amolatar</option>
                                    <option value="Amudat">Amudat</option>
                                    <option value="Amuria">Amuria</option>
                                    <option value="Amuru">Amuru</option>
                                    <option value="Apac">Apac</option>
                                    <option value="Arua">Arua</option>
                                    <option value="Budaka">Budaka</option>
                                    <option value="Bududa">Bududa</option>
                                    <option value="Bugiri">Bugiri</option>
                                    <option value="Bugweri">Bugweri</option>
                                    <option value="Buhweju">Buhweju</option>
                                    <option value="Buikwe">Buikwe</option>
                                    <option value="Bukedea">Bukedea</option>
                                    <option value="Bukomansimbi">Bukomansimbi</option>
                                    <option value="Bukwo">Bukwo</option>
                                    <option value="Bulambuli">Bulambuli</option>
                                    <option value="Buliisa">Buliisa</option>
                                    <option value="Bundibugyo">Bundibugyo</option>
                                    <option value="Bunyangabu">Bunyangabu</option>
                                    <option value="Bushenyi">Bushenyi</option>
                                    <option value="Busia">Busia</option>
                                    <option value="Butaleja">Butaleja</option>
                                    <option value="Butambala">Butambala</option>
                                    <option value="Butebo">Butebo</option>
                                    <option value="Buvuma">Buvuma</option>
                                    <option value="Buyende">Buyende</option>
                                    <option value="Dokolo">Dokolo</option>
                                    <option value="Gomba">Gomba</option>
                                    <option value="Gulu">Gulu</option>
                                    <option value="Hoima">Hoima</option>
                                    <option value="Ibanda">Ibanda</option>
                                    <option value="Iganga">Iganga</option>
                                    <option value="Isingiro">Isingiro</option>
                                    <option value="Jinja">Jinja</option>
                                    <option value="Kaabong">Kaabong</option>
                                    <option value="Kabale">Kabale</option>
                                    <option value="Kabarole">Kabarole</option>
                                    <option value="Kaberamaido">Kaberamaido</option>
                                    <option value="Kagadi">Kagadi</option>
                                    <option value="Kakumiro">Kakumiro</option>
                                    <option value="Kalangala">Kalangala</option>
                                    <option value="Kaliro">Kaliro</option>
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
                            <div class="form-group">
                                <label class="form-label" for="location">Business Location / Address</label>
                                <input type="text" class="form-control" id="location" name="location"
                                       placeholder="e.g. Nakasero Market, Stall 14" required>
                            </div>
                        </div>

                        <!-- Business -->
                        <div class="ac-section-label">Business Details</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="business_name">Business Name</label>
                                <input type="text" class="form-control" id="business_name" name="business_name"
                                       placeholder="Business name" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="businessTypeSelect">Type of Business</label>
                                <select class="form-select" id="businessTypeSelect" required>
                                    <option value="" disabled selected>Select type</option>
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
                                    <option value="Other">Other (specify)</option>
                                </select>
                                <input type="hidden" name="business_type" id="finalBusinessType">
                            </div>
                            <!-- "Other" text field - hidden until selected -->
                            <div class="form-group" id="otherBusinessTypeField" style="display:none">
                                <label class="form-label" for="otherBusinessInput">Please Specify Business Type</label>
                                <input type="text" class="form-control" id="otherBusinessInput"
                                       placeholder="Describe the business type">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="business_period">How long in business?</label>
                                <select class="form-select" id="business_period" name="business_period" required>
                                    <option value="" disabled selected>Select period</option>
                                    <option value="Less than 6 months">Less than 6 months</option>
                                    <option value="6 months - 1 year">6 months - 1 year</option>
                                    <option value="1 - 2 years">1 - 2 years</option>
                                    <option value="2 - 5 years">2 - 5 years</option>
                                    <option value="More than 5 years">More than 5 years</option>
                                </select>
                            </div>
                        </div>

                        <!-- Sales -->
                        <div class="ac-section-label">Sales Performance (UGX)</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="amount_last">Sales Last Week</label>
                                <select class="form-select" id="amount_last" name="amount_last" required>
                                    <option value="" disabled selected>Select range</option>
                                    <option value="10,000 - 50,000">10,000 - 50,000</option>
                                    <option value="50,000 - 100,000">50,000 - 100,000</option>
                                    <option value="100,000 - 500,000">100,000 - 500,000</option>
                                    <option value="500,000 - 1M">500,000 - 1M</option>
                                    <option value="1 - 2M">1M - 2M</option>
                                    <option value="Above 2M">Above 2M</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="amount_this">Sales Two Weeks Ago</label>
                                <select class="form-select" id="amount_this" name="amount_this" required>
                                    <option value="" disabled selected>Select range</option>
                                    <option value="10,000 - 50,000">10,000 - 50,000</option>
                                    <option value="50,000 - 100,000">50,000 - 100,000</option>
                                    <option value="100,000 - 500,000">100,000 - 500,000</option>
                                    <option value="500,000 - 1M">500,000 - 1M</option>
                                    <option value="1 - 2M">1M - 2M</option>
                                    <option value="Above 2M">Above 2M</option>
                                </select>
                            </div>
                        </div>

                        <!-- Documents -->
                        <div class="ac-section-label">Documents <span class="text-muted fw-400 ms-1" style="text-transform:none;font-size:.72rem">(optional)</span></div>
                        <div class="info-grid mb-2">
                            <div class="form-group">
                                <label class="form-label" for="license">Trading License</label>
                                <input type="file" class="form-control ac-file" id="license" name="license"
                                       accept="image/*,application/pdf">
                                <p class="form-help">Image or PDF - optional</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="stock">Business Photo</label>
                                <input type="file" class="form-control ac-file" id="stock" name="stock"
                                       accept="image/*,application/pdf">
                                <p class="form-help">Photo of shop / stock</p>
                            </div>
                        </div>

                        <div class="ac-pane-footer">
                            <button type="button" class="btn btn-ghost btn-ac-prev" data-prev="pane-personal">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </button>
                            <button type="button" class="btn btn-primary btn-ac-next" data-next="pane-account">
                                Next: NOK &amp; Account <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div><!-- /pane-business -->


            <!-- --------------------------------------------------
                 PANE 3 - Next of Kin & Account Setup
            ------------------------------------------------------ -->
            <div class="ac-pane" id="pane-account" role="tabpanel">
                <div class="loan-tab-card">
                    <div class="loan-tab-header">
                        <div>
                            <h5><i class="fas fa-lock text-primary me-2"></i>Next of Kin &amp; Account Setup</h5>
                            <p>Emergency contact, profile photo, signature, and login password.</p>
                        </div>
                        <span class="badge badge-soft-primary px-3 py-2">Step 3 of 3</span>
                    </div>

                    <div class="ac-form-body">

                        <!-- NOK -->
                        <div class="ac-section-label">Next of Kin</div>
                        <div class="info-grid mb-4">
                            <div class="form-group">
                                <label class="form-label" for="nok_name">Full Name</label>
                                <input type="text" class="form-control" id="nok_name" name="nok_name"
                                       placeholder="Next of kin full name" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="nok_tel">Phone Number</label>
                                <input type="tel" class="form-control" id="nok_tel" name="nok_tel"
                                       placeholder="NOK phone number" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="nok_rel">Relationship</label>
                                <input type="text" class="form-control" id="nok_rel" name="nok_rel"
                                       placeholder="e.g. Spouse, Parent, Sibling" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="profile_img">Profile Photo</label>
                                <input type="file" class="form-control ac-file" id="profile_img" name="profile_img"
                                       accept="image/*,.pdf" required>
                                <p class="form-help">Clear photo of the client</p>
                            </div>
                        </div>

                        <!-- Signature -->
                        <div class="ac-section-label">Applicant Signature</div>
                        <div class="form-group mb-4">

                            <!-- Mode toggle pills -->
                            <div class="ac-sig-mode-row mb-3">
                                <button type="button" class="ac-sig-mode-btn active" id="sig-mode-draw"
                                        data-mode="draw">
                                    <i class="fas fa-pen-nib me-2"></i>Draw Signature
                                </button>
                                <button type="button" class="ac-sig-mode-btn" id="sig-mode-upload"
                                        data-mode="upload">
                                    <i class="fas fa-upload me-2"></i>Upload Signature
                                </button>
                            </div>

                            <!-- DRAW panel -->
                            <div id="sig-panel-draw">
                                <div class="ac-sig-wrapper" id="sig-wrapper">
                                    <canvas id="signature-pad"></canvas>
                                    <div class="ac-sig-placeholder" id="sig-placeholder">
                                        <i class="fas fa-pen-nib"></i>
                                        <span>Sign here</span>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-3 mt-2">
                                    <span class="ac-sig-status" id="sig-status">
                                        <i class="fas fa-pen-nib me-1"></i>No signature drawn
                                    </span>
                                    <button type="button" class="btn btn-danger btn-sm" id="btn-clear-sig">
                                        <i class="fas fa-trash me-1"></i>Clear
                                    </button>
                                </div>
                                <p class="form-help">Use mouse or touch to sign inside the box.</p>
                            </div>

                            <!-- UPLOAD panel -->
                            <div id="sig-panel-upload" style="display:none">
                                <div class="ac-sig-upload-zone" id="sig-upload-zone">
                                    <input type="file" id="sig-upload-input" name="signature_file"
                                           accept="image/*" class="ac-sig-upload-input">
                                    <div class="ac-sig-upload-inner" id="sig-upload-inner">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Click to browse or drag &amp; drop</p>
                                        <span>PNG, JPG, GIF - max 2 MB</span>
                                    </div>
                                    <img id="sig-upload-preview" class="ac-sig-upload-preview" alt="Signature preview" style="display:none">
                                </div>
                                <div class="d-flex align-items-center gap-3 mt-2">
                                    <span class="ac-sig-status" id="sig-upload-status">
                                        <i class="fas fa-image me-1"></i>No file selected
                                    </span>
                                    <button type="button" class="btn btn-danger btn-sm" id="btn-clear-upload"
                                            style="display:none">
                                        <i class="fas fa-trash me-1"></i>Remove
                                    </button>
                                </div>
                                <p class="form-help">Upload a photo or scan of the client's handwritten signature.</p>
                            </div>

                            <!-- Single hidden field that always carries the final base64 value -->
                            <input type="hidden" name="applicant_signature" id="signature-data" required>

                        </div>

                        <!-- Password -->
                        <div class="ac-section-label">Account Password</div>
                        <div class="info-grid mb-2">
                            <div class="form-group">
                                <label class="form-label" for="password">Create Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password"
                                           placeholder="Minimum 6 characters" minlength="6"
                                           style="border-right:none" required>
                                    <span class="input-group-text ac-pwd-toggle"
                                          data-target="password" style="cursor:pointer;border-left:none">
                                        <i class="fas fa-eye fs-xs text-muted"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="con_password">Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="con_password" name="con_password"
                                           placeholder="Re-enter password" minlength="6"
                                           style="border-right:none" required>
                                    <span class="input-group-text ac-pwd-toggle"
                                          data-target="con_password" style="cursor:pointer;border-left:none">
                                        <i class="fas fa-eye fs-xs text-muted"></i>
                                    </span>
                                </div>
                                <p class="form-help ac-pwd-match" id="pwd-match-msg" style="display:none"></p>
                            </div>
                        </div>

                        <div class="ac-pane-footer">
                            <button type="button" class="btn btn-ghost btn-ac-prev" data-prev="pane-business">
                                <i class="fas fa-arrow-left me-2"></i>Back
                            </button>
                            <button type="submit" name="add" class="btn btn-primary" id="btn-submit">
                                <i class="fas fa-user-plus me-2"></i>Register Client
                            </button>
                        </div>
                    </div>
                </div>
            </div><!-- /pane-account -->

        </div><!-- /clientTabsContent -->

    </form>

</div><!-- /container-fluid -->

<!-- -- SCOPED STYLES -------------------------------------------- -->
<style>
/* Pane visibility */
#clientTabsContent .ac-pane        { display:none; opacity:0; transition:opacity .18s ease; }
#clientTabsContent .ac-pane.active { display:block; }
#clientTabsContent .ac-pane.show   { opacity:1; }

/* Section sub-labels */
.ac-section-label {
    font-size:.67rem;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--text-muted);
    border-bottom:1px solid var(--border-light);
    padding-bottom:.38rem;
    margin-bottom:.9rem;
}

/* Form body & footer */
.ac-form-body    { padding:1.4rem; }
.ac-pane-footer  {
    display:flex; align-items:center; justify-content:space-between;
    padding-top:1.25rem; margin-top:1rem;
    border-top:1px solid var(--border-light);
}

/* Step badge on tab buttons */
.ac-step-badge {
    display:inline-flex; align-items:center; justify-content:center;
    width:18px; height:18px; border-radius:50%;
    font-size:.62rem; font-weight:800; margin-left:.42rem;
    background:rgba(255,255,255,.22); color:inherit;
}
.loan-tabs .nav-link:not(.active) .ac-step-badge {
    background:var(--border); color:var(--text-muted);
}

/* File input */
.ac-file { cursor:pointer; }

/* Signature pad */
.ac-sig-wrapper {
    width:100%; height:160px;
    border:1.5px solid var(--border);
    border-radius:var(--r);
    background:#fafafa;
    position:relative; overflow:hidden; cursor:crosshair;
    transition:border-color .2s;
}
.ac-sig-wrapper:hover { border-color:var(--primary); }
.ac-sig-wrapper canvas { width:100%; height:100%; display:block; }

/* Placeholder hint inside canvas */
.ac-sig-placeholder {
    position:absolute; inset:0;
    display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    gap:.35rem; pointer-events:none;
    color:var(--text-muted); opacity:.35;
    transition:opacity .2s;
}
.ac-sig-placeholder i   { font-size:1.6rem; }
.ac-sig-placeholder span { font-size:.78rem; font-weight:600; }
.ac-sig-wrapper.has-sig .ac-sig-placeholder { opacity:0; }

.ac-sig-status {
    font-size:.76rem; font-weight:600;
    color:var(--text-muted);
}
.ac-sig-status.signed { color:var(--success); }

/* Mode toggle row */
.ac-sig-mode-row {
    display:flex; gap:.5rem;
}
.ac-sig-mode-btn {
    display:inline-flex; align-items:center;
    padding:.48rem 1rem;
    border:1.5px solid var(--border);
    border-radius:var(--r);
    background:var(--white);
    color:var(--text-secondary);
    font-size:.82rem; font-weight:600;
    cursor:pointer; transition:var(--t);
}
.ac-sig-mode-btn:hover {
    border-color:var(--primary-light);
    background:var(--primary-xlight);
    color:var(--primary);
}
.ac-sig-mode-btn.active {
    border-color:var(--primary);
    background:var(--primary);
    color:#fff;
    box-shadow:0 2px 8px rgba(18,50,153,.2);
}

/* Upload drop zone */
.ac-sig-upload-zone {
    width:100%; height:160px;
    border:2px dashed var(--border);
    border-radius:var(--r);
    background:#fafafa;
    position:relative; overflow:hidden;
    transition:border-color .2s, background .2s;
    cursor:pointer;
}
.ac-sig-upload-zone:hover,
.ac-sig-upload-zone.drag-over {
    border-color:var(--primary);
    background:var(--primary-xlight);
}
.ac-sig-upload-input {
    position:absolute; inset:0;
    opacity:0; cursor:pointer; width:100%; height:100%;
}
.ac-sig-upload-inner {
    position:absolute; inset:0;
    display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    gap:.3rem; pointer-events:none;
    color:var(--text-muted);
}
.ac-sig-upload-inner i    { font-size:2rem; opacity:.4; }
.ac-sig-upload-inner p    { font-size:.82rem; font-weight:600; margin:0; }
.ac-sig-upload-inner span { font-size:.7rem; color:var(--text-muted); }
.ac-sig-upload-preview {
    width:100%; height:100%;
    object-fit:contain; padding:.5rem;
    background:#fff;
}

/* Password toggle */
.ac-pwd-toggle { transition:var(--t); }
.ac-pwd-toggle:hover { background:var(--primary-xlight); color:var(--primary); }

/* Validation */
.form-control.ac-invalid,
.form-select.ac-invalid {
    border-color:var(--danger) !important;
    box-shadow:0 0 0 3px rgba(220,38,38,.1) !important;
}
.ac-sig-wrapper.ac-invalid { border-color:var(--danger) !important; }
.ac-pwd-match.error { color:var(--danger); display:block !important; }
.ac-pwd-match.ok    { color:var(--success); display:block !important; }
</style>

<!-- -- SCRIPTS --------------------------------------------------- -->
<script>
(() => {
    'use strict';

    /* -- DOM refs ------------------------------------------------ */
    const tabNav   = document.getElementById('clientTabs');
    const tabBtns  = Array.from(tabNav.querySelectorAll('[data-tab]'));
    const panes    = Array.from(document.querySelectorAll('#clientTabsContent .ac-pane'));
    const form     = document.getElementById('form-add-client');

    /* ----------------------------------------
       TAB ENGINE
    ---------------------------------------- */
    function activate(paneId) {
        tabBtns.forEach(b => {
            const on = b.dataset.tab === paneId;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', String(on));
        });
        panes.forEach(p => {
            if (p.id === paneId) {
                p.classList.add('active');
                requestAnimationFrame(() => p.classList.add('show'));
            } else {
                p.classList.remove('show', 'active');
            }
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    tabBtns.forEach(b => b.addEventListener('click', () => activate(b.dataset.tab)));

    /* ----------------------------------------
       BUSINESS TYPE - "Other" toggle
    ---------------------------------------- */
    const bizSelect     = document.getElementById('businessTypeSelect');
    const otherField    = document.getElementById('otherBusinessTypeField');
    const otherInput    = document.getElementById('otherBusinessInput');
    const finalBizType  = document.getElementById('finalBusinessType');

    function syncBizType() {
        const val = bizSelect.value;
        const isOther = val === 'Other';
        otherField.style.display = isOther ? 'block' : 'none';
        otherInput.required      = isOther;
        finalBizType.value       = isOther ? otherInput.value.trim() : val;
    }

    bizSelect.addEventListener('change', syncBizType);
    otherInput.addEventListener('input',  syncBizType);
    syncBizType();

    /* ----------------------------------------
       VALIDATION
    ---------------------------------------- */
    function clearInvalid(el) {
        el.classList.remove('ac-invalid');
    }

    function markInvalid(el) {
        el.classList.add('ac-invalid');
        el.addEventListener('input',  () => clearInvalid(el), { once: true });
        el.addEventListener('change', () => clearInvalid(el), { once: true });
    }

    function validatePane(pane) {
        syncBizType();
        // Sync whichever signature mode is active
        const sigData = document.getElementById('signature-data');
        if (sigData && !sigData.value) {
            // draw mode: try to pull from canvas
            const canvas = document.getElementById('signature-pad');
            if (canvas) { try { /* value already set by draw events */ } catch(_) {} }
        }

        let ok = true;
        let firstBad = null;

        pane.querySelectorAll('[required]').forEach(el => {
            clearInvalid(el);
            // Skip visually hidden elements (but not hidden inputs with actual required values)
            if (el.type !== 'hidden' && el.offsetParent === null) return;

            const empty = el.type === 'file'
                ? (!el.files || el.files.length === 0)
                : !el.value.trim();

            if (empty) {
                ok = false;
                // For hidden finalBusinessType, highlight the visible control
                if (el.id === 'finalBusinessType') {
                    const target = bizSelect.value === 'Other' ? otherInput : bizSelect;
                    markInvalid(target);
                    if (!firstBad) firstBad = target;
                } else if (el.id === 'signature-data') {
                    document.getElementById('sig-wrapper').classList.add('ac-invalid');
                    if (!firstBad) firstBad = document.getElementById('sig-wrapper');
                } else {
                    markInvalid(el);
                    if (!firstBad) firstBad = el;
                }
            }
        });

        // Password match check (only pane-account)
        if (pane.id === 'pane-account') {
            const pw1 = document.getElementById('password');
            const pw2 = document.getElementById('con_password');
            if (pw1 && pw2 && pw1.value && pw2.value && pw1.value !== pw2.value) {
                ok = false;
                markInvalid(pw2);
                if (!firstBad) firstBad = pw2;
                showPwdMatchMsg('Passwords do not match.', 'error');
            }
        }

        if (firstBad) firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return ok;
    }

    /* Next buttons */
    document.querySelectorAll('.btn-ac-next').forEach(btn => {
        btn.addEventListener('click', () => {
            const pane = btn.closest('.ac-pane');
            if (validatePane(pane)) activate(btn.dataset.next);
        });
    });

    /* Prev buttons */
    document.querySelectorAll('.btn-ac-prev').forEach(btn => {
        btn.addEventListener('click', () => activate(btn.dataset.prev));
    });

    /* Final submit */
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        syncBizType();

        let firstBadPane = null;
        panes.forEach(pane => {
            if (!firstBadPane && !validatePane(pane)) firstBadPane = pane.id;
        });

        if (firstBadPane) {
            activate(firstBadPane);
            return;
        }

        const btn  = document.getElementById('btn-submit');
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Registering…';
        btn.disabled  = true;

        fetch('added_client.php', { method: 'POST', body: new FormData(form) })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    showMsg(data.message || 'Client registered!', 'success');
                    if (data.redirect) setTimeout(() => location.href = data.redirect, data.delay || 2000);
                } else {
                    showMsg(data.message || 'Registration failed.', 'danger');
                }
            })
            .catch(() => showMsg('Server error. Please try again.', 'danger'))
            .finally(() => { btn.innerHTML = orig; btn.disabled = false; });
    });

    /* ----------------------------------------
       ALERT BANNER
    ---------------------------------------- */
    function showMsg(text, type) {
        const area = document.getElementById('ac-message');
        const span = document.getElementById('ac-message-text');
        area.className = `alert alert-${type} mb-4`;
        span.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>${text}`;
        area.style.display = 'block';
        area.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (type === 'success') setTimeout(() => area.style.display = 'none', 5000);
    }

    /* ----------------------------------------
       PASSWORD TOGGLE & MATCH
    ---------------------------------------- */
    document.querySelectorAll('.ac-pwd-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = `fas fa-eye${show ? '-slash' : ''} fs-xs text-muted`;
        });
    });

    function showPwdMatchMsg(msg, type) {
        const el = document.getElementById('pwd-match-msg');
        if (!el) return;
        el.textContent  = msg;
        el.className    = `ac-pwd-match ${type}`;
    }

    document.getElementById('con_password')?.addEventListener('input', function () {
        const pw1 = document.getElementById('password').value;
        if (!this.value) { showPwdMatchMsg('', ''); return; }
        pw1 === this.value
            ? showPwdMatchMsg('Passwords match ?', 'ok')
            : showPwdMatchMsg('Passwords do not match.', 'error');
    });

    /* ----------------------------------------
       SIGNATURE - draw OR upload
    ---------------------------------------- */
    (function initSignature() {
        const drawPanel    = document.getElementById('sig-panel-draw');
        const uploadPanel  = document.getElementById('sig-panel-upload');
        const modeBtns     = document.querySelectorAll('.ac-sig-mode-btn');
        const dataInput    = document.getElementById('signature-data');

        /* -- mode toggle ---------------------------------------- */
        let currentMode = 'draw';

        modeBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                currentMode = btn.dataset.mode;
                modeBtns.forEach(b => b.classList.toggle('active', b === btn));
                drawPanel.style.display   = currentMode === 'draw'   ? '' : 'none';
                uploadPanel.style.display = currentMode === 'upload' ? '' : 'none';
                // Reset the hidden value so validation re-checks the active mode
                syncHiddenValue();
            });
        });

        /* -- hidden field sync ---------------------------------- */
        function syncHiddenValue() {
            if (currentMode === 'draw') {
                saveDraw();
            } else {
                dataInput.value = uploadBase64 || '';
            }
        }

        /* ------------------------
           DRAW mode
        ------------------------ */
        const wrapper     = document.getElementById('sig-wrapper');
        const canvas      = document.getElementById('signature-pad');
        const statusEl    = document.getElementById('sig-status');
        const clearBtn    = document.getElementById('btn-clear-sig');
        const placeholder = document.getElementById('sig-placeholder');

        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        let drawing = false, hasDraw = false;

        function resizeDraw() {
            const r   = wrapper.getBoundingClientRect();
            const old = hasDraw ? canvas.toDataURL() : null;
            canvas.width  = r.width;
            canvas.height = r.height;
            setCtx();
            if (old) { const img = new Image(); img.onload = () => ctx.drawImage(img, 0, 0); img.src = old; }
        }

        function setCtx() {
            ctx.strokeStyle = '#1a1a2e';
            ctx.lineWidth   = 2.2;
            ctx.lineCap     = 'round';
            ctx.lineJoin    = 'round';
        }

        function canvasPos(e) {
            const r = canvas.getBoundingClientRect();
            return e.touches
                ? { x: e.touches[0].clientX - r.left, y: e.touches[0].clientY - r.top }
                : { x: e.clientX - r.left,             y: e.clientY - r.top };
        }

        function startDraw(e) {
            e.preventDefault();
            drawing = true; hasDraw = true;
            const p = canvasPos(e);
            ctx.beginPath(); ctx.moveTo(p.x, p.y);
            wrapper.classList.add('has-sig');
            wrapper.classList.remove('ac-invalid');
            updateDrawStatus();
        }

        function onDraw(e) {
            e.preventDefault();
            if (!drawing) return;
            const p = canvasPos(e);
            ctx.lineTo(p.x, p.y); ctx.stroke();
        }

        function stopDraw() {
            if (!drawing) return;
            drawing = false;
            ctx.beginPath();
            saveDraw();
        }

        function saveDraw() {
            if (!hasDraw) { dataInput.value = ''; return; }
            try { dataInput.value = canvas.toDataURL('image/png'); } catch (_) {}
        }

        function updateDrawStatus() {
            if (!statusEl) return;
            statusEl.className = hasDraw ? 'ac-sig-status signed' : 'ac-sig-status';
            statusEl.innerHTML = hasDraw
                ? '<i class="fas fa-check-circle me-1"></i>Signature captured'
                : '<i class="fas fa-pen-nib me-1"></i>No signature drawn';
        }

        clearBtn?.addEventListener('click', () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            dataInput.value = '';
            hasDraw = false;
            wrapper.classList.remove('has-sig');
            updateDrawStatus();
        });

        canvas.addEventListener('mousedown',  startDraw);
        canvas.addEventListener('mousemove',  onDraw);
        canvas.addEventListener('mouseup',    stopDraw);
        canvas.addEventListener('mouseleave', stopDraw);
        canvas.addEventListener('touchstart', startDraw, { passive: false });
        canvas.addEventListener('touchmove',  onDraw,    { passive: false });
        canvas.addEventListener('touchend',   stopDraw,  { passive: false });
        window.addEventListener('resize', resizeDraw);
        resizeDraw();
        updateDrawStatus();

        /* ------------------------
           UPLOAD mode
        ------------------------ */
        const uploadZone    = document.getElementById('sig-upload-zone');
        const uploadInput   = document.getElementById('sig-upload-input');
        const uploadInner   = document.getElementById('sig-upload-inner');
        const uploadPreview = document.getElementById('sig-upload-preview');
        const uploadStatus  = document.getElementById('sig-upload-status');
        const clearUpload   = document.getElementById('btn-clear-upload');

        let uploadBase64 = '';

        function loadUploadFile(file) {
            if (!file || !file.type.startsWith('image/')) {
                showMsg('Please select a valid image file.', 'danger');
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                showMsg('Signature image must be under 2 MB.', 'danger');
                return;
            }
            const reader = new FileReader();
            reader.onload = e => {
                uploadBase64 = e.target.result;
                uploadPreview.src     = uploadBase64;
                uploadPreview.style.display = 'block';
                uploadInner.style.display   = 'none';
                clearUpload.style.display   = '';
                uploadStatus.className = 'ac-sig-status signed';
                uploadStatus.innerHTML = '<i class="fas fa-check-circle me-1"></i>Signature uploaded';
                uploadZone.classList.remove('ac-invalid');
                if (currentMode === 'upload') dataInput.value = uploadBase64;
            };
            reader.readAsDataURL(file);
        }

        uploadInput.addEventListener('change', () => {
            if (uploadInput.files[0]) loadUploadFile(uploadInput.files[0]);
        });

        // Drag-and-drop
        uploadZone.addEventListener('dragover', e => {
            e.preventDefault();
            uploadZone.classList.add('drag-over');
        });
        uploadZone.addEventListener('dragleave', () => uploadZone.classList.remove('drag-over'));
        uploadZone.addEventListener('drop', e => {
            e.preventDefault();
            uploadZone.classList.remove('drag-over');
            const file = e.dataTransfer.files[0];
            if (file) loadUploadFile(file);
        });

        clearUpload?.addEventListener('click', () => {
            uploadBase64 = '';
            uploadInput.value      = '';
            uploadPreview.src      = '';
            uploadPreview.style.display = 'none';
            uploadInner.style.display   = '';
            clearUpload.style.display   = 'none';
            uploadStatus.className = 'ac-sig-status';
            uploadStatus.innerHTML = '<i class="fas fa-image me-1"></i>No file selected';
            if (currentMode === 'upload') dataInput.value = '';
        });
    })();

    /* -- Boot --------------------------------------------------- */
    activate('pane-personal');
})();
</script>

<?php require 'footer.php'; ?>
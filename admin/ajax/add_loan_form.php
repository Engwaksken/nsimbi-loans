<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session and database
|--------------------------------------------------------------------------
*/
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

if (empty($_SESSION['logged_in'])) {
    http_response_code(403);

    exit(
        '<div class="alert alert-danger">
            <i class="fas fa-lock"></i>
            Your session has expired. Please sign in again.
        </div>'
    );
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);

    exit(
        '<div class="alert alert-danger">
            <i class="fas fa-database"></i>
            Database connection was not established.
        </div>'
    );
}

$conn->set_charset('utf8mb4');

date_default_timezone_set('Africa/Kampala');

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/
function addLoanEscape(mixed $value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function addLoanTableHasColumn(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $sql = "
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ss',
        $table,
        $column
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $exists = false;

    if ($result && ($row = $result->fetch_assoc())) {
        $exists = (int)($row['total'] ?? 0) > 0;
    }

    $stmt->close();

    return $exists;
}

/*
|--------------------------------------------------------------------------
| Determine whether loan.client_type exists
|--------------------------------------------------------------------------
| When this column exists, duplicate-loan checks can distinguish customers,
| members and startups that happen to share the same numerical ID.
|--------------------------------------------------------------------------
*/
$loanHasClientType = addLoanTableHasColumn(
    $conn,
    'loan',
    'client_type'
);

/*
|--------------------------------------------------------------------------
| Determine startup primary key
|--------------------------------------------------------------------------
| Supports databases using either startups.id or startups.startup_id.
|--------------------------------------------------------------------------
*/
$startupIdColumn = addLoanTableHasColumn(
    $conn,
    'startups',
    'id'
) ? 'id' : 'startup_id';

/*
|--------------------------------------------------------------------------
| Eligible client collections
|--------------------------------------------------------------------------
*/
$customers = [];
$members   = [];
$startups  = [];
$organisations = [];

/*
|--------------------------------------------------------------------------
| Customer query
|--------------------------------------------------------------------------
*/
if ($loanHasClientType) {
    $customerDuplicateCondition = "
        AND NOT EXISTS (
            SELECT 1
            FROM loan l
            WHERE l.client_id = c.user_id
              AND l.client_type = 'customer'
              AND UPPER(TRIM(l.status)) IN (
                  'PENDING',
                  'OUTSTANDING',
                  'OVERDUE',
                  'ACTIVE',
                  'APPROVED'
              )
        )
    ";
} else {
    $customerDuplicateCondition = "
        AND NOT EXISTS (
            SELECT 1
            FROM loan l
            WHERE l.client_id = c.user_id
              AND UPPER(TRIM(l.status)) IN (
                  'PENDING',
                  'OUTSTANDING',
                  'OVERDUE',
                  'ACTIVE',
                  'APPROVED'
              )
        )
    ";
}

$customerSql = "
    SELECT
        c.user_id AS client_id,
        TRIM(
            CONCAT(
                COALESCE(u.fname, ''),
                ' ',
                COALESCE(u.lname, '')
            )
        ) AS client_name,
        COALESCE(c.phone, '') AS phone,
        COALESCE(c.loan_limit, 0) AS loan_limit,
        COALESCE(c.business_name, '') AS organisation
    FROM customers c
    INNER JOIN user u
        ON u.id = c.user_id
    WHERE u.status = 1
      AND c.active = 1
      {$customerDuplicateCondition}
    ORDER BY
        u.fname ASC,
        u.lname ASC
";

$customerResult = $conn->query($customerSql);

if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $clientId = (int)($row['client_id'] ?? 0);
        $name     = trim((string)($row['client_name'] ?? ''));
        $phone    = trim((string)($row['phone'] ?? ''));
        $business = trim((string)($row['organisation'] ?? ''));

        if ($clientId <= 0) {
            continue;
        }

        if ($name === '') {
            $name = 'Customer #' . $clientId;
        }

        $label = $name;

        if ($phone !== '') {
            $label .= ' - ' . $phone;
        }

        if ($business !== '') {
            $label .= ' (' . $business . ')';
        }

        $customers[] = [
            'id'    => $clientId,
            'label' => $label,
            'limit' => (float)($row['loan_limit'] ?? 0),
        ];
    }

    $customerResult->free();
} else {
    error_log(
        '[Add Loan Form] Customer query failed: ' .
        $conn->error
    );
}

/*
|--------------------------------------------------------------------------
| Member/staff query
|--------------------------------------------------------------------------
*/
if ($loanHasClientType) {
    $memberDuplicateCondition = "
        AND NOT EXISTS (
            SELECT 1
            FROM loan l
            WHERE l.client_id = m.user_id
              AND l.client_type = 'member'
              AND UPPER(TRIM(l.status)) IN (
                  'PENDING',
                  'OUTSTANDING',
                  'OVERDUE',
                  'ACTIVE',
                  'APPROVED'
              )
        )
    ";
} else {
    $memberDuplicateCondition = "
        AND NOT EXISTS (
            SELECT 1
            FROM loan l
            WHERE l.client_id = m.user_id
              AND UPPER(TRIM(l.status)) IN (
                  'PENDING',
                  'OUTSTANDING',
                  'OVERDUE',
                  'ACTIVE',
                  'APPROVED'
              )
        )
    ";
}

$memberSql = "
    SELECT
        m.user_id AS client_id,
        TRIM(
            CONCAT(
                COALESCE(u.fname, ''),
                ' ',
                COALESCE(u.lname, '')
            )
        ) AS client_name,
        COALESCE(m.phone, '') AS phone,
        COALESCE(m.loan_limit, 0) AS loan_limit,
        COALESCE(m.employer, '') AS organisation
    FROM members m
    INNER JOIN user u
        ON u.id = m.user_id
    WHERE u.status = 1
      {$memberDuplicateCondition}
    ORDER BY
        u.fname ASC,
        u.lname ASC
";

$memberResult = $conn->query($memberSql);

if ($memberResult) {
    while ($row = $memberResult->fetch_assoc()) {
        $clientId = (int)($row['client_id'] ?? 0);
        $name     = trim((string)($row['client_name'] ?? ''));
        $phone    = trim((string)($row['phone'] ?? ''));
        $employer = trim((string)($row['organisation'] ?? ''));

        if ($clientId <= 0) {
            continue;
        }

        if ($name === '') {
            $name = 'Member #' . $clientId;
        }

        $label = $name;

        if ($phone !== '') {
            $label .= ' - ' . $phone;
        }

        if ($employer !== '') {
            $label .= ' (' . $employer . ')';
        }

        $members[] = [
            'id'    => $clientId,
            'label' => $label,
            'limit' => (float)($row['loan_limit'] ?? 0),
        ];
    }

    $memberResult->free();
} else {
    error_log(
        '[Add Loan Form] Member query failed: ' .
        $conn->error
    );
}

/*
|--------------------------------------------------------------------------
| Startup query
|--------------------------------------------------------------------------
*/
if ($loanHasClientType) {
    $startupDuplicateCondition = "
        AND NOT EXISTS (
            SELECT 1
            FROM loan l
            WHERE l.client_id = s.{$startupIdColumn}
              AND l.client_type = 'startup'
              AND UPPER(TRIM(l.status)) IN (
                  'PENDING',
                  'OUTSTANDING',
                  'OVERDUE',
                  'ACTIVE',
                  'APPROVED'
              )
        )
    ";
} else {
    $startupDuplicateCondition = "
        AND NOT EXISTS (
            SELECT 1
            FROM loan l
            WHERE l.client_id = s.{$startupIdColumn}
              AND UPPER(TRIM(l.status)) IN (
                  'PENDING',
                  'OUTSTANDING',
                  'OVERDUE',
                  'ACTIVE',
                  'APPROVED'
              )
        )
    ";
}

$startupStatusCondition = '';

if (addLoanTableHasColumn($conn, 'startups', 'status')) {
    $startupStatusCondition = "
        AND (
            s.status IS NULL
            OR TRIM(s.status) = ''
            OR LOWER(TRIM(s.status)) = 'active'
        )
    ";
}

$startupSql = "
    SELECT
        s.{$startupIdColumn} AS client_id,
        COALESCE(
            NULLIF(TRIM(s.company_name), ''),
            NULLIF(TRIM(s.ceo_name), ''),
            CONCAT('Startup #', s.{$startupIdColumn})
        ) AS client_name,
        COALESCE(s.company_name, '') AS company_name,
        COALESCE(s.ceo_name, '') AS ceo_name,
        COALESCE(s.phone, '') AS phone
    FROM startups s
    WHERE 1 = 1
      {$startupStatusCondition}
      {$startupDuplicateCondition}
    ORDER BY
        s.company_name ASC,
        s.ceo_name ASC
";

$startupResult = $conn->query($startupSql);

if ($startupResult) {
    while ($row = $startupResult->fetch_assoc()) {
        $clientId   = (int)($row['client_id'] ?? 0);
        $company    = trim((string)($row['company_name'] ?? ''));
        $clientName = trim((string)($row['client_name'] ?? ''));
        $ceoName    = trim((string)($row['ceo_name'] ?? ''));
        $phone      = trim((string)($row['phone'] ?? ''));

        if ($clientId <= 0) {
            continue;
        }

        $label = $company !== ''
            ? $company
            : $clientName;

        if ($label === '') {
            $label = 'Startup #' . $clientId;
        }

        if (
            $ceoName !== '' &&
            strcasecmp($ceoName, $label) !== 0
        ) {
            $label .= ' - ' . $ceoName;
        }

        if ($phone !== '') {
            $label .= ' - ' . $phone;
        }

        $startups[] = [
            'id'    => $clientId,
            'label' => $label,
            'limit' => null,
        ];
    }

    $startupResult->free();
} else {
    error_log(
        '[Add Loan Form] Startup query failed: ' .
        $conn->error
    );
}

$totalEligible = count($customers)
    + count($members)
    + count($startups);

// Keep organisation clients distinct from startup applicants.
if (addLoanTableHasColumn($conn, 'organisations', 'id')) {
    $organisationResult = $conn->query("SELECT id, company_name, ceo_name, phone FROM organisations WHERE status = 'active' ORDER BY company_name");
    if ($organisationResult) {
        while ($row = $organisationResult->fetch_assoc()) {
            $organisations[] = [
                'id' => (int)$row['id'],
                'label' => trim((string)$row['company_name']) . ' — ' . trim((string)$row['ceo_name']) . ' — ' . trim((string)$row['phone']),
            ];
        }
        $organisationResult->free();
    }
}
$totalEligible += count($organisations);
?>

<form
    id="form-add-loan"
    method="post"
    action="/loans/admin/includes/process-new-loan.php"
    autocomplete="off"
    onsubmit="
        event.preventDefault();

        var form = this;
        var clientRef = form.querySelector('[name=client_ref]');
        var clientType = form.querySelector('[name=client_type]');
        var clientId = form.querySelector('[name=client_id]');
        var loanPeriod = form.querySelector('[name=loan_period]');
        var amount = form.querySelector('[name=amount]');
        var submitButton = form.querySelector('[type=submit]');
        var messageBox = form.querySelector('[data-loan-message]');

        var selectedParts = clientRef && clientRef.value
            ? clientRef.value.split(':')
            : [];

        clientType.value = selectedParts[0] || '';
        clientId.value = selectedParts[1] || '';

        messageBox.style.display = 'none';
        messageBox.textContent = '';

        if (!clientType.value || !clientId.value) {
            messageBox.className = 'alert alert-danger';
            messageBox.textContent =
                'Please select a valid client.';
            messageBox.style.display = 'block';

            return false;
        }

        var selectedPeriod = parseInt(
            loanPeriod.value,
            10
        );

        /*
         * Validate the loan period according to the selected client type.
         *
         * Customer: 1-6 months
         * Member / Staff: fixed at 3 months
         * Startup: 24-36 months (2-3 years)
         */
        if (
            clientType.value === 'customer' &&
            (
                !selectedPeriod ||
                selectedPeriod < 1 ||
                selectedPeriod > 6
            )
        ) {
            messageBox.className = 'alert alert-danger';
            messageBox.textContent =
                'Customer loans must use a period between 1 and 6 months.';
            messageBox.style.display = 'block';

            loanPeriod.focus();

            return false;
        }

        if (
            clientType.value === 'member' &&
            selectedPeriod !== 3
        ) {
            messageBox.className = 'alert alert-danger';
            messageBox.textContent =
                'Staff and member loans must use a 3-month period.';
            messageBox.style.display = 'block';

            loanPeriod.focus();

            return false;
        }

        if (
            (clientType.value === 'startup' || clientType.value === 'organisation') &&
            (
                !selectedPeriod ||
                selectedPeriod < 24 ||
                selectedPeriod > 36
            )
        ) {
            messageBox.className = 'alert alert-danger';
            messageBox.textContent =
                (clientType.value === 'organisation' ? 'Organisation' : 'Startup') + ' loans must use a period between 24 and 36 months (2 to 3 years).';
            messageBox.style.display = 'block';

            loanPeriod.focus();

            return false;
        }

        var loanAmount = parseFloat(
            amount.value
        );

        if (
            !loanAmount ||
            loanAmount < 1000
        ) {
            messageBox.className = 'alert alert-danger';
            messageBox.textContent =
                'Loan amount must be at least UGX 1,000.';
            messageBox.style.display = 'block';

            amount.focus();

            return false;
        }

        var originalButtonHtml = submitButton.innerHTML;

        submitButton.disabled = true;
        submitButton.innerHTML =
            '<i class=\'fas fa-spinner fa-spin\'></i> Creating loan...';

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            return response.text().then(function (responseText) {
                var result;

                try {
                    result = JSON.parse(responseText);
                } catch (error) {
                    console.error(
                        'Invalid server response:',
                        responseText
                    );

                    throw new Error(
                        'The server returned an invalid response. Check the PHP error log.'
                    );
                }

                if (!response.ok || !result.success) {
                    throw new Error(
                        result.message ||
                        'The loan could not be created.'
                    );
                }

                return result;
            });
        })
        .then(function (result) {
            messageBox.className = 'alert alert-success';
            messageBox.textContent = result.message;
            messageBox.style.display = 'block';

            submitButton.innerHTML =
                '<i class=\'fas fa-check\'></i> Loan created';

            setTimeout(function () {
                if (
                    window.Modal &&
                    typeof window.Modal.close === 'function'
                ) {
                    window.Modal.close('m-add-loan');
                }

                window.location.href =
                    result.redirect ||
                    '/loans/admin/pending_loans.php';
            }, 800);
        })
        .catch(function (error) {
            console.error(error);

            messageBox.className = 'alert alert-danger';
            messageBox.textContent =
                error && error.message
                    ? error.message
                    : 'The loan could not be created.';

            messageBox.style.display = 'block';

            submitButton.disabled = false;
            submitButton.innerHTML = originalButtonHtml;
        });

        return false;
    "
>
    <div
        class="alert"
        data-loan-message
        role="alert"
        aria-live="polite"
        style="display:none;margin-bottom:1rem"
    ></div>

    <?php if ($totalEligible === 0): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i>

            No eligible clients are currently available. Clients with pending
            or open loans are not shown.
        </div>
    <?php endif; ?>

    <div class="grid-2">

        <!-- Client -->
        <div
            class="form-group"
            style="grid-column:1/-1"
        >
            <label
                class="form-label"
                for="client_ref"
            >
                Select Client
                <span class="text-danger">*</span>
            </label>

            <select
                name="client_ref"
                id="client_ref"
                class="form-select"
                required
                onchange="
                    var form = this.form;
                    var parts = (this.value || '').split(':');
                    var clientType = parts[0] || '';
                    var clientId = parts[1] || '';
                    var loanPeriod = form.querySelector('[name=loan_period]');
                    var rateDisplay = form.querySelector('[data-interest-rate]');
                    var periodHelp = form.querySelector('[data-period-help]');

                    form.querySelector('[name=client_type]').value =
                        clientType;

                    form.querySelector('[name=client_id]').value =
                        clientId;

                    loanPeriod.innerHTML = '';

                    var placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent =
                        ' Select loan period ';

                    loanPeriod.appendChild(placeholder);

                    var addMonth = function (month) {
                        var option = document.createElement('option');

                        option.value = String(month);
                        option.textContent =
                            month + (month === 1 ? ' month' : ' months');

                        loanPeriod.appendChild(option);
                    };

                    if (clientType === 'member') {
                        addMonth(3);

                        loanPeriod.value = '3';
                        rateDisplay.value = '10%';

                        periodHelp.textContent =
                            'Staff and member loans are fixed at 3 months.';
                    } else if (clientType === 'customer') {
                        for (var month = 1; month <= 6; month++) {
                            addMonth(month);
                        }

                        rateDisplay.value =
                            'Select loan period';

                        periodHelp.textContent =
                            'Customer loans can run from 1 to 6 months.';
                    } else if (clientType === 'startup' || clientType === 'organisation') {
                        /*
                         * Startup financing runs from 2 to 3 years.
                         * Values are stored/submitted in months so the
                         * processing endpoint receives 24-36.
                         */
                        for (
                            var startupMonth = 24;
                            startupMonth <= 36;
                            startupMonth++
                        ) {
                            addMonth(startupMonth);
                        }

                        rateDisplay.value = '10%';

                        periodHelp.textContent = clientType === 'organisation'
                            ? 'Organisation loans can run from 24 to 36 months (2 to 3 years) at 10% interest.'
                            : 'Startup loans can run from 24 to 36 months (2 to 3 years) at 10% interest.';
                    } else {
                        rateDisplay.value = '';

                        periodHelp.textContent =
                            'Select a client to view available periods.';
                    }
                "
            >
                <option value="">
                     Choose an eligible client 
                </option>

                <?php if ($customers): ?>
                    <optgroup label="Customers">
                        <?php foreach ($customers as $customer): ?>
                            <option
                                value="customer:<?= (int)$customer['id'] ?>"
                            >
                                <?= addLoanEscape($customer['label']) ?>

                                <?php if ((float)$customer['limit'] > 0): ?>
                                     Limit: UGX
                                    <?= number_format((float)$customer['limit']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>

                <?php if ($members): ?>
                    <optgroup label="Members / Staff">
                        <?php foreach ($members as $member): ?>
                            <option
                                value="member:<?= (int)$member['id'] ?>"
                            >
                                <?= addLoanEscape($member['label']) ?>

                                <?php if ((float)$member['limit'] > 0): ?>
                                     Limit: UGX
                                    <?= number_format((float)$member['limit']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>

                <?php if ($startups): ?>
                    <optgroup label="Startups">
                        <?php foreach ($startups as $startup): ?>
                            <option
                                value="startup:<?= (int)$startup['id'] ?>"
                            >
                                <?= addLoanEscape($startup['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php if ($organisations): ?>
                    <optgroup label="Organisations">
                        <?php foreach ($organisations as $organisation): ?>
                            <option value="organisation:<?= (int)$organisation['id'] ?>"><?= addLoanEscape($organisation['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>

            <small class="form-help">
                Only clients without pending or open loans are listed.
            </small>

            <input
                type="hidden"
                name="client_type"
                id="client_type"
                value=""
            >

            <input
                type="hidden"
                name="client_id"
                id="client_id"
                value=""
            >
        </div>

        <!-- Amount -->
        <div class="form-group">
            <label
                class="form-label"
                for="loan_amount"
            >
                Amount (UGX)
                <span class="text-danger">*</span>
            </label>

            <input
                type="number"
                name="amount"
                id="loan_amount"
                class="form-control"
                placeholder="For example: 500000"
                min="1000"
                step="1000"
                required
            >
        </div>

        <!-- Loan period -->
        <div class="form-group">
            <label
                class="form-label"
                for="loan_period"
            >
                Loan Period
                <span class="text-danger">*</span>
            </label>

            <select
                name="loan_period"
                id="loan_period"
                class="form-select"
                required
                onchange="
                    var form = this.form;
                    var clientType =
                        form.querySelector('[name=client_type]').value;

                    var rateDisplay =
                        form.querySelector('[data-interest-rate]');

                    if (clientType === 'customer') {
                        if (this.value === '1') {
                            rateDisplay.value = '10%';
                        } else if (this.value !== '') {
                            rateDisplay.value = '6.6%';
                        } else {
                            rateDisplay.value =
                                'Select loan period';
                        }
                    } else if (clientType === 'member') {
                        rateDisplay.value =
                            this.value === '3' ? '10%' : '';
                    } else if (clientType === 'startup' || clientType === 'organisation') {
                        rateDisplay.value =
                            this.value !== '' ? '10%' : '';
                    }
                "
            >
                <option value="">
                    Select a client first
                </option>
            </select>

            <small
                class="form-help"
                data-period-help
            >
                Select a client to view the permitted loan period.
            </small>
        </div>

        <!-- Interest display -->
        <div class="form-group">
            <label
                class="form-label"
                for="interest_rate_display"
            >
                Interest Rate
            </label>

            <input
                type="text"
                id="interest_rate_display"
                class="form-control"
                data-interest-rate
                value=""
                placeholder="Calculated automatically"
                readonly
            >

            <small class="form-help">
                Interest is calculated securely by the server.
            </small>
        </div>

        <!-- Status -->
        <div class="form-group">
            <label class="form-label">
                Application Status
            </label>

            <input
                type="text"
                class="form-control"
                value="Pending approval"
                readonly
            >
        </div>

        <!-- Notes -->
        <div
            class="form-group"
            style="grid-column:1/-1"
        >
            <label
                class="form-label"
                for="loan_notes"
            >
                Loan Purpose or Notes
            </label>

            <textarea
                name="notes"
                id="loan_notes"
                class="form-control"
                rows="3"
                maxlength="1000"
                placeholder="Enter the purpose of the loan"
            ></textarea>
        </div>
    </div>

    <div class="d-flex gap-3 justify-end mt-4">
        <button
            type="button"
            class="btn btn-ghost"
            onclick="
                if (
                    window.Modal &&
                    typeof window.Modal.close === 'function'
                ) {
                    window.Modal.close('m-add-loan');
                }
            "
        >
            Cancel
        </button>

        <button
            type="submit"
            name="add"
            class="btn btn-primary"
            <?= $totalEligible === 0 ? 'disabled' : '' ?>
        >
            <i class="fas fa-check"></i>
            Create Loan
        </button>
    </div>
</form>

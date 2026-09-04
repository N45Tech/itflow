<?php
/*
 * Client Portal
 * User profile
 */

// Read by client/includes/header.php and footer.php - see the note there.
// Must be set before inc_all.php, which pulls the header in.
$portal_load_phone_inputs = true;

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

/*
 * check_login.php runs these through escapeSql(), which is for writing to the
 * database rather than printing. It leaves a backslash in front of any quote in
 * the value - O'Brien renders as O\'Brien - which is why the name was already
 * wrapped in stripslashes() here. Email, PIN and company were printed raw, and
 * the company name had no escaping of any kind. Normalise all four in one place
 * and escape at the point of output.
 */
$contact_name = escapeHtml(stripslashes($session_contact_name));
$contact_email = escapeHtml(stripslashes($session_contact_email));
$contact_pin = escapeHtml(stripslashes($session_contact_pin));
$client_name = escapeHtml(stripslashes($session_client_name));

/*
 * check_login.php loads the handful of contact columns every portal page needs.
 * The rest are only wanted here, so they are fetched on this page rather than
 * added to a query that runs on every request. Scoped to the session contact
 * and their client - nothing here reads an id from the request.
 */
$row = mysqli_fetch_assoc(mysqli_query(
    $mysqli,
    "SELECT contact_department, contact_extension, contact_location_id, contact_mobile,
        contact_mobile_country_code, contact_phone, contact_phone_country_code
    FROM contacts
    WHERE contact_id = $session_contact_id AND contact_client_id = $session_client_id
    LIMIT 1"
));

$contact_title = escapeHtml(stripslashes($session_contact_title));
$contact_department = escapeHtml($row['contact_department']);
$contact_phone_country_code = escapeHtml($row['contact_phone_country_code']);
$contact_extension = escapeHtml($row['contact_extension']);
$contact_mobile_country_code = escapeHtml($row['contact_mobile_country_code']);
$contact_location_id = intval($row['contact_location_id']);

/*
 * Two renderings of the same digits. The tables show them formatted for
 * reading; the modal inputs get formatPhoneNumber(..., false), which is the
 * form-input variant - intl-tel-input runs in separateDialCode mode, so the
 * visible input holds the national number only and the dial code lives in the
 * hidden field beside it.
 */
$contact_phone = escapeHtml(formatPhoneNumber($row['contact_phone'], $row['contact_phone_country_code']));
$contact_mobile = escapeHtml(formatPhoneNumber($row['contact_mobile'], $row['contact_mobile_country_code']));
$contact_phone_input = escapeHtml(formatPhoneNumber($row['contact_phone'], $row['contact_phone_country_code'], false));
$contact_mobile_input = escapeHtml(formatPhoneNumber($row['contact_mobile'], $row['contact_mobile_country_code'], false));

/*
 * The location is joined on client too, so a contact whose location_id points
 * at another client's row - stale data, a moved contact - gets nothing rather
 * than another company's address.
 */
$contact_location = '';
$contact_location_address = '';
if (!empty($contact_location_id)) {
    $location = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT location_address, location_city, location_country, location_name, location_state, location_zip
        FROM locations
        WHERE location_id = $contact_location_id
        AND location_client_id = $session_client_id
        AND location_archived_at IS NULL
        LIMIT 1"
    ));
    if ($location) {
        $contact_location = escapeHtml($location['location_name']);
        $contact_location_address = nl2br(escapeHtml(formatAddress(
            $location['location_address'],
            $location['location_city'],
            $location['location_state'],
            $location['location_zip'],
            $location['location_country']
        )));
    }
}

$login_method = $_SESSION['login_method'] ?? 'local';

if ($login_method === 'local') {
    $login_method_display = 'Password';
} elseif ($login_method === 'azure') {
    $login_method_display = 'Microsoft account';
} else {
    $login_method_display = escapeHtml(ucfirst($login_method));
}

/*
 * These three decide which sections of the portal a contact can reach - the
 * same flags contactCan() switches on - so the answer to "why can't I see
 * invoices" is on this page rather than only in the agent's copy of the record.
 *
 * All three are booleans. The billing row used to be written as
 * ($session_contact_is_billing_contact == $session_contact_id), comparing a
 * bool to a contact id. PHP casts the id to bool for that comparison, so it
 * gave the right answer for every real contact and would only have misreported
 * at id 0 - working by luck rather than by meaning.
 */
$contact_roles = [
    ['Primary', (bool) $session_contact_primary, 'Everything in the portal'],
    ['Billing', (bool) $session_contact_is_billing_contact, 'Invoices, quotes and payments'],
    ['Technical', (bool) $session_contact_is_technical_contact, 'Assets, documents, domains and contacts'],
];

/*
 * Recent activity, both scoped on log_user_id - the portal user this contact
 * signs in as. logAudit() fills that from the $session_user_id global, and
 * login.php deliberately sets it before logging a client login ("Option B" in
 * that file), so both the sign-ins and the actions carry it. An agent working
 * on this client writes their own user id, so their work never appears here.
 *
 * Successful sign-ins only. Failed attempts are logged without a reliable user
 * id - login.php cannot always resolve one from a bad email - so they cannot
 * honestly be attributed to this contact.
 */
$sql_logins = mysqli_query(
    $mysqli,
    "SELECT log_created_at, log_ip FROM logs
    WHERE log_type = 'Client Login'
    AND log_action = 'Success'
    AND log_user_id = $session_user_id
    AND log_client_id = $session_client_id
    ORDER BY log_id DESC
    LIMIT 5"
);

$sql_actions = mysqli_query(
    $mysqli,
    "SELECT log_action, log_created_at, log_description, log_type FROM logs
    WHERE log_user_id = $session_user_id
    AND log_client_id = $session_client_id
    AND log_type != 'Client Login'
    ORDER BY log_id DESC
    LIMIT 5"
);

?>

<header class="n45-page-header">
    <div>
        <h1>Your account</h1>
        <p>Review your portal identity, client responsibilities, and sign-in method.</p>
    </div>
</header>

<section class="n45-account-panel" aria-labelledby="account-details-heading">
    <div class="n45-form-intro">
        <h2 id="account-details-heading">Account details</h2>
        <p>This information is managed as part of your N45 client record.</p>
    </div>

    <dl class="n45-account-grid">
        <div class="n45-account-field"><dt>Name</dt><dd><?= stripslashes(escapeHtml($session_contact_name)) ?></dd></div>
        <div class="n45-account-field"><dt>Email</dt><dd><?= escapeHtml($session_contact_email) ?></dd></div>
        <div class="n45-account-field"><dt>Client</dt><dd><?= escapeHtml($session_client_name) ?></dd></div>
        <div class="n45-account-field"><dt>Support PIN</dt><dd><?= escapeHtml($session_contact_pin) ?></dd></div>
        <div class="n45-account-field"><dt>Primary contact</dt><dd><?= $session_contact_primary == 1 ? 'Yes' : 'No' ?></dd></div>
        <div class="n45-account-field"><dt>Technical contact</dt><dd><?= $session_contact_is_technical_contact ? 'Yes' : 'No' ?></dd></div>
        <div class="n45-account-field"><dt>Billing contact</dt><dd><?= $session_contact_is_billing_contact == $session_contact_id ? 'Yes' : 'No' ?></dd></div>
        <div class="n45-account-field"><dt>Sign-in method</dt><dd><?= escapeHtml(ucfirst($_SESSION['login_method'])) ?></dd></div>
    </dl>

    <?php if ($_SESSION['login_method'] == 'local'): ?>
        <div class="n45-account-security">
            <h2>Password</h2>
            <p class="text-muted">Use at least eight characters and avoid reusing a password from another service.</p>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="mb-3">
                    <label for="newPassword">New password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-lock" aria-hidden="true"></i></span>
                        <input type="password" class="form-control" id="newPassword" minlength="8" required name="new_password" placeholder="Enter a new password" autocomplete="new-password">
                    </div>
                </div>
                <div class="n45-form-actions">
                    <button type="submit" name="edit_profile" class="btn btn-primary"><i class="fas fa-check" aria-hidden="true"></i>Update password</button>
                </div>
            </form>
        </div>
    <?php endif ?>
</section>

<?php
require_once 'includes/footer.php';

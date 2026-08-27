<?php
/*
* Client Portal
* Contact management for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

enforceContactCan('contacts');

$contacts_sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email, contact_primary, contact_technical, contact_billing FROM contacts WHERE contact_client_id = $session_client_id AND contacts.contact_archived_at IS NULL ORDER BY contact_created_at");
?>

    <header class="n45-page-header">
        <div>
            <h1>Contacts</h1>
            <p>Manage who can work with N45 and which client responsibilities they hold.</p>
        </div>
        <div class="n45-page-header-actions">
            <a href="contact_add.php" class="btn btn-primary" role="button"><i class="fas fa-plus" aria-hidden="true"></i>New contact</a>
        </div>
    </header>

    <div class="row">

        <div class="col-md-10">

            <table class="table tabled-bordered border border-dark">
                <thead class="thead-dark">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Roles</th>
                </tr>
                </thead>
                <tbody>

                <?php
                while ($row = mysqli_fetch_assoc($contacts_sql)) {
                    $contact_id = intval($row['contact_id']);
                    $contact_name = escapeHtml($row['contact_name']);
                    $contact_email = escapeHtml($row['contact_email']);
                    $contact_primary = intval($row['contact_primary']);
                    $contact_technical = intval($row['contact_technical']);
                    $contact_billing = intval($row['contact_billing']);

                    $contact_roles_display = '-';
                    if ($contact_primary) {
                        $contact_roles_display = 'Primary contact';
                    } else if ($contact_technical && $contact_billing) {
                        $contact_roles_display = 'Technical & Billing';
                    } else if ($contact_technical) {
                        $contact_roles_display = 'Technical';
                    } else if ($contact_billing) {
                        $contact_roles_display = 'Billing';
                    }

                    ?>

                    <tr>
                        <td><a href="contact_edit.php?id=<?= $contact_id ?>"><?= $contact_name ?></a></td>
                        <td><?= $contact_email ?></td>
                        <td><?= $contact_roles_display ?></td>
                    </tr>

                <?php } ?>

                </tbody>
            </table>

        </div>

    </div>

<?php
require_once "includes/footer.php";

<?php
/*
* Client Portal
* Contact management for PTC / technical contacts
*/

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

enforceContactCan('contacts');

$contacts_sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_email, contact_primary, contact_technical, contact_billing,
    contact_portal_ticket_scope, contact_portal_asset_scope, contact_portal_manage_contacts
    FROM contacts WHERE contact_client_id = $session_client_id AND contacts.contact_archived_at IS NULL ORDER BY contact_created_at");
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

    <div class="n45-table-scroll" tabindex="0" role="region" aria-label="Client contacts">
        <?php if (mysqli_num_rows($contacts_sql) == 0) { ?>
            <?= portalEmptyState('There are no contacts on this account yet.') ?>
        <?php } else { ?>
            <table class="table">
                <thead class="table-dark">
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Portal access</th>
                    <th>Additional permissions</th>
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
                    $contact_portal_role = portalAccessRoleFromScopes($row['contact_portal_ticket_scope'], $row['contact_portal_asset_scope']);
                    $contact_portal_manage_contacts = intval($row['contact_portal_manage_contacts']);

                    $contact_role_display = $contact_portal_role === 'manager' ? 'Portal manager' : 'Portal user';
                    if ($contact_primary) {
                        $contact_role_display = 'Primary contact';
                    }

                    $additional_permissions = [];
                    if ($contact_billing) { $additional_permissions[] = 'Billing'; }
                    if ($contact_technical) { $additional_permissions[] = 'Technical contact'; }
                    if ($contact_portal_manage_contacts) { $additional_permissions[] = 'Manage contacts'; }

                    ?>

                    <tr>
                        <td><a href="contact_edit.php?id=<?= $contact_id ?>"><?= $contact_name ?></a></td>
                        <td><?= $contact_email ?></td>
                        <td><span class="n45-access-badge"><?= escapeHtml($contact_role_display) ?></span></td>
                        <td><?= $additional_permissions ? escapeHtml(implode(' · ', $additional_permissions)) : '<span class="text-muted">None</span>' ?></td>
                    </tr>

                <?php } ?>

                </tbody>
            </table>
        <?php } ?>
    </div>

<?php
require_once "includes/footer.php";

<?php

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

// Contact PINs are verification secrets, not directory fields. An explicit
// projection also prevents future private columns from entering this API.
$contact_fields = 'contact_id, contact_name, contact_title, contact_email,
    contact_phone_country_code, contact_phone, contact_extension,
    contact_mobile_country_code, contact_mobile, contact_photo, contact_notes,
    contact_primary, contact_important, contact_billing, contact_technical,
    contact_portal_ticket_scope, contact_portal_asset_scope,
    contact_portal_manage_contacts, contact_portal_review_access,
    contact_created_at, contact_updated_at, contact_archived_at, contact_accessed_at,
    contact_location_id, contact_vendor_id, contact_user_id, contact_department,
    contact_client_id';

// Specific contact via ID (single)
if (isset($_GET['contact_id'])) {
    $id = intval($_GET['contact_id']);
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE contact_id = '$id' " . apiClientScopeSql('contact_client_id'));

} elseif (isset($_GET['contact_email'])) {
    // Specific contact via email (single)
    $email = mysqli_real_escape_string($mysqli, $_GET['contact_email']);
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE contact_email = '$email' " . apiClientScopeSql('contact_client_id'));

} elseif (isset($_GET['contact_phone_or_mobile'])) {
    // Specific contact via phone number or mobile (single)
    $phone_or_mob = mysqli_real_escape_string($mysqli, $_GET['contact_phone_or_mobile']);
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE (contact_mobile = '$phone_or_mob' OR contact_phone = '$phone_or_mob') " . apiClientScopeSql('contact_client_id') . " ORDER BY contact_id LIMIT 1");

} else {
    // All contacts (by client ID, or all in general if key permits)
    $sql = mysqli_query($mysqli, "SELECT $contact_fields FROM contacts WHERE 1=1 " . apiClientScopeSql('contact_client_id') . " ORDER BY contact_id LIMIT $limit OFFSET $offset");
}

// Output
require_once "../read_output.php";

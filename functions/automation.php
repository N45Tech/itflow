<?php

// Shared identity resolution and incident-ticket helpers for low-code automation.

class AutomationConflictException extends RuntimeException
{
}

function automationDbEscape($value): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $value);
}

function automationDbQuery(string $sql, string $message)
{
    global $mysqli;
    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($message);
    }
    return $result;
}

function automationLimitText($value, int $length): string
{
    return mb_substr(trim((string) $value), 0, $length);
}

function automationNormalizeName($value): string
{
    $value = mb_strtolower(trim((string) $value));
    $value = str_replace('&', ' and ', $value);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false) {
        $value = $ascii;
    }
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function automationCanonicalClientName($value): string
{
    $name = automationLimitText($value, 200);
    $aliases = [
        'n45 technologies' => 'N45 Technology Solutions',
        'n45 tech solutions' => 'N45 Technology Solutions',
    ];
    return $aliases[automationNormalizeName($name)] ?? $name;
}

function automationBool($value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function automationSource($value): string
{
    $source = strtolower(automationLimitText($value, 40));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $source)) {
        throw new InvalidArgumentException('source must contain only lowercase letters, numbers, dots, dashes, or underscores');
    }
    return $source;
}

function automationEntityType($value): string
{
    $type = strtolower(automationLimitText($value, 40));
    if (!preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $type)) {
        throw new InvalidArgumentException('entity_type is invalid');
    }
    return $type;
}

function automationValidHttpUrl($value): string
{
    $url = automationLimitText($value, 500);
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : '';
}

function automationRequirePermission(string $module, int $level, string $message): void
{
    if (!function_exists('lookupUserPermission') || lookupUserPermission($module) < $level) {
        throw new AutomationConflictException($message);
    }
}

function automationMapping(string $source, string $entity_type, string $external_id): ?array
{
    global $mysqli;
    $source_sql = automationDbEscape($source);
    $type_sql = automationDbEscape($entity_type);
    $external_sql = automationDbEscape($external_id);
    $sql = mysqli_query($mysqli, "SELECT * FROM automation_entity_mappings
        WHERE automation_mapping_source = '$source_sql'
        AND automation_mapping_entity_type = '$type_sql'
        AND automation_mapping_external_id = '$external_sql' LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function automationClientRow(int $client_id): ?array
{
    global $mysqli;
    if ($client_id < 1 || (function_exists('apiUserCanAccessClient') && !apiUserCanAccessClient($client_id))) {
        return null;
    }
    $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients
        WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function automationFindClientByName(string $name): array
{
    global $mysqli;
    $normalized = automationNormalizeName(automationCanonicalClientName($name));
    if ($normalized === '') {
        return [];
    }
    $matches = [];
    $scope = function_exists('apiClientScopeSql') ? apiClientScopeSql('client_id') : '';
    $sql = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients
        WHERE client_archived_at IS NULL $scope ORDER BY client_id");
    while ($sql && $row = mysqli_fetch_assoc($sql)) {
        if (automationNormalizeName($row['client_name']) === $normalized) {
            $matches[] = $row;
        }
    }
    return $matches;
}

function automationCreateClient(array $client): array
{
    global $mysqli, $session_is_admin;
    automationRequirePermission('module_client', 2, 'The API user cannot create clients');
    if (empty($session_is_admin)) {
        throw new AutomationConflictException('Automatic client creation requires an administrator API user');
    }
    $name = automationCanonicalClientName($client['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('A client name is required before a client can be created');
    }
    $company = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_currency FROM companies WHERE company_id = 1"));
    $settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_default_net_terms FROM settings WHERE company_id = 1"));
    $currency = automationLimitText(($client['currency_code'] ?? '') ?: ($company['company_currency'] ?? 'USD'), 10);
    $net_terms = intval($client['net_terms'] ?? ($settings['config_default_net_terms'] ?? 30));
    $type = automationLimitText($client['type'] ?? 'Business', 200);
    $website = automationValidHttpUrl($client['website'] ?? '');
    $abbreviation = automationLimitText($client['abbreviation'] ?? '', 6);
    if ($abbreviation === '') {
        $words = preg_split('/\s+/', $name);
        $abbreviation = '';
        foreach ($words as $word) {
            $abbreviation .= mb_substr($word, 0, 1);
        }
        $abbreviation = mb_strtoupper(automationLimitText($abbreviation ?: $name, 6));
    }
    $notes = automationLimitText($client['notes'] ?? 'Created by the N45 automation resolver.', 2000);
    $name_sql = automationDbEscape($name);
    $type_sql = automationDbEscape($type);
    $website_sql = automationDbEscape(preg_replace('(^https?://)', '', $website));
    $currency_sql = automationDbEscape($currency);
    $abbr_sql = automationDbEscape($abbreviation);
    $notes_sql = automationDbEscape($notes);
    automationDbQuery("INSERT INTO clients SET client_name = '$name_sql',
        client_type = '$type_sql', client_website = '$website_sql', client_rate = 0,
        client_currency_code = '$currency_sql', client_net_terms = $net_terms,
        client_abbreviation = '$abbr_sql', client_notes = '$notes_sql', client_accessed_at = NOW()",
        'Could not create the client');
    $client_id = mysqli_insert_id($mysqli);
    logAudit('Automation', 'Create', "Created client $name via automation", $client_id);
    return ['client_id' => $client_id, 'client_name' => $name];
}

function automationLocationRow(int $location_id, int $client_id): ?array
{
    global $mysqli;
    if ($location_id < 1 || $client_id < 1) {
        return null;
    }
    $sql = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_id = $location_id
        AND location_client_id = $client_id AND location_archived_at IS NULL LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function automationFindLocation(array $location, int $client_id): array
{
    global $mysqli;
    $name = automationNormalizeName($location['name'] ?? '');
    $address = automationNormalizeName($location['address'] ?? '');
    $zip = automationNormalizeName($location['zip'] ?? '');
    $matches = [];
    $sql = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_client_id = $client_id
        AND location_archived_at IS NULL ORDER BY location_id");
    while ($sql && $row = mysqli_fetch_assoc($sql)) {
        $name_match = $name !== '' && automationNormalizeName($row['location_name']) === $name;
        $address_match = $address !== '' && automationNormalizeName($row['location_address']) === $address;
        $zip_match = $zip === '' || automationNormalizeName($row['location_zip']) === $zip;
        if ($name_match || ($address_match && $zip_match)) {
            $matches[$row['location_id']] = $row;
        }
    }
    return array_values($matches);
}

function automationCreateLocation(array $location, int $client_id): array
{
    global $mysqli;
    automationRequirePermission('module_client', 2, 'The API user cannot create locations');
    $name = automationLimitText($location['name'] ?? '', 200);
    if ($name === '') {
        $city = automationLimitText($location['city'] ?? '', 200);
        $state = automationLimitText($location['state'] ?? '', 200);
        $name = trim($city . ($city !== '' && $state !== '' ? ', ' : '') . $state);
    }
    if ($name === '') {
        throw new InvalidArgumentException('A location name, or city and state, is required before a location can be created');
    }
    $fields = [
        'name' => $name,
        'description' => automationLimitText($location['description'] ?? '', 1000),
        'country' => automationLimitText($location['country'] ?? 'US', 200),
        'address' => automationLimitText($location['address'] ?? '', 200),
        'city' => automationLimitText($location['city'] ?? '', 200),
        'state' => automationLimitText($location['state'] ?? '', 200),
        'zip' => automationLimitText($location['zip'] ?? '', 200),
        'notes' => automationLimitText($location['notes'] ?? 'Created by the N45 automation resolver.', 2000),
    ];
    foreach ($fields as $key => $value) {
        $fields[$key] = automationDbEscape($value);
    }
    automationDbQuery("INSERT INTO locations SET
        location_name = '{$fields['name']}', location_description = '{$fields['description']}',
        location_country = '{$fields['country']}', location_address = '{$fields['address']}',
        location_city = '{$fields['city']}', location_state = '{$fields['state']}',
        location_zip = '{$fields['zip']}', location_notes = '{$fields['notes']}',
        location_primary = 0, location_client_id = $client_id",
        'Could not create the location');
    $location_id = mysqli_insert_id($mysqli);
    logAudit('Automation', 'Create', "Created location $name via automation", $client_id, $location_id);
    return ['location_id' => $location_id, 'location_name' => $name];
}

function automationAssetRow(int $asset_id, int $client_id): ?array
{
    global $mysqli;
    if ($asset_id < 1 || $client_id < 1) {
        return null;
    }
    $sql = mysqli_query($mysqli, "SELECT assets.*, interface_ip, interface_mac FROM assets
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_id = $asset_id AND asset_client_id = $client_id
        AND asset_archived_at IS NULL LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function automationFindAsset(array $asset, int $client_id, int $location_id): array
{
    global $mysqli;
    $serial = automationNormalizeName($asset['serial'] ?? '');
    $uri = automationValidHttpUrl($asset['uri'] ?? '');
    $name = automationNormalizeName($asset['name'] ?? '');
    $matches = [];
    $sql = mysqli_query($mysqli, "SELECT assets.*, interface_ip, interface_mac FROM assets
        LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
        WHERE asset_client_id = $client_id AND asset_archived_at IS NULL ORDER BY asset_id");
    while ($sql && $row = mysqli_fetch_assoc($sql)) {
        $strong = ($serial !== '' && automationNormalizeName($row['asset_serial']) === $serial)
            || ($uri !== '' && ($row['asset_uri'] === $uri || $row['asset_uri_2'] === $uri));
        $name_match = $name !== '' && automationNormalizeName($row['asset_name']) === $name;
        $location_match = $location_id < 1 || intval($row['asset_location_id']) === $location_id;
        if ($strong || ($name_match && $location_match)) {
            $matches[$row['asset_id']] = $row;
        }
    }
    return array_values($matches);
}

function automationCreateAsset(array $asset, int $client_id, int $location_id): array
{
    global $mysqli;
    automationRequirePermission('module_support', 2, 'The API user cannot create assets');
    $name = automationLimitText($asset['name'] ?? '', 200);
    if ($name === '') {
        throw new InvalidArgumentException('An asset name is required before an asset can be created');
    }
    $values = [
        'name' => $name,
        'description' => automationLimitText($asset['description'] ?? '', 255),
        'type' => automationLimitText($asset['type'] ?? 'Other', 200),
        'make' => automationLimitText($asset['make'] ?? 'Unknown', 200),
        'model' => automationLimitText($asset['model'] ?? '', 200),
        'serial' => automationLimitText($asset['serial'] ?? '', 200),
        'os' => automationLimitText($asset['os'] ?? '', 200),
        'uri' => automationValidHttpUrl($asset['uri'] ?? ''),
        'status' => automationLimitText($asset['status'] ?? 'Active', 200),
        'notes' => automationLimitText($asset['notes'] ?? 'Created by the N45 automation resolver.', 4000),
        'ip' => automationLimitText($asset['ip'] ?? '', 200),
        'mac' => automationLimitText($asset['mac'] ?? '', 200),
    ];
    foreach ($values as $key => $value) {
        $values[$key] = automationDbEscape($value);
    }
    automationDbQuery("INSERT INTO assets SET asset_name = '{$values['name']}',
        asset_description = '{$values['description']}', asset_type = '{$values['type']}',
        asset_make = '{$values['make']}', asset_model = '{$values['model']}',
        asset_serial = '{$values['serial']}', asset_os = '{$values['os']}',
        asset_uri = '{$values['uri']}', asset_status = '{$values['status']}',
        asset_location_id = $location_id, asset_notes = '{$values['notes']}', asset_client_id = $client_id",
        'Could not create the asset');
    $asset_id = mysqli_insert_id($mysqli);
    if ($values['mac'] !== '' || $values['ip'] !== '') {
        automationDbQuery("INSERT INTO asset_interfaces SET interface_name = '1',
            interface_mac = '{$values['mac']}', interface_ip = '{$values['ip']}',
            interface_primary = 1, interface_asset_id = $asset_id",
            'Could not create the asset interface');
    }
    logAudit('Automation', 'Create', "Created asset $name via automation", $client_id, $asset_id);
    return ['asset_id' => $asset_id, 'asset_name' => $name];
}

function automationAttachAssetUri(int $asset_id, int $client_id, string $uri): void
{
    global $mysqli;
    $uri = automationValidHttpUrl($uri);
    if ($asset_id < 1 || $client_id < 1 || $uri === '') {
        return;
    }
    $uri_sql = automationDbEscape($uri);
    mysqli_query($mysqli, "UPDATE assets SET asset_uri_2 = '$uri_sql'
        WHERE asset_id = $asset_id AND asset_client_id = $client_id
        AND (asset_uri_2 IS NULL OR asset_uri_2 = '') LIMIT 1");
}

function automationDomainName($value): string
{
    $value = strtolower(automationLimitText($value, 200));
    $host = parse_url(str_contains($value, '://') ? $value : '//' . $value, PHP_URL_HOST);
    $name = rtrim((string) ($host ?: $value), '.');
    return filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $name : '';
}

function automationDomainRow(int $domain_id, int $client_id): ?array
{
    global $mysqli;
    if ($domain_id < 1 || $client_id < 1) {
        return null;
    }
    $sql = mysqli_query($mysqli, "SELECT domain_id, domain_name FROM domains
        WHERE domain_id = $domain_id AND domain_client_id = $client_id
        AND domain_archived_at IS NULL LIMIT 1");
    return $sql ? (mysqli_fetch_assoc($sql) ?: null) : null;
}

function automationFindDomain(string $name, int $client_id): array
{
    global $mysqli;
    $name = automationDomainName($name);
    if ($name === '') {
        return [];
    }
    $name_sql = automationDbEscape($name);
    $matches = [];
    $sql = mysqli_query($mysqli, "SELECT domain_id, domain_name, domain_client_id FROM domains
        WHERE domain_name = '$name_sql' AND domain_archived_at IS NULL
        ORDER BY domain_id");
    while ($sql && $row = mysqli_fetch_assoc($sql)) {
        $matches[] = $row;
    }
    return $matches;
}

function automationCreateDomain(array $domain, int $client_id): array
{
    global $mysqli;
    automationRequirePermission('module_support', 2, 'The API user cannot create domains');
    $name = automationDomainName($domain['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('A valid domain name is required before a domain can be created');
    }
    $description = automationLimitText($domain['description'] ?? 'Managed through an external DNS provider.', 1000);
    $notes = automationLimitText($domain['notes'] ?? 'Created by the N45 automation resolver; native ITFlow refresh jobs maintain DNS and expiry data.', 4000);
    $name_sql = automationDbEscape($name);
    $description_sql = automationDbEscape($description);
    $notes_sql = automationDbEscape($notes);
    automationDbQuery("INSERT INTO domains SET domain_name = '$name_sql',
        domain_description = '$description_sql', domain_notes = '$notes_sql', domain_client_id = $client_id",
        'Could not create the domain');
    $domain_id = mysqli_insert_id($mysqli);
    logAudit('Automation', 'Create', "Created domain $name via automation", $client_id, $domain_id);
    return ['domain_id' => $domain_id, 'domain_name' => $name];
}

function automationSaveMapping(string $source, string $entity_type, string $external_id,
    string $external_name, int $client_id, int $location_id, int $asset_id, int $domain_id,
    string $strategy, array $metadata = []): void
{
    $has_binding = $client_id > 0 || $location_id > 0 || $asset_id > 0 || $domain_id > 0;
    integrationIdentityUpsertMapping([
        'source' => $source,
        'entity_type' => $entity_type,
        'external_id' => $external_id,
        'external_name' => $external_name,
        'client_id' => $client_id,
        'location_id' => $location_id,
        'asset_id' => $asset_id,
        'domain_id' => $domain_id,
        'strategy' => $strategy,
        'state' => $has_binding ? 'automatic' : 'unresolved',
        'confidence' => $has_binding ? 100 : 0,
        'metadata' => $metadata,
    ]);
}

/**
 * Remove the Operations incident and event history tied to a ticket.
 *
 * The caller may wrap this helper and the ticket DELETE in a transaction so
 * the ticket and its Operations record are always removed together.
 */
function automationDeleteTicketOperations(int $ticket_id): int
{
    global $mysqli;
    if ($ticket_id < 1) {
        return 0;
    }

    $incident_keys = [];
    $sql_incidents = automationDbQuery("SELECT automation_incident_source,
        automation_incident_key FROM automation_incidents
        WHERE automation_incident_ticket_id = $ticket_id",
        'Could not find the Operations records associated with the ticket');
    while ($incident = mysqli_fetch_assoc($sql_incidents)) {
        $incident_keys[] = [
            automationDbEscape($incident['automation_incident_source']),
            automationDbEscape($incident['automation_incident_key']),
        ];
    }

    foreach ($incident_keys as [$source, $incident_key]) {
        automationDbQuery("DELETE FROM automation_events
            WHERE automation_event_source = '$source'
            AND automation_event_incident_key = '$incident_key'",
            'Could not delete the Operations event history associated with the ticket');
    }

    // Also remove any standalone event rows left by an interrupted incident write.
    automationDbQuery("DELETE FROM automation_events
        WHERE automation_event_ticket_id = $ticket_id",
        'Could not delete the Operations events associated with the ticket');
    automationDbQuery("DELETE FROM automation_incidents
        WHERE automation_incident_ticket_id = $ticket_id",
        'Could not delete the Operations incident associated with the ticket');

    return mysqli_affected_rows($mysqli);
}

function automationResolveIdentityUnlocked(array $input): array
{
    $source = automationSource($input['source'] ?? '');
    $entity_type = automationEntityType($input['entity_type'] ?? 'resource');
    $external_id = automationLimitText($input['external_id'] ?? '', 255);
    $external_name = automationLimitText($input['external_name'] ?? '', 255);
    if ($external_id === '') {
        throw new InvalidArgumentException('external_id is required for durable mapping');
    }
    $client = is_array($input['client'] ?? null) ? $input['client'] : [];
    if (trim((string) ($client['name'] ?? '')) !== '') {
        $client['name'] = automationCanonicalClientName($client['name']);
    }
    $location = is_array($input['location'] ?? null) ? $input['location'] : [];
    $asset = is_array($input['asset'] ?? null) ? $input['asset'] : [];
    $domain = is_array($input['domain'] ?? null) ? $input['domain'] : [];
    $options = is_array($input['options'] ?? null) ? $input['options'] : [];
    $create_client = automationBool($options['create_client'] ?? null, false);
    $create_location = automationBool($options['create_location'] ?? null, false);
    $create_asset = automationBool($options['create_asset'] ?? null, false);
    $create_domain = automationBool($options['create_domain'] ?? null, false);

    $mapping = automationMapping($source, $entity_type, $external_id);
    $client_external_id = automationLimitText($client['external_id'] ?? '', 255);
    $client_entity_type = automationEntityType($client['entity_type'] ?? 'client');
    $client_mapping = $client_external_id !== ''
        ? automationMapping($source, $client_entity_type, $client_external_id) : null;
    $location_external_id = automationLimitText($location['external_id'] ?? '', 255);
    $location_entity_type = automationEntityType($location['entity_type'] ?? 'location');
    $location_mapping = $location_external_id !== ''
        ? automationMapping($source, $location_entity_type, $location_external_id) : null;

    $mapped_client_ids = array_values(array_unique(array_filter([
        intval($mapping['automation_mapping_client_id'] ?? 0),
        intval($client_mapping['automation_mapping_client_id'] ?? 0),
        intval($location_mapping['automation_mapping_client_id'] ?? 0),
    ])));
    if (count($mapped_client_ids) > 1) {
        throw new AutomationConflictException('Saved external mappings disagree about the ITFlow client');
    }
    $mapped_client_id = intval($mapped_client_ids[0] ?? 0);
    $client_row = $mapped_client_id > 0 ? automationClientRow($mapped_client_id) : null;
    if ($mapped_client_id > 0 && !$client_row) {
        throw new AutomationConflictException('The saved client mapping is unavailable to this API key');
    }
    $strategy = $mapping ? 'external_id' : ($client_mapping ? 'client_external_id' : ($location_mapping ? 'location_external_id' : ''));
    $client_id = intval($client_row['client_id'] ?? 0);
    if ($client_id === 0 && intval($client['id'] ?? 0) > 0) {
        $client_row = automationClientRow(intval($client['id']));
        if (!$client_row) {
            throw new AutomationConflictException('The supplied client ID is unavailable to this API key');
        }
        $client_id = intval($client_row['client_id']);
        $strategy = 'client_id';
    }
    if ($client_id === 0 && trim((string) ($client['name'] ?? '')) !== '') {
        $matches = automationFindClientByName((string) $client['name']);
        if (count($matches) > 1) {
            throw new AutomationConflictException('The client name matched more than one ITFlow client');
        }
        if (count($matches) === 1) {
            $client_row = $matches[0];
            $client_id = intval($client_row['client_id']);
            $strategy = 'client_name';
        } elseif ($create_client) {
            $client_row = automationCreateClient($client);
            $client_id = intval($client_row['client_id']);
            $strategy = 'client_created';
        }
    }

    $mapped_location_ids = array_values(array_unique(array_filter([
        intval($mapping['automation_mapping_location_id'] ?? 0),
        intval($location_mapping['automation_mapping_location_id'] ?? 0),
    ])));
    if (count($mapped_location_ids) > 1) {
        throw new AutomationConflictException('Saved external mappings disagree about the ITFlow location');
    }
    $mapped_location_id = intval($mapped_location_ids[0] ?? 0);
    $location_row = $mapped_location_id > 0 && $client_id > 0
        ? automationLocationRow($mapped_location_id, $client_id) : null;
    if ($mapped_location_id > 0 && !$location_row) {
        throw new AutomationConflictException('The saved location mapping is no longer available');
    }
    $location_id = intval($location_row['location_id'] ?? 0);
    if ($client_id > 0 && $location_id === 0 && intval($location['id'] ?? 0) > 0) {
        $location_row = automationLocationRow(intval($location['id']), $client_id);
        if (!$location_row) {
            throw new AutomationConflictException('The supplied location does not belong to the resolved client');
        }
        $location_id = intval($location_row['location_id']);
        $strategy .= '+location_id';
    }
    $has_location_identity = trim((string) ($location['name'] ?? '')) !== ''
        || trim((string) ($location['address'] ?? '')) !== ''
        || trim((string) ($location['city'] ?? '')) !== '';
    if ($client_id > 0 && $location_id === 0 && $has_location_identity) {
        $matches = automationFindLocation($location, $client_id);
        if (count($matches) > 1) {
            throw new AutomationConflictException('The location matched more than one ITFlow location');
        }
        if (count($matches) === 1) {
            $location_row = $matches[0];
            $location_id = intval($location_row['location_id']);
            $strategy .= '+location_match';
        } elseif ($create_location) {
            $location_row = automationCreateLocation($location, $client_id);
            $location_id = intval($location_row['location_id']);
            $strategy .= '+location_created';
        }
    }

    $asset_row = ($mapping && $client_id > 0)
        ? automationAssetRow(intval($mapping['automation_mapping_asset_id']), $client_id) : null;
    if ($mapping && intval($mapping['automation_mapping_asset_id']) > 0 && !$asset_row) {
        throw new AutomationConflictException('The saved asset mapping is no longer available');
    }
    $asset_id = intval($asset_row['asset_id'] ?? 0);
    if ($client_id > 0 && $asset_id === 0 && intval($asset['id'] ?? 0) > 0) {
        $asset_row = automationAssetRow(intval($asset['id']), $client_id);
        if (!$asset_row) {
            throw new AutomationConflictException('The supplied asset does not belong to the resolved client');
        }
        $asset_id = intval($asset_row['asset_id']);
        $strategy .= '+asset_id';
    }
    if ($client_id > 0 && $asset_id === 0 && trim((string) ($asset['name'] ?? '')) !== '') {
        $matches = automationFindAsset($asset, $client_id, $location_id);
        if (count($matches) > 1) {
            throw new AutomationConflictException('The asset matched more than one ITFlow asset');
        }
        if (count($matches) === 1) {
            $asset_row = $matches[0];
            $asset_id = intval($asset_row['asset_id']);
            $strategy .= '+asset_match';
        } elseif ($create_asset) {
            $asset_row = automationCreateAsset($asset, $client_id, $location_id);
            $asset_id = intval($asset_row['asset_id']);
            $strategy .= '+asset_created';
        }
    }
    if ($asset_id > 0) {
        automationAttachAssetUri($asset_id, $client_id, (string) ($asset['uri'] ?? ''));
    }

    $domain_row = ($mapping && $client_id > 0)
        ? automationDomainRow(intval($mapping['automation_mapping_domain_id'] ?? 0), $client_id) : null;
    if ($mapping && intval($mapping['automation_mapping_domain_id'] ?? 0) > 0 && !$domain_row) {
        throw new AutomationConflictException('The saved domain mapping is no longer available');
    }
    $domain_id = intval($domain_row['domain_id'] ?? 0);
    if ($client_id > 0 && $domain_id === 0 && intval($domain['id'] ?? 0) > 0) {
        $domain_row = automationDomainRow(intval($domain['id']), $client_id);
        if (!$domain_row) {
            throw new AutomationConflictException('The supplied domain does not belong to the resolved client');
        }
        $domain_id = intval($domain_row['domain_id']);
        $strategy .= '+domain_id';
    }
    if ($client_id > 0 && $domain_id === 0 && automationDomainName($domain['name'] ?? '') !== '') {
        $matches = automationFindDomain((string) $domain['name'], $client_id);
        if (count($matches) > 1) {
            throw new AutomationConflictException('The domain matched more than one ITFlow domain');
        }
        if (count($matches) === 1) {
            $domain_row = $matches[0];
            if (intval($domain_row['domain_client_id']) !== $client_id) {
                throw new AutomationConflictException('The domain already belongs to a different client or is unassigned');
            }
            $domain_id = intval($domain_row['domain_id']);
            $strategy .= '+domain_match';
        } elseif ($create_domain) {
            $domain_row = automationCreateDomain($domain, $client_id);
            $domain_id = intval($domain_row['domain_id']);
            $strategy .= '+domain_created';
        }
    }

    if ($create_client && trim((string) ($client['name'] ?? '')) !== '' && $client_id === 0) {
        throw new AutomationConflictException('The requested client could not be matched or created');
    }
    if ($create_location && $has_location_identity && $location_id === 0) {
        throw new AutomationConflictException('The requested location could not be matched or created');
    }
    if ($create_asset && trim((string) ($asset['name'] ?? '')) !== '' && $asset_id === 0) {
        throw new AutomationConflictException('The requested asset could not be matched or created');
    }
    if ($create_domain && automationDomainName($domain['name'] ?? '') !== '' && $domain_id === 0) {
        throw new AutomationConflictException('The requested domain could not be matched or created');
    }

    automationSaveMapping($source, $entity_type, $external_id, $external_name,
        $client_id, $location_id, $asset_id, $domain_id, $strategy ?: 'unresolved',
        is_array($input['metadata'] ?? null) ? $input['metadata'] : []);
    if ($client_external_id !== '' && ($client_entity_type !== $entity_type || $client_external_id !== $external_id)) {
        automationSaveMapping($source, $client_entity_type, $client_external_id,
            (string) ($client['name'] ?? ''), $client_id, 0, 0, 0, 'client_external_id');
    }
    if ($location_external_id !== '' && ($location_entity_type !== $entity_type || $location_external_id !== $external_id)) {
        automationSaveMapping($source, $location_entity_type, $location_external_id,
            (string) ($location['name'] ?? ''), $client_id, $location_id, 0, 0, 'location_external_id');
    }

    return [
        'source' => $source,
        'entity_type' => $entity_type,
        'external_id' => $external_id,
        'strategy' => $strategy ?: 'unresolved',
        'client_id' => $client_id,
        'client_name' => (string) ($client_row['client_name'] ?? ''),
        'location_id' => $location_id,
        'location_name' => (string) ($location_row['location_name'] ?? ''),
        'asset_id' => $asset_id,
        'asset_name' => (string) ($asset_row['asset_name'] ?? ''),
        'domain_id' => $domain_id,
        'domain_name' => (string) ($domain_row['domain_name'] ?? ''),
    ];
}

function automationResolveIdentity(array $input): array
{
    global $mysqli;

    if (!n45FeatureEnabled('automation')) {
        throw new RuntimeException('Automation identity resolution is disabled by deployment feature flag');
    }

    $source = automationSource($input['source'] ?? '');
    $entity_type = automationEntityType($input['entity_type'] ?? 'resource');
    $external_id = automationLimitText($input['external_id'] ?? '', 255);
    if ($external_id === '') {
        throw new InvalidArgumentException('external_id is required for durable mapping');
    }
    $client = is_array($input['client'] ?? null) ? $input['client'] : [];
    if (trim((string) ($client['name'] ?? '')) !== '') {
        $client['name'] = automationCanonicalClientName($client['name']);
        $input['client'] = $client;
    }
    $domain = is_array($input['domain'] ?? null) ? $input['domain'] : [];
    $identity_locks = [sha1('mapping:' . $source . ':' . $entity_type . ':' . $external_id)];
    $client_name = automationNormalizeName($client['name'] ?? '');
    if ($client_name !== '') {
        $identity_locks[] = sha1('client:' . $client_name);
    }
    $domain_name = automationDomainName($domain['name'] ?? '');
    if ($domain_name !== '') {
        $identity_locks[] = sha1('domain:' . $domain_name);
    }
    $identity_locks = array_values(array_unique($identity_locks));
    sort($identity_locks, SORT_STRING);
    $acquired_locks = [];
    foreach ($identity_locks as $identity_lock) {
        $lock_name = automationDbEscape('itflow_identity_' . $identity_lock);
        $lock_row = mysqli_fetch_row(automationDbQuery("SELECT GET_LOCK('$lock_name', 10)",
            'Could not obtain an external identity lock'));
        if (intval($lock_row[0] ?? 0) !== 1) {
            foreach (array_reverse($acquired_locks) as $acquired_lock) {
                mysqli_query($mysqli, "SELECT RELEASE_LOCK('$acquired_lock')");
            }
            throw new RuntimeException('Could not obtain an external identity lock');
        }
        $acquired_locks[] = $lock_name;
    }
    try {
        mysqli_begin_transaction($mysqli);
        try {
            $resolved = automationResolveIdentityUnlocked($input);
            mysqli_commit($mysqli);
            return $resolved;
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }
    } finally {
        foreach (array_reverse($acquired_locks) as $acquired_lock) {
            mysqli_query($mysqli, "SELECT RELEASE_LOCK('$acquired_lock')");
        }
    }
}

function automationEventDetails(array $event, array $resolved): string
{
    $source = escapeHtml(automationLimitText($event['source'] ?? '', 40));
    $description = escapeHtml(automationLimitText($event['description'] ?? '', 8000));
    $occurred_at = escapeHtml(automationLimitText($event['occurred_at'] ?? date(DATE_ATOM), 60));
    $external_id = escapeHtml(automationLimitText($event['event_id'] ?? '', 255));
    $details = "<p><strong>Automated event from $source</strong></p>"
        . '<p><strong>Occurred:</strong> ' . $occurred_at . '<br>'
        . '<strong>Event ID:</strong> <code>' . $external_id . '</code></p>';
    if ($description !== '') {
        $details .= '<p>' . nl2br($description) . '</p>';
    }
    $url = automationValidHttpUrl($event['url'] ?? '');
    if ($url !== '') {
        $url_html = escapeHtml($url);
        $details .= "<p><a href=\"$url_html\" rel=\"noopener noreferrer\">Open source record</a></p>";
    }
    if (($resolved['strategy'] ?? '') !== '') {
        $details .= '<p><small>Automation mapping: ' . escapeHtml($resolved['strategy']) . '</small></p>';
    }
    if (!empty($resolved['service_name'])) {
        $details .= '<p><small>Service: ' . escapeHtml($resolved['service_name']) . '</small></p>';
    }
    return $details;
}

function automationCreateIncidentTicket(array $event, array $resolved): array
{
    global $mysqli, $api_key_name;
    $settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_module_enable_ticketing,
        config_ticket_default_billable, config_ticket_prefix FROM settings WHERE company_id = 1"));
    if (empty($settings['config_module_enable_ticketing'])) {
        throw new RuntimeException('Ticketing is disabled in ITFlow');
    }
    $subject = automationLimitText($event['title'] ?? 'Automation event', 500);
    $severity = strtolower(automationLimitText($event['severity'] ?? 'low', 20));
    $priority = match ($severity) {
        'emergency', 'critical', 'high' => 'High',
        'warning', 'medium' => 'Medium',
        default => 'Low',
    };
    $client_id = intval($resolved['client_id'] ?? 0);
    $location_id = intval($resolved['location_id'] ?? 0);
    $asset_id = intval($resolved['asset_id'] ?? 0);
    $assigned_to = intval($event['assigned_to'] ?? 0);
    $contact_id = 0;
    if ($client_id > 0) {
        $contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_id FROM contacts
            WHERE contact_client_id = $client_id AND contact_primary = 1
            AND contact_archived_at IS NULL LIMIT 1"));
        $contact_id = intval($contact['contact_id'] ?? 0);
    }
    $subject_sql = automationDbEscape($subject);
    $details_sql = automationDbEscape(automationEventDetails($event, $resolved));
    $priority_sql = automationDbEscape($priority);
    $prefix = automationLimitText($settings['config_ticket_prefix'] ?? 'TCK-', 200);
    $prefix_sql = automationDbEscape($prefix);
    $billable = intval($settings['config_ticket_default_billable'] ?? 0);

    if (!mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the automation incident ticket transaction');
    }
    try {
        if ($client_id > 0 && !agreementLockClientForAuditRetention($client_id)) {
            throw new RuntimeException('The automation incident client no longer exists');
        }
        automationDbQuery("UPDATE settings SET
            config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1",
            'Could not allocate the automation incident ticket number');
        if (mysqli_affected_rows($mysqli) !== 1) {
            throw new RuntimeException('The automation incident ticket number source is unavailable');
        }
        $ticket_number = intval(mysqli_insert_id($mysqli));
        if ($ticket_number < 1) {
            throw new RuntimeException('The automation incident ticket number was not allocated');
        }
        $url_key = automationDbEscape(randomString(32));
        automationDbQuery("INSERT INTO tickets SET ticket_prefix = '$prefix_sql',
            ticket_number = $ticket_number, ticket_source = 'Automation', ticket_subject = '$subject_sql',
            ticket_details = '$details_sql', ticket_priority = '$priority_sql', ticket_status = 1,
            ticket_billable = $billable, ticket_url_key = '$url_key', ticket_created_by = 0,
            ticket_assigned_to = $assigned_to, ticket_client_id = $client_id,
            ticket_contact_id = $contact_id, ticket_location_id = $location_id, ticket_asset_id = $asset_id,
            ticket_configuration_change = 0, ticket_documentation_impact = 'None',
            ticket_documentation_assessed_by = 0, ticket_documentation_assessed_at = NOW()",
            'Could not create the automation incident ticket');
        $ticket_id = intval(mysqli_insert_id($mysqli));
        if ($ticket_id < 1) {
            throw new RuntimeException('The automation incident ticket did not receive an ID');
        }
        applyTicketSla($ticket_id, null, null, true);
        if (!mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the automation incident ticket');
        }
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }
    $key_name = automationLimitText($api_key_name ?? 'automation', 200);
    logTicketHistory($ticket_id, automationDbEscape("Created from an automation event via $key_name."));
    logAudit('Automation', 'Create', "Created ticket $prefix$ticket_number from an automation event", $client_id, $ticket_id);
    appNotify('Automation Event', "$subject opened ticket $prefix$ticket_number", "ticket.php?ticket_id=$ticket_id", $client_id, $ticket_id);
    return ['ticket_id' => $ticket_id, 'ticket_number' => $prefix . $ticket_number];
}

function automationAddIncidentReply(int $ticket_id, int $client_id, string $reply, bool $resolve): void
{
    global $mysqli, $session_user_id;
    if ($ticket_id < 1) {
        return;
    }
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_status FROM tickets
        WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id LIMIT 1"));
    if (!$ticket) {
        throw new AutomationConflictException('The mapped automation ticket is unavailable');
    }
    $reply_sql = automationDbEscape($reply);
    $user_id = intval($session_user_id ?? 0);
    automationDbQuery("INSERT INTO ticket_replies SET ticket_reply = '$reply_sql',
        ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00',
        ticket_reply_by = $user_id, ticket_reply_ticket_id = $ticket_id",
        'Could not add the automation incident update');
    if ($resolve && intval($ticket['ticket_status']) !== 4) {
        if (!mysqli_begin_transaction($mysqli)) {
            throw new RuntimeException('Could not begin the automation resolution transaction');
        }
        try {
            documentationLockClientTicket($ticket_id, $client_id);
            $locked_ticket = runbookLockOpenTicket($ticket_id);
            [$can_resolve] = runbookTicketCanResolve($ticket_id);
            if (!$can_resolve) {
                mysqli_rollback($mysqli);
                logTicketHistory($ticket_id, automationDbEscape('Recovery received; automatic resolution was blocked by unfinished runbook work.'));
                return;
            }

            $locked_status = intval($locked_ticket['ticket_status']);
            $resolved_at_predicate = empty($locked_ticket['ticket_resolved_at'])
                ? 'ticket_resolved_at IS NULL'
                : "ticket_resolved_at = '" . automationDbEscape($locked_ticket['ticket_resolved_at']) . "'";
            automationDbQuery("UPDATE tickets SET ticket_status = 4, ticket_resolved_at = NOW()
                WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id
                AND ticket_status = $locked_status AND $resolved_at_predicate
                AND ticket_closed_at IS NULL LIMIT 1", 'Could not resolve the automation incident ticket');
            if (mysqli_affected_rows($mysqli) !== 1) {
                throw new RuntimeException('The automation incident ticket changed before it could be resolved');
            }
            documentationRecordChangePassport($ticket_id, 4, 0, true);
            if (!mysqli_commit($mysqli)) {
                throw new RuntimeException('Could not commit the automation incident resolution');
            }
        } catch (Throwable $e) {
            mysqli_rollback($mysqli);
            throw $e;
        }

        setTicketResolutionSlaMet($ticket_id);
        syncTicketSlaClock($ticket_id);
        logTicketHistory($ticket_id, automationDbEscape('Resolved automatically after a recovery event.'));
        triggerCustomAction('ticket_resolve', $ticket_id);
    }
}

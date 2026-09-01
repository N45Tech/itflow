<?php

/*
 * API - validate_api_key.php
 * Called by API endpoint to validate API key is valid
 * Allows execution to continue or exits returning errors to the user
 */

// Includes
require_once __DIR__ . '../../../functions.php';
require_once __DIR__ . "../../../config.php";

// JSON header
header('Content-Type: application/json');

// Reject unsupported methods before reading a body.
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => 'False', 'message' => 'Method not allowed.']);
    exit();
}

// API requests are JSON, while PHP permits much larger browser uploads elsewhere.
// Endpoint telemetry receives a smaller route-specific envelope.
const API_DEFAULT_MAX_JSON_BODY_BYTES = 16 * 1024 * 1024;
const API_ENDPOINT_MAX_JSON_BODY_BYTES = 2 * 1024 * 1024;
const API_MAX_JSON_DEPTH = 64;
const API_ENDPOINT_MAX_JSON_NODES = 10000;
const API_ENDPOINT_MAX_CONTAINER_ITEMS = 256;

$api_route_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$api_is_endpoint_ingestion = preg_match(
    '#/integrations/endpoint/(?:create|update)(?:\.php)?/?$#',
    $api_route_path
) === 1;
$api_max_json_body_bytes = $api_is_endpoint_ingestion
    ? API_ENDPOINT_MAX_JSON_BODY_BYTES
    : API_DEFAULT_MAX_JSON_BODY_BYTES;
$api_body_limit_label = $api_is_endpoint_ingestion ? '2 MiB' : '16 MiB';

$content_length = trim((string) ($_SERVER['CONTENT_LENGTH'] ?? ''));
if ($content_length !== '' && (!ctype_digit($content_length)
    || (int) $content_length > $api_max_json_body_bytes)) {
    http_response_code(ctype_digit($content_length) ? 413 : 400);
    echo json_encode([
        'success' => 'False',
        'message' => ctype_digit($content_length)
            ? "Request body exceeds the $api_body_limit_label API limit."
            : 'Content-Length must be a non-negative integer.',
    ]);
    exit();
}

$_POST = [];
$api_body_decoded = false;
function apiDecodeJsonRequestBody(
    int $max_bytes,
    string $limit_label,
    bool $enforce_endpoint_breadth
): array {
    $raw_body = file_get_contents('php://input', false, null, 0, $max_bytes + 1);
    if ($raw_body === false) {
        http_response_code(400);
        echo json_encode(['success' => 'False', 'message' => 'Could not read the JSON request body.']);
        exit();
    }
    if (strlen($raw_body) > $max_bytes) {
        http_response_code(413);
        echo json_encode(['success' => 'False', 'message' => "Request body exceeds the $limit_label API limit."]);
        exit();
    }
    if (trim($raw_body) === '') {
        return [];
    }
    try {
        $decoded_body = json_decode($raw_body, true, API_MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        http_response_code(400);
        echo json_encode(['success' => 'False', 'message' => 'Request body is not valid JSON.']);
        exit();
    }
    if (!is_array($decoded_body) || ($decoded_body !== [] && array_is_list($decoded_body))) {
        http_response_code(400);
        echo json_encode(['success' => 'False', 'message' => 'Request body must be a JSON object.']);
        exit();
    }
    if ($enforce_endpoint_breadth) {
        $nodes = 1;
        $stack = [$decoded_body];
        while ($stack) {
            $container = array_pop($stack);
            if (count($container) > API_ENDPOINT_MAX_CONTAINER_ITEMS) {
                http_response_code(413);
                echo json_encode(['success' => 'False', 'message' => 'Endpoint JSON container is too broad.']);
                exit();
            }
            $nodes += count($container);
            if ($nodes > API_ENDPOINT_MAX_JSON_NODES) {
                http_response_code(413);
                echo json_encode(['success' => 'False', 'message' => 'Endpoint JSON payload is too complex.']);
                exit();
            }
            foreach ($container as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }
    return $decoded_body;
}

// Get IP & UA
$ip = escapeSql(getIP());
$user_agent = escapeSql($_SERVER['HTTP_USER_AGENT'] ?? '');

// Temp Added this to work with the new logAction function
$session_ip = $ip;
$session_user_agent = $user_agent;

// Setup return array
$return_arr = array();

// Unauthorised wording
DEFINE("WORDING_UNAUTHORIZED", "HTTP/1.1 401 Unauthorized");

/*
 * API Notes:
 *
 * To avoid over-complicating the app by using PUT and DELETE methods, only going to allow the use of GET and POST methods.
 * GET - Retrieving (READ) data
 * POST - Inserting (CREATE), Updating (UPDATE) or Deleting (DELETE) data
 *
 * Data returned as json encoded $return_arr:-
     * Success - True/False
     * Message - Brief info about a request / failure
     * Count - Count of rows affected/returned
     * Data - Requested data
 *
 */

// Prefer an Authorization header so credentials do not appear in URLs, proxy
// access logs, or workflow bodies. Query/body keys remain for API compatibility.
$authorization_header = trim((string) (
    $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''
));
if ($authorization_header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $header_name => $header_value) {
        if (strcasecmp((string) $header_name, 'Authorization') === 0) {
            $authorization_header = trim((string) $header_value);
            break;
        }
    }
}
if (preg_match('/^Bearer\s+([^\s]+)$/i', $authorization_header, $authorization_match)) {
    $api_key = escapeSql($authorization_match[1]);
} elseif ($authorization_header !== '') {
    header(WORDING_UNAUTHORIZED);
    echo json_encode(['success' => 'False', 'message' => 'Authorization header must use Bearer authentication.']);
    exit();
}

// Header and query credentials can be authenticated before any body is decoded.
if (!isset($api_key) && isset($_GET['api_key'])) {
    $api_key = escapeSql($_GET['api_key']);
}
if (!isset($api_key)) {
    $_POST = apiDecodeJsonRequestBody(
        $api_max_json_body_bytes,
        $api_body_limit_label,
        $api_is_endpoint_ingestion
    );
    $api_body_decoded = true;
    if (isset($_POST['api_key'])) {
        $api_key = escapeSql($_POST['api_key']);
    }
}
if (!isset($api_key)) {
    header(WORDING_UNAUTHORIZED);
    exit();
}

// Validate API key
if (isset($api_key)) {
    $api_key = escapeSql($api_key);

    $sql = mysqli_query($mysqli, "SELECT api_key_decrypt_hash, api_key_name, api_key_user_id FROM api_keys WHERE api_key_secret = '$api_key' AND api_key_expire > NOW() LIMIT 1");

    // Failed
    if (mysqli_num_rows($sql) !== 1) {
        // Invalid Key

        $url_path = escapeSql(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH));
        mysqli_query($mysqli, "INSERT INTO logs SET log_type = 'API', log_action = 'Failed', log_description = 'Incorrect or expired key (endpoint: $url_path)', log_ip = '$ip', log_user_agent = '$user_agent'");

        $return_arr['success'] = "False";
        $return_arr['message'] = "Authentication failed. API key is invalid or has expired.";

        header(WORDING_UNAUTHORIZED);
        echo json_encode($return_arr);
        exit();

    } else {

        // SUCCESS

        // Set client ID, company ID & key name
        $row = mysqli_fetch_assoc($sql);
        $api_key_name = htmlentities($row['api_key_name']);
        $api_key_decrypt_hash = $row['api_key_decrypt_hash']; // No sanitization
        $api_key_user_id = intval($row['api_key_user_id']);

        if (!$api_body_decoded) {
            $_POST = apiDecodeJsonRequestBody(
                $api_max_json_body_bytes,
                $api_body_limit_label,
                $api_is_endpoint_ingestion
            );
            $api_body_decoded = true;
        }

        // Set limit & offset for queries
        if (isset($_GET['limit'])) {
            $limit = intval($_GET['limit']);
        } elseif (isset($_POST['limit'])) {
            $limit = intval($_POST['limit']);
        } else {
            $limit = 50;
        }

        if (isset($_GET['offset'])) {
            $offset = intval($_GET['offset']);
        } elseif (isset($_POST['offset'])) {
            $offset = intval($_POST['offset']);
        } else {
            $offset = 0;
        }

        // When the key is tied to a user, enforce that user's RBAC (module + operation + client scope)
        require __DIR__ . '/enforce_api_rbac.php';

    }
}

<?php

// Microsoft Entra OAuth helpers shared by interactive sign-in flows.

function entraGuidIsValid($value) {
    return is_string($value)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
}

function entraBase64UrlEncode($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/*
 * Make an HTTPS request to Microsoft and decode its JSON response.
 *
 * Returns an array with status, data and error keys. The error is deliberately
 * generic: OAuth responses can contain identifiers and tokens that must never
 * be copied into ITFlow's logs or shown to the browser.
 */
function entraRequestJson($url, $method = 'GET', array $headers = [], $body = null) {
    $url_parts = parse_url($url);
    if (
        $url_parts === false
        || ($url_parts['scheme'] ?? '') !== 'https'
        || !in_array(strtolower($url_parts['host'] ?? ''), ['login.microsoftonline.com', 'graph.microsoft.com'], true)
    ) {
        return ['status' => 0, 'data' => null, 'error' => 'Invalid Microsoft endpoint'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
    }

    $response = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curl_error !== '') {
        return ['status' => $status, 'data' => null, 'error' => 'Microsoft request failed'];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['status' => $status, 'data' => null, 'error' => 'Microsoft returned an invalid response'];
    }

    if ($status < 200 || $status >= 300) {
        return ['status' => $status, 'data' => $data, 'error' => 'Microsoft rejected the request'];
    }

    return ['status' => $status, 'data' => $data, 'error' => null];
}

<?php

/*
 * Resolve the intended sign-in experience from the public hostname.
 * Unknown/local hosts retain ITFlow's unified login for development and recovery.
 */
function n45LoginSurfaceForHost($host) {
    $normalized_host = strtolower(trim((string) $host));

    // HTTP_HOST may include a port in local or non-standard deployments.
    if (str_starts_with($normalized_host, '[')) {
        $closing_bracket = strpos($normalized_host, ']');
        if ($closing_bracket !== false) {
            $normalized_host = substr($normalized_host, 1, $closing_bracket - 1);
        }
    } else {
        $normalized_host = preg_replace('/:\d+$/', '', $normalized_host);
    }

    if ($normalized_host === 'portal.n45tech.com') {
        return 'customer';
    }

    if ($normalized_host === 'psa.n45tech.com') {
        return 'technician';
    }

    return 'unified';
}

function n45LoginUserFilter($surface) {
    if ($surface === 'customer') {
        return "user_type = 2 AND client_archived_at IS NULL";
    }

    if ($surface === 'technician') {
        return "user_type = 1";
    }

    return "(user_type = 1 OR (user_type = 2 AND client_archived_at IS NULL))";
}

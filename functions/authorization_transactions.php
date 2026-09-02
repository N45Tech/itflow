<?php

/*
 * Transaction-bound authorization primitives.
 *
 * A web request's session permission check is only advisory. An administrator
 * can disable the actor, change their role, or change their client allow/deny
 * list before the protected write commits. Mutation paths use this file to
 * lock every human principal first, in one numeric order, and then revalidate
 * the exact authorization facts while those rows remain locked.
 */

function authorizationDbQuery(string $sql, string $context)
{
    global $mysqli;

    $result = mysqli_query($mysqli, $sql);
    if ($result === false) {
        throw new RuntimeException($context . ': ' . mysqli_error($mysqli));
    }
    return $result;
}

/**
 * Server-side state is authoritative; PHP-side flags become stale after an
 * implicit commit. @@SESSION.in_transaction is supported by the MariaDB and
 * MySQL versions in the N45 compatibility matrix.
 */
function authorizationRequireActiveTransaction(): void
{
    $state = mysqli_fetch_row(authorizationDbQuery(
        'SELECT @@SESSION.in_transaction',
        'Could not inspect the authorization transaction state'
    ));
    if (intval($state[0] ?? 0) !== 1) {
        throw new RuntimeException('Authorization locks require an active database transaction');
    }
}

function authorizationNormalizePositiveIds(array $ids, string $label): array
{
    $normalized = [];
    foreach ($ids as $id) {
        $id = intval($id);
        if ($id <= 0) {
            throw new InvalidArgumentException("$label IDs must be positive integers");
        }
        $normalized[$id] = $id;
    }
    ksort($normalized, SORT_NUMERIC);
    return array_values($normalized);
}

/**
 * Lock and reauthorize internal support actors and portal users in one order.
 *
 * Internal requirement rows accept:
 *   user_id       positive internal user ID (required)
 *   minimum_level module_support level 1..3 (default 2)
 *   client_ids    clients the actor must currently be able to access
 *   require_admin require an active administrator role (default false)
 *
 * Duplicate actor requirements are intentionally combined: strongest support
 * level, administrator if any caller requires it, and the union of clients.
 * The caller must acquire this lock before any project/client/ticket/contact or
 * other business-row lock and retain the transaction through the mutation.
 */
function authorizationLockSupportAgentsAndPortalUsers(
    array $internal_requirements,
    array $portal_user_ids
): array {
    authorizationRequireActiveTransaction();

    $requirements = [];
    foreach ($internal_requirements as $requirement) {
        if (!is_array($requirement)) {
            throw new InvalidArgumentException('Internal authorization requirements must be arrays');
        }
        $user_id = intval($requirement['user_id'] ?? 0);
        if ($user_id <= 0) {
            throw new InvalidArgumentException('An active internal user is required');
        }
        $minimum_level = max(1, min(3, intval($requirement['minimum_level'] ?? 2)));
        $client_ids = authorizationNormalizePositiveIds(
            is_array($requirement['client_ids'] ?? null) ? $requirement['client_ids'] : [],
            'Client scope'
        );
        if (!isset($requirements[$user_id])) {
            $requirements[$user_id] = [
                'user_id' => $user_id,
                'minimum_level' => $minimum_level,
                'client_ids' => [],
                'require_admin' => false,
            ];
        }
        $requirements[$user_id]['minimum_level'] = max(
            $requirements[$user_id]['minimum_level'],
            $minimum_level
        );
        $requirements[$user_id]['require_admin'] =
            $requirements[$user_id]['require_admin'] || !empty($requirement['require_admin']);
        foreach ($client_ids as $client_id) {
            $requirements[$user_id]['client_ids'][$client_id] = $client_id;
        }
    }
    ksort($requirements, SORT_NUMERIC);
    foreach ($requirements as &$requirement) {
        ksort($requirement['client_ids'], SORT_NUMERIC);
        $requirement['client_ids'] = array_values($requirement['client_ids']);
    }
    unset($requirement);

    $portal_user_ids = authorizationNormalizePositiveIds($portal_user_ids, 'Portal user');
    $all_user_ids = array_values(array_unique(array_merge(
        array_keys($requirements),
        $portal_user_ids
    )));
    sort($all_user_ids, SORT_NUMERIC);

    if (!$all_user_ids) {
        return ['agents' => [], 'portal_users' => []];
    }

    $user_id_sql = implode(',', $all_user_ids);
    $users = [];
    $user_result = authorizationDbQuery("SELECT user_id, user_name, user_email,
        user_type, user_status, user_archived_at, user_role_id
        FROM users WHERE user_id IN ($user_id_sql)
        ORDER BY user_id ASC FOR UPDATE", 'Could not lock authorization principals');
    while ($row = mysqli_fetch_assoc($user_result)) {
        $users[intval($row['user_id'])] = $row;
    }
    foreach ($all_user_ids as $user_id) {
        if (!isset($users[$user_id])) {
            throw new RuntimeException("Authorization principal $user_id no longer exists");
        }
    }

    $role_ids = [];
    foreach (array_keys($requirements) as $user_id) {
        $role_id = intval($users[$user_id]['user_role_id'] ?? 0);
        if ($role_id > 0) {
            $role_ids[$role_id] = $role_id;
        }
    }
    ksort($role_ids, SORT_NUMERIC);

    $roles = [];
    $role_permissions = [];
    if ($role_ids) {
        $role_id_sql = implode(',', $role_ids);
        $role_result = authorizationDbQuery("SELECT role_id, role_type, role_is_admin,
            role_archived_at FROM user_roles WHERE role_id IN ($role_id_sql)
            ORDER BY role_id ASC FOR UPDATE", 'Could not lock authorization roles');
        while ($row = mysqli_fetch_assoc($role_result)) {
            $roles[intval($row['role_id'])] = $row;
        }

        $permission_result = authorizationDbQuery("SELECT user_role_id, module_id,
            user_role_permission_level FROM user_role_permissions
            WHERE user_role_id IN ($role_id_sql)
            ORDER BY user_role_id ASC, module_id ASC FOR UPDATE",
            'Could not lock role authorization grants');
        while ($row = mysqli_fetch_assoc($permission_result)) {
            $role_permissions[intval($row['user_role_id'])][intval($row['module_id'])] =
                intval($row['user_role_permission_level']);
        }
    }

    $support_module = mysqli_fetch_assoc(authorizationDbQuery("SELECT module_id
        FROM modules WHERE module_name = 'module_support' LIMIT 1",
        'Could not resolve the support permission module'));
    $support_module_id = intval($support_module['module_id'] ?? 0);
    if (!$support_module_id && $requirements) {
        throw new RuntimeException('The support permission module is unavailable');
    }

    $client_permissions = [];
    if ($requirements) {
        $internal_id_sql = implode(',', array_keys($requirements));
        $scope_result = authorizationDbQuery("SELECT user_id, client_id, permission_type
            FROM user_client_permissions WHERE user_id IN ($internal_id_sql)
            ORDER BY user_id ASC, client_id ASC FOR UPDATE",
            'Could not lock client authorization grants');
        while ($row = mysqli_fetch_assoc($scope_result)) {
            $client_permissions[intval($row['user_id'])][] = [
                'client_id' => intval($row['client_id']),
                'permission_type' => (string) $row['permission_type'],
            ];
        }
    }

    $locked_agents = [];
    foreach ($requirements as $user_id => $requirement) {
        $user = $users[$user_id];
        if (intval($user['user_type']) !== 1 || intval($user['user_status']) !== 1
            || !empty($user['user_archived_at'])) {
            throw new RuntimeException("Internal authorization principal $user_id is inactive");
        }
        $role_id = intval($user['user_role_id'] ?? 0);
        $role = $roles[$role_id] ?? null;
        if (!$role || intval($role['role_type']) !== 1 || !empty($role['role_archived_at'])) {
            throw new RuntimeException("Internal authorization principal $user_id has no active internal role");
        }
        $is_admin = intval($role['role_is_admin']) === 1;
        if ($requirement['require_admin'] && !$is_admin) {
            throw new RuntimeException("Internal authorization principal $user_id is no longer an administrator");
        }
        $support_level = intval($role_permissions[$role_id][$support_module_id] ?? 0);
        if (!$is_admin && $support_level < intval($requirement['minimum_level'])) {
            throw new RuntimeException("Internal authorization principal $user_id no longer has the required support permission");
        }

        if (!$is_admin && $requirement['client_ids']) {
            $rows = $client_permissions[$user_id] ?? [];
            $has_any_allow = false;
            $allow = [];
            $deny = [];
            foreach ($rows as $scope) {
                $scope_client_id = intval($scope['client_id']);
                if ($scope['permission_type'] === 'allow') {
                    $has_any_allow = true;
                    $allow[$scope_client_id] = true;
                } elseif ($scope['permission_type'] === 'deny') {
                    $deny[$scope_client_id] = true;
                }
            }
            foreach ($requirement['client_ids'] as $client_id) {
                if (isset($deny[$client_id]) || ($has_any_allow && !isset($allow[$client_id]))) {
                    throw new RuntimeException("Internal authorization principal $user_id lost client $client_id access");
                }
            }
        }
        $user['role_is_admin'] = intval($role['role_is_admin']);
        $user['support_level'] = $support_level;
        $locked_agents[$user_id] = $user;
    }

    $locked_portal_users = [];
    foreach ($portal_user_ids as $user_id) {
        $user = $users[$user_id];
        if (intval($user['user_type']) !== 2 || intval($user['user_status']) !== 1
            || !empty($user['user_archived_at'])) {
            throw new RuntimeException("Portal authorization principal $user_id is inactive");
        }
        $locked_portal_users[$user_id] = $user;
    }

    return ['agents' => $locked_agents, 'portal_users' => $locked_portal_users];
}

function authorizationLockAgents(array $internal_requirements): array
{
    $locked = authorizationLockSupportAgentsAndPortalUsers($internal_requirements, []);
    return $locked['agents'];
}

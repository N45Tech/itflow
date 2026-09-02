<?php

if (PHP_SAPI !== 'cli') {
    exit("This test can only run from the command line.\n");
}

$client_id = intval($argv[1] ?? 0);
$actor_id = intval($argv[2] ?? 0);
if ($client_id <= 0 || $actor_id <= 0) {
    fwrite(STDERR, "Usage: php tests/agreement_internal_database_assert.php CLIENT_ID ACTOR_ID\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/functions/sanitize.php';
require_once $root . '/functions/sla.php';
require_once $root . '/functions/agreements.php';

$failures = [];
$assert = static function ($condition, string $message) use (&$failures): void {
    if ($condition !== true) {
        $failures[] = $message;
    }
};

try {
    $specification = agreementInternalBaselineSpecification();
    $contract = mysqli_fetch_assoc(agreementDbQuery("SELECT * FROM contracts
        WHERE contract_client_id = $client_id
        AND contract_name = 'N45 Internal Baseline' LIMIT 1",
        'Could not load the reconciled internal agreement'));
    $contract_id = intval($contract['contract_id'] ?? 0);
    $version_id = intval($contract['contract_published_version_id'] ?? 0);
    $assert($contract_id > 0, 'The reconciler did not create the internal agreement');
    $assert((string) ($contract['contract_status'] ?? '') === 'Active', 'The internal agreement is not active');
    $assert($version_id > 0, 'The internal agreement has no published pointer');

    $version = agreementVersionContext($version_id);
    $assert(is_array($version), 'The published internal agreement version is missing');
    if ($version) {
        agreementAssertVersionIntegrity($version);
    }
    $definition = agreementGetVersionDefinition($version_id);
    $expected = agreementInternalBaselineExpectedDefinition(
        $contract_id,
        intval($definition['version_number'] ?? 0)
    );
    $assert(
        is_array($definition)
            && hash_equals(agreementDefinitionHash($expected), agreementDefinitionHash($definition)),
        'The reconciled definition differs from the canonical baseline'
    );

    $rules = [];
    $rule_rows = agreementDbQuery("SELECT * FROM agreement_sla_rules
        WHERE agreement_sla_rule_version_id = $version_id ORDER BY agreement_sla_rule_id",
        'Could not load the internal canary rule');
    while ($rule = mysqli_fetch_assoc($rule_rows)) {
        $rules[] = $rule;
    }
    $assert(count($rules) === 1, 'The internal baseline does not have exactly one canary rule');
    $assert(
        agreementSelectSlaRule($rules, $specification['sla_rule']['request_type_key'], 'Low') !== null,
        'The exact internal canary does not match'
    );
    $assert(
        agreementSelectSlaRule($rules, 'ordinary-support-request', 'Low') === null,
        'An ordinary ticket was captured instead of preserving legacy fallback'
    );
    $fallback_decision = null;
    getTicketSlaId(
        $client_id,
        'Low',
        'ordinary-support-request',
        $fallback_decision,
        []
    );
    $assert(
        is_array($fallback_decision)
            && ($fallback_decision['source'] ?? '') === 'legacy_assignment'
            && intval($fallback_decision['rule_id'] ?? -1) === 0
            && intval($fallback_decision['contract_id'] ?? 0) === $contract_id,
        'The ordinary request did not traverse the existing client/global SLA fallback'
    );

    $scoped_actor_id = $actor_id + 1;
    $scoped_role_id = $actor_id + 1;
    $other_client_id = $client_id + 1;
    mysqli_begin_transaction($mysqli);
    try {
        $support_module = intval(mysqli_fetch_row(agreementDbQuery("SELECT module_id FROM modules
            WHERE module_name = 'module_support' LIMIT 1",
            'Could not load support module for actor-scope acceptance'))[0] ?? 0);
        $assert($support_module > 0, 'The support module is unavailable for actor-scope acceptance');
        agreementDbQuery("INSERT INTO user_roles
            (role_id, role_name, role_description, role_type, role_is_admin)
            VALUES ($scoped_role_id, 'N45 scoped agreement owner', 'Synthetic scoped owner', 1, 0)",
            'Could not create scoped agreement role');
        agreementDbQuery("INSERT INTO user_role_permissions
            (user_role_id, module_id, user_role_permission_level)
            VALUES ($scoped_role_id, $support_module, 2)",
            'Could not grant scoped support-write permission');
        agreementDbQuery("INSERT INTO users
            (user_id, user_name, user_email, user_password, user_auth_method,
             user_type, user_status, user_role_id)
            VALUES ($scoped_actor_id, 'N45 Scoped Agreement Owner',
                'scoped-agreement-owner@example.invalid', 'not-a-login-secret',
                'local', 1, 1, $scoped_role_id)", 'Could not create scoped agreement actor');
        agreementDbQuery("INSERT INTO clients
            (client_id, client_lead, client_name, client_currency_code, client_net_terms)
            VALUES ($other_client_id, 0, 'N45 Other Synthetic', 'USD', 30)",
            'Could not create unrelated actor-scope client');

        agreementDbQuery("INSERT INTO user_client_permissions (user_id, client_id, permission_type)
            VALUES ($scoped_actor_id, $client_id, 'deny')",
            'Could not stage explicit client deny');
        try {
            agreementPublishingActor($scoped_actor_id, true, $client_id);
            $failures[] = 'A support-write actor with an explicit client deny was accepted';
        } catch (RuntimeException $e) {
            $assert(str_contains($e->getMessage(), 'lacks access'),
                'Explicit client deny failed for an unexpected reason');
        }

        agreementDbQuery("DELETE FROM user_client_permissions WHERE user_id = $scoped_actor_id",
            'Could not reset explicit client deny');
        agreementDbQuery("INSERT INTO user_client_permissions (user_id, client_id, permission_type)
            VALUES ($scoped_actor_id, $other_client_id, 'allow')",
            'Could not stage restricted client allow list');
        try {
            agreementPublishingActor($scoped_actor_id, true, $client_id);
            $failures[] = 'A support-write actor whose allow list excludes N45 Internal was accepted';
        } catch (RuntimeException $e) {
            $assert(str_contains($e->getMessage(), 'lacks access'),
                'Restricted client allow-list rejection failed for an unexpected reason');
        }

        agreementDbQuery("DELETE FROM user_client_permissions WHERE user_id = $scoped_actor_id",
            'Could not reset restricted client allow list');
        agreementDbQuery("INSERT INTO user_client_permissions (user_id, client_id, permission_type)
            VALUES ($scoped_actor_id, $client_id, 'allow')",
            'Could not stage matching client allow');
        $scoped_actor = agreementPublishingActor($scoped_actor_id, true, $client_id);
        $assert(intval($scoped_actor['user_id'] ?? 0) === $scoped_actor_id,
            'A support-write actor with the matching client allow was rejected');
        mysqli_rollback($mysqli);
    } catch (Throwable $e) {
        mysqli_rollback($mysqli);
        throw $e;
    }

    $period_end = date('Y-m-d');
    $period_start = (new DateTimeImmutable($period_end))->modify('-89 days')->format('Y-m-d');
    $review_id = agreementGenerateServiceReview(
        $client_id,
        $period_start,
        $period_end,
        $actor_id,
        $contract_id
    );
    $review = mysqli_fetch_assoc(agreementDbQuery("SELECT * FROM service_reviews
        WHERE service_review_id = $review_id AND service_review_client_id = $client_id LIMIT 1",
        'Could not load the generated internal service review'));
    $assert(is_array($review), 'The internal service review was not generated');
    $snapshot = agreementValidateServiceReviewSnapshot($review ?: []);
    agreementValidateServiceReviewAgreementEvidence($review ?: [], $snapshot);
    $events = agreementServiceReviewEvents($review_id, $client_id);
    $assert(agreementValidateServiceReviewApproval($review ?: [], $events) === null,
        'A generated draft unexpectedly carried publication approval');
    $assert(intval($snapshot['client']['id'] ?? 0) === $client_id,
        'The generated review is not bound to the internal client');
    $assert(intval($snapshot['agreement']['version_id'] ?? 0) === $version_id,
        'The generated review is not bound to the published agreement version');

    $review['_service_review_events'] = $events;
    $draft_export = agreementServiceReviewMarkdown($review);
    $assert(str_contains($draft_export, (string) $review['service_review_snapshot_hash']),
        'The draft export omitted its snapshot hash');

    $reason = 'Approve CI synthetic N45 Internal immutable service-review canary';
    agreementPublishServiceReview($review_id, $actor_id, $reason);
    $published = mysqli_fetch_assoc(agreementDbQuery("SELECT * FROM service_reviews
        WHERE service_review_id = $review_id AND service_review_client_id = $client_id LIMIT 1",
        'Could not reload the published internal service review'));
    $events = agreementServiceReviewEvents($review_id, $client_id);
    $approval = agreementValidateServiceReviewApproval($published ?: [], $events);
    $assert((string) ($published['service_review_status'] ?? '') === 'Published',
        'The internal service review was not published');
    $assert((string) ($approval['service_review_event_reason'] ?? '') === $reason,
        'The published review lost its approval reason');
    $published['_service_review_events'] = $events;
    $published_export = agreementServiceReviewMarkdown($published);
    $assert(str_contains($published_export, $reason),
        'The published Markdown export omitted its approval reason');
    $assert(str_contains($published_export, (string) $published['service_review_snapshot_hash']),
        'The published Markdown export omitted its snapshot hash');

    try {
        agreementPublishServiceReview($review_id, $actor_id, 'Attempt duplicate publication');
        $failures[] = 'A published service review accepted a second publication mutation';
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'immutable'),
            'Duplicate publication did not fail through the immutable-state guard');
    }

    mysqli_begin_transaction($mysqli);
    agreementDbQuery("UPDATE service_reviews SET service_review_summary = 'tampered'
        WHERE service_review_id = $review_id", 'Could not stage the review mutation check');
    $tampered = mysqli_fetch_assoc(agreementDbQuery("SELECT * FROM service_reviews
        WHERE service_review_id = $review_id LIMIT 1", 'Could not load the staged mutation'));
    try {
        agreementValidateServiceReviewSnapshot($tampered ?: []);
        $failures[] = 'A mutated published review presentation was accepted';
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'presentation fields'),
            'Published review mutation failed for an unexpected reason');
    }
    mysqli_rollback($mysqli);

    $foreign_events = $events;
    foreach ($foreign_events as &$event) {
        if (($event['service_review_event_action'] ?? '') === 'Published') {
            $event['service_review_event_client_id'] = $client_id + 1;
        }
    }
    unset($event);
    try {
        agreementValidateServiceReviewApproval($published, $foreign_events);
        $failures[] = 'Cross-tenant publication evidence was accepted';
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'binding'),
            'Cross-tenant publication evidence failed for an unexpected reason');
    }

    $foreign_agreement_review = $published;
    $foreign_agreement_snapshot = json_decode(
        $published['service_review_source_snapshot'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $foreign_agreement_review['service_review_client_id'] = $client_id + 1;
    $foreign_agreement_snapshot['client']['id'] = $client_id + 1;
    $foreign_agreement_review['service_review_source_snapshot'] = agreementCanonicalJson(
        $foreign_agreement_snapshot
    );
    $foreign_agreement_review['service_review_snapshot_hash'] = hash(
        'sha256',
        $foreign_agreement_review['service_review_source_snapshot']
    );
    try {
        $foreign_snapshot = agreementValidateServiceReviewSnapshot($foreign_agreement_review);
        agreementValidateServiceReviewAgreementEvidence(
            $foreign_agreement_review,
            $foreign_snapshot
        );
        $failures[] = 'A structurally valid cross-tenant agreement binding was accepted';
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'another tenant'),
            'Cross-tenant agreement evidence failed for an unexpected reason');
    }

    $late_resolution_review = $published;
    $late_resolution_snapshot = json_decode(
        $published['service_review_source_snapshot'],
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $late_resolution_snapshot['agreement']['resolution_as_of'] = (new DateTimeImmutable(
        $published['service_review_period_end']
    ))->modify('+1 day')->format('Y-m-d 00:00:00');
    $late_resolution_review['service_review_source_snapshot'] = agreementCanonicalJson(
        $late_resolution_snapshot
    );
    $late_resolution_review['service_review_snapshot_hash'] = hash(
        'sha256',
        $late_resolution_review['service_review_source_snapshot']
    );
    try {
        $late_snapshot = agreementValidateServiceReviewSnapshot($late_resolution_review);
        agreementValidateServiceReviewAgreementEvidence($late_resolution_review, $late_snapshot);
        $failures[] = 'A review used an agreement resolution instant after its review boundary';
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'resolution instant'),
            'Late agreement resolution evidence failed for an unexpected reason');
    }

    echo 'Synthetic review evidence: contract ' . $contract_id
        . ', version ' . $version_id . ', review ' . $review_id
        . ', definition ' . agreementDefinitionHash($definition)
        . ', snapshot ' . $published['service_review_snapshot_hash'] . ".\n";
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
}

if ($failures) {
    fwrite(STDERR, "N45 Internal database acceptance failed:\n- "
        . implode("\n- ", array_unique($failures)) . "\n");
    exit(1);
}

echo "N45 Internal database acceptance passed.\n";

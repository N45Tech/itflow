<?php

$failures = [];
$assertContains = static function (string $needle, string $haystack, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message;
    }
};

$schema = file_get_contents(__DIR__ . '/../db.sql');
$migration = file_get_contents(__DIR__ . '/../n45/migrations/n45-0018-portal-business-review-access.php');
$functions = file_get_contents(__DIR__ . '/../client/functions.php');
$header = file_get_contents(__DIR__ . '/../client/includes/header.php');
$reviews = file_get_contents(__DIR__ . '/../client/reviews.php');
$review = file_get_contents(__DIR__ . '/../client/review.php');
$post = file_get_contents(__DIR__ . '/../client/post.php');
$agent_review = file_get_contents(__DIR__ . '/../agent/service_review.php');
$agent_reviews = file_get_contents(__DIR__ . '/../agent/reports/service_reviews.php');
$agent_client_reviews = file_get_contents(__DIR__ . '/../agent/business_reviews.php');
$client_nav = file_get_contents(__DIR__ . '/../agent/includes/client_side_nav.php');

$assertContains('contact_portal_review_access', $schema, 'The baseline schema is missing the review permission');
$assertContains('contact_portal_review_access', $migration, 'The stable N45 migration is missing the review permission');
$assertContains("case 'service_reviews'", $functions, 'The portal capability is not fail-closed behind a named permission');
$assertContains("=== 'client'", $functions, 'Business reviews are not restricted to portal managers');
$assertContains('/client/reviews.php', $header, 'Business reviews are missing from portal navigation');
$assertContains("service_review_client_id = \$session_client_id", $reviews, 'The review index is not client scoped');
$assertContains("service_review_status = 'Published'", $reviews, 'The review index exposes non-completed reviews');
$assertContains('agreementValidateServiceReviewSnapshot', $review, 'The portal review does not validate its immutable snapshot');
$assertContains("service_review_event_action IN ('Published', 'ClientComment')", $review, 'The portal review does not limit its visible event stream');
$assertContains("enforceContactCan('service_reviews')", $post, 'Portal comments are not permission checked');
$assertContains("service_review_event_action = 'ClientComment'", $post, 'Portal comments are not recorded append-only');
$assertContains('service_review_client_id = $session_client_id', $post, 'Portal comments are not client scoped');
$assertContains('Client discussion', $agent_review, 'Technicians cannot see portal review comments');
$assertContains('SELECT client_id, client_name FROM clients', $agent_reviews, 'Clients without a prior review disappear from the business-review filter');
$assertContains("require_once 'includes/inc_all_client.php'", $agent_client_reviews, 'Client business reviews do not stay in the client workspace');
$assertContains('Reviews follow the active agreement schedule.', $agent_client_reviews, 'Client business reviews do not explain how reviews are initiated');
$assertContains('/agent/documentation.php?client_id=', $client_nav, 'Client documents are not available from client navigation');
$assertContains('/agent/business_reviews.php?client_id=', $client_nav, 'Business reviews are not available from client navigation');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Client portal business review tests passed.\n";

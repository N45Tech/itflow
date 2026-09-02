<?php

/* Executable contract for the cross-module transaction lock order. */

$root = dirname(__DIR__);
require_once $root . '/functions/n45_lock_order.php';
$failures = [];
$assertThrows = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message;
    } catch (LogicException $expected) {
        // Expected fail-closed rejection.
    }
};

$order = new N45LockOrder('valid contract');
foreach ([
    ['authorization', 0], ['api_key', 2], ['client', 3], ['settings', 1],
    ['asset', 7], ['identity', 0], ['automation_incident', 0], ['ticket', 11],
    ['automation_event', 19], ['custom_action_outbox', 19], ['audit', 0],
] as [$resource, $id]) {
    $order->observe($resource, $id);
}

$assertThrows(static function (): void {
    $order = new N45LockOrder('inverted contract');
    $order->observe('ticket', 9);
    $order->observe('client', 2);
}, 'An inverted lock class was accepted');
$assertThrows(static function (): void {
    $order = new N45LockOrder('descending rows');
    $order->observe('ticket', 9);
    $order->observe('ticket', 8);
}, 'Descending row identifiers were accepted');
$assertThrows(static function (): void {
    (new N45LockOrder('unknown resource'))->observe('not_registered');
}, 'An unknown lock class was accepted');

$ranks = N45LockOrder::ranks();
if (($ranks['authorization'] ?? 0) >= ($ranks['api_key'] ?? 0)
    || ($ranks['client'] ?? 0) >= ($ranks['asset'] ?? 0)
    || ($ranks['automation_incident'] ?? 0) >= ($ranks['ticket'] ?? 0)
    || ($ranks['automation_event'] ?? 0) >= ($ranks['custom_action_outbox'] ?? 0)
    || ($ranks['custom_action_outbox'] ?? 0) >= ($ranks['audit'] ?? 0)) {
    $failures[] = 'The canonical lock class order drifted';
}

$documentation = (string) file_get_contents($root . '/docs/n45/transaction-lock-order.md');
foreach (['authorization', 'API key', 'client', 'asset', 'automation incident',
          'ticket', 'automation event', 'custom-action outbox', 'audit'] as $resource) {
    if (stripos($documentation, $resource) === false) {
        $failures[] = "Lock-order documentation omits $resource";
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "N45 lock-order contract passed.\n";

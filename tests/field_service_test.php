<?php
require_once dirname(__DIR__) . '/functions/field_service.php';
$assert = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$reject = static function (callable $fn): void { try { $fn(); } catch (DomainException $e) { return; } throw new RuntimeException('Invalid position was accepted'); };
$future_epoch = time()+3600;
$future = gmdate('Y-m-d\TH:i:s', $future_epoch) . '.000Z';
$assert(fieldFutureUtc($future) === gmdate('Y-m-d H:i:s',$future_epoch), 'A browser UTC deadline shifted timezone');
$reject(static fn()=>fieldFutureUtc('2020-01-01T00:00:00.000Z'));
$now = 1800000000;
$position = ['latitude' => 45.5, 'longitude' => -73.5, 'accuracy' => 15, 'observed_at' => $now * 1000];
$fix = fieldPosition($position, $now);
$job = ['ticket_id' => 7, 'location_id' => 3, 'scheduled_at' => gmdate('c', $now), 'pin_valid' => true,
    'pin_latitude' => 45.5, 'pin_longitude' => -73.5, 'pin_radius_meters' => 150];
$assert(count(fieldArrivalCandidates([$job], $fix, $now)) === 1, 'A scheduled nearby job did not match');
$other = array_replace($job, ['ticket_id' => 8, 'scheduled_at' => gmdate('c', $now + 600)]);
$assert(array_column(fieldArrivalCandidates([$other, $job], $fix, $now), 'ticket_id') === [7,8], 'Ambiguous jobs must all be returned, ranked by schedule');
foreach ([['pin_valid'=>false], ['scheduled_at'=>null], ['scheduled_at'=>gmdate('c',$now+14401)], ['pin_latitude'=>46.5]] as $change) {
    $assert(fieldArrivalCandidates([array_replace($job,$change)],$fix,$now) === [], 'An unverified, unscheduled, distant, or out-of-window job matched');
}
$assert(fieldArrivalCandidates([$job], array_replace($fix,['accuracy'=>101]), $now) === [], 'An inaccurate reading matched');
$assert(fieldPosition([], $now) === null, 'Manual arrival should not require a GPS position');
foreach ([['latitude'=>91],['longitude'=>-181],['observed_at'=>($now-121)*1000],['observed_at'=>($now+31)*1000],['accuracy'=>-1],['accuracy'=>INF],['latitude'=>[]]] as $change) {
    $reject(static fn()=>fieldPosition(array_replace($position,$change),$now));
}
$assert(fieldAddressHash(['location_address'=>'10 MAIN St ']) === fieldAddressHash(['location_address'=>'10 main st']), 'Address normalization changed');
$assert(fieldAddressHash(['location_address'=>'10 Main St']) !== fieldAddressHash(['location_address'=>'12 Main St']), 'Changed address did not invalidate pin');
$assert(abs(fieldDistance(0,0,0,1)-111195)<2, 'Distance calculation is incorrect');
$assert(fieldPlainText('<script>alert(1)</script><p>Site &amp; access</p><p>Call first</p>') === "Site & access\nCall first", 'Document plain-text projection failed');
echo "Field arrival matching and input tests passed.\n";

<?php

/*
 * ITFlow - Ticket SLA helpers
 *
 * SLAs are optional at every level. A ticket only gets SLA targets when an
 * assignment resolves for its client + priority (client-level assignments win,
 * client 0 rows are the global default, and an assignment pointing at SLA 0 is
 * an explicit "no SLA" override). Tickets with ticket_sla_id = 0 are simply
 * ignored by the SLA cron, the list highlighting and the reports.
 *
 * Deadlines are computed ONCE at write time (creation / priority change /
 * client change / manual SLA change) and stored on the ticket as
 * ticket_response_due_at / ticket_resolution_due_at, so the ticket list and
 * cron/ticket_sla.php only ever compare datetimes - no business-hours math at
 * render time.
 */

// A single set of impact-based priority definitions for every ticket form and
// the SLA administration page. The array keys remain the values stored on
// tickets; the short labels are guidance only.
function ticketPriorityDefinitions()
{
    return [
        'Low' => [
            'short' => 'request or planned work',
            'description' => 'No active service disruption. Use for information requests, routine changes and planned work.',
        ],
        'Medium' => [
            'short' => 'limited impact; workaround available',
            'description' => 'One or a few users are impaired, or a service is degraded, and a practical workaround exists.',
        ],
        'High' => [
            'short' => 'major impact; no workaround',
            'description' => 'A critical service is unavailable, many users are affected or there is no practical workaround.',
        ],
        'Urgent' => [
            'short' => 'outage or active security threat',
            'description' => 'Business-wide outage, active security incident or immediate threat of material data loss. After-hours emergency terms may apply.',
        ],
    ];
}

// Workflow guidance is deliberately keyed by status name so custom statuses
// still work without a schema change. Unknown statuses are identified as custom
// rather than silently receiving the wrong lifecycle description.
function ticketStatusGuidance($status_name)
{
    $guidance = [
        'New' => 'Unreviewed intake; ownership and impact have not been confirmed.',
        'Open' => 'Triaged and accepted into the work queue.',
        'In Progress' => 'A technician is actively working the ticket.',
        'Scheduled' => 'Work has a committed appointment or change window; the SLA clock keeps running.',
        'Waiting on Client' => 'The next action or required information belongs to the client; resolution SLA is paused.',
        'Waiting on Vendor' => 'A documented third-party action is blocking progress; resolution SLA is paused.',
        'Resolved' => 'Work is complete and the outcome has been communicated.',
        'Closed' => 'Final state after validation, acceptance or the closure window.',
    ];

    return $guidance[$status_name] ?? 'Custom workflow status.';
}

function slaTargetDisplay($minutes)
{
    $minutes = intval($minutes);
    if ($minutes <= 0) {
        return '-';
    }
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    if ($minutes % 60 === 0) {
        $hours = intval($minutes / 60);
        return $hours . ' business hr' . ($hours === 1 ? '' : 's');
    }

    $hours = floor($minutes / 60);
    $remaining_minutes = $minutes % 60;
    return $hours . ' hr ' . $remaining_minutes . ' min';
}

// Business hours + SLA settings, fetched once per request
function getSlaSettings($refresh = false)
{
    global $mysqli;

    static $sla_settings = null;

    // Callers that have just written to the settings row pass true - otherwise
    // they would restamp tickets using the business hours this request started
    // with rather than the ones just saved
    if ($refresh) {
        $sla_settings = null;
    }

    if (!is_null($sla_settings)) {
        return $sla_settings;
    }

    $sql = mysqli_query($mysqli, "SELECT config_business_days, config_business_hours_start, config_business_hours_end, config_sla_warning_percent, config_sla_notification_email, config_ticket_from_name, config_ticket_from_email, config_mail_from_email, config_mail_from_name FROM settings WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);

    // ISO weekday numbers (1 = Monday .. 7 = Sunday)
    $business_days = [];
    foreach (explode(',', strval($row['config_business_days'])) as $day) {
        $day = intval($day);
        if ($day >= 1 && $day <= 7) {
            $business_days[] = $day;
        }
    }

    $sla_settings = [
        'business_days' => $business_days,
        'business_hours_start' => $row['config_business_hours_start'],
        'business_hours_end' => $row['config_business_hours_end'],
        'warning_percent' => intval($row['config_sla_warning_percent']),
        'notification_email' => $row['config_sla_notification_email'],
        'ticket_from_name' => $row['config_ticket_from_name'] ?: $row['config_mail_from_name'],
        'ticket_from_email' => $row['config_ticket_from_email'] ?: $row['config_mail_from_email'],
    ];

    return $sla_settings;
}

// Commercial SLA rules and stamped tickets carry this normalized calendar
// snapshot. The global settings remain the legacy/default source only; once a
// rule is published or a ticket is stamped, later settings edits cannot move
// its deadlines.
function slaNormalizeCalendarSnapshot(array $calendar): array
{
    $mode = (string) ($calendar['calendar_mode'] ?? '24x7');
    if (!in_array($mode, ['none', '24x7', 'business_hours'], true)) {
        throw new InvalidArgumentException('Unsupported SLA calendar mode');
    }

    $days_input = $calendar['business_days'] ?? [];
    if (!is_array($days_input)) {
        $days_input = explode(',', (string) $days_input);
    }
    $days = [];
    foreach ($days_input as $day) {
        $day = intval($day);
        if ($day >= 1 && $day <= 7) {
            $days[$day] = $day;
        }
    }
    ksort($days);
    $days = array_values($days);

    $start = trim((string) ($calendar['business_hours_start'] ?? ''));
    $end = trim((string) ($calendar['business_hours_end'] ?? ''));
    $timezone = trim((string) ($calendar['timezone'] ?? date_default_timezone_get()));
    try {
        new DateTimeZone($timezone);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Unsupported SLA calendar timezone');
    }

    if ($mode === 'business_hours'
        && (!$days || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $start)
            || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $end) || $start >= $end)) {
        throw new InvalidArgumentException('Business-hours SLA calendars require valid days and an increasing time window');
    }

    if ($mode !== 'business_hours') {
        $days = [];
        $start = '';
        $end = '';
    }

    return [
        'calendar_mode' => $mode,
        'business_days' => $days,
        'business_hours_start' => $start ?: null,
        'business_hours_end' => $end ?: null,
        'timezone' => $timezone,
    ];
}

function slaCurrentCalendarSnapshot(): array
{
    $settings = getSlaSettings();
    $days = $settings['business_days'] ?? [];
    $start = (string) ($settings['business_hours_start'] ?? '');
    $end = (string) ($settings['business_hours_end'] ?? '');
    $business_mode = $days && $start !== '' && $end !== '' && $start < $end;

    return slaNormalizeCalendarSnapshot([
        'calendar_mode' => $business_mode ? 'business_hours' : '24x7',
        'business_days' => $days,
        'business_hours_start' => $start,
        'business_hours_end' => $end,
        'timezone' => date_default_timezone_get(),
    ]);
}

function slaSnapshotFromRecord(array $sla): array
{
    $sla_id = intval($sla['sla_id'] ?? 0);
    if ($sla_id === 0) {
        return [
            'sla_id' => 0,
            'sla_name' => 'None',
            'response_minutes' => null,
            'resolution_minutes' => null,
        ] + slaNormalizeCalendarSnapshot([
            'calendar_mode' => 'none',
            'timezone' => date_default_timezone_get(),
        ]);
    }

    $response = intval($sla['sla_response_minutes'] ?? -1);
    $resolution = is_null($sla['sla_resolution_minutes'] ?? null)
        ? null : intval($sla['sla_resolution_minutes']);
    if ($response < 0 || (!is_null($resolution) && $resolution < 0)) {
        throw new InvalidArgumentException('SLA targets cannot be negative');
    }

    return [
        'sla_id' => $sla_id,
        'sla_name' => (string) ($sla['sla_name'] ?? "SLA $sla_id"),
        'response_minutes' => $response,
        'resolution_minutes' => $resolution,
    ] + slaCurrentCalendarSnapshot();
}

function slaCalendarFromTicket(array $ticket): array
{
    $mode = (string) ($ticket['ticket_sla_calendar_mode'] ?? '');
    if ($mode === '') {
        return slaCurrentCalendarSnapshot();
    }
    return slaNormalizeCalendarSnapshot([
        'calendar_mode' => $mode,
        'business_days' => $ticket['ticket_sla_business_days'] ?? '',
        'business_hours_start' => $ticket['ticket_sla_business_hours_start'] ?? '',
        'business_hours_end' => $ticket['ticket_sla_business_hours_end'] ?? '',
        'timezone' => $ticket['ticket_sla_timezone'] ?? date_default_timezone_get(),
    ]);
}

function slaTicketTargetMinutes(array $ticket, string $track): ?int
{
    if (!in_array($track, ['response', 'resolution'], true)) {
        throw new InvalidArgumentException('Unsupported SLA target track');
    }

    $snapshot_key = "ticket_sla_{$track}_minutes_snapshot";
    $legacy_key = "sla_{$track}_minutes";
    $value = !is_null($ticket['ticket_sla_calendar_mode'] ?? null)
        ? ($ticket[$snapshot_key] ?? null)
        : ($ticket[$legacy_key] ?? null);

    return is_null($value) ? null : intval($value);
}

// Closure days for the business calendar, fetched once per request. Returns a
// map of 'Y-m-d' => holiday name so the day-walk in addBusinessMinutes and
// businessMinutesBetween can do an O(1) lookup rather than a query per day -
// those loops run up to 731 iterations.
//
// Callers that have just written to business_holidays pass true, same contract
// as getSlaSettings().
function getBusinessHolidays($refresh = false)
{
    global $mysqli;

    static $holidays = null;

    if ($refresh) {
        $holidays = null;
    }

    if (!is_null($holidays)) {
        return $holidays;
    }

    // SLA calendar helpers are also used by the deterministic regression
    // suite without bootstrapping a database connection. An unavailable
    // connection means that no deployment-specific closure days can be
    // loaded; the caller still gets the snapshotted weekday/hour behavior.
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        return [];
    }

    $holidays = [];

    $sql = mysqli_query($mysqli, "SELECT holiday_date, holiday_name FROM business_holidays");
    if ($sql) {
        while ($row = mysqli_fetch_assoc($sql)) {
            $holidays[$row['holiday_date']] = $row['holiday_name'];
        }
    }

    return $holidays;
}

// US federal holidays for a calendar year, as a list of ['date' => 'Y-m-d',
// 'name' => string]. Six of the eleven float on an nth-weekday rule, which
// strtotime() understands directly, so no recurrence table is needed - the
// generator writes concrete dates and the lookup above stays an exact match.
//
// Fixed-date holidays are shifted to the OBSERVED day (Saturday -> the Friday
// before, Sunday -> the Monday after), because that is the weekday a business
// actually closes. The floating ones always land on a Monday or Thursday and
// need no shift.
function usFederalHolidays($year)
{
    $year = intval($year);

    $observed = function ($date) {
        $day = intval(date('N', strtotime($date)));
        if ($day == 6) {
            return date('Y-m-d', strtotime($date . ' -1 day'));
        }
        if ($day == 7) {
            return date('Y-m-d', strtotime($date . ' +1 day'));
        }
        return $date;
    };

    $fixed = [
        "$year-01-01" => "New Year's Day",
        "$year-06-19" => 'Juneteenth',
        "$year-07-04" => 'Independence Day',
        "$year-11-11" => 'Veterans Day',
        "$year-12-25" => 'Christmas Day',
    ];

    $floating = [
        "third monday of january $year"    => 'Martin Luther King Jr. Day',
        "third monday of february $year"   => "Presidents' Day",
        "last monday of may $year"         => 'Memorial Day',
        "first monday of september $year"  => 'Labor Day',
        "second monday of october $year"   => 'Columbus Day',
        "fourth thursday of november $year" => 'Thanksgiving Day',
    ];

    $holidays = [];

    foreach ($fixed as $date => $name) {
        $holidays[] = ['date' => $observed($date), 'name' => $name];
    }

    foreach ($floating as $rule => $name) {
        $holidays[] = ['date' => date('Y-m-d', strtotime($rule)), 'name' => $name];
    }

    usort($holidays, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });

    return $holidays;
}

// Re-stamp every open SLA ticket. Business hours and closure days both feed the
// due-date math, so anything that changes the calendar has to run this or the
// change only applies to tickets raised afterwards - which is the opposite of
// what an operator adding next week's shutdown expects. Returns the count.
function restampOpenSlaTickets()
{
    global $mysqli;

    $restamped = 0;

    $sql = mysqli_query($mysqli, "SELECT ticket_id, ticket_sla_id FROM tickets WHERE ticket_sla_id > 0 AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL");
    while ($row = mysqli_fetch_assoc($sql)) {
        $ticket_id = intval($row['ticket_id']);
        $decision = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_agreement_decision_source
            FROM ticket_agreement_decisions WHERE ticket_agreement_decision_ticket_id = $ticket_id
            ORDER BY ticket_agreement_decision_id DESC LIMIT 1"));
        if (($decision['ticket_agreement_decision_source'] ?? '') === 'agreement_rule') {
            applyTicketSla($ticket_id);
        } else {
            applyTicketSla($ticket_id, intval($row['ticket_sla_id']));
        }
        $restamped++;
    }

    return $restamped;
}

// Add $minutes of business time to a datetime, honouring the configured
// business days and hours, and skipping closure days entirely. Returns a
// Y-m-d H:i:s string in the app timezone
// (includes/inc_set_timezone.php has already set it). With no usable business
// calendar configured the clock is treated as 24x7.
function addBusinessMinutes($start_datetime, $minutes, ?array $calendar = null)
{
    $minutes = intval($minutes);
    $calendar = is_null($calendar) ? slaCurrentCalendarSnapshot() : slaNormalizeCalendarSnapshot($calendar);
    $timezone = new DateTimeZone($calendar['timezone']);
    $cursor = new DateTime($start_datetime, $timezone);

    if ($minutes <= 0) {
        return $cursor->format('Y-m-d H:i:s');
    }

    $business_days = $calendar['business_days'];
    $day_start = $calendar['business_hours_start'];
    $day_end = $calendar['business_hours_end'];

    if ($calendar['calendar_mode'] !== 'business_hours') {
        $cursor->modify("+$minutes minutes");
        return $cursor->format('Y-m-d H:i:s');
    }

    $holidays = getBusinessHolidays();

    $remaining_seconds = $minutes * 60;

    // Walk forward a day at a time consuming available business time. Interval
    // math is done on timestamps (real elapsed time), window edges by wall
    // clock - so a DST-shortened business day yields less SLA time, which is
    // the honest reading. Guard: two years of calendar.
    for ($i = 0; $i < 731; $i++) {

        $date_key = $cursor->format('Y-m-d');

        // A closure day yields no business time, same as a non-business weekday
        if (in_array(intval($cursor->format('N')), $business_days) && !isset($holidays[$date_key])) {

            $window_start = new DateTime($date_key . " $day_start", $timezone);
            $window_end = new DateTime($date_key . " $day_end", $timezone);

            if ($cursor < $window_start) {
                $cursor = $window_start;
            }

            if ($cursor < $window_end) {
                $available_seconds = $window_end->getTimestamp() - $cursor->getTimestamp();

                if ($remaining_seconds <= $available_seconds) {
                    $cursor->setTimestamp($cursor->getTimestamp() + $remaining_seconds);
                    return $cursor->format('Y-m-d H:i:s');
                }

                $remaining_seconds -= $available_seconds;
            }
        }

        // Start of the next calendar day
        $cursor = new DateTime($cursor->format('Y-m-d') . ' 00:00:00', $timezone);
        $cursor->modify('+1 day');
    }

    // Unreachable with a sane configuration - fail open rather than loop
    $cursor->setTimestamp($cursor->getTimestamp() + $remaining_seconds);
    return $cursor->format('Y-m-d H:i:s');
}

// Business minutes elapsed between two datetimes - the inverse of
// addBusinessMinutes, used to measure how much of a resolution budget an
// interval actually consumed. Skips closure days for the same reason: time
// nobody was working must not be charged to a ticket's budget.
function businessMinutesBetween($start_datetime, $end_datetime, ?array $calendar = null)
{
    $calendar = is_null($calendar) ? slaCurrentCalendarSnapshot() : slaNormalizeCalendarSnapshot($calendar);
    $timezone = new DateTimeZone($calendar['timezone']);
    $start = new DateTime($start_datetime, $timezone);
    $end = new DateTime($end_datetime, $timezone);

    if ($end <= $start) {
        return 0;
    }

    $business_days = $calendar['business_days'];
    $day_start = $calendar['business_hours_start'];
    $day_end = $calendar['business_hours_end'];

    if ($calendar['calendar_mode'] !== 'business_hours') {
        return intval(floor(($end->getTimestamp() - $start->getTimestamp()) / 60));
    }

    $holidays = getBusinessHolidays();

    $seconds = 0;
    $cursor = clone $start;

    // Sum the overlap between the interval and each day's business window
    for ($i = 0; $i < 731; $i++) {

        if ($cursor > $end) {
            break;
        }

        $date_key = $cursor->format('Y-m-d');

        if (in_array(intval($cursor->format('N')), $business_days) && !isset($holidays[$date_key])) {

            $window_start = new DateTime($date_key . " $day_start", $timezone);
            $window_end = new DateTime($date_key . " $day_end", $timezone);

            $from = $cursor > $window_start ? $cursor : $window_start;
            $to = $end < $window_end ? $end : $window_end;

            if ($to > $from) {
                $seconds += $to->getTimestamp() - $from->getTimestamp();
            }
        }

        $cursor = new DateTime($cursor->format('Y-m-d') . ' 00:00:00', $timezone);
        $cursor->modify('+1 day');
    }

    return intval(floor($seconds / 60));
}

/** Parse an application-local database datetime as an absolute instant. */
function slaAppTimestampInstant(string $timestamp): DateTimeImmutable
{
    $timezone = new DateTimeZone(date_default_timezone_get());
    $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $timestamp, $timezone);
    if (!$instant || $instant->format('Y-m-d H:i:s') !== $timestamp) {
        throw new InvalidArgumentException('Application SLA timestamp is invalid');
    }
    return $instant;
}

/**
 * Add SLA time to an application-local timestamp using the snapshotted rule
 * timezone. Both representations are returned: app-local for existing UI and
 * UTC for unambiguous comparisons, indexes and cross-timezone operation.
 */
function slaAddBusinessMinutesFromAppTimestamp(
    string $start_datetime,
    int $minutes,
    ?array $calendar = null
): array {
    $calendar = is_null($calendar) ? slaCurrentCalendarSnapshot() : slaNormalizeCalendarSnapshot($calendar);
    $calendar_timezone = new DateTimeZone($calendar['timezone']);
    $calendar_start = slaAppTimestampInstant($start_datetime)->setTimezone($calendar_timezone);
    if ($calendar['calendar_mode'] !== 'business_hours') {
        $due_instant = $calendar_start->setTimestamp(
            $calendar_start->getTimestamp() + max(0, $minutes) * 60
        );
    } else {
        $calendar_due = addBusinessMinutes($calendar_start->format('Y-m-d H:i:s'), $minutes, $calendar);
        $due_instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $calendar_due, $calendar_timezone);
        if (!$due_instant || $due_instant->format('Y-m-d H:i:s') !== $calendar_due) {
            throw new RuntimeException('Calculated SLA deadline is not a valid calendar instant');
        }
    }

    return [
        'app' => $due_instant->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s'),
        'utc' => $due_instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
    ];
}

/** Measure business time between application-local database timestamps. */
function slaBusinessMinutesBetweenAppTimestamps(
    string $start_datetime,
    string $end_datetime,
    ?array $calendar = null
): int {
    $calendar = is_null($calendar) ? slaCurrentCalendarSnapshot() : slaNormalizeCalendarSnapshot($calendar);
    $start_instant = slaAppTimestampInstant($start_datetime);
    $end_instant = slaAppTimestampInstant($end_datetime);
    if ($calendar['calendar_mode'] !== 'business_hours') {
        return max(0, intval(floor(
            ($end_instant->getTimestamp() - $start_instant->getTimestamp()) / 60
        )));
    }
    $calendar_timezone = new DateTimeZone($calendar['timezone']);
    $start = $start_instant->setTimezone($calendar_timezone);
    $end = $end_instant->setTimezone($calendar_timezone);
    return businessMinutesBetween(
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s'),
        $calendar
    );
}

/** Canonical epoch for a ticket deadline, preferring the migration-safe UTC column. */
function slaTicketDueEpoch(array $ticket, string $track): ?int
{
    if (!in_array($track, ['response', 'resolution'], true)) {
        throw new InvalidArgumentException('Unsupported SLA deadline track');
    }
    $utc = trim((string) ($ticket["ticket_{$track}_due_at_utc"] ?? ''));
    if ($utc !== '') {
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if (!$instant || $instant->format('Y-m-d H:i:s') !== $utc) {
            throw new RuntimeException('Stored UTC SLA deadline is invalid');
        }
        return $instant->getTimestamp();
    }
    $local = trim((string) ($ticket["ticket_{$track}_due_at"] ?? ''));
    return $local === '' ? null : slaAppTimestampInstant($local)->getTimestamp();
}

// Business minutes already spent on a ticket's resolution clock, including the
// interval currently running.
//
// Tickets with no clock history at all fall back to the business time elapsed
// since they were raised. Only tickets carrying a resolution target get
// intervals, so this covers response-only plans, and tickets that were already
// resolved when SLA pausing was introduced. Without the fallback both report
// zero time spent, which reads as instant resolution.
function getTicketSlaConsumedMinutes($ticket_id, ?array $calendar = null, bool $strict = false)
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $consumed = 0;
    $has_history = false;

    $sql = mysqli_query($mysqli, "SELECT sla_history_started_at, sla_history_ended_at, sla_history_minutes FROM sla_history WHERE sla_history_ticket_id = $ticket_id");
    if (!$sql) {
        if ($strict) {
            throw new RuntimeException('Could not calculate consumed SLA time: ' . mysqli_error($mysqli));
        }
        return 0;
    }
    while ($row = mysqli_fetch_assoc($sql)) {
        $has_history = true;
        if (!is_null($row['sla_history_ended_at'])) {
            $consumed += intval($row['sla_history_minutes']);
        } else {
            $consumed += slaBusinessMinutesBetweenAppTimestamps(
                $row['sla_history_started_at'],
                date('Y-m-d H:i:s'),
                $calendar
            );
        }
    }

    if (!$has_history) {
        $ticket_sql = mysqli_query($mysqli, "SELECT ticket_created_at, ticket_resolved_at, ticket_closed_at FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
        if (!$ticket_sql) {
            if ($strict) {
                throw new RuntimeException('Could not load the ticket for consumed SLA time: ' . mysqli_error($mysqli));
            }
            return 0;
        }
        if (!mysqli_num_rows($ticket_sql)) {
            if ($strict) {
                throw new RuntimeException('Ticket not found while calculating consumed SLA time');
            }
            return 0;
        }
        $ticket = mysqli_fetch_assoc($ticket_sql);
        $ended_at = $ticket['ticket_resolved_at'] ?: ($ticket['ticket_closed_at'] ?: date('Y-m-d H:i:s'));
        return slaBusinessMinutesBetweenAppTimestamps($ticket['ticket_created_at'], $ended_at, $calendar);
    }

    return $consumed;
}

// How many times a ticket's clock has been stopped. Zero means it has run
// continuously since creation, so its deadline is still simply creation plus
// the budget - the same answer pausing-free installs have always had.
function getTicketSlaPausedCount($ticket_id, bool $strict = false)
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    $sql = mysqli_query($mysqli, "SELECT COUNT(sla_history_id) AS paused_count FROM sla_history WHERE sla_history_ticket_id = $ticket_id AND sla_history_ended_at IS NOT NULL");
    if (!$sql) {
        if ($strict) {
            throw new RuntimeException('Could not count paused SLA intervals: ' . mysqli_error($mysqli));
        }
        return 0;
    }
    $row = mysqli_fetch_assoc($sql);

    return intval($row['paused_count']);
}

// Colour an SLA compliance percentage for the reports. Null means nothing has
// been judged yet, which is not the same as zero.
function slaPercentDisplay($percent)
{
    if (is_null($percent)) {
        return "<span class='text-secondary'>-</span>";
    }
    if ($percent >= 95) {
        return "<span class='text-success text-bold'>$percent%</span>";
    }
    if ($percent >= 80) {
        return "<span class='text-warning text-bold'>$percent%</span>";
    }
    return "<span class='text-danger text-bold'>$percent%</span>";
}

// Reconcile a ticket's resolution clock with its current status. Safe to call
// after any status change (and after applyTicketSla) - it opens an interval
// when the clock should be running, closes it when it should not, and on
// resume re-bases the resolution deadline on the remaining budget. The response
// clock is deliberately never paused: a ticket cannot wait on a client before
// anyone has replied to it.
function syncTicketSlaClock($ticket_id, bool $strict = false)
{
    global $mysqli;

    $ticket_id = intval($ticket_id);

    $clock_query = static function (string $sql, string $message) use ($mysqli, $strict) {
        $result = mysqli_query($mysqli, $sql);
        if ($result === false && $strict) {
            throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
        }
        return $result;
    };

    $sql = $clock_query("SELECT ticket_sla_id, ticket_created_at, ticket_resolved_at,
        ticket_closed_at, ticket_archived_at, ticket_resolution_sla_alert_stage,
        ticket_resolution_due_at, ticket_resolution_due_at_utc,
        ticket_sla_resolution_minutes_snapshot, ticket_sla_calendar_mode,
        ticket_sla_business_days, ticket_sla_business_hours_start,
        ticket_sla_business_hours_end, ticket_sla_timezone,
        sla_resolution_minutes, ticket_status_pauses_sla
        FROM tickets
        LEFT JOIN slas ON ticket_sla_id = sla_id
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        WHERE ticket_id = $ticket_id
        LIMIT 1", 'Could not load the ticket SLA clock');
    if (!$sql || !mysqli_num_rows($sql)) {
        return false;
    }
    $row = mysqli_fetch_assoc($sql);
    $calendar = slaCalendarFromTicket($row);

    $open_sql = $clock_query("SELECT sla_history_id, sla_history_started_at FROM sla_history
        WHERE sla_history_ticket_id = $ticket_id AND sla_history_ended_at IS NULL
        ORDER BY sla_history_id DESC LIMIT 1", 'Could not load the open SLA clock interval');
    if (!$open_sql) {
        return false;
    }
    $open_interval = mysqli_num_rows($open_sql) ? mysqli_fetch_assoc($open_sql) : null;

    // Once calendar_mode is stamped, a NULL resolution snapshot deliberately
    // means response-only. Never fall back to a later live SLA edit in that
    // case; only pre-migration tickets use the legacy source row.
    $resolution_minutes = intval(slaTicketTargetMinutes($row, 'resolution'));
    $tracked = intval($row['ticket_sla_id']) > 0 && $resolution_minutes > 0;

    $should_run = $tracked
        && empty($row['ticket_resolved_at'])
        && empty($row['ticket_closed_at'])
        && empty($row['ticket_archived_at'])
        && intval($row['ticket_status_pauses_sla']) == 0;

    if ($should_run && is_null($open_interval)) {

        // Prior intervals mean this is a resume, so the deadline moves out by
        // whatever budget is left rather than staying where it was
        $had_history = getTicketSlaPausedCount($ticket_id, $strict) > 0;
        $consumed = $had_history
            ? getTicketSlaConsumedMinutes($ticket_id, $calendar, $strict) : 0;

        // The very first interval is anchored to the ticket's creation time, not
        // to now - otherwise a backdated ticket would start life with its whole
        // budget intact and re-stamping it would keep handing that budget back
        $interval_start = $had_history ? date('Y-m-d H:i:s') : escapeSql($row['ticket_created_at']);
        $clock_query("INSERT INTO sla_history SET sla_history_started_at = '$interval_start',
            sla_history_ticket_id = $ticket_id", 'Could not start the SLA clock interval');

        if ($had_history) {
            $remaining = $resolution_minutes - $consumed;
            if ($remaining < 0) {
                $remaining = 0;
            }
            $resolution_due = slaAddBusinessMinutesFromAppTimestamp(
                date('Y-m-d H:i:s'),
                $remaining,
                $calendar
            );
            $resolution_due_at = escapeSql($resolution_due['app']);
            $resolution_due_at_utc = escapeSql($resolution_due['utc']);
            // A ticket that already breached stays breached
            $alert_stage = intval($row['ticket_resolution_sla_alert_stage']) == 2 ? 2 : 0;
            $clock_query("UPDATE tickets SET ticket_resolution_due_at = '$resolution_due_at',
                ticket_resolution_due_at_utc = '$resolution_due_at_utc',
                ticket_resolution_sla_alert_stage = $alert_stage WHERE ticket_id = $ticket_id",
                'Could not rebase the ticket SLA clock');
        }

    } elseif (!$should_run && !is_null($open_interval)) {

        $interval_id = intval($open_interval['sla_history_id']);
        $minutes = slaBusinessMinutesBetweenAppTimestamps(
            $open_interval['sla_history_started_at'],
            date('Y-m-d H:i:s'),
            $calendar
        );
        $clock_query("UPDATE sla_history SET sla_history_ended_at = NOW(),
            sla_history_minutes = $minutes WHERE sla_history_id = $interval_id",
            'Could not stop the SLA clock interval');
    }

    return true;
}

// Resolve which SLA (if any) applies to a client + request type + priority.
// A matching rule from the client's current published agreement wins. The
// existing client/global assignments remain the compatibility fallback for
// clients whose agreement has no matching rule. $decision receives the exact
// explanation that applyTicketSla persists on the ticket's append-only trail.
function getTicketSlaId($client_id, $priority, $request_type_key = '*', &$decision = null, array $ticket = [])
{
    global $mysqli;

    $client_id = intval($client_id);
    $priority = escapeSql($priority);
    $request_type_key = function_exists('agreementNormalizeRequestTypeKey')
        ? agreementNormalizeRequestTypeKey($request_type_key) : '*';

    $agreement_decision = null;
    if (function_exists('agreementResolveTicketSlaDecision')) {
        $agreement_decision = agreementResolveTicketSlaDecision(
            $client_id,
            $priority,
            $request_type_key,
            true,
            $ticket
        );
        if ($agreement_decision && !empty($agreement_decision['matched'])) {
            $agreement_sla_id = intval($agreement_decision['sla_id']);
            $decision = $agreement_decision;
            return $agreement_sla_id;
        }
    }

    $sla_id = null;
    $assignment_source = 'none';

    // Client-level assignment wins; a row pointing at SLA 0 is an explicit
    // "no SLA for this client/priority" override of the global default
    if ($client_id > 0) {
        $sql = mysqli_query($mysqli, "SELECT sla_assignment_sla_id FROM sla_assignments
            WHERE sla_assignment_client_id = $client_id AND sla_assignment_priority = '$priority'
            LIMIT 1 FOR UPDATE");
        if (!$sql) {
            throw new RuntimeException('Could not lock the client SLA assignment: ' . mysqli_error($mysqli));
        }
        if (mysqli_num_rows($sql)) {
            $sla_id = intval(mysqli_fetch_assoc($sql)['sla_assignment_sla_id']);
            $assignment_source = 'client assignment';
        }
    }

    // Fall back to the global default (client 0)
    if (is_null($sla_id)) {
        $sql = mysqli_query($mysqli, "SELECT sla_assignment_sla_id FROM sla_assignments
            WHERE sla_assignment_client_id = 0 AND sla_assignment_priority = '$priority'
            LIMIT 1 FOR UPDATE");
        if (!$sql) {
            throw new RuntimeException('Could not lock the global SLA assignment: ' . mysqli_error($mysqli));
        }
        if (mysqli_num_rows($sql)) {
            $sla_id = intval(mysqli_fetch_assoc($sql)['sla_assignment_sla_id']);
            $assignment_source = 'global assignment';
        }
    }

    if (empty($sla_id)) {
        $decision = [
            'client_id' => $client_id,
            'contract_id' => intval($agreement_decision['contract_id'] ?? 0),
            'version_id' => intval($agreement_decision['version_id'] ?? 0),
            'rule_id' => 0,
            'request_type_key' => $request_type_key,
            'priority' => $priority,
            'sla_id' => 0,
            'sla_snapshot' => slaSnapshotFromRecord(['sla_id' => 0]),
            'classification' => null,
            'classification_basis' => null,
            'source' => 'legacy_assignment',
            'reason' => ($agreement_decision['reason'] ?? 'No active published agreement')
                . "; $assignment_source selected no SLA",
        ];
        return 0;
    }

    // Ignore assignments pointing at archived SLAs
    $sql = mysqli_query($mysqli, "SELECT sla_id, sla_name, sla_response_minutes,
        sla_resolution_minutes FROM slas WHERE sla_id = $sla_id
        AND sla_archived_at IS NULL LIMIT 1 FOR UPDATE");
    if (!$sql) {
        throw new RuntimeException('Could not lock the selected SLA: ' . mysqli_error($mysqli));
    }
    if (!mysqli_num_rows($sql)) {
        $decision = [
            'client_id' => $client_id,
            'contract_id' => intval($agreement_decision['contract_id'] ?? 0),
            'version_id' => intval($agreement_decision['version_id'] ?? 0),
            'rule_id' => 0,
            'request_type_key' => $request_type_key,
            'priority' => $priority,
            'sla_id' => 0,
            'sla_snapshot' => slaSnapshotFromRecord(['sla_id' => 0]),
            'classification' => null,
            'classification_basis' => null,
            'source' => 'legacy_assignment',
            'reason' => "$assignment_source referenced an archived or unavailable SLA",
        ];
        return 0;
    }
    $sla_snapshot = slaSnapshotFromRecord(mysqli_fetch_assoc($sql));

    $decision = [
        'client_id' => $client_id,
        'contract_id' => intval($agreement_decision['contract_id'] ?? 0),
        'version_id' => intval($agreement_decision['version_id'] ?? 0),
        'rule_id' => 0,
        'request_type_key' => $request_type_key,
        'priority' => $priority,
        'sla_id' => $sla_id,
        'sla_snapshot' => $sla_snapshot,
        'classification' => null,
        'classification_basis' => null,
        'source' => 'legacy_assignment',
        'reason' => ($agreement_decision['reason'] ?? 'No active published agreement')
            . "; selected SLA $sla_id from $assignment_source",
    ];

    return $sla_id;
}

// Stamp (or re-stamp) a ticket's SLA and computed due dates. Call after ticket
// creation and after anything that changes which SLA applies (priority edit,
// client change, manual SLA change). Pass $forced_sla_id to pin a specific SLA
// (0 = explicitly none) instead of resolving from the assignments. Supplying a
// $forced_reason records that pin as a chronological manual-override decision;
// maintenance restamps can omit it without replacing the original rationale.
function applyTicketSla(
    $ticket_id,
    $forced_sla_id = null,
    $forced_reason = null,
    bool $caller_transaction = false
): bool
{
    global $mysqli;

    $ticket_id = intval($ticket_id);
    if ($ticket_id <= 0) {
        throw new InvalidArgumentException('Ticket ID is required to apply an SLA');
    }

    $owns_transaction = !$caller_transaction;
    if ($owns_transaction && !mysqli_begin_transaction($mysqli)) {
        throw new RuntimeException('Could not begin the ticket SLA transaction');
    }

    $sla_query = static function (string $sql, string $message) use ($mysqli) {
        $result = mysqli_query($mysqli, $sql);
        if ($result === false) {
            throw new RuntimeException($message . ': ' . mysqli_error($mysqli));
        }
        return $result;
    };

    try {
        // Match the shared retention order: locate the owner, lock the client,
        // then lock and revalidate the ticket. Holding both through selection,
        // stamping, and the decision insert closes hard-delete races.
        $owner_sql = $sla_query("SELECT ticket_client_id FROM tickets
            WHERE ticket_id = $ticket_id LIMIT 1",
            'Could not locate the ticket client for SLA selection');
        if (!mysqli_num_rows($owner_sql)) {
            throw new RuntimeException('Ticket not found while applying its SLA');
        }
        $expected_client_id = intval(mysqli_fetch_assoc($owner_sql)['ticket_client_id']);
        if ($expected_client_id > 0) {
            if (function_exists('agreementLockClientForAuditRetention')) {
                $locked_sla_client = agreementLockClientForAuditRetention($expected_client_id);
            } else {
                $locked_sla_client_sql = $sla_query("SELECT client_id FROM clients
                    WHERE client_id = $expected_client_id LIMIT 1 FOR UPDATE",
                    'Could not lock the ticket client for SLA selection');
                $locked_sla_client = mysqli_num_rows($locked_sla_client_sql)
                    ? mysqli_fetch_assoc($locked_sla_client_sql) : null;
            }
            if (!$locked_sla_client) {
                throw new RuntimeException('Ticket client not found while applying its SLA');
            }
        }

        $sql = $sla_query("SELECT tickets.ticket_id, ticket_client_id, ticket_category,
            ticket_request_type_key, category_name AS ticket_category_name, ticket_priority,
            ticket_contact_id, ticket_location_id, ticket_asset_id, ticket_billable, ticket_onsite,
            ticket_created_at, ticket_first_response_at, ticket_resolved_at, ticket_sla_id,
            ticket_sla_response_minutes_snapshot, ticket_sla_resolution_minutes_snapshot,
            ticket_sla_calendar_mode, ticket_sla_business_days,
            ticket_sla_business_hours_start, ticket_sla_business_hours_end, ticket_sla_timezone,
            ticket_response_due_at, ticket_response_due_at_utc,
            ticket_resolution_due_at, ticket_resolution_due_at_utc
            FROM tickets LEFT JOIN categories
                ON category_id = ticket_category AND category_type = 'Ticket'
            WHERE tickets.ticket_id = $ticket_id LIMIT 1 FOR UPDATE",
            'Could not lock the ticket for SLA selection');
        if (!mysqli_num_rows($sql)) {
            throw new RuntimeException('Ticket not found while applying its SLA');
        }
        $row = mysqli_fetch_assoc($sql);
        $client_id = intval($row['ticket_client_id']);
        if ($client_id !== $expected_client_id) {
            throw new RuntimeException('Ticket client changed while applying its SLA; refresh and try again');
        }

        $agreement_decision = null;
        if (is_null($forced_sla_id)) {
            $request_type_key = function_exists('agreementResolveRequestTypeKey')
                ? agreementResolveRequestTypeKey($row) : '*';
            $sla_id = getTicketSlaId(
                $client_id,
                $row['ticket_priority'],
                $request_type_key,
                $agreement_decision,
                $row
            );
            if (!$agreement_decision || !isset($agreement_decision['sla_snapshot'])) {
                throw new RuntimeException('SLA selection did not provide immutable target/calendar terms');
            }
            $sla_snapshot = $agreement_decision['sla_snapshot'];
        } else {
            $sla_id = intval($forced_sla_id);
            if ($sla_id < 0) {
                throw new InvalidArgumentException('Forced SLA ID cannot be negative');
            }
            if ($sla_id > 0) {
                $forced_sql = $sla_query("SELECT sla_id, sla_name, sla_response_minutes,
                    sla_resolution_minutes FROM slas WHERE sla_id = $sla_id
                    AND sla_archived_at IS NULL LIMIT 1 FOR UPDATE",
                    'Could not validate the forced SLA');
                if (!mysqli_num_rows($forced_sql)) {
                    throw new RuntimeException('The forced SLA is archived or unavailable');
                }
                $sla_snapshot = slaSnapshotFromRecord(mysqli_fetch_assoc($forced_sql));
            } else {
                $sla_snapshot = slaSnapshotFromRecord(['sla_id' => 0]);
            }
            $manual = !is_null($forced_reason);
            $agreement_decision = [
                'client_id' => $client_id,
                'contract_id' => 0,
                'version_id' => 0,
                'rule_id' => 0,
                'request_type_key' => function_exists('agreementResolveRequestTypeKey')
                    ? agreementResolveRequestTypeKey($row) : '*',
                'priority' => $row['ticket_priority'],
                'sla_id' => $sla_id,
                'sla_snapshot' => $sla_snapshot,
                'classification' => null,
                'classification_basis' => null,
                'source' => $manual ? 'manual_override' : 'forced_restamp',
                'reason' => $manual
                    ? (trim((string) $forced_reason) ?: "Manual SLA override selected SLA $sla_id")
                    : "Maintenance restamp retained forced SLA $sla_id with a new immutable target/calendar snapshot",
            ];
        }

        $calendar = slaNormalizeCalendarSnapshot($sla_snapshot);
        if (intval($sla_snapshot['sla_id'] ?? -1) !== $sla_id) {
            throw new RuntimeException('SLA decision ID does not match its immutable target snapshot');
        }

        $old_calendar = slaCalendarFromTicket($row);
        $old_terms = [
            'sla_id' => intval($row['ticket_sla_id']),
            'response_minutes' => is_null($row['ticket_sla_response_minutes_snapshot'])
                ? null : intval($row['ticket_sla_response_minutes_snapshot']),
            'resolution_minutes' => is_null($row['ticket_sla_resolution_minutes_snapshot'])
                ? null : intval($row['ticket_sla_resolution_minutes_snapshot']),
            'calendar' => $old_calendar,
        ];
        $new_terms = [
            'sla_id' => $sla_id,
            'response_minutes' => is_null($sla_snapshot['response_minutes'] ?? null)
                ? null : intval($sla_snapshot['response_minutes']),
            'resolution_minutes' => is_null($sla_snapshot['resolution_minutes'] ?? null)
                ? null : intval($sla_snapshot['resolution_minutes']),
            'calendar' => $calendar,
        ];
        if ($old_terms !== $new_terms) {
            $open_interval_sql = $sla_query("SELECT sla_history_id, sla_history_started_at
                FROM sla_history WHERE sla_history_ticket_id = $ticket_id
                AND sla_history_ended_at IS NULL ORDER BY sla_history_id DESC
                LIMIT 1 FOR UPDATE", 'Could not lock the prior SLA clock interval');
            if (mysqli_num_rows($open_interval_sql)) {
                $open_interval = mysqli_fetch_assoc($open_interval_sql);
                $interval_id = intval($open_interval['sla_history_id']);
                $elapsed = slaBusinessMinutesBetweenAppTimestamps(
                    $open_interval['sla_history_started_at'],
                    date('Y-m-d H:i:s'),
                    $old_calendar
                );
                $sla_query("UPDATE sla_history SET sla_history_ended_at = NOW(),
                    sla_history_minutes = $elapsed WHERE sla_history_id = $interval_id",
                    'Could not close the prior SLA clock interval');
            }
        }

        $response_due_at = null;
        $response_due_at_utc = null;
        $resolution_due_at = null;
        $resolution_due_at_utc = null;
        $response_met_set = 'NULL';
        $resolution_met_set = 'NULL';
        if ($sla_id > 0) {
            $response_minutes = intval($sla_snapshot['response_minutes'] ?? -1);
            if ($response_minutes < 0) {
                throw new RuntimeException('The selected SLA has no valid response target snapshot');
            }
            $response_due = slaAddBusinessMinutesFromAppTimestamp(
                $row['ticket_created_at'],
                $response_minutes,
                $calendar
            );
            $response_due_at = $response_due['app'];
            $response_due_at_utc = $response_due['utc'];
            $resolution_minutes = is_null($sla_snapshot['resolution_minutes'] ?? null)
                ? null : intval($sla_snapshot['resolution_minutes']);
            if (!is_null($resolution_minutes) && $resolution_minutes < 0) {
                throw new RuntimeException('The selected SLA has an invalid resolution target snapshot');
            }
            if (!is_null($resolution_minutes) && $resolution_minutes > 0) {
                if (getTicketSlaPausedCount($ticket_id, true) > 0) {
                    $remaining = max(0, $resolution_minutes
                        - getTicketSlaConsumedMinutes($ticket_id, $calendar, true));
                    $resolution_due = slaAddBusinessMinutesFromAppTimestamp(
                        date('Y-m-d H:i:s'),
                        $remaining,
                        $calendar
                    );
                } else {
                    $resolution_due = slaAddBusinessMinutesFromAppTimestamp(
                        $row['ticket_created_at'],
                        $resolution_minutes,
                        $calendar
                    );
                }
                $resolution_due_at = $resolution_due['app'];
                $resolution_due_at_utc = $resolution_due['utc'];
            }
            if (!empty($row['ticket_first_response_at'])) {
                $response_met_set = slaAppTimestampInstant($row['ticket_first_response_at'])->getTimestamp()
                    <= slaTicketDueEpoch(['ticket_response_due_at_utc' => $response_due_at_utc], 'response')
                    ? '1' : '0';
            }
            if (!empty($row['ticket_resolved_at']) && !is_null($resolution_due_at)) {
                $resolution_met_set = slaAppTimestampInstant($row['ticket_resolved_at'])->getTimestamp()
                    <= slaTicketDueEpoch(['ticket_resolution_due_at_utc' => $resolution_due_at_utc], 'resolution')
                    ? '1' : '0';
            }
        }

        $response_due_sql = is_null($response_due_at)
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $response_due_at) . "'";
        $resolution_due_sql = is_null($resolution_due_at)
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $resolution_due_at) . "'";
        $response_due_utc_sql = is_null($response_due_at_utc)
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $response_due_at_utc) . "'";
        $resolution_due_utc_sql = is_null($resolution_due_at_utc)
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $resolution_due_at_utc) . "'";
        $response_minutes_sql = is_null($sla_snapshot['response_minutes'] ?? null)
            ? 'NULL' : intval($sla_snapshot['response_minutes']);
        $resolution_minutes_sql = is_null($sla_snapshot['resolution_minutes'] ?? null)
            ? 'NULL' : intval($sla_snapshot['resolution_minutes']);
        $calendar_mode_sql = mysqli_real_escape_string($mysqli, $calendar['calendar_mode']);
        $business_days = implode(',', $calendar['business_days']);
        $business_days_sql = $business_days === ''
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $business_days) . "'";
        $business_start_sql = is_null($calendar['business_hours_start'])
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $calendar['business_hours_start']) . "'";
        $business_end_sql = is_null($calendar['business_hours_end'])
            ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $calendar['business_hours_end']) . "'";
        $timezone_sql = mysqli_real_escape_string($mysqli, $calendar['timezone']);
        $request_type_key = function_exists('agreementNormalizeRequestTypeKey')
            ? agreementNormalizeRequestTypeKey($agreement_decision['request_type_key'] ?? '*') : '*';
        $request_type_key_sql = mysqli_real_escape_string($mysqli, $request_type_key);
        $ticket_billable = is_null($agreement_decision['classification'] ?? null)
            ? intval($row['ticket_billable']) : intval($agreement_decision['ticket_billable'] ?? 0);
        $ticket_onsite = is_null($agreement_decision['classification'] ?? null)
            ? intval($row['ticket_onsite']) : intval($agreement_decision['ticket_onsite'] ?? 0);

        $sla_query("UPDATE tickets SET ticket_sla_id = $sla_id,
            ticket_request_type_key = '$request_type_key_sql',
            ticket_sla_response_minutes_snapshot = $response_minutes_sql,
            ticket_sla_resolution_minutes_snapshot = $resolution_minutes_sql,
            ticket_sla_calendar_mode = '$calendar_mode_sql',
            ticket_sla_business_days = $business_days_sql,
            ticket_sla_business_hours_start = $business_start_sql,
            ticket_sla_business_hours_end = $business_end_sql,
            ticket_sla_timezone = '$timezone_sql',
            ticket_billable = $ticket_billable,
            ticket_onsite = $ticket_onsite,
            ticket_response_due_at = $response_due_sql,
            ticket_response_due_at_utc = $response_due_utc_sql,
            ticket_resolution_due_at = $resolution_due_sql,
            ticket_resolution_due_at_utc = $resolution_due_utc_sql,
            ticket_response_sla_met = $response_met_set,
            ticket_resolution_sla_met = $resolution_met_set,
            ticket_response_sla_alert_stage = 0,
            ticket_resolution_sla_alert_stage = 0
            WHERE ticket_id = $ticket_id AND ticket_client_id = $client_id",
            'Could not stamp the ticket SLA terms');

        $verification = mysqli_fetch_assoc($sla_query("SELECT ticket_client_id, ticket_priority,
            ticket_request_type_key, ticket_billable, ticket_onsite,
            ticket_sla_id, ticket_sla_response_minutes_snapshot,
            ticket_sla_resolution_minutes_snapshot, ticket_sla_calendar_mode,
            ticket_sla_business_days, ticket_sla_business_hours_start,
            ticket_sla_business_hours_end, ticket_sla_timezone,
            ticket_response_due_at, ticket_response_due_at_utc,
            ticket_resolution_due_at, ticket_resolution_due_at_utc
            FROM tickets WHERE ticket_id = $ticket_id LIMIT 1",
            'Could not verify the stamped ticket SLA terms'));
        if (!$verification || intval($verification['ticket_client_id']) !== $client_id
            || (string) $verification['ticket_priority'] !== (string) $row['ticket_priority']
            || (string) $verification['ticket_request_type_key'] !== $request_type_key
            || intval($verification['ticket_billable']) !== $ticket_billable
            || intval($verification['ticket_onsite']) !== $ticket_onsite
            || intval($verification['ticket_sla_id']) !== $sla_id
            || (is_null($verification['ticket_sla_response_minutes_snapshot'])
                ? null : intval($verification['ticket_sla_response_minutes_snapshot']))
                !== (is_null($sla_snapshot['response_minutes'] ?? null)
                    ? null : intval($sla_snapshot['response_minutes']))
            || (is_null($verification['ticket_sla_resolution_minutes_snapshot'])
                ? null : intval($verification['ticket_sla_resolution_minutes_snapshot']))
                !== (is_null($sla_snapshot['resolution_minutes'] ?? null)
                    ? null : intval($sla_snapshot['resolution_minutes']))
            || (string) $verification['ticket_sla_calendar_mode'] !== $calendar['calendar_mode']
            || (string) ($verification['ticket_sla_business_days'] ?? '') !== $business_days
            || (string) ($verification['ticket_sla_business_hours_start'] ?? '')
                !== (string) ($calendar['business_hours_start'] ?? '')
            || (string) ($verification['ticket_sla_business_hours_end'] ?? '')
                !== (string) ($calendar['business_hours_end'] ?? '')
            || (string) ($verification['ticket_sla_timezone'] ?? '') !== $calendar['timezone']
            || (string) ($verification['ticket_response_due_at'] ?? '') !== (string) ($response_due_at ?? '')
            || (string) ($verification['ticket_response_due_at_utc'] ?? '') !== (string) ($response_due_at_utc ?? '')
            || (string) ($verification['ticket_resolution_due_at'] ?? '') !== (string) ($resolution_due_at ?? '')
            || (string) ($verification['ticket_resolution_due_at_utc'] ?? '') !== (string) ($resolution_due_at_utc ?? '')) {
            throw new RuntimeException('The ticket SLA stamp did not verify against its locked decision inputs');
        }

        if (function_exists('agreementRecordTicketDecision')) {
            agreementRecordTicketDecision($ticket_id, $agreement_decision);
        }
        syncTicketSlaClock($ticket_id, true);

        if ($owns_transaction && !mysqli_commit($mysqli)) {
            throw new RuntimeException('Could not commit the ticket SLA decision');
        }
        return true;
    } catch (Throwable $e) {
        if ($owns_transaction) {
            mysqli_rollback($mysqli);
        }
        throw $e;
    }
}

// Human-readable SLA target, e.g. "45 minutes", "4 business hours" or
// "3 business days". The "business" qualifier is dropped when no business
// calendar is configured, because addBusinessMinutes runs 24x7 in that case
// and the promise would otherwise be misleading. The condition mirrors that
// function's own guard.
//
// Anything at or over one business day rolls up to days: with 9-5 hours a
// 1440-minute target is three working days, but "24 business hours" reads to
// a client as tomorrow. The exact deadline always travels next to this text
// in the email, so the wording only has to give the right impression - a
// remainder under five minutes is dropped rather than rendering the likes of
// "1 business hour 1 minute".
function formatSlaMinutes($minutes)
{
    $minutes = intval($minutes);

    $sla_settings = getSlaSettings();
    $day_start = $sla_settings['business_hours_start'];
    $day_end = $sla_settings['business_hours_end'];

    $qualifier = '';
    $day_minutes = 1440;
    if (!empty($sla_settings['business_days']) && !empty($day_start) && !empty($day_end) && $day_start < $day_end) {
        $qualifier = 'business ';
        $working_minutes = intval((strtotime($day_end) - strtotime($day_start)) / 60);
        if ($working_minutes > 0) {
            $day_minutes = $working_minutes;
        }
    }

    if ($minutes < 60) {
        return $minutes . ' minute' . ($minutes == 1 ? '' : 's');
    }

    if ($minutes < $day_minutes) {
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        $text = $hours . ' ' . $qualifier . 'hour' . ($hours == 1 ? '' : 's');
        if ($remainder >= 5) {
            $text .= ' ' . $remainder . ' minutes';
        }

        return $text;
    }

    $days = intdiv($minutes, $day_minutes);
    $hours = intdiv($minutes % $day_minutes, 60);

    $text = $days . ' ' . $qualifier . 'day' . ($days == 1 ? '' : 's');
    if ($hours > 0) {
        $text .= ' ' . $hours . ' hour' . ($hours == 1 ? '' : 's');
    }

    return $text;
}

// Client-facing SLA block for a "ticket created" email. Returns '' when no SLA
// applies to the ticket or the plan carries no response target, so callers can
// append it unconditionally.
//
// Call this AFTER applyTicketSla(), which is what stamps ticket_response_due_at.
//
// The returned HTML deliberately contains NO single or double quotes. Most of
// these email bodies are assembled pre-escaped and handed to addToMailQueue,
// which interpolates the body straight into its INSERT without escaping it, so
// a stray quote here would break the query at those call sites.
function getTicketSlaEmailNotice($ticket_id, $company_phone = '')
{
    global $mysqli;

    $ticket_id = intval($ticket_id);

    $sql = mysqli_query($mysqli, "SELECT ticket_priority, ticket_response_due_at, sla_response_minutes
        FROM tickets
        LEFT JOIN slas ON ticket_sla_id = sla_id
        WHERE ticket_id = $ticket_id LIMIT 1");
    if (!$sql || !mysqli_num_rows($sql)) {
        return '';
    }
    $row = mysqli_fetch_assoc($sql);

    $response_minutes = intval($row['sla_response_minutes']);

    if (empty($row['ticket_response_due_at']) || $response_minutes <= 0) {
        return '';
    }

    // The only value in this notice that comes from the database rather than a
    // literal - escaped so the quote-free guarantee above holds for it too
    $priority = escapeHtml($row['ticket_priority']);
    $target = formatSlaMinutes($response_minutes);
    $due = date('D j M, g:i A', strtotime($row['ticket_response_due_at']));

    $notice = "<br><br>Priority: $priority<br>Target response: within $target (by $due)";

    // Higher priorities get told to phone rather than sit on an email thread
    if (!empty($company_phone) && ($priority == 'Urgent' || $priority == 'High')) {
        if ($priority == 'Urgent') {
            $notice .= "<br><br><strong>This ticket is marked Urgent.</strong> If the issue is stopping work right now, please call us on $company_phone rather than waiting for a reply to this email.";
        } else {
            $notice .= "<br><br><strong>This ticket is marked High priority.</strong> If it is business-impacting, calling us on $company_phone will get you the fastest response.";
        }
    }

    return $notice;
}

// Record the ticket's first response (if not already recorded) and judge the
// response SLA against the stored due date. Replaces the previous inline
// ticket_first_response_at updates so the SLA verdict can never drift from
// the timestamp.
function setTicketFirstResponse($ticket_id)
{
    global $mysqli;

    $ticket_id = intval($ticket_id);

    $sql = mysqli_query($mysqli, "SELECT ticket_first_response_at, ticket_response_due_at,
        ticket_response_due_at_utc FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
    if (!$sql || !mysqli_num_rows($sql)) {
        return;
    }
    $row = mysqli_fetch_assoc($sql);

    if (!empty($row['ticket_first_response_at'])) {
        return;
    }

    $response_met_set = "NULL";
    $response_due_epoch = slaTicketDueEpoch($row, 'response');
    if (!is_null($response_due_epoch)) {
        $response_met_set = time() <= $response_due_epoch ? 1 : 0;
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_first_response_at = NOW(), ticket_response_sla_met = $response_met_set WHERE ticket_id = $ticket_id");
}

// Judge the resolution SLA when a ticket is resolved (or closed without being
// resolved, which also stops the clock). No-op for tickets without a
// resolution target.
function setTicketResolutionSlaMet($ticket_id, bool $strict = false)
{
    global $mysqli;

    $ticket_id = intval($ticket_id);

    $sql = mysqli_query($mysqli, "SELECT ticket_resolution_due_at, ticket_resolution_due_at_utc,
        ticket_resolved_at, ticket_resolution_sla_met
        FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
    if (!$sql || !mysqli_num_rows($sql)) {
        if ($strict) {
            throw new RuntimeException('Could not load the ticket resolution SLA verdict: ' . mysqli_error($mysqli));
        }
        return;
    }
    $row = mysqli_fetch_assoc($sql);

    $resolution_due_epoch = slaTicketDueEpoch($row, 'resolution');
    if (is_null($resolution_due_epoch)) {
        return;
    }

    // A recorded miss is final at judge time. Reopening an exhausted ticket
    // re-bases its deadline to the present, so without this a resolve in the
    // same clock second would grade against that deadline and flip the miss
    // to a met. Only an explicit re-stamp (applyTicketSla) may re-judge.
    if (!is_null($row['ticket_resolution_sla_met']) && intval($row['ticket_resolution_sla_met']) === 0) {
        syncTicketSlaClock($ticket_id, $strict);
        return;
    }

    $ended_at = !empty($row['ticket_resolved_at'])
        ? slaAppTimestampInstant($row['ticket_resolved_at'])->getTimestamp() : time();
    $resolution_met = $ended_at <= $resolution_due_epoch ? 1 : 0;

    $updated = mysqli_query($mysqli, "UPDATE tickets SET ticket_resolution_sla_met = $resolution_met WHERE ticket_id = $ticket_id");
    if (!$updated && $strict) {
        throw new RuntimeException('Could not record the ticket resolution SLA verdict: ' . mysqli_error($mysqli));
    }

    syncTicketSlaClock($ticket_id, $strict);
}

// A reopened ticket goes back on the resolution clock. syncTicketSlaClock
// reopens an interval and re-bases the deadline on the budget that is left, so
// a ticket that was resolved with time to spare gets that remainder back.
//
// A missed verdict survives the reopen when the budget is already spent.
// Without this, re-basing would hand an exhausted ticket a zero-length fresh
// window, and resolving it again straight away would overwrite the recorded
// miss with a met - reopen must never be a way to launder a breach.
function resetTicketResolutionSla($ticket_id)
{
    global $mysqli;

    $ticket_id = intval($ticket_id);

    $sql = mysqli_query($mysqli, "SELECT ticket_resolution_sla_met,
        ticket_sla_resolution_minutes_snapshot, ticket_sla_calendar_mode,
        ticket_sla_business_days, ticket_sla_business_hours_start,
        ticket_sla_business_hours_end, ticket_sla_timezone, sla_resolution_minutes
        FROM tickets LEFT JOIN slas ON ticket_sla_id = sla_id
        WHERE ticket_id = $ticket_id LIMIT 1");
    if ($sql && mysqli_num_rows($sql)) {
        $row = mysqli_fetch_assoc($sql);
        $resolution_minutes = intval(slaTicketTargetMinutes($row, 'resolution'));
        $calendar = slaCalendarFromTicket($row);
        $was_missed = !is_null($row['ticket_resolution_sla_met']) && intval($row['ticket_resolution_sla_met']) === 0;

        if ($was_missed && $resolution_minutes > 0
            && getTicketSlaConsumedMinutes($ticket_id, $calendar) >= $resolution_minutes) {
            // Budget gone: keep the miss and the breach stage (so the cron does
            // not re-alert), just restart the clock for the time-spent record
            syncTicketSlaClock($ticket_id);
            return;
        }
    }

    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolution_sla_met = NULL, ticket_resolution_sla_alert_stage = 0 WHERE ticket_id = $ticket_id");

    syncTicketSlaClock($ticket_id);
}

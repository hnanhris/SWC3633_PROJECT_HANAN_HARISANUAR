<?php
$requiredRole = 'organiser';
require __DIR__ . '/../includes/auth_guard.php';

$name = htmlspecialchars(
    (string) ($_SESSION['full_name'] ?? 'Organiser'),
    ENT_QUOTES,
    'UTF-8'
);

$token = (string) ($_SESSION['access_token'] ?? '');
$apiBase = 'http://localhost/event_ticketing_api/api/';

function organiserApi(string $endpoint, string $token): array
{
    global $apiBase;

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'body' => [],
            'error' => 'PHP cURL extension is not enabled.'
        ];
    }

    $ch = curl_init($apiBase . $endpoint);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $body = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'ok' => $code === 200
            && is_array($body)
            && !empty($body['success']),
        'body' => is_array($body) ? $body : [],
        'error' => $error
    ];
}

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Use the authenticated session identity, with JWT claims as fallback.
// The API filters bookings by organiser; events are filtered here.
$jwtParts = explode('.', $token);
$claims = [];

if (count($jwtParts) === 3) {
    $encodedPayload = strtr($jwtParts[1], '-_', '+/');
    $payload = base64_decode($encodedPayload, true);
    $decoded = $payload !== false ? json_decode($payload, true) : null;

    if (is_array($decoded)) {
        $claims = $decoded;
    }
}

$organiserId = (int) (
    $_SESSION['user_id']
    ?? $claims['user_id']
    ?? $claims['data']['user_id']
    ?? 0
);

$events = [];
$eventError = false;

for ($page = 1; $page <= 30; $page++) {
    $result = organiserApi(
        'events.php?page=' . $page . '&limit=100',
        $token
    );

    if (!$result['ok']) {
        $eventError = true;
        break;
    }

    $batch = $result['body']['data'] ?? [];

    if (!is_array($batch)) {
        $eventError = true;
        break;
    }

    foreach ($batch as $event) {
        if (
            is_array($event)
            && $organiserId > 0
            && (int) ($event['organiser_id'] ?? 0) === $organiserId
        ) {
            $events[] = $event;
        }
    }

    $totalPages = (int) ($result['body']['total_pages'] ?? 1);

    if ($page >= $totalPages || count($batch) === 0) {
        break;
    }
}

$bookingsResult = organiserApi('bookings.php', $token);
$bookings = $bookingsResult['body']['data'] ?? [];

if (!is_array($bookings)) {
    $bookings = [];
}

$confirmed = 0;

foreach ($bookings as $booking) {
    if (
        is_array($booking)
        && ($booking['booking_status'] ?? '') === 'confirmed'
    ) {
        $confirmed++;
    }
}

$published = 0;

foreach ($events as $event) {
    if (($event['status'] ?? '') === 'published') {
        $published++;
    }
}

$recent = array_slice(array_reverse($events), 0, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organiser Workspace | EventTix Hanan</title>

    <style>
        :root {
            --forest: #234638;
            --sage: #78977d;
            --cream: #faf8f1;
            --line: #e2e8dc;
            --ink: #233c32;
            --muted: #738477;
            --pale: #eaf0e7;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .shell {
            max-width: 1450px;
            margin: 0 auto;
            padding: 22px 35px 50px;
        }

        .topnav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 16px 24px;
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 8px 25px #233c3209;
        }

        .logo {
            font-size: 23px;
            font-weight: 900;
            letter-spacing: -1px;
            white-space: nowrap;
        }

        .logo span {
            color: var(--sage);
        }

        .navlinks {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .navlinks a {
            padding: 11px 13px;
            border-radius: 11px;
            font-size: 13px;
            color: #4e6657;
        }

        .navlinks a:hover,
        .navlinks a.active {
            background: var(--pale);
            color: var(--forest);
            font-weight: 700;
        }

        .navlinks a.logout {
            background: var(--forest);
            color: white;
        }

        .heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin: 34px 2px 23px;
        }

        .eyebrow {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: var(--sage);
        }

        .heading h1 {
            margin: 8px 0;
            font-family: Georgia, serif;
            font-size: 39px;
            font-weight: 500;
        }

        .heading p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .profile {
            padding: 14px 20px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 14px;
            font-size: 13px;
        }

        .profile strong {
            color: var(--forest);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.6fr 0.7fr;
            gap: 20px;
            padding: 32px 38px;
            background: var(--forest);
            border-radius: 24px;
            color: white;
        }

        .hero .eyebrow {
            color: #c1d7b9;
        }

        .hero h2 {
            margin: 8px 0 12px;
            font-family: Georgia, serif;
            font-size: 31px;
            font-weight: 500;
            line-height: 1.25;
        }

        .hero p {
            margin: 0;
            color: #d7e7d9;
            font-size: 14px;
            line-height: 1.7;
        }

        .hero-aside {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding-left: 26px;
            border-left: 1px solid #ffffff35;
        }

        .hero-aside strong {
            font-family: Georgia, serif;
            font-size: 28px;
            font-weight: 500;
        }

        .hero-aside small {
            margin-top: 8px;
            color: #d7e7d9;
            line-height: 1.7;
        }

        .warning {
            margin-top: 18px;
            padding: 14px 18px;
            border: 1px solid #eddcb4;
            border-radius: 13px;
            background: #fff6e5;
            color: #88651c;
            font-size: 13px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin: 32px 0 17px;
        }

        .section-heading h2 {
            margin: 0;
            font-family: Georgia, serif;
            font-size: 24px;
            font-weight: 500;
        }

        .section-heading a {
            color: var(--forest);
            font-size: 13px;
            font-weight: bold;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .stat {
            display: flex;
            align-items: center;
            gap: 17px;
            padding: 23px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 18px;
        }

        .stat-icon {
            display: grid;
            place-items: center;
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            background: var(--pale);
            border-radius: 16px;
            color: var(--forest);
            font-size: 24px;
        }

        .stat label {
            display: block;
            margin-bottom: 7px;
            color: var(--muted);
            font-size: 12px;
        }

        .stat strong {
            font-family: Georgia, serif;
            font-size: 30px;
            font-weight: 500;
        }

        .lower {
            display: grid;
            grid-template-columns: 1.6fr 0.8fr;
            gap: 20px;
            margin-top: 28px;
        }

        .panel {
            min-width: 0;
            padding: 24px;
            background: white;
            border: 1px solid var(--line);
            border-radius: 20px;
        }

        .panel h2 {
            margin: 0 0 17px;
            font-family: Georgia, serif;
            font-size: 23px;
            font-weight: 500;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 550px;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            padding: 13px 10px;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            text-align: left;
        }

        td {
            padding: 16px 10px;
            border-bottom: 1px solid #edf1eb;
            font-size: 12px;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .pill {
            display: inline-block;
            padding: 7px 11px;
            background: var(--pale);
            border-radius: 20px;
            color: var(--forest);
            font-size: 11px;
            text-transform: capitalize;
        }

        .pill.cancelled {
            background: #fff0ed;
            color: #ae594d;
        }

        .quicklinks {
            display: grid;
            gap: 11px;
        }

        .quicklinks a {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 14px;
            font-size: 13px;
            font-weight: 700;
        }

        .quicklinks a:hover {
            background: var(--pale);
        }

        .quicklinks small {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-weight: 400;
        }

        .empty {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .footer {
            margin-top: 35px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 1050px) {
            .topnav {
                flex-wrap: wrap;
            }

            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .lower {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .shell {
                padding: 12px;
            }

            .topnav {
                padding: 17px;
            }

            .navlinks a {
                padding: 9px;
            }

            .heading {
                flex-direction: column;
                align-items: flex-start;
            }

            .heading h1 {
                font-size: 32px;
            }

            .hero {
                grid-template-columns: 1fr;
                padding: 25px;
            }

            .hero-aside {
                padding: 18px 0 0;
                border-left: 0;
                border-top: 1px solid #ffffff35;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="topnav">
            <a class="logo" href="organiser.php">
                ✦ EVENT<span>TIX</span>
            </a>

            <nav class="navlinks" aria-label="Organiser navigation">
                <a class="active" href="organiser.php">Overview</a>
                <a href="organiser_events.php">My Events</a>
                <a href="organiser_schedules.php">Schedules</a>
                <a href="organiser_bookings.php">Bookings</a>
                <a class="logout" href="../logout.php">Log out ↗</a>
            </nav>
        </header>

        <div class="heading">
            <div>
                <div class="eyebrow">EventTix / Organiser</div>
                <h1>Your event workspace</h1>
                <p>Keep your events organised and follow your ticket bookings.</p>
            </div>

            <div class="profile">
                ● <?= $name ?> &nbsp;·&nbsp; <strong>Organiser</strong>
            </div>
        </div>

        <section class="hero">
            <div>
                <div class="eyebrow">Your organiser hub</div>
                <h2>Bring your next event<br>to life beautifully.</h2>
                <p>
                    Create memorable experiences, manage schedules,
                    and keep track of every reservation.
                </p>
            </div>

            <div class="hero-aside">
                <strong>Welcome back.</strong>
                <small>
                    Everything you need to manage your events is
                    just a click away.
                </small>
            </div>
        </section>

        <?php if ($organiserId <= 0): ?>
            <div class="warning">
                Unable to determine organiser ID from the login session.
                Check the JWT user_id claim before trusting event statistics.
            </div>
        <?php endif; ?>

        <?php if ($eventError || !$bookingsResult['ok']): ?>
            <div class="warning">
                Some API data could not be loaded.
                Check your JWT session and backend API connection.
            </div>
        <?php endif; ?>

        <div class="section-heading">
            <h2>At a glance</h2>
            <span class="eyebrow">Live API overview</span>
        </div>

        <section class="stats" aria-label="Organiser statistics">
            <div class="stat">
                <div class="stat-icon">◇</div>
                <div>
                    <label>My Events</label>
                    <strong>
                        <?= $organiserId > 0 ? count($events) : '—' ?>
                    </strong>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon">✦</div>
                <div>
                    <label>Published Events</label>
                    <strong>
                        <?= $organiserId > 0 ? $published : '—' ?>
                    </strong>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon">▤</div>
                <div>
                    <label>Confirmed Bookings</label>
                    <strong>
                        <?= $bookingsResult['ok'] ? $confirmed : '—' ?>
                    </strong>
                </div>
            </div>
        </section>

        <div class="lower">
            <section class="panel">
                <div class="section-heading" style="margin: 0 0 12px;">
                    <h2>My recent events</h2>
                    <a href="organiser_events.php">View all →</a>
                </div>

                <?php if (!$recent): ?>
                    <p class="empty">
                        No events found for this organiser yet.
                    </p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Event ID</th>
                                    <th>Event Name</th>
                                    <th>Venue</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($recent as $event): ?>
                                    <tr>
                                        <td>#<?= esc($event['event_id'] ?? '-') ?></td>
                                        <td><?= esc($event['title'] ?? '-') ?></td>
                                        <td><?= esc($event['venue_name'] ?? '-') ?></td>
                                        <td><?= esc($event['category'] ?? '-') ?></td>
                                        <td>
                                            <span class="pill <?= ($event['status'] ?? '') === 'cancelled' ? 'cancelled' : '' ?>">
                                                <?= esc($event['status'] ?? '-') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="panel">
                <h2>Quick management</h2>

                <div class="quicklinks">
                    <a href="organiser_events.php">
                        <span>
                            Manage my events
                            <small>Event details and publishing</small>
                        </span>
                        <span>↗</span>
                    </a>

                    <a href="organiser_schedules.php">
                        <span>
                            Manage schedules
                            <small>Dates, tickets and availability</small>
                        </span>
                        <span>↗</span>
                    </a>

                    <a href="organiser_bookings.php">
                        <span>
                            View event bookings
                            <small>Reservations and statuses</small>
                        </span>
                        <span>↗</span>
                    </a>
                </div>
            </aside>
        </div>

        <div class="footer">
            EventTix · Hanan Organiser Workspace
        </div>
    </div>
</body>
</html>

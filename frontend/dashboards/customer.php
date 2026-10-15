<?php
$requiredRole = 'customer';
require __DIR__ . '/../includes/auth_guard.php';

$name = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');
$token = (string)$_SESSION['access_token'];

function customerGet(string $path, string $token): array
{
    $ch = curl_init('http://localhost/event_ticketing_api/api/' . $path);
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
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $status === 200 && is_array($data) && !empty($data['success']),
        'data' => is_array($data) ? $data : [],
        'error' => $error
    ];
}

function customerEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// The API filters bookings to the authenticated customer.
$bookingResult = customerGet('bookings.php', $token);
$bookings = $bookingResult['data']['data'] ?? [];
if (!is_array($bookings)) {
    $bookings = [];
}

$confirmed = 0;
$tickets = 0;
$spent = 0.0;
foreach ($bookings as $booking) {
    if (!is_array($booking)) {
        continue;
    }
    if (($booking['booking_status'] ?? '') === 'confirmed') {
        $confirmed++;
        $tickets += (int)($booking['quantity'] ?? 0);
        $spent += (float)($booking['total_price'] ?? 0);
    }
}

$events = [];
$eventsOk = true;
for ($page = 1; $page <= 15; $page++) {
    $result = customerGet('events.php?page=' . $page . '&limit=100', $token);
    if (!$result['ok']) {
        $eventsOk = false;
        break;
    }
    $batch = $result['data']['data'] ?? [];
    if (!is_array($batch)) {
        $eventsOk = false;
        break;
    }
    foreach ($batch as $event) {
        if (is_array($event) && ($event['status'] ?? '') === 'published') {
            $events[] = $event;
        }
    }
    if ($page >= (int)($result['data']['total_pages'] ?? 1)) {
        break;
    }
}
$recent = array_slice(array_reverse($bookings), 0, 5);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --sage: #78977d;
            --cream: #faf8f1;
            --ink: #21382c;
            --soft: #dce5d9;
            --muted: #748477;
            --border: #e5e9e0;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: 'DM Sans', Arial, sans-serif;
        }

        a {
            color: inherit;
        }

        .site-header {
            background: var(--white);
            border-bottom: 1px solid var(--border);
        }

        .nav-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 17px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand {
            color: var(--forest);
            font-size: 23px;
            font-weight: 800;
            text-decoration: none;
            letter-spacing: -1px;
        }

        .brand span {
            color: var(--sage);
        }

        .navigation {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .navigation a {
            text-decoration: none;
            color: #647768;
            padding: 11px 15px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            transition: background 0.2s ease;
        }

        .navigation a:hover,
        .navigation a.active {
            background: var(--soft);
            color: var(--forest);
        }

        .navigation a.logout {
            color: #a54c45;
        }

        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 38px 30px 65px;
        }

        .page-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .eyebrow {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 800;
            color: var(--sage);
            margin: 0 0 9px;
        }

        h1,
        h2,
        h3 {
            font-family: 'Playfair Display', Georgia, serif;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 34px;
        }

        .muted {
            color: var(--muted);
            margin: 0;
            line-height: 1.6;
        }

        .profile {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 30px;
            padding: 12px 18px;
            font-size: 13px;
        }

        .profile strong {
            color: var(--forest);
        }

        .hero {
            background: linear-gradient(115deg, #244738, #426b51);
            border-radius: 24px;
            color: white;
            padding: 39px 43px;
            margin-bottom: 26px;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            right: -65px;
            top: -105px;
            width: 310px;
            height: 310px;
            border: 1px solid #ffffff33;
            border-radius: 50%;
            pointer-events: none;
        }

        .hero h2 {
            font-size: 30px;
            margin: 0 0 10px;
        }

        .hero p {
            color: #e2ebe0;
            margin: 0 0 23px;
            max-width: 550px;
            line-height: 1.7;
        }

        .hero a {
            display: inline-block;
            padding: 12px 20px;
            background: var(--cream);
            color: var(--forest);
            text-decoration: none;
            border-radius: 11px;
            font-weight: 800;
            font-size: 13px;
        }

        .warning {
            padding: 15px 18px;
            border-radius: 13px;
            background: #fff2dc;
            color: #8c621c;
            margin-bottom: 22px;
            font-size: 13px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 27px;
        }

        .card,
        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 8px 25px #21382c08;
        }

        .card {
            padding: 24px;
        }

        .card-icon {
            width: 45px;
            height: 45px;
            background: #e9f0e6;
            color: var(--forest);
            display: grid;
            place-items: center;
            border-radius: 13px;
            font-size: 20px;
            margin-bottom: 20px;
        }

        .card p {
            font-size: 13px;
            color: var(--muted);
            margin: 0 0 8px;
        }

        .card h3 {
            font-size: 29px;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .panel {
            padding: 26px;
            margin-bottom: 23px;
            overflow-x: auto;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 19px;
        }

        .section-head h2 {
            font-size: 22px;
            margin: 0;
        }

        .section-head a {
            color: var(--forest);
            font-weight: 800;
            font-size: 13px;
            text-decoration: none;
        }

        table {
            width: 100%;
            min-width: 690px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 16px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            font-size: 13px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .pill {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            background: #eef1e9;
            color: var(--forest);
            font-size: 12px;
            text-transform: capitalize;
        }

        .pill.confirmed {
            background: #e3f3e7;
            color: #276342;
        }

        .pill.cancelled {
            background: #ffeded;
            color: #a74747;
        }

        .empty {
            font-size: 14px;
            color: var(--muted);
            padding: 10px 0;
        }

        .available-number {
            font-size: 26px;
            font-family: 'Playfair Display', Georgia, serif;
            color: var(--forest);
            font-weight: 700;
            margin: 0 0 8px;
        }

        @media (max-width: 1050px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .nav-container,
            .page {
                padding-left: 18px;
                padding-right: 18px;
            }

            .navigation {
                width: 100%;
            }

            .navigation a {
                padding: 9px 11px;
            }

            .hero {
                padding: 28px 24px;
            }

            .hero h2 {
                font-size: 25px;
            }

            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="nav-container">
            <a class="brand" href="customer.php">✦ EVENT<span>TIX</span></a>

            <nav class="navigation" aria-label="Customer navigation">
                <a class="active" href="customer.php">Dashboard</a>
                <a href="customer_events.php">Browse Events</a>
                <a href="customer_bookings.php">My Bookings</a>
                <a class="logout" href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="page">
        <div class="page-top">
            <div>
                <p class="eyebrow">Customer Overview</p>
                <h1>Customer Dashboard</h1>
                <p class="muted">Discover events and keep track of your tickets.</p>
            </div>

            <div class="profile">● <?= $name ?> · <strong>Customer</strong></div>
        </div>

        <section class="hero">
            <h2>Your next great experience awaits.</h2>
            <p>Find events worth remembering, reserve your tickets and keep everything in one place.</p>
            <a href="customer_events.php">Explore Events →</a>
        </section>

        <?php if (!$bookingResult['ok'] || !$eventsOk): ?>
            <div class="warning">
                Some information could not be loaded from the API. Please check your login session and backend connection.
            </div>
        <?php endif; ?>

        <section class="cards" aria-label="Booking statistics">
            <div class="card">
                <div class="card-icon">▤</div>
                <p>Total Bookings</p>
                <h3><?= $bookingResult['ok'] ? count($bookings) : '—' ?></h3>
            </div>

            <div class="card">
                <div class="card-icon">✓</div>
                <p>Confirmed Bookings</p>
                <h3><?= $bookingResult['ok'] ? $confirmed : '—' ?></h3>
            </div>

            <div class="card">
                <div class="card-icon">♧</div>
                <p>Confirmed Tickets</p>
                <h3><?= $bookingResult['ok'] ? $tickets : '—' ?></h3>
            </div>

            <div class="card">
                <div class="card-icon">RM</div>
                <p>Confirmed Booking Value</p>
                <h3><?= $bookingResult['ok'] ? 'RM ' . number_format($spent, 2) : '—' ?></h3>
            </div>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Recent Bookings</h2>
                <a href="customer_bookings.php">View All →</a>
            </div>

            <?php if (!$recent): ?>
                <p class="empty">No bookings yet. Browse events to get started.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Event</th>
                            <th>Date &amp; Time</th>
                            <th>Tickets</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $booking): ?>
                            <?php
                            if (!is_array($booking)) {
                                continue;
                            }
                            $status = strtolower((string)($booking['booking_status'] ?? ''));
                            ?>
                            <tr>
                                <td>#<?= customerEsc($booking['booking_id'] ?? '') ?></td>
                                <td><?= customerEsc($booking['event_title'] ?? '—') ?></td>
                                <td><?= customerEsc($booking['start_time'] ?? '—') ?></td>
                                <td><?= customerEsc($booking['quantity'] ?? 0) ?></td>
                                <td>RM <?= number_format((float)($booking['total_price'] ?? 0), 2) ?></td>
                                <td>
                                    <span class="pill <?= in_array($status, ['confirmed', 'cancelled'], true) ? customerEsc($status) : '' ?>">
                                        <?= customerEsc(ucfirst($status ?: 'Unknown')) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="section-head">
                <h2>Available Events</h2>
                <a href="customer_events.php">Browse Events →</a>
            </div>

            <?php if ($eventsOk): ?>
                <p class="available-number"><?= count($events) ?> Published Events</p>
                <p class="muted">Discover your next favourite experience.</p>
            <?php else: ?>
                <p class="muted">Unable to load published events.</p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

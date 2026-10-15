<?php
$requiredRole = 'organiser';
require __DIR__ . '/../includes/auth_guard.php';

$token = $_SESSION['access_token'];
$name = $_SESSION['full_name'] ?? 'Organiser';
$base = 'http://localhost/event_ticketing_api/api/';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function apiGet(string $endpoint, string $token): array
{
    global $base;

    $ch = curl_init($base . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'status' => $status,
        'data' => is_array($data)
            ? $data
            : ['success' => false, 'message' => $error ?: 'Invalid API response']
    ];
}

// The backend restricts the results to this organiser's events.
// Do not filter ownership in the browser or request bookings as an admin.
$result = apiGet('bookings.php', $token);
$bookings = $result['data']['data'] ?? [];

if (!is_array($bookings)) {
    $bookings = [];
}

$bookings = array_values(array_filter($bookings, 'is_array'));
$error = $result['status'] === 200
    ? ''
    : ($result['data']['message'] ?? 'Unable to load bookings');

$confirmed = 0;
$cancelled = 0;
$tickets = 0;
$revenue = 0.0;

foreach ($bookings as $booking) {
    $status = strtolower((string)($booking['booking_status'] ?? ''));

    if ($status === 'confirmed') {
        $confirmed++;
        $tickets += (int)($booking['quantity'] ?? 0);
        $revenue += (float)($booking['total_price'] ?? 0);
    } elseif ($status === 'cancelled') {
        $cancelled++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Bookings | EventTix</title>
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
            --muted: #77877a;
            --border: #e4e9df;
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

        .nav-shell {
            max-width: 1280px;
            margin: auto;
            padding: 18px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 22px;
            flex-wrap: wrap;
        }

        .brand {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 25px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }

        .brand span {
            color: var(--sage);
        }

        .nav-links {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px;
        }

        .nav-links a {
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            color: #647568;
            transition: background .2s, color .2s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--forest);
            background: #eaf0e7;
        }

        .nav-links a.logout {
            color: #a35d4f;
        }

        .container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 34px 32px 60px;
        }

        .eyebrow {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 800;
            color: var(--sage);
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: clamp(29px, 4vw, 39px);
            margin: 7px 0 8px;
        }

        .page-heading p {
            color: var(--muted);
            margin: 0;
            font-size: 14px;
        }

        .hero {
            background: linear-gradient(115deg, #244738, #51745a);
            color: white;
            border-radius: 22px;
            padding: 31px 35px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border: 1px solid #ffffff32;
            border-radius: 50%;
            top: -125px;
            right: -45px;
            pointer-events: none;
        }

        .hero h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 27px;
            margin: 0 0 9px;
        }

        .hero p {
            margin: 0;
            color: #e4eee3;
            font-size: 14px;
            line-height: 1.6;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 26px;
        }

        .stat {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 23px;
        }

        .stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: #eaf0e7;
            color: var(--forest);
            font-size: 19px;
            margin-bottom: 16px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }

        .stat-value {
            font-size: 27px;
            font-weight: 800;
            margin-top: 7px;
            overflow-wrap: anywhere;
        }

        .alert {
            background: #fff0ed;
            color: #a94439;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 19px;
            padding: 25px;
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
        }

        .toolbar h2 {
            font-family: 'Playfair Display', Georgia, serif;
            margin: 0 0 5px;
            font-size: 22px;
        }

        .toolbar p {
            color: var(--muted);
            font-size: 12px;
            margin: 0;
        }

        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filters input,
        .filters select {
            background: #fff;
            border: 1px solid #dce4d9;
            border-radius: 10px;
            padding: 11px 13px;
            font: inherit;
            font-size: 13px;
            color: var(--ink);
        }

        .filters input {
            width: 255px;
            max-width: 100%;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 930px;
            border-collapse: collapse;
        }

        th,
        td {
            text-align: left;
            padding: 16px 12px;
            border-bottom: 1px solid #edf0e9;
            font-size: 13px;
        }

        th {
            font-size: 11px;
            letter-spacing: .5px;
            text-transform: uppercase;
            color: #7c8a7d;
            font-weight: 800;
        }

        tbody tr:hover {
            background: #fafcf8;
        }

        .pill {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .pill.confirmed {
            background: #e4f3e8;
            color: #287348;
        }

        .pill.cancelled {
            background: #fff0ed;
            color: #b44d42;
        }

        .pill.other {
            background: #eef1e6;
            color: #68774e;
        }

        .empty {
            text-align: center;
            padding: 35px 12px;
            color: var(--muted);
        }

        .note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.7;
            margin: 18px 0 0;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 1000px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {
            .nav-shell,
            .container {
                padding-left: 18px;
                padding-right: 18px;
            }

            .stats {
                gap: 10px;
            }

            .stat {
                padding: 16px;
            }

            .stat-value {
                font-size: 22px;
            }

            .hero {
                padding: 25px 22px;
            }

            .panel {
                padding: 17px;
            }

            .filters,
            .filters input,
            .filters select {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="nav-shell">
            <a class="brand" href="organiser.php">✦ EVENT<span>TIX</span></a>

            <nav class="nav-links" aria-label="Organiser navigation">
                <a href="organiser.php">Dashboard</a>
                <a href="organiser_events.php">My Events</a>
                <a href="organiser_schedules.php">Event Schedules</a>
                <a href="organiser_bookings.php" class="active" aria-current="page">Event Bookings</a>
                <a href="../logout.php" class="logout">Logout ↗</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="page-heading">
            <span class="eyebrow">ORGANISER PORTAL / BOOKINGS</span>
            <h1>Event Bookings</h1>
            <p>Track ticket reservations for your events, <?= h($name) ?>.</p>
        </div>

        <section class="hero">
            <h2>Your bookings, all in one place.</h2>
            <p>Stay updated with confirmed reservations, ticket sales and booking activity.</p>
        </section>

        <?php if ($error): ?>
            <div class="alert" role="alert">
                <?= h($error) ?>
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="Booking statistics">
            <article class="stat">
                <div class="stat-icon">▤</div>
                <div class="stat-label">Total Bookings</div>
                <div class="stat-value"><?= $error ? '—' : count($bookings) ?></div>
            </article>

            <article class="stat">
                <div class="stat-icon">✓</div>
                <div class="stat-label">Confirmed Bookings</div>
                <div class="stat-value"><?= $error ? '—' : $confirmed ?></div>
            </article>

            <article class="stat">
                <div class="stat-icon">◈</div>
                <div class="stat-label">Tickets Sold</div>
                <div class="stat-value"><?= $error ? '—' : $tickets ?></div>
            </article>

            <article class="stat">
                <div class="stat-icon">RM</div>
                <div class="stat-label">Confirmed Booking Value</div>
                <div class="stat-value"><?= $error ? '—' : 'RM ' . number_format($revenue, 2) ?></div>
            </article>
        </section>

        <section class="panel">
            <div class="toolbar">
                <div>
                    <h2>Booking Records</h2>
                    <p><?= $error ? 'Unable to retrieve records' : count($bookings) . ' bookings in your event directory' ?></p>
                </div>

                <div class="filters">
                    <input
                        id="search"
                        type="search"
                        placeholder="Search customer or event..."
                        aria-label="Search bookings"
                    >

                    <select id="statusFilter" aria-label="Filter booking status">
                        <option value="">All Statuses</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Customer</th>
                            <th>Event</th>
                            <th>Schedule Date &amp; Time</th>
                            <th>Quantity</th>
                            <th>Total Price</th>
                            <th>Status</th>
                            <th>Booked At</th>
                        </tr>
                    </thead>
                    <tbody id="bookingRows">
                        <?php foreach ($bookings as $booking): ?>
                            <?php
                                $status = strtolower((string)($booking['booking_status'] ?? ''));
                                $pill = in_array($status, ['confirmed', 'cancelled'], true)
                                    ? $status
                                    : 'other';
                                $searchText = strtolower(
                                    ($booking['customer_name'] ?? '') . ' ' .
                                    ($booking['event_title'] ?? '') . ' ' .
                                    ($booking['booking_id'] ?? '')
                                );
                            ?>
                            <tr
                                data-status="<?= h($status) ?>"
                                data-search="<?= h($searchText) ?>"
                            >
                                <td>#<?= h($booking['booking_id'] ?? '') ?></td>
                                <td><?= h($booking['customer_name'] ?? '—') ?></td>
                                <td><?= h($booking['event_title'] ?? '—') ?></td>
                                <td><?= h($booking['start_time'] ?? '—') ?></td>
                                <td><?= h($booking['quantity'] ?? '0') ?></td>
                                <td>RM <?= number_format((float)($booking['total_price'] ?? 0), 2) ?></td>
                                <td>
                                    <span class="pill <?= h($pill) ?>">
                                        <?= h(ucfirst($status ?: 'Unknown')) ?>
                                    </span>
                                </td>
                                <td><?= h($booking['booked_at'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$bookings): ?>
                            <tr id="emptyInitial">
                                <td colspan="8" class="empty">
                                    <?= $error ? 'Bookings could not be loaded.' : 'No bookings for your events yet.' ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <tr id="noMatch" hidden>
                            <td colspan="8" class="empty">No matching bookings found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="note">
                Read-only view. The API returns only bookings belonging to your events.
                Confirmed booking value does not necessarily represent collected revenue.
            </p>
        </section>
    </main>

    <script>
        const search = document.getElementById('search');
        const statusFilter = document.getElementById('statusFilter');
        const rows = Array.from(document.querySelectorAll('#bookingRows tr[data-status]'));
        const noMatch = document.getElementById('noMatch');

        function filterRows() {
            const query = search.value.toLowerCase().trim();
            const selectedStatus = statusFilter.value;
            let shown = 0;

            rows.forEach(function (row) {
                const matchesStatus = !selectedStatus || row.dataset.status === selectedStatus;
                const matchesSearch = row.dataset.search.includes(query);
                const visible = matchesStatus && matchesSearch;

                row.hidden = !visible;

                if (visible) {
                    shown++;
                }
            });

            noMatch.hidden = shown > 0 || rows.length === 0;
        }

        search.addEventListener('input', filterRows);
        statusFilter.addEventListener('change', filterRows);
    </script>
</body>
</html>

<?php
$requiredRole = 'customer';
require __DIR__ . '/../includes/auth_guard.php';

$token = (string) $_SESSION['access_token'];
$name = htmlspecialchars((string) ($_SESSION['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');

function evtEsc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function evtApi(string $path, string $token): array
{
    $ch = curl_init('http://localhost/event_ticketing_api/api/' . $path);

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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'ok' => $status === 200 && is_array($decoded) && !empty($decoded['success']),
        'data' => is_array($decoded) ? $decoded : [],
        'error' => $error
    ];
}

$events = [];
$eventsError = '';

for ($page = 1; $page <= 50; $page++) {
    $result = evtApi('events.php?page=' . $page . '&limit=100', $token);

    if (!$result['ok']) {
        $eventsError = $result['data']['message'] ?? ($result['error'] ?: 'Could not load events.');
        break;
    }

    $batch = $result['data']['data'] ?? [];

    if (!is_array($batch)) {
        $eventsError = 'Unexpected events response.';
        break;
    }

    foreach ($batch as $event) {
        if (strtolower((string) ($event['status'] ?? '')) === 'published') {
            $events[] = $event;
        }
    }

    if ($page >= (int) ($result['data']['total_pages'] ?? 1)) {
        break;
    }
}

$schedulesResult = evtApi('schedules.php', $token);
$schedulesByEvent = [];

if ($schedulesResult['ok']) {
    foreach (($schedulesResult['data']['data'] ?? []) as $schedule) {
        if (!is_array($schedule)) {
            continue;
        }

        $id = (int) ($schedule['event_id'] ?? 0);

        if ($id > 0) {
            $schedulesByEvent[$id][] = $schedule;
        }
    }
}

foreach ($schedulesByEvent as &$rows) {
    usort($rows, static fn($a, $b) => strcmp(
        (string) ($a['start_time'] ?? ''),
        (string) ($b['start_time'] ?? '')
    ));
}
unset($rows);

$categories = [];

foreach ($events as $event) {
    $category = trim((string) ($event['category'] ?? ''));

    if ($category !== '') {
        $categories[$category] = true;
    }
}

ksort($categories);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Browse Events | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --sage: #78977d;
            --cream: #faf8f1;
            --ink: #21382c;
            --soft: #dce5d9;
            --muted: #738477;
            --border: #e6eae2;
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
            text-decoration: none;
        }

        .site-header {
            background: #fff;
            border-bottom: 1px solid var(--border);
        }

        .header-inner {
            max-width: 1320px;
            margin: auto;
            padding: 18px 36px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .logo {
            color: var(--forest);
            font-size: 23px;
            font-weight: 800;
            letter-spacing: -.7px;
            white-space: nowrap;
        }

        .logo span {
            color: var(--sage);
        }

        .navigation {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .navigation a {
            padding: 11px 16px;
            border-radius: 10px;
            color: #647669;
            font-size: 13px;
            font-weight: 700;
        }

        .navigation a:hover,
        .navigation a.active {
            color: var(--forest);
            background: #eaf0e8;
        }

        .navigation .logout {
            color: #a24e4e;
        }

        .profile {
            color: var(--forest);
            background: #f2f6ef;
            border-radius: 12px;
            padding: 11px 15px;
            font-size: 12px;
            white-space: nowrap;
        }

        .profile strong {
            color: var(--sage);
        }

        .main {
            max-width: 1320px;
            margin: auto;
            padding: 37px 36px 70px;
        }

        .page-title {
            margin-bottom: 25px;
        }

        .page-title h1 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 34px;
            margin: 0 0 8px;
        }

        .muted {
            color: var(--muted);
            margin: 0;
            font-size: 14px;
        }

        .banner {
            background: linear-gradient(115deg, #244738, #64856d);
            color: white;
            border-radius: 22px;
            padding: 36px 39px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .banner::after {
            content: '✦';
            position: absolute;
            right: 48px;
            top: -42px;
            font-size: 170px;
            color: #ffffff17;
            pointer-events: none;
        }

        .banner h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 29px;
            margin: 0 0 10px;
        }

        .banner p {
            color: #e0ece0;
            margin: 0;
            line-height: 1.6;
            font-size: 14px;
        }

        .warning {
            background: #fff2e9;
            color: #94501f;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 13px;
        }

        .filters {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .filters input,
        .filters select {
            padding: 13px 15px;
            border: 1px solid #dce5dc;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
            color: var(--ink);
            background: #fff;
            min-width: 180px;
        }

        .filters input {
            flex: 1;
        }

        .filters input:focus,
        .filters select:focus {
            outline: 2px solid #a4bea7;
            outline-offset: 1px;
        }

        .count {
            font-size: 13px;
            color: var(--muted);
            margin: 0 0 17px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 19px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            box-shadow: 0 8px 28px #24473808;
        }

        .category {
            background: #eaf0e8;
            color: var(--forest);
            font-size: 11px;
            font-weight: 800;
            border-radius: 50px;
            padding: 8px 12px;
            align-self: flex-start;
        }

        .card h3 {
            font-family: 'Playfair Display', Georgia, serif;
            font-size: 21px;
            margin: 0;
        }

        .desc {
            color: #728274;
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
        }

        .meta {
            font-size: 13px;
            color: #536c5b;
            line-height: 1.6;
        }

        .schedule {
            border-top: 1px solid var(--border);
            padding-top: 13px;
            font-size: 13px;
        }

        .schedule p {
            margin: 7px 0;
            color: #667b6b;
        }

        .price {
            color: var(--forest);
            font-size: 21px;
            font-weight: 800;
            margin-top: 9px;
        }

        .button {
            display: block;
            margin-top: auto;
            background: var(--forest);
            color: #fff;
            text-align: center;
            padding: 13px 16px;
            border-radius: 11px;
            font-weight: 700;
            font-size: 13px;
        }

        .button:hover {
            background: #365e48;
        }

        .button.disabled {
            background: #edf0ea;
            color: #8d9b90;
            cursor: not-allowed;
        }

        .empty {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 17px;
            padding: 32px;
            color: var(--muted);
            font-size: 14px;
        }

        @media (max-width: 1100px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .header-inner {
                padding: 17px 20px;
            }

            .navigation {
                order: 3;
                width: 100%;
            }

            .navigation a {
                padding: 10px 12px;
            }

            .main {
                padding: 25px 18px 45px;
            }

            .banner {
                padding: 28px 23px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .filters input,
            .filters select {
                width: 100%;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="logo" href="customer.php">✦ EVENT<span>TIX</span></a>

            <nav class="navigation" aria-label="Customer navigation">
                <a href="customer.php">Dashboard</a>
                <a href="customer_events.php" class="active" aria-current="page">Browse Events</a>
                <a href="customer_bookings.php">My Bookings</a>
                <a href="../logout.php" class="logout">Logout</a>
            </nav>

            <div class="profile">● <?= $name ?> · <strong>Customer</strong></div>
        </div>
    </header>

    <main class="main">
        <div class="page-title">
            <h1>Browse Events</h1>
            <p class="muted">Discover experiences and find your next ticket.</p>
        </div>

        <section class="banner">
            <h2>Find something amazing ✨</h2>
            <p>Explore published events and their available schedules.</p>
        </section>

        <?php if ($eventsError): ?>
            <div class="warning"><?= evtEsc($eventsError) ?></div>
        <?php endif; ?>

        <?php if (!$schedulesResult['ok']): ?>
            <div class="warning">Schedules could not be loaded. Check the API connection.</div>
        <?php endif; ?>

        <div class="filters">
            <input
                type="search"
                id="search"
                placeholder="Search event, venue or location..."
                aria-label="Search events"
            >

            <select id="category" aria-label="Filter by category">
                <option value="">All Categories</option>
                <?php foreach (array_keys($categories) as $category): ?>
                    <option value="<?= evtEsc(strtolower($category)) ?>">
                        <?= evtEsc($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <p class="count" id="count"><?= count($events) ?> published event(s)</p>

        <?php if (!$events): ?>
            <div class="empty">No published events are available yet.</div>
        <?php else: ?>
            <div class="grid" id="eventGrid">
                <?php foreach ($events as $event): ?>
                    <?php
                    $eventId = (int) ($event['event_id'] ?? 0);
                    $schedules = $schedulesByEvent[$eventId] ?? [];

                    $upcoming = array_values(array_filter(
                        $schedules,
                        static fn($schedule) => !empty($schedule['start_time'])
                            && strtotime((string) $schedule['start_time']) >= time()
                    ));

                    $available = array_values(array_filter(
                        $upcoming,
                        static fn($schedule) => (int) ($schedule['available_tickets'] ?? 0) > 0
                    ));

                    $next = $available[0] ?? ($upcoming[0] ?? null);

                    $searchText = strtolower(implode(' ', [
                        $event['title'] ?? '',
                        $event['venue_name'] ?? '',
                        $event['location'] ?? '',
                        $event['description'] ?? ''
                    ]));
                    ?>

                    <article
                        class="card"
                        data-search="<?= evtEsc($searchText) ?>"
                        data-category="<?= evtEsc(strtolower((string) ($event['category'] ?? ''))) ?>"
                    >
                        <span class="category">
                            <?= evtEsc(($event['category'] ?? '') ?: 'Event') ?>
                        </span>

                        <h3><?= evtEsc($event['title'] ?? 'Untitled Event') ?></h3>
                        <p class="desc"><?= evtEsc($event['description'] ?? '') ?></p>

                        <div class="meta">
                            📍 <?= evtEsc($event['venue_name'] ?? 'Venue not assigned') ?>
                            <?php if (!empty($event['location'])): ?>
                                · <?= evtEsc($event['location']) ?>
                            <?php endif; ?>
                        </div>

                        <div class="schedule">
                            <?php if ($next): ?>
                                <p>
                                    📅 <?= evtEsc(date('d M Y, h:i A', strtotime((string) $next['start_time']))) ?>
                                </p>
                                <p>🎟 <?= evtEsc($next['available_tickets'] ?? 0) ?> tickets available</p>
                                <div class="price">
                                    RM <?= number_format((float) ($next['ticket_price'] ?? 0), 2) ?>
                                </div>
                            <?php else: ?>
                                <p>No upcoming schedule currently available.</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($available): ?>
                            <a class="button" href="customer_book.php?event_id=<?= $eventId ?>">
                                View Details &amp; Book →
                            </a>
                        <?php else: ?>
                            <span class="button disabled">Not Available</span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="empty" id="noMatch" hidden>No events match your search.</div>
        <?php endif; ?>
    </main>

    <script>
        const search = document.getElementById('search');
        const category = document.getElementById('category');
        const cards = [...document.querySelectorAll('.card')];
        const count = document.getElementById('count');
        const noMatch = document.getElementById('noMatch');

        function filterEvents() {
            let shown = 0;
            const query = search.value.toLowerCase().trim();
            const selectedCategory = category.value;

            for (const card of cards) {
                const matchesSearch = card.dataset.search.includes(query);
                const matchesCategory = !selectedCategory
                    || card.dataset.category === selectedCategory;
                const visible = matchesSearch && matchesCategory;

                card.style.display = visible ? '' : 'none';

                if (visible) {
                    shown++;
                }
            }

            count.textContent = shown + ' published event(s)';

            if (noMatch) {
                noMatch.hidden = shown > 0;
            }
        }

        search.addEventListener('input', filterEvents);
        category.addEventListener('change', filterEvents);
    </script>
</body>
</html>

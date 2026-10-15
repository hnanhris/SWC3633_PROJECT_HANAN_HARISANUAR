<?php
$requiredRole = 'customer';
require __DIR__ . '/../includes/auth_guard.php';

$token = (string) $_SESSION['access_token'];
$name = (string) ($_SESSION['full_name'] ?? 'Customer');

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

// Load published events through the existing paginated API.
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
        if (is_array($event) && strtolower((string) ($event['status'] ?? '')) === 'published') {
            $events[] = $event;
        }
    }

    if ($page >= (int) ($result['data']['total_pages'] ?? 1)) {
        break;
    }
}

// Load schedules without changing the existing backend or booking flow.
$schedulesResult = evtApi('schedules.php', $token);
$schedulesByEvent = [];

if ($schedulesResult['ok']) {
    $scheduleRows = $schedulesResult['data']['data'] ?? [];

    if (is_array($scheduleRows)) {
        foreach ($scheduleRows as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }

            $eventId = (int) ($schedule['event_id'] ?? 0);

            if ($eventId > 0) {
                $schedulesByEvent[$eventId][] = $schedule;
            }
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Events | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --forest-deep: #19382c;
            --sage: #78977d;
            --cream: #faf8f1;
            --ink: #21382c;
            --muted: #78877d;
            --soft-green: #dce5d9;
            --border: #e6e9e0;
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
            text-decoration: none;
        }

        .site-header {
            background: var(--white);
            border-bottom: 1px solid var(--border);
        }

        .nav-shell {
            max-width: 1320px;
            margin: 0 auto;
            min-height: 84px;
            padding: 15px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .brand {
            color: var(--forest);
            font-weight: 800;
            font-size: 24px;
            letter-spacing: -1px;
            white-space: nowrap;
        }

        .brand span {
            color: var(--sage);
        }

        .nav-links {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .nav-links a {
            padding: 11px 15px;
            color: #617568;
            border-radius: 11px;
            font-size: 13px;
            font-weight: 700;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: var(--soft-green);
            color: var(--forest);
        }

        .nav-links .logout {
            border: 1px solid var(--border);
        }

        .page {
            max-width: 1320px;
            padding: 38px 32px 70px;
            margin: 0 auto;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 26px;
        }

        h1,
        h2,
        h3 {
            margin-top: 0;
        }

        h1,
        .banner h2,
        .event-title {
            font-family: 'Playfair Display', Georgia, serif;
        }

        h1 {
            margin-bottom: 8px;
            font-size: clamp(29px, 3vw, 38px);
        }

        .muted {
            color: var(--muted);
            margin: 0;
            line-height: 1.6;
        }

        .profile {
            padding: 12px 18px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 14px;
            font-size: 13px;
        }

        .profile strong {
            color: var(--forest);
        }

        .banner {
            padding: 34px 38px;
            background: linear-gradient(115deg, var(--forest-deep), #466f55);
            color: var(--white);
            border-radius: 22px;
            margin-bottom: 26px;
            position: relative;
            overflow: hidden;
        }

        .banner::after {
            content: '✦';
            position: absolute;
            right: 45px;
            top: -37px;
            font-size: 160px;
            opacity: 0.09;
            pointer-events: none;
        }

        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 2px;
            font-size: 11px;
            font-weight: 800;
            color: #c9ddca;
            margin-bottom: 12px;
        }

        .banner h2 {
            font-size: clamp(27px, 3vw, 38px);
            margin-bottom: 10px;
        }

        .banner p {
            color: #e3ece1;
            margin: 0;
            font-size: 14px;
        }

        .filters {
            background: var(--white);
            padding: 20px;
            border: 1px solid var(--border);
            border-radius: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .filters input,
        .filters select {
            border: 1px solid #dce3da;
            border-radius: 11px;
            padding: 13px 15px;
            font: inherit;
            font-size: 13px;
            color: var(--ink);
            background: var(--white);
            min-width: 200px;
        }

        .filters input {
            flex: 1;
        }

        .filters input:focus,
        .filters select:focus {
            outline: 2px solid var(--sage);
            outline-offset: 1px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 19px;
        }

        .section-heading h2 {
            font-size: 19px;
            margin: 0;
        }

        .count {
            color: var(--muted);
            font-size: 13px;
        }

        .event-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .event-card {
            display: flex;
            flex-direction: column;
            gap: 15px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 25px rgba(30, 65, 45, 0.035);
        }

        .category {
            display: inline-block;
            align-self: flex-start;
            border-radius: 30px;
            background: var(--soft-green);
            color: var(--forest);
            padding: 7px 12px;
            font-size: 11px;
            font-weight: 800;
        }

        .event-title {
            font-size: 23px;
            margin: 0;
            line-height: 1.3;
        }

        .description {
            color: #6f8074;
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .venue {
            color: #526c5a;
            font-size: 13px;
            line-height: 1.6;
        }

        .schedule-info {
            margin-top: auto;
            padding-top: 17px;
            border-top: 1px solid var(--border);
        }

        .schedule-info p {
            font-size: 13px;
            margin: 6px 0;
            color: #64776a;
        }

        .price {
            margin-top: 13px;
            color: var(--forest);
            font-size: 22px;
            font-weight: 800;
        }

        .book-button {
            display: block;
            text-align: center;
            padding: 13px 16px;
            background: var(--forest);
            color: var(--white);
            border-radius: 11px;
            font-size: 13px;
            font-weight: 800;
        }

        .book-button:hover {
            background: var(--forest-deep);
        }

        .book-button.disabled {
            background: #edf0e9;
            color: #829086;
            cursor: not-allowed;
        }

        .warning {
            background: #fff3e9;
            color: #91592d;
            border: 1px solid #f1dccb;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .empty {
            background: var(--white);
            color: var(--muted);
            border: 1px solid var(--border);
            padding: 34px;
            border-radius: 18px;
            font-size: 14px;
        }

        [hidden] {
            display: none !important;
        }

        @media (max-width: 1050px) {
            .event-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 680px) {
            .nav-shell,
            .page {
                padding-left: 18px;
                padding-right: 18px;
            }

            .event-grid {
                grid-template-columns: 1fr;
            }

            .banner {
                padding: 28px 24px;
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
        <nav class="nav-shell" aria-label="Customer navigation">
            <a class="brand" href="customer.php">✦ EVENT<span>TIX</span></a>

            <div class="nav-links">
                <a href="customer.php">Dashboard</a>
                <a class="active" href="customer_events.php" aria-current="page">Browse Events</a>
                <a href="customer_bookings.php">My Bookings</a>
                <a class="logout" href="../logout.php">Logout ↗</a>
            </div>
        </nav>
    </header>

    <main class="page">
        <div class="page-heading">
            <div>
                <h1>Browse Events</h1>
                <p class="muted">Discover experiences and find your next ticket.</p>
            </div>

            <div class="profile">
                ● <?= evtEsc($name) ?> · <strong>Customer</strong>
            </div>
        </div>

        <section class="banner">
            <div class="eyebrow">Discover · Explore · Experience</div>
            <h2>Find something amazing ✨</h2>
            <p>Explore published events and their available schedules.</p>
        </section>

        <?php if ($eventsError !== ''): ?>
            <div class="warning" role="alert">
                <?= evtEsc($eventsError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$schedulesResult['ok']): ?>
            <div class="warning" role="alert">
                Schedules could not be loaded. Check the API connection.
            </div>
        <?php endif; ?>

        <div class="filters">
            <input
                id="search"
                type="search"
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

        <div class="section-heading">
            <h2>Discover Events</h2>
            <span class="count" id="count"><?= count($events) ?> published event(s)</span>
        </div>

        <?php if (!$events): ?>
            <div class="empty">No published events are available yet.</div>
        <?php else: ?>
            <div class="event-grid" id="eventGrid">
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
                        class="event-card"
                        data-search="<?= evtEsc($searchText) ?>"
                        data-category="<?= evtEsc(strtolower((string) ($event['category'] ?? ''))) ?>"
                    >
                        <span class="category">
                            <?= evtEsc(($event['category'] ?? '') ?: 'Event') ?>
                        </span>

                        <h3 class="event-title">
                            <?= evtEsc($event['title'] ?? 'Untitled Event') ?>
                        </h3>

                        <p class="description">
                            <?= evtEsc($event['description'] ?? '') ?>
                        </p>

                        <div class="venue">
                            📍 <?= evtEsc($event['venue_name'] ?? 'Venue not assigned') ?>

                            <?php if (!empty($event['location'])): ?>
                                · <?= evtEsc($event['location']) ?>
                            <?php endif; ?>
                        </div>

                        <div class="schedule-info">
                            <?php if ($next): ?>
                                <p>
                                    📅 <?= evtEsc(date('d M Y, h:i A', strtotime((string) $next['start_time']))) ?>
                                </p>

                                <p>
                                    🎟 <?= evtEsc($next['available_tickets'] ?? 0) ?> tickets available
                                </p>

                                <div class="price">
                                    RM <?= number_format((float) ($next['ticket_price'] ?? 0), 2) ?>
                                </div>
                            <?php else: ?>
                                <p>No upcoming schedule currently available.</p>
                            <?php endif; ?>
                        </div>

                        <?php if ($available): ?>
                            <a class="book-button" href="customer_book.php?event_id=<?= $eventId ?>">
                                View Details &amp; Book →
                            </a>
                        <?php else: ?>
                            <span class="book-button disabled">Not Available</span>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="empty" id="noMatch" hidden>
                No events match your search.
            </div>
        <?php endif; ?>
    </main>

    <script>
        const searchInput = document.getElementById('search');
        const categorySelect = document.getElementById('category');
        const cards = Array.from(document.querySelectorAll('.event-card'));
        const countLabel = document.getElementById('count');
        const noMatch = document.getElementById('noMatch');

        function filterEvents() {
            const query = searchInput.value.toLowerCase().trim();
            const selectedCategory = categorySelect.value;
            let shown = 0;

            cards.forEach(function (card) {
                const matchesSearch = card.dataset.search.includes(query);
                const matchesCategory = !selectedCategory
                    || card.dataset.category === selectedCategory;
                const visible = matchesSearch && matchesCategory;

                card.hidden = !visible;

                if (visible) {
                    shown++;
                }
            });

            countLabel.textContent = shown + ' published event(s)';

            if (noMatch) {
                noMatch.hidden = shown > 0;
            }
        }

        searchInput.addEventListener('input', filterEvents);
        categorySelect.addEventListener('change', filterEvents);
    </script>
</body>
</html>

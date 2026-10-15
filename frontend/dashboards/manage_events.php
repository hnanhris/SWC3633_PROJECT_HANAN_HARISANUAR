<?php

$requiredRole = 'admin';

require __DIR__ . '/../includes/auth_guard.php';



$token = $_SESSION['access_token'];

$apiBase = 'http://localhost/event_ticketing_api/api/';



function eventApi(string $method, string $endpoint, string $token, ?array $body = null): array {

    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

    if ($body !== null) $headers[] = 'Content-Type: application/json';

    $ch = curl_init($endpoint);

    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,

        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $raw = curl_exec($ch);

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $error = curl_error($ch);

    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;

    return ['status' => $status, 'body' => is_array($json) ? $json : ['message' => $error ?: 'Invalid API response']];

}

function eh($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function eventList(string $endpoint, string $token): array {

    $all = [];

    for ($page = 1; $page <= 100; $page++) {

        $separator = str_contains($endpoint, '?') ? '&' : '?';

        $result = eventApi('GET', $endpoint . $separator . 'page=' . $page . '&limit=100', $token);

        if ($result['status'] !== 200) return ['items' => $all, 'error' => $result['body']['message'] ?? 'Failed to load data'];

        $data = $result['body']['data'] ?? [];

        if (!is_array($data)) return ['items' => $all, 'error' => 'Unexpected API response'];

        $all = array_merge($all, $data);

        $totalPages = (int)($result['body']['total_pages'] ?? 1);

        if ($page >= $totalPages || count($data) === 0) break;

    }

    return ['items' => $all, 'error' => ''];

}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$notice = ''; $noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {

        http_response_code(403); exit('Invalid CSRF token');

    }

    $action = $_POST['action'] ?? '';

    if (in_array($action, ['create', 'update'], true)) {

        $payload = [

            'organiser_id' => (int)($_POST['organiser_id'] ?? 0),

            'venue_id' => (int)($_POST['venue_id'] ?? 0),

            'title' => trim((string)($_POST['title'] ?? '')),

            'description' => trim((string)($_POST['description'] ?? '')),

            'category' => trim((string)($_POST['category'] ?? '')),

            'status' => (string)($_POST['status'] ?? 'draft')

        ];

        $id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);

        if ($payload['organiser_id'] <= 0 || $payload['venue_id'] <= 0 || $payload['title'] === '' || strlen($payload['title']) > 255 || !in_array($payload['status'], ['draft','published','cancelled'], true)) {

            $result = ['status'=>400, 'body'=>['message'=>'Please complete valid event fields.']];

        } elseif ($action === 'update' && (!$id || $id <= 0)) {

            $result = ['status'=>400, 'body'=>['message'=>'Invalid event ID.']];

        } else {

            $url = $apiBase . 'events.php' . ($action === 'update' ? '?id=' . $id : '');

            $result = eventApi($action === 'create' ? 'POST' : 'PUT', $url, $token, $payload);

        }

    } elseif ($action === 'delete') {

        $id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);

        $result = ($id && $id > 0)

            ? eventApi('DELETE', $apiBase . 'events.php?id=' . $id, $token)

            : ['status'=>400, 'body'=>['message'=>'Invalid event ID.']];

    } else {

        $result = ['status'=>400, 'body'=>['message'=>'Invalid action.']];

    }

    $noticeType = ($result['status'] >= 200 && $result['status'] < 300 && !empty($result['body']['success'])) ? 'success' : 'error';

    $notice = $result['body']['message'] ?? 'Request completed.';

    if ($result['status'] === 401) $notice = 'Session expired. Please log in again.';

}

$eventsResult = eventList($apiBase . 'events.php', $token);

$events = $eventsResult['items'];

$usersResult = eventList($apiBase . 'users.php', $token);

$organisers = array_values(array_filter($usersResult['items'], fn($u) => ($u['role'] ?? '') === 'organiser'));

$venuesResult = eventList($apiBase . 'venues.php', $token);

$venues = $venuesResult['items'];

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Events | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --sage: #78977d;
            --cream: #faf8f1;
            --ink: #21382c;
            --soft: #dce5d9;
            --line: #e5e9df;
            --muted: #748479;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
        }

        a { text-decoration: none; }

        .site-header {
            background: #fff;
            border-bottom: 1px solid var(--line);
        }

        .header-inner {
            max-width: 1280px;
            margin: auto;
            padding: 18px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 23px;
            font-weight: 700;
            letter-spacing: -.8px;
            color: var(--forest);
        }

        .brand span { color: var(--sage); }

        .navigation {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .navigation a {
            color: #5f7164;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 13px;
            border-radius: 999px;
        }

        .navigation a:hover,
        .navigation a.active {
            background: var(--soft);
            color: var(--forest);
        }

        .navigation .logout { color: #a3544e; }

        .container {
            max-width: 1280px;
            padding: 38px 28px 70px;
            margin: auto;
        }

        .eyebrow {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 700;
            color: var(--sage);
        }

        .hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 26px;
        }

        h1 {
            font-family: 'Playfair Display', serif;
            font-size: clamp(30px, 4vw, 43px);
            font-weight: 600;
            margin: 8px 0 8px;
        }

        .subtitle { color: var(--muted); margin: 0; }

        .btn {
            background: var(--forest);
            color: white;
            border: none;
            border-radius: 11px;
            padding: 12px 17px;
            font: 600 13px 'DM Sans', sans-serif;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn:hover { background: #355d48; }

        .btn.secondary {
            background: #e9efe6;
            color: var(--forest);
        }

        .btn.danger {
            background: #fff0ed;
            color: #ad5049;
        }

        .btn.small { padding: 8px 12px; }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 17px;
            padding: 22px;
        }

        .summary-card p {
            margin: 0 0 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .summary-card strong {
            font-size: 30px;
            color: var(--forest);
        }

        .panel {
            background: white;
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 24px;
            overflow-x: auto;
        }

        .panel-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .panel-top h2 {
            font-family: 'Playfair Display', serif;
            font-size: 23px;
            margin: 0;
        }

        .filters {
            display: flex;
            gap: 9px;
            flex-wrap: wrap;
        }

        .search,
        .filter-select {
            padding: 11px 13px;
            border: 1px solid #d7e1d5;
            border-radius: 10px;
            background: #fff;
            font: 13px 'DM Sans', sans-serif;
            color: var(--ink);
        }

        .search { min-width: 210px; }

        table {
            width: 100%;
            min-width: 860px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 16px 11px;
            border-bottom: 1px solid #edf0e9;
            text-align: left;
            font-size: 13px;
        }

        th {
            color: var(--muted);
            font-size: 11px;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        .pill {
            display: inline-block;
            border-radius: 99px;
            padding: 7px 11px;
            background: #edf0e9;
            color: #617364;
            text-transform: capitalize;
            font-size: 12px;
        }

        .pill.published {
            background: #e3f3e7;
            color: #287446;
        }

        .pill.cancelled {
            background: #fff0ed;
            color: #ad5049;
        }

        .actions { display: flex; gap: 8px; }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert.success { background: #e3f3e7; color: #287446; }
        .alert.error { background: #fff0ed; color: #ad5049; }
        .muted { color: var(--muted); }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: #15281db3;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 100;
        }

        .modal-backdrop.open { display: flex; }

        .modal {
            background: white;
            width: 100%;
            max-width: 570px;
            padding: 29px;
            border-radius: 20px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal h2 {
            font-family: 'Playfair Display', serif;
            margin: 0 0 23px;
        }

        .field { margin-bottom: 17px; }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        .field input,
        .field select,
        .field textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7e1d5;
            border-radius: 10px;
            font: 14px 'DM Sans', sans-serif;
        }

        .field textarea { min-height: 95px; resize: vertical; }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        @media (max-width: 780px) {
            .container { padding: 25px 16px; }
            .header-inner { padding: 15px 16px; }
            .hero,
            .panel-top { flex-direction: column; align-items: flex-start; }
            .summary { grid-template-columns: 1fr; }
            .search { min-width: 0; width: 100%; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="admin.php">✦ EVENT<span>TIX</span></a>
            <nav class="navigation" aria-label="Admin navigation">
                <a href="admin.php">Overview</a>
                <a href="manage_users.php">Users</a>
                <a class="active" href="manage_events.php">Events</a>
                <a href="manage_venues.php">Venues</a>
                <a href="manage_bookings.php">Bookings</a>
                <a class="logout" href="../logout.php">Logout ↗</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <div class="hero">
            <div>
                <span class="eyebrow">Event administration / 02</span>
                <h1>Manage Events</h1>
                <p class="subtitle">Organise your event collection, all in one place.</p>
            </div>
            <button class="btn" type="button" onclick="openCreate()">+ Add New Event</button>
        </div>

        <?php if ($notice !== ''): ?>
            <div class="alert <?= eh($noticeType) ?>"><?= eh($notice) ?></div>
        <?php endif; ?>

        <?php foreach (['events' => $eventsResult, 'organisers' => $usersResult, 'venues' => $venuesResult] as $key => $response): ?>
            <?php if ($response['error'] !== ''): ?>
                <div class="alert error">Unable to load <?= eh($key) ?>: <?= eh($response['error']) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>

        <section class="summary" aria-label="Event summary">
            <div class="summary-card">
                <p>Total Events</p>
                <strong><?= count($events) ?></strong>
            </div>
            <div class="summary-card">
                <p>Published</p>
                <strong><?= count(array_filter($events, fn($event) => ($event['status'] ?? '') === 'published')) ?></strong>
            </div>
            <div class="summary-card">
                <p>Draft Events</p>
                <strong><?= count(array_filter($events, fn($event) => ($event['status'] ?? '') === 'draft')) ?></strong>
            </div>
        </section>

        <section class="panel">
            <div class="panel-top">
                <h2>Event Directory</h2>
                <div class="filters">
                    <input
                        class="search"
                        id="search"
                        type="search"
                        placeholder="Search events..."
                        aria-label="Search events"
                        oninput="filterEvents()"
                    >
                    <select class="filter-select" id="statusFilter" aria-label="Filter by status" onchange="filterEvents()">
                        <option value="">All statuses</option>
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <table id="eventsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Event Name</th>
                        <th>Organiser</th>
                        <th>Venue</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr data-status="<?= eh($event['status'] ?? '') ?>">
                            <td>#<?= eh($event['event_id'] ?? '') ?></td>
                            <td><strong><?= eh($event['title'] ?? '') ?></strong></td>
                            <td><?= eh($event['organiser_name'] ?? $event['organiser_id'] ?? '') ?></td>
                            <td><?= eh($event['venue_name'] ?? $event['venue_id'] ?? '') ?></td>
                            <td><?= eh($event['category'] ?? '') ?></td>
                            <td>
                                <span class="pill <?= eh(in_array($event['status'] ?? '', ['published', 'cancelled'], true) ? $event['status'] : '') ?>">
                                    <?= eh($event['status'] ?? '') ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <button
                                        class="btn secondary small"
                                        type="button"
                                        data-event="<?= eh(json_encode($event, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>"
                                        onclick="openEdit(this)"
                                    >Edit</button>
                                    <form method="post" onsubmit="return confirm('Delete this event? Events with schedules cannot be deleted.');">
                                        <input type="hidden" name="csrf_token" value="<?= eh($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="event_id" value="<?= eh($event['event_id'] ?? '') ?>">
                                        <button class="btn danger small" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$events): ?>
                        <tr>
                            <td colspan="7" class="muted">No events found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="muted" id="filterEmpty" style="display: none;">No matching events found.</p>
        </section>
    </main>

    <div class="modal-backdrop" id="eventModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <h2 id="modalTitle">Add Event</h2>
            <form method="post" id="eventForm">
                <input type="hidden" name="csrf_token" value="<?= eh($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="event_id" id="eventId">

                <div class="field">
                    <label for="title">Event Title</label>
                    <input id="title" name="title" maxlength="255" required>
                </div>

                <div class="field">
                    <label for="organiserId">Organiser</label>
                    <select id="organiserId" name="organiser_id" required>
                        <option value="">Select organiser</option>
                        <?php foreach ($organisers as $organiser): ?>
                            <option value="<?= eh($organiser['user_id'] ?? '') ?>">
                                <?= eh($organiser['full_name'] ?? '') ?> (#<?= eh($organiser['user_id'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="venueId">Venue</label>
                    <select id="venueId" name="venue_id" required>
                        <option value="">Select venue</option>
                        <?php foreach ($venues as $venue): ?>
                            <option value="<?= eh($venue['venue_id'] ?? '') ?>">
                                <?= eh($venue['venue_name'] ?? '') ?> (#<?= eh($venue['venue_id'] ?? '') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="category">Category</label>
                    <input id="category" name="category" maxlength="150" placeholder="e.g. Concert">
                </div>

                <div class="field">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" placeholder="Describe the event..."></textarea>
                </div>

                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button class="btn secondary" type="button" onclick="closeModal()">Cancel</button>
                    <button class="btn" type="submit">Save Event</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('eventModal');

        function openCreate() {
            document.getElementById('eventForm').reset();
            document.getElementById('modalTitle').textContent = 'Add New Event';
            document.getElementById('formAction').value = 'create';
            document.getElementById('eventId').value = '';
            modal.classList.add('open');
        }

        function openEdit(button) {
            const event = JSON.parse(button.dataset.event);

            document.getElementById('modalTitle').textContent = 'Edit Event';
            document.getElementById('formAction').value = 'update';
            document.getElementById('eventId').value = event.event_id;
            document.getElementById('title').value = event.title ?? '';
            document.getElementById('description').value = event.description ?? '';
            document.getElementById('category').value = event.category ?? '';
            document.getElementById('status').value = event.status ?? 'draft';
            document.getElementById('organiserId').value = event.organiser_id ?? '';
            document.getElementById('venueId').value = event.venue_id ?? '';
            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });

        function filterEvents() {
            const query = document.getElementById('search').value.toLowerCase();
            const status = document.getElementById('statusFilter').value;
            let visible = 0;

            document.querySelectorAll('#eventsTable tbody tr[data-status]').forEach(function (row) {
                const matchesText = row.textContent.toLowerCase().includes(query);
                const matchesStatus = !status || row.dataset.status === status;
                const show = matchesText && matchesStatus;
                row.style.display = show ? '' : 'none';

                if (show) {
                    visible++;
                }
            });

            document.getElementById('filterEmpty').style.display = visible === 0 ? '' : 'none';
        }
    </script>
</body>
</html>

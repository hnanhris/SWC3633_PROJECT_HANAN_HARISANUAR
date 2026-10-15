<?php

$requiredRole = 'organiser';

require __DIR__ . '/../includes/auth_guard.php';

$token = $_SESSION['access_token'];

function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function api(string $method, string $path, string $token, ?array $body = null): array {

    $url = 'http://localhost/event_ticketing_api/api/' . $path;

    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

    if ($body !== null) $headers[] = 'Content-Type: application/json';

    $ch = curl_init($url);

    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>12, CURLOPT_FOLLOWLOCATION=>false]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $err = curl_error($ch); curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    return ['status'=>$status, 'json'=>is_array($decoded) ? $decoded : ['success'=>false,'message'=>$err ?: 'Invalid API response']];

}

// JWT payload is used only to identify the current organiser for UI filtering.

// All write permissions and ownership are enforced independently by events.php.

function jwtUserId(string $token): int {

    $parts = explode('.', $token);

    if (count($parts) !== 3) return 0;

    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if (!is_array($payload)) return 0;

    foreach (['user_id','id','sub'] as $key) {

        if (isset($payload[$key]) && filter_var($payload[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])) return (int)$payload[$key];

    }

    if (isset($payload['data']['user_id'])) return (int)$payload['data']['user_id'];

    return 0;

}

$organiserId = (int)($_SESSION['user_id'] ?? 0);

if ($organiserId < 1) $organiserId = jwtUserId($token);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$notice = ''; $noticeClass = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('Invalid CSRF token'); }

    $action = (string)($_POST['action'] ?? '');

    if ($organiserId < 1) {

        $notice = 'Could not identify your organiser ID. Please log out and log in again.'; $noticeClass = 'bad';

    } elseif (in_array($action, ['create','update'], true)) {

        $body = [

            'organiser_id'=>$organiserId,

            'venue_id'=>(int)($_POST['venue_id'] ?? 0),

            'title'=>trim((string)($_POST['title'] ?? '')),

            'description'=>trim((string)($_POST['description'] ?? '')),

            'category'=>trim((string)($_POST['category'] ?? '')),

            'status'=>(string)($_POST['status'] ?? 'draft')

        ];

        $id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

        if ($body['venue_id'] < 1 || $body['title'] === '' || !in_array($body['status'], ['draft','published','cancelled'], true) || ($action === 'update' && !$id)) {

            $notice = 'Please provide valid event details.'; $noticeClass = 'bad';

        } else {

            $path = 'events.php' . ($action === 'update' ? '?id=' . $id : '');

            $r = api($action === 'create' ? 'POST' : 'PUT', $path, $token, $body);

            $notice = $r['json']['message'] ?? 'Request completed';

            $noticeClass = !empty($r['json']['success']) ? 'ok' : 'bad';

        }

    } elseif ($action === 'delete') {

        $id = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

        if (!$id) { $notice = 'Invalid event ID'; $noticeClass = 'bad'; }

        else {

            $r = api('DELETE', 'events.php?id=' . $id, $token);

            $notice = $r['json']['message'] ?? 'Request completed';

            $noticeClass = !empty($r['json']['success']) ? 'ok' : 'bad';

        }

    }

}

$venuesResult = api('GET', 'venues.php', $token);

$venues = $venuesResult['json']['data'] ?? [];

if (!is_array($venues)) $venues = [];

$allEvents = []; $loadError = '';

for ($page=1; $page<=50; $page++) {

    $r = api('GET', 'events.php?page='.$page.'&limit=100', $token);

    if ($r['status'] !== 200) { $loadError = $r['json']['message'] ?? 'Unable to load events'; break; }

    $batch = $r['json']['data'] ?? [];

    if (!is_array($batch)) { $loadError = 'Unexpected events response'; break; }

    $allEvents = array_merge($allEvents, $batch);

    if ($page >= (int)($r['json']['total_pages'] ?? 1)) break;

}

$myEvents = array_values(array_filter($allEvents, fn($ev) => (int)($ev['organiser_id'] ?? 0) === $organiserId));

usort($myEvents, fn($a,$b) => (int)$b['event_id'] <=> (int)$a['event_id']);

$name = $_SESSION['full_name'] ?? 'Organiser';

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Events | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --sage: #78977d;
            --cream: #faf8f1;
            --ink: #21382c;
            --soft: #dce5d9;
            --line: #e3e8df;
            --muted: #728278;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--cream); color: var(--ink); font-family: 'DM Sans', Arial, sans-serif; }
        button, input, select, textarea { font: inherit; }
        .site-header { background: #fff; border-bottom: 1px solid var(--line); }
        .header-inner { max-width: 1250px; margin: auto; padding: 19px 30px; display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
        .logo { text-decoration: none; font-weight: 800; color: var(--forest); font-size: 22px; letter-spacing: -.8px; }
        .logo span { color: var(--sage); }
        .nav { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .nav a { text-decoration: none; padding: 10px 13px; border-radius: 9px; font-size: 13px; color: #53665a; font-weight: 600; }
        .nav a:hover, .nav a.active { background: var(--soft); color: var(--forest); }
        .nav a.logout { color: #9b4e4e; }
        .container { max-width: 1250px; padding: 36px 30px 70px; margin: auto; }
        .eyebrow { text-transform: uppercase; letter-spacing: 2px; font-size: 11px; font-weight: 700; color: var(--sage); }
        h1, h2 { font-family: 'Playfair Display', Georgia, serif; }
        h1 { font-size: 38px; margin: 8px 0; }
        .muted { color: var(--muted); margin: 0; font-size: 14px; }
        .hero { border-radius: 22px; background: linear-gradient(120deg, var(--forest), #426b50); color: white; padding: 31px 34px; display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin-bottom: 25px; }
        .hero h2 { margin: 5px 0 9px; font-size: 29px; }
        .hero p { margin: 0; color: #dce8dd; font-size: 14px; }
        .hero .eyebrow { color: #c8dec9; }
        .btn { background: var(--forest); color: #fff; border: 1px solid var(--forest); border-radius: 10px; padding: 12px 17px; cursor: pointer; font-size: 13px; font-weight: 700; }
        .btn:hover { background: #365c45; }
        .btn.light { background: #fff; color: var(--forest); border-color: #fff; }
        .btn.secondary { background: #edf3ea; color: var(--forest); border-color: #edf3ea; }
        .btn.danger { background: #fdf0ed; color: #b14b4b; border-color: #fdf0ed; }
        .btn.small { padding: 8px 12px; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 25px; }
        .stat { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 22px; }
        .stat span { font-size: 13px; color: var(--muted); }
        .stat strong { display: block; margin-top: 8px; font-size: 31px; color: var(--forest); }
        .panel { background: #fff; border: 1px solid var(--line); border-radius: 19px; padding: 25px; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
        .toolbar h2 { font-size: 23px; margin: 0; }
        .filters { display: flex; gap: 10px; flex-wrap: wrap; }
        .search, .filter { border: 1px solid #d7e1d6; border-radius: 10px; padding: 11px 13px; background: #fff; color: var(--ink); font-size: 13px; }
        .search { width: 245px; max-width: 100%; }
        .table-wrap { overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; min-width: 770px; }
        th, td { text-align: left; padding: 16px 12px; border-bottom: 1px solid #eef0ea; font-size: 13px; }
        th { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; }
        td strong { color: var(--forest); }
        .status { display: inline-block; padding: 7px 11px; border-radius: 20px; background: #edf2ea; color: #476d4f; text-transform: capitalize; font-weight: 700; font-size: 12px; }
        .status.draft { background: #f5f0e2; color: #967b3d; }
        .status.cancelled { background: #fbeceb; color: #b15454; }
        .actions { display: flex; gap: 8px; }
        .actions form { margin: 0; }
        .alert { padding: 14px 17px; border-radius: 11px; margin-bottom: 17px; font-size: 13px; }
        .alert.ok { background: #e7f3e8; color: #2d6d42; }
        .alert.bad { background: #fbe9e8; color: #a74343; }
        .empty { padding: 24px; color: var(--muted); text-align: center; }
        .backdrop { position: fixed; inset: 0; background: #10251bc2; display: none; justify-content: center; align-items: center; padding: 20px; z-index: 10; }
        .backdrop.open { display: flex; }
        .modal { background: #fff; width: 100%; max-width: 560px; border-radius: 20px; padding: 29px; max-height: 90vh; overflow-y: auto; }
        .modal h2 { margin: 0 0 19px; font-size: 27px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
        .field input, .field select, .field textarea { width: 100%; padding: 12px; border: 1px solid #d7e1d6; border-radius: 10px; background: #fff; color: var(--ink); font-size: 14px; }
        .field textarea { resize: vertical; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 24px; }
        @media (max-width: 700px) {
            .container { padding: 23px 16px; }
            .header-inner { padding: 17px 16px; }
            .stats { grid-template-columns: 1fr; }
            .hero { padding: 24px; }
            h1 { font-size: 30px; }
            .search { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="logo" href="organiser.php">✦ EVENT<span>TIX</span></a>
            <nav class="nav" aria-label="Organiser navigation">
                <a href="organiser.php">Dashboard</a>
                <a class="active" href="organiser_events.php">My Events</a>
                <a href="organiser_schedules.php">Event Schedules</a>
                <a href="organiser_bookings.php">Event Bookings</a>
                <a class="logout" href="../logout.php">Logout ↗</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <div style="margin-bottom: 25px;">
            <span class="eyebrow">Organiser Workspace</span>
            <h1>My Events</h1>
            <p class="muted">Create and manage your own EventTix events, <?= esc($name) ?>.</p>
        </div>

        <section class="hero">
            <div>
                <span class="eyebrow">Your Event Collection</span>
                <h2>Bring your next event to life.</h2>
                <p>Keep everything organised, from your first draft to publication.</p>
            </div>
            <button type="button" class="btn light" onclick="openCreate()" <?= $organiserId < 1 || !$venues ? 'disabled' : '' ?>>+ Add Event</button>
        </section>

        <?php if ($notice !== ''): ?>
            <div class="alert <?= esc($noticeClass) ?>"><?= esc($notice) ?></div>
        <?php endif; ?>

        <?php if ($organiserId < 1): ?>
            <div class="alert bad">Your organiser ID could not be read from the session or JWT. Please log in again.</div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert bad"><?= esc($loadError) ?></div>
        <?php endif; ?>

        <?php if ($venuesResult['status'] !== 200): ?>
            <div class="alert bad">Venue options could not be loaded. Check the venues API.</div>
        <?php endif; ?>

        <?php
            $publishedCount = count(array_filter($myEvents, fn($ev) => ($ev['status'] ?? '') === 'published'));
            $draftCount = count(array_filter($myEvents, fn($ev) => ($ev['status'] ?? '') === 'draft'));
        ?>
        <section class="stats" aria-label="Event statistics">
            <div class="stat"><span>Total Events</span><strong><?= $organiserId > 0 ? count($myEvents) : '—' ?></strong></div>
            <div class="stat"><span>Published Events</span><strong><?= $organiserId > 0 ? $publishedCount : '—' ?></strong></div>
            <div class="stat"><span>Draft Events</span><strong><?= $organiserId > 0 ? $draftCount : '—' ?></strong></div>
        </section>

        <section class="panel">
            <div class="toolbar">
                <h2>Event Directory</h2>
                <div class="filters">
                    <input class="search" id="search" type="search" placeholder="Search my events..." oninput="filterRows()" aria-label="Search events">
                    <select class="filter" id="statusFilter" onchange="filterRows()" aria-label="Filter by status">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Event</th>
                            <th>Venue</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myEvents as $ev): ?>
                            <tr class="event-row" data-status="<?= esc($ev['status'] ?? '') ?>">
                                <td>#<?= esc($ev['event_id'] ?? '') ?></td>
                                <td><strong><?= esc($ev['title'] ?? '') ?></strong></td>
                                <td><?= esc($ev['venue_name'] ?? '') ?></td>
                                <td><?= esc($ev['category'] ?? '') ?></td>
                                <td><span class="status <?= esc($ev['status'] ?? '') ?>"><?= esc($ev['status'] ?? '') ?></span></td>
                                <td>
                                    <div class="actions">
                                        <button
                                            type="button"
                                            class="btn small secondary edit-button"
                                            data-event="<?= esc(json_encode([
                                                'event_id' => $ev['event_id'] ?? '',
                                                'title' => $ev['title'] ?? '',
                                                'venue_id' => $ev['venue_id'] ?? '',
                                                'category' => $ev['category'] ?? '',
                                                'description' => $ev['description'] ?? '',
                                                'status' => $ev['status'] ?? 'draft'
                                            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"
                                        >Edit</button>
                                        <form method="post" onsubmit="return confirm('Delete this event? Events with schedules cannot be deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="event_id" value="<?= esc($ev['event_id'] ?? '') ?>">
                                            <button type="submit" class="btn small danger">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$myEvents): ?>
                            <tr><td colspan="6" class="empty">No events found for your organiser account.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="backdrop" id="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal">
            <h2 id="modalTitle">Add Event</h2>
            <form method="post" id="eventForm">
                <input type="hidden" name="csrf_token" value="<?= esc($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" id="action" value="create">
                <input type="hidden" name="event_id" id="eventId">

                <div class="field">
                    <label for="title">Event Title</label>
                    <input id="title" name="title" required maxlength="255">
                </div>
                <div class="field">
                    <label for="venue">Venue</label>
                    <select id="venue" name="venue_id" required>
                        <option value="">Select venue</option>
                        <?php foreach ($venues as $v): ?>
                            <?php if (!is_array($v)) continue; ?>
                            <option value="<?= esc($v['venue_id'] ?? '') ?>">
                                <?= esc($v['venue_name'] ?? '') ?> — <?= esc($v['location'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="category">Category</label>
                    <input id="category" name="category" placeholder="Concert, Conference, Festival..." required>
                </div>
                <div class="field">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="4"></textarea>
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
                    <button type="button" class="btn secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn">Save Event</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('modal');
        const form = document.getElementById('eventForm');

        function openCreate() {
            form.reset();
            document.getElementById('modalTitle').textContent = 'Add Event';
            document.getElementById('action').value = 'create';
            document.getElementById('eventId').value = '';
            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
        }

        document.querySelectorAll('.edit-button').forEach((button) => {
            button.addEventListener('click', () => {
                const event = JSON.parse(button.dataset.event);
                form.reset();
                document.getElementById('modalTitle').textContent = 'Edit Event';
                document.getElementById('action').value = 'update';
                document.getElementById('eventId').value = event.event_id;
                document.getElementById('title').value = event.title;
                document.getElementById('venue').value = event.venue_id;
                document.getElementById('category').value = event.category;
                document.getElementById('description').value = event.description;
                document.getElementById('status').value = event.status;
                modal.classList.add('open');
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) closeModal();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeModal();
        });

        function filterRows() {
            const query = document.getElementById('search').value.toLowerCase().trim();
            const status = document.getElementById('statusFilter').value;
            document.querySelectorAll('.event-row').forEach((row) => {
                const matchesText = row.textContent.toLowerCase().includes(query);
                const matchesStatus = !status || row.dataset.status === status;
                row.style.display = matchesText && matchesStatus ? '' : 'none';
            });
        }
    </script>
</body>
</html>

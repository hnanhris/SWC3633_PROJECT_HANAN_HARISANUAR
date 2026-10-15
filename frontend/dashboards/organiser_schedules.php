<?php

$requiredRole = 'organiser';

require __DIR__ . '/../includes/auth_guard.php';

$token = $_SESSION['access_token'];

$name = $_SESSION['full_name'] ?? 'Organiser';

$base = 'http://localhost/event_ticketing_api/api/';

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function requestApi($method, $endpoint, $token, $body = null) {

    global $base;

    $ch = curl_init($base . $endpoint);

    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];

    if ($body !== null) $headers[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>12, CURLOPT_FOLLOWLOCATION=>false]);

    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $raw = curl_exec($ch);

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $error = curl_error($ch);

    curl_close($ch);

    $data = is_string($raw) ? json_decode($raw, true) : null;

    return ['status'=>$status, 'data'=>is_array($data)?$data:['success'=>false,'message'=>$error ?: 'Invalid API response']];

}

function ownEvents($token) {

    $all = []; $page=1;

    do {

        $r = requestApi('GET', 'events.php?page='.$page.'&limit=100', $token);

        if ($r['status'] !== 200) return ['error'=>$r['data']['message'] ?? 'Could not load events', 'items'=>[]];

        $rows = $r['data']['data'] ?? [];

        if (!is_array($rows)) break;

        foreach ($rows as $row) $all[]=$row;

        $pages = (int)($r['data']['total_pages'] ?? 1);

        $page++;

    } while ($page <= $pages && $page <= 100);

    return ['error'=>'','items'=>$all];

}

$eventsResult = ownEvents($token);

$myEvents=[];

// Backend does not offer a 'my events' filter; determine owner from signed-in user's JWT subject.

// The session might not contain user_id, so resolve it using the JWT payload only as a UI filter;

// API still verifies JWT signature and ownership on mutations.

$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$userId) {

    $parts = explode('.', $token);

    if (count($parts) === 3) {

        $payload = strtr($parts[1], '-_', '+/');

        $payload .= str_repeat('=', (4-strlen($payload)%4)%4);

        $claims = json_decode(base64_decode($payload, true) ?: '', true);

        if (is_array($claims)) $userId = (int)($claims['user_id'] ?? $claims['data']['user_id'] ?? $claims['sub'] ?? 0);

    }

}

foreach ($eventsResult['items'] as $event) if ((int)($event['organiser_id'] ?? 0) === $userId) $myEvents[]=$event;

$eventIds = array_fill_keys(array_map(fn($e)=>(int)$e['event_id'], $myEvents), true);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));

$notice=''; $noticeType='success';

if ($_SERVER['REQUEST_METHOD']==='POST') {

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid CSRF token'); }

    $action = $_POST['action'] ?? '';

    $id = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

    $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);

    if ($action === 'delete') {

        $result = $id ? requestApi('DELETE', 'schedules.php?id='.$id, $token) : ['status'=>400,'data'=>['message'=>'Invalid schedule ID']];

    } elseif (in_array($action, ['create','update'], true)) {

        $start = trim($_POST['start_time'] ?? ''); $end = trim($_POST['end_time'] ?? '');

        $start = str_replace('T',' ', $start); $end = str_replace('T',' ', $end);

        if (strlen($start)===16) $start .= ':00';

        if (strlen($end)===16) $end .= ':00';

        $total = filter_var($_POST['total_tickets'] ?? null, FILTER_VALIDATE_INT);

        $available = filter_var($_POST['available_tickets'] ?? null, FILTER_VALIDATE_INT);

        $price = $_POST['ticket_price'] ?? '';

        if (!$eventId || !isset($eventIds[$eventId]) || !$total || $total<1 || $available===false || $available<0 || $available>$total || !is_numeric($price) || (float)$price<0 || strtotime($end) <= strtotime($start)) {

            $result=['status'=>400,'data'=>['message'=>'Check event ownership, date/time, ticket counts and price.']];

        } else {

            $body=['event_id'=>$eventId,'start_time'=>$start,'end_time'=>$end,'total_tickets'=>$total,'available_tickets'=>$available,'ticket_price'=>(float)$price];

            $result = $action==='create' ? requestApi('POST','schedules.php',$token,$body) : ($id ? requestApi('PUT','schedules.php?id='.$id,$token,$body) : ['status'=>400,'data'=>['message'=>'Invalid schedule ID']]);

        }

    } else $result=['status'=>400,'data'=>['message'=>'Invalid action']];

    $notice=$result['data']['message'] ?? 'Request completed';

    $noticeType=!empty($result['data']['success'])?'success':'error';

}

$schedulesResult=requestApi('GET','schedules.php',$token);

$allSchedules=$schedulesResult['data']['data'] ?? [];

$schedules=[];

if (is_array($allSchedules)) foreach ($allSchedules as $schedule) if (isset($eventIds[(int)($schedule['event_id'] ?? 0)])) $schedules[]=$schedule;

if ($eventsResult['error'] && !$notice) { $notice=$eventsResult['error']; $noticeType='error'; }

if ($schedulesResult['status'] !== 200 && !$notice) { $notice=$schedulesResult['data']['message'] ?? 'Could not load schedules'; $noticeType='error'; }

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Event Schedules | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --sage: #78977d;
            --cream: #faf8f1;
            --ink: #21382c;
            --soft: #dce5d9;
            --border: #e2e8dd;
            --muted: #758477;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--cream); color: var(--ink); font-family: 'DM Sans', Arial, sans-serif; }
        a { color: inherit; }
        .site-header { background: #fff; border-bottom: 1px solid var(--border); }
        .header-inner { max-width: 1320px; margin: auto; min-height: 82px; padding: 16px 34px; display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
        .logo { text-decoration: none; color: var(--forest); font-size: 24px; font-weight: 700; letter-spacing: -1px; white-space: nowrap; }
        .logo span { color: var(--sage); }
        .navigation { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .navigation a { text-decoration: none; font-size: 13px; font-weight: 600; color: #607568; padding: 12px 14px; border-radius: 10px; }
        .navigation a:hover, .navigation a.active { background: #e9f0e6; color: var(--forest); }
        .navigation a.logout { color: #a44c45; }
        .container { max-width: 1320px; padding: 36px 34px 70px; margin: auto; }
        .heading { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 24px; flex-wrap: wrap; }
        .eyebrow { color: var(--sage); text-transform: uppercase; font-size: 11px; font-weight: 700; letter-spacing: 2px; margin: 0 0 8px; }
        h1, h2 { font-family: 'Playfair Display', Georgia, serif; }
        h1 { font-size: clamp(30px, 4vw, 42px); margin: 0 0 8px; }
        .subtitle { color: var(--muted); font-size: 14px; margin: 0; }
        .btn { display: inline-flex; justify-content: center; align-items: center; border: 0; background: var(--forest); color: white; padding: 12px 18px; border-radius: 11px; font-size: 13px; font-family: inherit; font-weight: 700; cursor: pointer; text-decoration: none; white-space: nowrap; }
        .btn:hover { background: #35634b; }
        .btn:disabled { opacity: .45; cursor: not-allowed; }
        .btn.secondary { background: #e9f0e6; color: var(--forest); }
        .btn.danger { background: #fbece9; color: #aa4e45; }
        .btn.small { padding: 9px 12px; }
        .hero { background: linear-gradient(115deg, #244738, #567c61); border-radius: 22px; padding: 27px 30px; color: #fff; margin-bottom: 24px; }
        .hero h2 { margin: 0 0 7px; font-size: 25px; }
        .hero p { margin: 0; color: #e4efe3; font-size: 14px; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-bottom: 25px; }
        .stat { background: white; border: 1px solid var(--border); border-radius: 17px; padding: 22px; }
        .stat-label { color: var(--muted); font-size: 13px; margin-bottom: 10px; }
        .stat-value { font-size: 30px; font-weight: 700; color: var(--forest); }
        .panel { background: white; border: 1px solid var(--border); border-radius: 19px; padding: 25px; }
        .panel-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 18px; }
        .panel h2 { margin: 0; font-size: 23px; }
        .filters { display: flex; flex-wrap: wrap; gap: 10px; }
        .search, .filter { border: 1px solid var(--border); border-radius: 10px; padding: 11px 13px; font: inherit; font-size: 13px; background: #fff; color: var(--ink); }
        .search { width: 245px; max-width: 100%; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 940px; }
        th, td { text-align: left; border-bottom: 1px solid #edf1eb; padding: 16px 12px; font-size: 13px; }
        th { color: var(--muted); font-size: 11px; letter-spacing: .5px; text-transform: uppercase; }
        td { vertical-align: middle; }
        .pill { display: inline-block; background: #e7f1e5; color: #326447; padding: 7px 10px; border-radius: 30px; font-weight: 700; }
        .actions { display: flex; gap: 8px; }
        .actions form { margin: 0; }
        .alert { border-radius: 12px; padding: 14px 17px; margin-bottom: 20px; font-size: 13px; }
        .alert.success { background: #e8f3e8; color: #326447; }
        .alert.error { background: #fff0ec; color: #a3443d; }
        .empty { color: var(--muted); text-align: center; padding: 25px; }
        .modal-wrap { display: none; position: fixed; inset: 0; z-index: 20; background: #14261caa; padding: 18px; align-items: center; justify-content: center; }
        .modal-wrap.open { display: flex; }
        .modal { width: 100%; max-width: 580px; max-height: 92vh; overflow-y: auto; background: white; padding: 30px; border-radius: 20px; }
        .modal h2 { margin: 0 0 20px; font-size: 27px; }
        .field { margin-bottom: 17px; }
        .field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 8px; }
        .field input, .field select { width: 100%; border: 1px solid var(--border); border-radius: 10px; padding: 12px; font: inherit; font-size: 13px; background: white; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .hint { font-size: 12px; line-height: 1.6; color: var(--muted); }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
        @media (max-width: 800px) {
            .header-inner { padding: 16px 20px; }
            .container { padding: 24px 18px 50px; }
            .stats { grid-template-columns: 1fr; }
            .panel { padding: 18px; }
            .grid { grid-template-columns: 1fr; gap: 0; }
            .search { width: 100%; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="logo" href="organiser.php">✦ EVENT<span>TIX</span></a>
            <nav class="navigation" aria-label="Organiser navigation">
                <a href="organiser.php">Dashboard</a>
                <a href="organiser_events.php">My Events</a>
                <a href="organiser_schedules.php" class="active" aria-current="page">Event Schedules</a>
                <a href="organiser_bookings.php">Event Bookings</a>
                <a class="logout" href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>
    <main class="container">
        <div class="heading">
            <div>
                <p class="eyebrow">Organiser workspace</p>
                <h1>Event Schedules</h1>
                <p class="subtitle">Manage event dates, ticket availability and pricing.</p>
            </div>
            <button type="button" class="btn" onclick="openCreate()" <?= empty($myEvents) ? 'disabled' : '' ?>>+ Add Schedule</button>
        </div>
        <section class="hero">
            <h2>Make every event count.</h2>
            <p>Keep your schedule details organised and your tickets ready for customers.</p>
        </section>
        <?php if ($notice): ?>
            <div class="alert <?= h($noticeType) ?>"><?= h($notice) ?></div>
        <?php endif; ?>
        <section class="stats">
            <div class="stat">
                <div class="stat-label">Total Schedules</div>
                <div class="stat-value"><?= count($schedules) ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Total Tickets</div>
                <div class="stat-value"><?= number_format(array_sum(array_map(fn($s) => (int)($s['total_tickets'] ?? 0), $schedules))) ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Available Tickets</div>
                <div class="stat-value"><?= number_format(array_sum(array_map(fn($s) => (int)($s['available_tickets'] ?? 0), $schedules))) ?></div>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head">
                <h2>Schedule Directory</h2>
                <div class="filters">
                    <input id="search" class="search" type="search" placeholder="Search schedules..." oninput="filterRows()" aria-label="Search schedules">
                    <select id="eventFilter" class="filter" onchange="filterRows()" aria-label="Filter by event">
                        <option value="">All Events</option>
                        <?php foreach ($myEvents as $e): ?>
                            <option value="<?= h($e['event_id']) ?>"><?= h($e['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Event</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Total Tickets</th>
                            <th>Available</th>
                            <th>Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $s): ?>
                            <tr class="schedule-row" data-event="<?= h($s['event_id'] ?? '') ?>">
                                <td>#<?= h($s['schedule_id'] ?? '') ?></td>
                                <td><strong><?= h($s['event_title'] ?? '') ?></strong></td>
                                <td><?= h($s['start_time'] ?? '') ?></td>
                                <td><?= h($s['end_time'] ?? '') ?></td>
                                <td><?= h($s['total_tickets'] ?? '') ?></td>
                                <td><span class="pill"><?= h($s['available_tickets'] ?? '') ?></span></td>
                                <td>RM <?= number_format((float)($s['ticket_price'] ?? 0), 2) ?></td>
                                <td>
                                    <div class="actions">
                                        <button type="button" class="btn secondary small" onclick="openEdit(this)"
                                            data-id="<?= h($s['schedule_id'] ?? '') ?>"
                                            data-event="<?= h($s['event_id'] ?? '') ?>"
                                            data-start="<?= h($s['start_time'] ?? '') ?>"
                                            data-end="<?= h($s['end_time'] ?? '') ?>"
                                            data-total="<?= h($s['total_tickets'] ?? '') ?>"
                                            data-available="<?= h($s['available_tickets'] ?? '') ?>"
                                            data-price="<?= h($s['ticket_price'] ?? '') ?>">Edit</button>
                                        <form method="post" onsubmit="return confirm('Delete this schedule? Schedules with bookings cannot be deleted.');">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="schedule_id" value="<?= h($s['schedule_id'] ?? '') ?>">
                                            <button class="btn danger small" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$schedules): ?>
                            <tr><td colspan="8" class="empty">No schedules for your events yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
    <div class="modal-wrap" id="modal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <h2 id="modalTitle">Add Schedule</h2>
            <form method="post" id="scheduleForm">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" id="action" value="create">
                <input type="hidden" name="schedule_id" id="scheduleId">
                <div class="field">
                    <label for="eventId">Your Event</label>
                    <select id="eventId" name="event_id" required>
                        <?php foreach ($myEvents as $e): ?>
                            <option value="<?= h($e['event_id']) ?>"><?= h($e['title']) ?> (#<?= h($e['event_id']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="start">Start Date &amp; Time</label>
                        <input type="datetime-local" id="start" name="start_time" required>
                    </div>
                    <div class="field">
                        <label for="end">End Date &amp; Time</label>
                        <input type="datetime-local" id="end" name="end_time" required>
                    </div>
                </div>
                <div class="grid">
                    <div class="field">
                        <label for="total">Total Tickets</label>
                        <input type="number" id="total" name="total_tickets" min="1" required>
                    </div>
                    <div class="field">
                        <label for="available">Available Tickets</label>
                        <input type="number" id="available" name="available_tickets" min="0" required>
                    </div>
                </div>
                <div class="field">
                    <label for="price">Ticket Price (RM)</label>
                    <input type="number" id="price" name="ticket_price" min="0" step="0.01" required>
                </div>
                <p class="hint">For new schedules, available tickets should normally equal total tickets. If bookings exist, keep stock consistent with confirmed reservations.</p>
                <div class="modal-actions">
                    <button class="btn secondary" type="button" onclick="closeModal()">Cancel</button>
                    <button class="btn" type="submit">Save Schedule</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        const modal = document.getElementById('modal');
        const form = document.getElementById('scheduleForm');

        function openCreate() {
            form.reset();
            document.getElementById('action').value = 'create';
            document.getElementById('scheduleId').value = '';
            document.getElementById('modalTitle').textContent = 'Add Schedule';
            modal.classList.add('open');
        }

        function openEdit(button) {
            form.reset();
            document.getElementById('action').value = 'update';
            document.getElementById('scheduleId').value = button.dataset.id;
            document.getElementById('eventId').value = button.dataset.event;
            document.getElementById('start').value = button.dataset.start.replace(' ', 'T').slice(0, 16);
            document.getElementById('end').value = button.dataset.end.replace(' ', 'T').slice(0, 16);
            document.getElementById('total').value = button.dataset.total;
            document.getElementById('available').value = button.dataset.available;
            document.getElementById('price').value = button.dataset.price;
            document.getElementById('modalTitle').textContent = 'Edit Schedule';
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

        function filterRows() {
            const query = document.getElementById('search').value.toLowerCase();
            const eventId = document.getElementById('eventFilter').value;
            document.querySelectorAll('.schedule-row').forEach(function (row) {
                const matchesText = row.textContent.toLowerCase().includes(query);
                const matchesEvent = !eventId || row.dataset.event === eventId;
                row.style.display = matchesText && matchesEvent ? '' : 'none';
            });
        }
    </script>
</body>
</html>

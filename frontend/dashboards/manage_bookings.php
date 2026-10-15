<?php
$requiredRole = 'admin';
require __DIR__ . '/../includes/auth_guard.php';

$token = $_SESSION['access_token'];
$base = 'http://localhost/event_ticketing_api/api/';
$name = $_SESSION['full_name'] ?? 'Administrator';

function bookingApi(string $method, string $endpoint, string $token, ?array $payload = null): array
{
    global $base;

    if (!function_exists('curl_init')) {
        return [
            'status' => 0,
            'body' => ['success' => false, 'message' => 'PHP cURL is not enabled']
        ];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($base . $endpoint);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'status' => $status,
        'body' => is_array($json) ? $json : [
            'success' => false,
            'message' => $error ?: 'Invalid API response'
        ]
    ];
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bookingId($value): ?int
{
    $id = filter_var(
        $value,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    return $id === false || $id === null ? null : $id;
}

if (empty($_SESSION['bookings_csrf'])) {
    $_SESSION['bookings_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['bookings_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $action = $_POST['action'] ?? '';
    $id = bookingId($_POST['booking_id'] ?? null);
    $result = [
        'status' => 400,
        'body' => ['success' => false, 'message' => 'Invalid form action']
    ];

    if ($action === 'create') {
        $customer = bookingId($_POST['user_id'] ?? null);
        $schedule = bookingId($_POST['schedule_id'] ?? null);
        $quantity = bookingId($_POST['quantity'] ?? null);

        if ($customer && $schedule && $quantity) {
            $result = bookingApi('POST', 'bookings.php', $token, [
                'user_id' => $customer,
                'schedule_id' => $schedule,
                'quantity' => $quantity
            ]);
        } else {
            $result['body']['message'] = 'Select a customer, schedule and valid quantity';
        }
    } elseif ($action === 'update' && $id) {
        $quantity = bookingId($_POST['quantity'] ?? null);
        $status = $_POST['booking_status'] ?? '';

        if ($quantity && in_array($status, ['confirmed', 'cancelled'], true)) {
            $result = bookingApi('PUT', 'bookings.php?id=' . $id, $token, [
                'quantity' => $quantity,
                'booking_status' => $status
            ]);
        } else {
            $result['body']['message'] = 'Invalid quantity or status';
        }
    } elseif ($action === 'delete' && $id) {
        $result = bookingApi('DELETE', 'bookings.php?id=' . $id, $token);
    }

    $_SESSION['bookings_flash'] = [
        'success' => !empty($result['body']['success'])
            && $result['status'] >= 200
            && $result['status'] < 300,
        'message' => $result['body']['message'] ?? 'Request completed'
    ];

    header('Location: manage_bookings.php', true, 303);
    exit;
}

$flash = $_SESSION['bookings_flash'] ?? null;
unset($_SESSION['bookings_flash']);

$bookingsResult = bookingApi('GET', 'bookings.php', $token);
$usersResult = bookingApi('GET', 'users.php', $token);
$schedulesResult = bookingApi('GET', 'schedules.php', $token);

$bookings = $bookingsResult['body']['data'] ?? [];
$users = $usersResult['body']['data'] ?? [];
$schedules = $schedulesResult['body']['data'] ?? [];

if (!is_array($bookings)) {
    $bookings = [];
}

if (!is_array($users)) {
    $users = [];
}

if (!is_array($schedules)) {
    $schedules = [];
}

$customers = array_values(array_filter(
    $users,
    fn($user) => is_array($user) && ($user['role'] ?? '') === 'customer'
));

$loadError = $bookingsResult['status'] !== 200
    ? ($bookingsResult['body']['message'] ?? 'Cannot load bookings')
    : '';

$selectionError = $usersResult['status'] !== 200 || $schedulesResult['status'] !== 200;
$totalBookings = count($bookings);
$confirmedBookings = count(array_filter(
    $bookings,
    fn($booking) => is_array($booking) && ($booking['booking_status'] ?? '') === 'confirmed'
));
$cancelledBookings = count(array_filter(
    $bookings,
    fn($booking) => is_array($booking) && ($booking['booking_status'] ?? '') === 'cancelled'
));
$totalRevenue = array_reduce(
    $bookings,
    fn($sum, $booking) => $sum + (
        is_array($booking) && ($booking['booking_status'] ?? '') === 'confirmed'
            ? (float) ($booking['total_price'] ?? 0)
            : 0
    ),
    0.0
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Bookings | EventTix</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --forest: #244738;
            --sage: #78977d;
            --cream: #faf8f1;
            --soft: #e8eee5;
            --ink: #21382c;
            --muted: #718076;
            --line: #e2e8de;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
        }

        button,
        input,
        select {
            font: inherit;
        }

        .site-header {
            background: #ffffff;
            border-bottom: 1px solid var(--line);
        }

        .header-inner {
            max-width: 1320px;
            margin: auto;
            padding: 18px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .brand {
            color: var(--forest);
            font-size: 22px;
            font-weight: 700;
            text-decoration: none;
            letter-spacing: 0.5px;
        }

        .brand span {
            color: var(--sage);
        }

        .navigation {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .navigation a {
            color: #61756a;
            text-decoration: none;
            padding: 10px 13px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
        }

        .navigation a:hover,
        .navigation a.active {
            background: var(--soft);
            color: var(--forest);
        }

        .navigation a.logout {
            color: #a1534f;
        }

        .main {
            max-width: 1320px;
            margin: 0 auto;
            padding: 38px 30px 70px;
        }

        .eyebrow {
            color: var(--sage);
            font-size: 11px;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 700;
        }

        h1,
        h2 {
            font-family: 'Playfair Display', Georgia, serif;
        }

        h1 {
            font-size: clamp(30px, 4vw, 42px);
            margin: 10px 0;
        }

        .subtitle {
            color: var(--muted);
            margin: 0;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 27px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 11px;
            padding: 12px 17px;
            background: var(--forest);
            color: white;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn:hover {
            background: #315c47;
        }

        .btn.secondary {
            background: var(--soft);
            color: var(--forest);
        }

        .btn.danger {
            background: #fbece9;
            color: #aa4d46;
        }

        .btn.small {
            padding: 8px 12px;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .hero {
            background: var(--forest);
            color: white;
            border-radius: 20px;
            padding: 27px 30px;
            margin-bottom: 23px;
        }

        .hero p {
            color: #d1dfd2;
            margin: 9px 0 0;
        }

        .hero h2 {
            font-size: 26px;
            margin: 6px 0;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat {
            background: white;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 23px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
            margin: 0 0 12px;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--forest);
            margin: 0;
        }

        .panel {
            background: white;
            border: 1px solid var(--line);
            border-radius: 19px;
            padding: 25px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 19px;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 23px;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .search,
        .filter {
            border: 1px solid var(--line);
            background: white;
            border-radius: 10px;
            padding: 11px 13px;
            color: var(--ink);
            outline-color: var(--sage);
        }

        .search {
            min-width: 220px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            min-width: 930px;
        }

        th,
        td {
            padding: 16px 12px;
            text-align: left;
            border-bottom: 1px solid #edf0eb;
            font-size: 13px;
        }

        th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: var(--muted);
        }

        .status {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 20px;
            background: #e6f0e7;
            color: #286246;
            text-transform: capitalize;
            font-weight: 600;
            font-size: 12px;
        }

        .status.cancelled {
            background: #fbece9;
            color: #a34c46;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .actions form {
            margin: 0;
        }

        .alert {
            padding: 14px 17px;
            border-radius: 11px;
            margin-bottom: 20px;
        }

        .alert.success {
            background: #e5f3e8;
            color: #286246;
        }

        .alert.error {
            background: #fbece9;
            color: #a34c46;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            background: #17271fc2;
            padding: 18px;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            background: var(--cream);
            width: 100%;
            max-width: 530px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 20px;
            padding: 30px;
        }

        .modal h2 {
            margin: 0 0 23px;
        }

        .field {
            margin-bottom: 17px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        .field input,
        .field select {
            display: block;
            width: 100%;
            padding: 12px;
            border: 1px solid #d5dfd4;
            border-radius: 10px;
            background: white;
        }

        .hint {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        .empty {
            color: var(--muted);
            text-align: center;
            padding: 30px;
        }

        @media (max-width: 850px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .page-heading,
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 520px) {
            .main {
                padding: 25px 15px;
            }

            .header-inner {
                padding: 15px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 17px;
            }

            .search {
                min-width: 0;
                width: 100%;
            }
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
                <a href="manage_events.php">Events</a>
                <a href="manage_venues.php">Venues</a>
                <a class="active" href="manage_bookings.php">Bookings</a>
                <a class="logout" href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="main">
        <div class="page-heading">
            <div>
                <div class="eyebrow">EventTix / Admin / Bookings</div>
                <h1>Booking Management</h1>
                <p class="subtitle">Review and manage ticket reservations across your events.</p>
            </div>

            <button
                class="btn"
                type="button"
                onclick="openCreate()"
                <?= ($selectionError || !$customers || !$schedules) ? 'disabled' : '' ?>
            >
                + Add Booking
            </button>
        </div>

        <section class="hero">
            <div class="eyebrow" style="color: #bed6c0;">RESERVATION CONTROL CENTER</div>
            <h2>Every booking, beautifully organised.</h2>
            <p>Welcome, <?= h($name) ?>. Keep your reservations and ticket availability in sync.</p>
        </section>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['success'] ? 'success' : 'error' ?>">
                <?= h($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError): ?>
            <div class="alert error"><?= h($loadError) ?></div>
        <?php endif; ?>

        <?php if ($selectionError): ?>
            <div class="alert error">
                Customer or schedule options could not be loaded. Check users.php and schedules.php.
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="Booking statistics">
            <div class="stat">
                <p class="stat-label">Total Bookings</p>
                <p class="stat-value"><?= h($totalBookings) ?></p>
            </div>

            <div class="stat">
                <p class="stat-label">Confirmed</p>
                <p class="stat-value"><?= h($confirmedBookings) ?></p>
            </div>

            <div class="stat">
                <p class="stat-label">Cancelled</p>
                <p class="stat-value"><?= h($cancelledBookings) ?></p>
            </div>

            <div class="stat">
                <p class="stat-label">Confirmed Value (RM)</p>
                <p class="stat-value"><?= h(number_format($totalRevenue, 2)) ?></p>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2>Booking Directory</h2>

                <div class="filters">
                    <input
                        class="search"
                        id="search"
                        type="search"
                        placeholder="Search bookings..."
                        oninput="filterRows()"
                        aria-label="Search bookings"
                    >

                    <select class="filter" id="statusFilter" onchange="filterRows()" aria-label="Filter booking status">
                        <option value="all">All Statuses</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Event</th>
                            <th>Schedule</th>
                            <th>Tickets</th>
                            <th>Total (RM)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody id="bookingRows">
                        <?php foreach ($bookings as $booking): ?>
                            <?php if (!is_array($booking)) continue; ?>

                            <tr data-status="<?= h($booking['booking_status'] ?? '') ?>">
                                <td>#<?= h($booking['booking_id'] ?? '') ?></td>
                                <td><?= h($booking['customer_name'] ?? '') ?></td>
                                <td><?= h($booking['event_title'] ?? '') ?></td>
                                <td><?= h($booking['start_time'] ?? '') ?></td>
                                <td><?= h($booking['quantity'] ?? '') ?></td>
                                <td><?= h(number_format((float) ($booking['total_price'] ?? 0), 2)) ?></td>
                                <td>
                                    <span class="status <?= ($booking['booking_status'] ?? '') === 'cancelled' ? 'cancelled' : '' ?>">
                                        <?= h($booking['booking_status'] ?? '') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button
                                            type="button"
                                            class="btn secondary small"
                                            onclick="openEdit(this)"
                                            data-id="<?= h($booking['booking_id'] ?? '') ?>"
                                            data-quantity="<?= h($booking['quantity'] ?? '') ?>"
                                            data-status="<?= h($booking['booking_status'] ?? '') ?>"
                                        >
                                            Edit
                                        </button>

                                        <form method="post" onsubmit="return confirm('Delete this booking? This cannot be undone.');">
                                            <input type="hidden" name="csrf" value="<?= h($_SESSION['bookings_csrf']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="booking_id" value="<?= h($booking['booking_id'] ?? '') ?>">
                                            <button class="btn danger small" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$bookings): ?>
                            <tr>
                                <td class="empty" colspan="8">No bookings available.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="modal-backdrop" id="bookingModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <h2 id="modalTitle">Add Booking</h2>

            <form method="post" id="bookingForm">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['bookings_csrf']) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="booking_id" id="bookingId">

                <div id="createFields">
                    <div class="field">
                        <label for="customer">Customer</label>
                        <select id="customer" name="user_id" required>
                            <option value="">Select customer</option>

                            <?php foreach ($customers as $customer): ?>
                                <option value="<?= h($customer['user_id'] ?? '') ?>">
                                    <?= h($customer['full_name'] ?? '') ?> (#<?= h($customer['user_id'] ?? '') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="schedule">Event Schedule</label>
                        <select id="schedule" name="schedule_id" required>
                            <option value="">Select schedule</option>

                            <?php foreach ($schedules as $schedule): ?>
                                <?php if (!is_array($schedule)) continue; ?>
                                <option value="<?= h($schedule['schedule_id'] ?? '') ?>">
                                    #<?= h($schedule['schedule_id'] ?? '') ?> —
                                    <?= h($schedule['event_title'] ?? $schedule['title'] ?? ('Event #' . ($schedule['event_id'] ?? '?'))) ?> —
                                    <?= h($schedule['start_time'] ?? '') ?>
                                    (RM <?= h($schedule['ticket_price'] ?? '0') ?>,
                                    <?= h($schedule['available_tickets'] ?? '?') ?> left)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label for="quantity">Ticket Quantity</label>
                    <input id="quantity" name="quantity" type="number" min="1" step="1" required>
                </div>

                <div class="field" id="statusField" hidden>
                    <label for="bookingStatus">Booking Status</label>
                    <select id="bookingStatus" name="booking_status" disabled>
                        <option value="confirmed">Confirmed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <p class="hint">
                    Ticket prices and stock are calculated by the backend.
                    Cancelling a confirmed booking restores its reserved tickets.
                </p>

                <div class="modal-actions">
                    <button class="btn secondary" type="button" onclick="closeModal()">Cancel</button>
                    <button class="btn" type="submit">Save Booking</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('bookingModal');

        function openCreate() {
            document.getElementById('bookingForm').reset();
            document.getElementById('modalTitle').textContent = 'Add Booking';
            document.getElementById('formAction').value = 'create';
            document.getElementById('bookingId').value = '';
            document.getElementById('createFields').hidden = false;
            document.getElementById('customer').disabled = false;
            document.getElementById('schedule').disabled = false;
            document.getElementById('statusField').hidden = true;
            document.getElementById('bookingStatus').disabled = true;
            modal.classList.add('open');
        }

        function openEdit(button) {
            document.getElementById('bookingForm').reset();
            document.getElementById('modalTitle').textContent = 'Edit Booking #' + button.dataset.id;
            document.getElementById('formAction').value = 'update';
            document.getElementById('bookingId').value = button.dataset.id;
            document.getElementById('quantity').value = button.dataset.quantity;
            document.getElementById('createFields').hidden = true;
            document.getElementById('customer').disabled = true;
            document.getElementById('schedule').disabled = true;
            document.getElementById('statusField').hidden = false;
            document.getElementById('bookingStatus').disabled = false;
            document.getElementById('bookingStatus').value = button.dataset.status;
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
            const term = document.getElementById('search').value.toLowerCase();
            const status = document.getElementById('statusFilter').value;

            document.querySelectorAll('#bookingRows tr[data-status]').forEach(function (row) {
                const matchesText = row.textContent.toLowerCase().includes(term);
                const matchesStatus = status === 'all' || row.dataset.status === status;
                row.style.display = matchesText && matchesStatus ? '' : 'none';
            });
        }
    </script>
</body>
</html>

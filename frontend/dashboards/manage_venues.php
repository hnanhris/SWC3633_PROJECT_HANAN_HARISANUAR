<?php
$requiredRole = 'admin';
require __DIR__ . '/../includes/auth_guard.php';

$token = $_SESSION['access_token'];
$apiUrl = 'http://localhost/event_ticketing_api/api/venues.php';

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function venueApi(string $method, string $url, string $token, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => ['message' => 'PHP cURL extension is not enabled']];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
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

    $body = is_string($raw) ? json_decode($raw, true) : null;

    return [
        'status' => $status,
        'body' => is_array($body) ? $body : [
            'message' => $error ?: 'Unexpected API response'
        ]
    ];
}

if (empty($_SESSION['venues_csrf'])) {
    $_SESSION['venues_csrf'] = bin2hex(random_bytes(32));
}

$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['venues_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid form token');
    }

    $action = (string) ($_POST['action'] ?? '');
    $id = filter_var(
        $_POST['venue_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($action === 'create' || $action === 'update') {
        $capacity = filter_var(
            $_POST['capacity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $payload = [
            'venue_name' => trim((string) ($_POST['venue_name'] ?? '')),
            'location' => trim((string) ($_POST['location'] ?? '')),
            'capacity' => $capacity,
            'description' => trim((string) ($_POST['description'] ?? ''))
        ];

        if ($payload['venue_name'] === '' || $payload['location'] === '' || $capacity === false) {
            $result = [
                'status' => 400,
                'body' => ['message' => 'Venue name, location and positive capacity are required']
            ];
        } elseif ($action === 'update' && $id === false) {
            $result = ['status' => 400, 'body' => ['message' => 'Invalid venue ID']];
        } else {
            $url = $apiUrl . ($action === 'update' ? '?id=' . $id : '');
            $result = venueApi($action === 'create' ? 'POST' : 'PUT', $url, $token, $payload);
        }
    } elseif ($action === 'delete') {
        $result = $id === false
            ? ['status' => 400, 'body' => ['message' => 'Invalid venue ID']]
            : venueApi('DELETE', $apiUrl . '?id=' . $id, $token);
    } else {
        $result = ['status' => 400, 'body' => ['message' => 'Invalid action']];
    }

    $notice = (string) ($result['body']['message'] ?? 'Request completed');
    $noticeType = !empty($result['body']['success']) ? 'success' : 'error';

    if ($result['status'] === 401) {
        $notice = 'Login expired. Please log in again.';
    }
}

$listing = venueApi('GET', $apiUrl, $token);
$venues = $listing['body']['data'] ?? [];

if (!is_array($venues)) {
    $venues = [];
}

if ($listing['status'] !== 200) {
    $notice = (string) ($listing['body']['message'] ?? 'Unable to load venues');
    $noticeType = 'error';
}

$totalCapacity = array_sum(array_map(
    static fn($venue) => (int) ($venue['capacity'] ?? 0),
    $venues
));
$locations = array_unique(array_filter(array_map(
    static fn($venue) => trim((string) ($venue['location'] ?? '')),
    $venues
)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Venues | EventTix</title>
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

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: 'DM Sans', sans-serif;
        }

        a {
            text-decoration: none;
        }

        .site-header {
            background: white;
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

        .brand span {
            color: var(--sage);
        }

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

        .logout {
            color: var(--forest);
            font-size: 13px;
            font-weight: 700;
        }

        .main {
            max-width: 1280px;
            margin: auto;
            padding: 35px 28px 70px;
        }

        .eyebrow {
            color: var(--sage);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        h1,
        .hero h2 {
            font-family: 'Playfair Display', Georgia, serif;
            font-weight: 600;
        }

        h1 {
            margin: 8px 0;
            font-size: 36px;
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 11px;
            background: var(--forest);
            color: white;
            padding: 12px 19px;
            font: 600 13px 'DM Sans', sans-serif;
            cursor: pointer;
        }

        .btn:hover {
            background: #35614a;
        }

        .btn.secondary {
            background: #e7eee5;
            color: var(--forest);
        }

        .btn.danger {
            background: #fff0ec;
            color: #a3433a;
        }

        .btn.small {
            padding: 8px 12px;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-radius: 20px;
            padding: 28px 32px;
            background: var(--forest);
            color: white;
            margin-bottom: 24px;
        }

        .hero h2 {
            font-size: 26px;
            margin: 7px 0;
        }

        .hero p {
            margin: 0;
            color: #d5e3d8;
            font-size: 13px;
        }

        .hero-icon {
            font-size: 42px;
            opacity: .7;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 25px;
        }

        .stat {
            background: white;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 22px;
        }

        .stat-label {
            font-size: 13px;
            color: var(--muted);
        }

        .stat-number {
            font-size: 29px;
            font-weight: 700;
            color: var(--forest);
            margin-top: 10px;
        }

        .panel {
            background: white;
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 24px;
        }

        .panel-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }

        .panel-top h2 {
            margin: 0 0 5px;
            font-size: 19px;
        }

        .panel-top p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .search {
            width: 100%;
            max-width: 300px;
            padding: 12px 14px;
            border: 1px solid var(--line);
            border-radius: 11px;
            font: inherit;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 780px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 16px 12px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            font-size: 13px;
            vertical-align: middle;
        }

        th {
            color: var(--muted);
            font-size: 12px;
        }

        .capacity-pill {
            display: inline-block;
            border-radius: 30px;
            background: #e8f1e5;
            color: var(--forest);
            padding: 7px 11px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .actions form {
            margin: 0;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .alert.success {
            background: #e8f5e9;
            color: #246d3c;
        }

        .alert.error {
            background: #fff0ed;
            color: #a3433a;
        }

        .muted {
            color: var(--muted);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: #15291fc0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 100;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            width: 100%;
            max-width: 520px;
            max-height: 90vh;
            overflow-y: auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
        }

        .modal h2 {
            font-family: 'Playfair Display', Georgia, serif;
            margin-top: 0;
        }

        .field {
            margin-bottom: 17px;
        }

        .field label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .field input,
        .field textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            font: inherit;
        }

        .field textarea {
            min-height: 90px;
            resize: vertical;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
        }

        @media (max-width: 750px) {
            .main {
                padding: 22px 16px;
            }

            .header-inner {
                padding: 16px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .hero-icon {
                display: none;
            }

            h1 {
                font-size: 30px;
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
                <a class="active" href="manage_venues.php">Venues</a>
                <a href="manage_bookings.php">Bookings</a>
            </nav>

            <a class="logout" href="../logout.php">Log out ↗</a>
        </div>
    </header>

    <main class="main">
        <div class="page-heading">
            <div>
                <div class="eyebrow">EVENTTIX / ADMINISTRATION</div>
                <h1>Manage Venues</h1>
                <p class="subtitle">Organise your spaces, locations and venue capacities.</p>
            </div>

            <button type="button" class="btn" onclick="openCreate()">
                + Add Venue
            </button>
        </div>

        <section class="hero">
            <div>
                <div class="eyebrow" style="color: #b9d5c0;">VENUE MANAGEMENT</div>
                <h2>Every great event needs a place.</h2>
                <p>Keep your venue information accurate and up to date.</p>
            </div>
            <div class="hero-icon" aria-hidden="true">⌂</div>
        </section>

        <?php if ($notice !== ''): ?>
            <div class="alert <?= esc($noticeType) ?>">
                <?= esc($notice) ?>
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="Venue statistics">
            <div class="stat">
                <div class="stat-label">Total Venues</div>
                <div class="stat-number"><?= esc(count($venues)) ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Total Capacity</div>
                <div class="stat-number"><?= esc(number_format($totalCapacity)) ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Unique Locations</div>
                <div class="stat-number"><?= esc(count($locations)) ?></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-top">
                <div>
                    <h2>Venue Directory</h2>
                    <p>Search and manage registered venues.</p>
                </div>
                <input
                    class="search"
                    id="search"
                    type="search"
                    placeholder="Search venues or locations..."
                    aria-label="Search venues"
                >
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Venue ID</th>
                            <th>Venue Name</th>
                            <th>Location</th>
                            <th>Capacity</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="venueRows">
                        <?php foreach ($venues as $venue): ?>
                            <tr>
                                <td>#<?= esc($venue['venue_id'] ?? '') ?></td>
                                <td><strong><?= esc($venue['venue_name'] ?? '') ?></strong></td>
                                <td><?= esc($venue['location'] ?? '') ?></td>
                                <td>
                                    <span class="capacity-pill">
                                        <?= esc($venue['capacity'] ?? '') ?> seats
                                    </span>
                                </td>
                                <td><?= esc($venue['description'] ?? '') ?></td>
                                <td>
                                    <div class="actions">
                                        <button
                                            type="button"
                                            class="btn secondary small"
                                            onclick="openEdit(this)"
                                            data-id="<?= esc($venue['venue_id'] ?? '') ?>"
                                            data-name="<?= esc($venue['venue_name'] ?? '') ?>"
                                            data-location="<?= esc($venue['location'] ?? '') ?>"
                                            data-capacity="<?= esc($venue['capacity'] ?? '') ?>"
                                            data-description="<?= esc($venue['description'] ?? '') ?>"
                                        >
                                            Edit
                                        </button>

                                        <form
                                            method="post"
                                            onsubmit="return confirm('Delete this venue? Venues linked to events cannot be deleted.');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= esc($_SESSION['venues_csrf']) ?>"
                                            >
                                            <input type="hidden" name="action" value="delete">
                                            <input
                                                type="hidden"
                                                name="venue_id"
                                                value="<?= esc($venue['venue_id'] ?? '') ?>"
                                            >
                                            <button type="submit" class="btn danger small">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($venues)): ?>
                            <tr>
                                <td colspan="6" class="muted">No venues found or API data unavailable.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="modal-backdrop" id="venueModal">
        <div
            class="modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="modalTitle"
        >
            <h2 id="modalTitle">Add Venue</h2>

            <form method="post" id="venueForm">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= esc($_SESSION['venues_csrf']) ?>"
                >
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="venue_id" id="venueId">

                <div class="field">
                    <label for="venueName">Venue Name</label>
                    <input
                        id="venueName"
                        name="venue_name"
                        required
                        maxlength="255"
                    >
                </div>

                <div class="field">
                    <label for="venueLocation">Location</label>
                    <input
                        id="venueLocation"
                        name="location"
                        required
                        maxlength="255"
                    >
                </div>

                <div class="field">
                    <label for="venueCapacity">Capacity</label>
                    <input
                        id="venueCapacity"
                        name="capacity"
                        type="number"
                        min="1"
                        step="1"
                        required
                    >
                </div>

                <div class="field">
                    <label for="venueDescription">Description</label>
                    <textarea id="venueDescription" name="description"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn secondary" onclick="closeModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn">Save Venue</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('venueModal');

        function openCreate() {
            document.getElementById('venueForm').reset();
            document.getElementById('modalTitle').textContent = 'Add New Venue';
            document.getElementById('formAction').value = 'create';
            document.getElementById('venueId').value = '';
            modal.classList.add('open');
            document.getElementById('venueName').focus();
        }

        function openEdit(button) {
            document.getElementById('modalTitle').textContent = 'Edit Venue';
            document.getElementById('formAction').value = 'update';
            document.getElementById('venueId').value = button.dataset.id;
            document.getElementById('venueName').value = button.dataset.name;
            document.getElementById('venueLocation').value = button.dataset.location;
            document.getElementById('venueCapacity').value = button.dataset.capacity;
            document.getElementById('venueDescription').value = button.dataset.description;
            modal.classList.add('open');
            document.getElementById('venueName').focus();
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

        document.getElementById('search').addEventListener('input', function () {
            const query = this.value.toLowerCase();

            document.querySelectorAll('#venueRows tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(query)
                    ? ''
                    : 'none';
            });
        });
    </script>
</body>
</html>

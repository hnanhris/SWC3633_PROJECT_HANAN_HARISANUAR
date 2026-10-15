<?php
$requiredRole = 'admin';
require __DIR__ . '/../includes/auth_guard.php';

$token = $_SESSION['access_token'];
$apiUrl = 'http://localhost/event_ticketing_api/api/users.php';
$name = $_SESSION['full_name'] ?? 'Administrator';

function apiRequest(string $method, string $url, string $token, ?array $body = null): array
{
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ];

    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($response) ? json_decode($response, true) : null;

    return [
        'status' => $status,
        'data' => is_array($decoded) ? $decoded : [
            'success' => false,
            'message' => 'Unable to connect to the API'
        ]
    ];
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        exit('Invalid form token');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $body = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'role' => (string) ($_POST['role'] ?? '')
        ];

        $password = (string) ($_POST['password'] ?? '');

        if ($action === 'create' || $password !== '') {
            $body['password'] = $password;
        }

        if ($action === 'create') {
            $result = apiRequest('POST', $apiUrl, $token, $body);
        } else {
            $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $result = $id
                ? apiRequest('PUT', $apiUrl . '?id=' . $id, $token, $body)
                : [
                    'status' => 400,
                    'data' => [
                        'success' => false,
                        'message' => 'Invalid user ID'
                    ]
                ];
        }
    } elseif ($action === 'delete') {
        $id = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $result = $id
            ? apiRequest('DELETE', $apiUrl . '?id=' . $id, $token)
            : [
                'status' => 400,
                'data' => [
                    'success' => false,
                    'message' => 'Invalid user ID'
                ]
            ];
    } else {
        $result = [
            'status' => 400,
            'data' => [
                'success' => false,
                'message' => 'Invalid action'
            ]
        ];
    }

    $notice = $result['data']['message'] ?? 'Request completed';
    $noticeType = !empty($result['data']['success']) ? 'success' : 'error';

    if ($result['status'] === 401) {
        $notice = 'Session expired. Please log in again.';
    }
}

$listResult = apiRequest('GET', $apiUrl, $token);
$users = $listResult['data']['data'] ?? [];

if (!is_array($users)) {
    $users = [];
}

if ($listResult['status'] !== 200 && $notice === '') {
    $notice = $listResult['data']['message'] ?? 'Unable to load users';
    $noticeType = 'error';
}

$roleCounts = [
    'admin' => 0,
    'organiser' => 0,
    'customer' => 0
];

foreach ($users as $user) {
    $role = $user['role'] ?? '';

    if (isset($roleCounts[$role])) {
        $roleCounts[$role]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Users | EventTix Hanan</title>
    <style>
        :root {
            --forest: #234638;
            --sage: #78977d;
            --cream: #faf8f1;
            --pale: #e8eee4;
            --ink: #253d32;
            --muted: #78857c;
            --border: #dfe6db;
            --white: #ffffff;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--cream);
            color: var(--ink);
            font-family: Arial, sans-serif;
        }

        a {
            text-decoration: none;
        }

        .site-header {
            background: var(--white);
            border-bottom: 1px solid var(--border);
        }

        .header-inner {
            max-width: 1440px;
            margin: 0 auto;
            min-height: 82px;
            padding: 16px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .brand {
            color: var(--forest);
            font-size: 23px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .brand span {
            color: var(--sage);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .nav-links a {
            color: #65786b;
            padding: 11px 13px;
            border-radius: 10px;
            font-size: 13px;
        }

        .nav-links a:hover,
        .nav-links a.active {
            background: var(--pale);
            color: var(--forest);
            font-weight: 700;
        }

        .nav-links .logout {
            color: #9b4d4d;
        }

        .container {
            max-width: 1440px;
            padding: 38px 32px 70px;
            margin: 0 auto;
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            margin-bottom: 26px;
        }

        .eyebrow {
            font-size: 11px;
            letter-spacing: 2.5px;
            font-weight: 700;
            color: var(--sage);
            text-transform: uppercase;
        }

        h1 {
            margin: 12px 0 8px;
            font-size: clamp(28px, 3vw, 40px);
            font-family: Georgia, serif;
            font-weight: 600;
            color: var(--forest);
        }

        .subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 12px 18px;
            background: var(--forest);
            color: var(--white);
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
        }

        .btn:hover {
            background: #355b47;
        }

        .btn.secondary {
            background: var(--pale);
            color: var(--forest);
        }

        .btn.danger {
            background: #faeeee;
            color: #aa4545;
        }

        .btn.small {
            padding: 9px 13px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat {
            padding: 23px;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 17px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 13px;
        }

        .stat-number {
            font-family: Georgia, serif;
            color: var(--forest);
            font-size: 33px;
            margin-top: 13px;
        }

        .panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 19px;
            padding: 25px;
        }

        .panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 18px;
        }

        .panel-head h2 {
            font-size: 20px;
            margin: 0;
            color: var(--forest);
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filters input,
        .filters select {
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fcfdfa;
            padding: 11px 13px;
            color: var(--ink);
            font-size: 13px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 680px;
        }

        th,
        td {
            text-align: left;
            padding: 17px 12px;
            border-bottom: 1px solid #edf1e9;
            font-size: 13px;
        }

        th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #89978b;
        }

        .role {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 100px;
            background: var(--pale);
            color: var(--forest);
            text-transform: capitalize;
            font-size: 12px;
            font-weight: 600;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .alert {
            margin-bottom: 20px;
            padding: 15px 18px;
            border-radius: 12px;
            font-size: 14px;
        }

        .alert.success {
            background: #e5f1e7;
            color: #245b3b;
        }

        .alert.error {
            background: #fff0f0;
            color: #ae3c4c;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: #15291fc2;
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
            padding: 30px;
            border-radius: 20px;
            background: var(--cream);
        }

        .modal h2 {
            margin: 0 0 23px;
            font-family: Georgia, serif;
            color: var(--forest);
            font-size: 28px;
        }

        .field {
            margin-bottom: 18px;
        }

        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        .field input,
        .field select {
            width: 100%;
            padding: 13px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            background: var(--white);
        }

        .field small {
            display: block;
            margin-top: 7px;
            color: var(--muted);
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 24px;
        }

        @media (max-width: 850px) {
            .stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 550px) {
            .header-inner,
            .container {
                padding-left: 18px;
                padding-right: 18px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="admin.php">✦ EVENT<span>TIX</span></a>

            <nav class="nav-links" aria-label="Admin navigation">
                <a href="admin.php">Overview</a>
                <a class="active" href="manage_users.php">Users</a>
                <a href="manage_events.php">Events</a>
                <a href="manage_venues.php">Venues</a>
                <a href="manage_bookings.php">Bookings</a>
                <a class="logout" href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <section class="page-head">
            <div>
                <div class="eyebrow">Administration / People</div>
                <h1>People &amp; accounts</h1>
                <p class="subtitle">Welcome, <?= e($name) ?>. Manage the people who make every EventTix experience possible.</p>
            </div>

            <button class="btn" type="button" onclick="openCreate()">
                + Add New User
            </button>
        </section>

        <?php if ($notice !== ''): ?>
            <div class="alert <?= e($noticeType) ?>" role="alert">
                <?= e($notice) ?>
            </div>
        <?php endif; ?>

        <section class="stats" aria-label="User statistics">
            <div class="stat">
                <div class="stat-label">Total Users</div>
                <div class="stat-number"><?= count($users) ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Administrators</div>
                <div class="stat-number"><?= $roleCounts['admin'] ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Organisers</div>
                <div class="stat-number"><?= $roleCounts['organiser'] ?></div>
            </div>

            <div class="stat">
                <div class="stat-label">Customers</div>
                <div class="stat-number"><?= $roleCounts['customer'] ?></div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>Registered users</h2>

                <div class="filters">
                    <input
                        id="userSearch"
                        type="search"
                        placeholder="Search name or email..."
                        aria-label="Search users"
                    >

                    <select id="roleFilter" aria-label="Filter by role">
                        <option value="">All roles</option>
                        <option value="admin">Admin</option>
                        <option value="organiser">Organiser</option>
                        <option value="customer">Customer</option>
                    </select>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersBody">
                        <?php foreach ($users as $user): ?>
                            <tr
                                class="user-row"
                                data-search="<?= e(strtolower(($user['full_name'] ?? '') . ' ' . ($user['email'] ?? ''))) ?>"
                                data-role="<?= e($user['role'] ?? '') ?>"
                            >
                                <td>#<?= e($user['user_id'] ?? '') ?></td>
                                <td><strong><?= e($user['full_name'] ?? '') ?></strong></td>
                                <td><?= e($user['email'] ?? '') ?></td>
                                <td>
                                    <span class="role">
                                        <?= e($user['role'] ?? '') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <button
                                            class="btn secondary small"
                                            type="button"
                                            onclick="openEdit(this)"
                                            data-id="<?= e($user['user_id'] ?? '') ?>"
                                            data-name="<?= e($user['full_name'] ?? '') ?>"
                                            data-email="<?= e($user['email'] ?? '') ?>"
                                            data-role="<?= e($user['role'] ?? '') ?>"
                                        >
                                            Edit
                                        </button>

                                        <form method="post" onsubmit="return confirm('Delete this user?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= e($user['user_id'] ?? '') ?>">

                                            <button class="btn danger small" type="submit">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="5">No users found or API data unavailable.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p id="filterEmpty" style="display:none;color:#78857c;font-size:13px;">
                No users match your search.
            </p>
        </section>
    </main>

    <div class="modal-backdrop" id="userModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <h2 id="modalTitle">Add New User</h2>

            <form method="post" id="userForm">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="user_id" id="formUserId">

                <div class="field">
                    <label for="fullName">Full Name</label>
                    <input id="fullName" name="full_name" required maxlength="150">
                </div>

                <div class="field">
                    <label for="email">Email Address</label>
                    <input id="email" name="email" type="email" required maxlength="255">
                </div>

                <div class="field">
                    <label for="role">User Role</label>
                    <select id="role" name="role" required>
                        <option value="admin">Admin</option>
                        <option value="organiser">Organiser</option>
                        <option value="customer">Customer</option>
                    </select>
                </div>

                <div class="field">
                    <label for="password" id="passwordLabel">Password</label>
                    <input id="password" name="password" type="password" minlength="8" maxlength="72">
                    <small id="passwordHint">Minimum 8 characters.</small>
                </div>

                <div class="modal-actions">
                    <button class="btn secondary" type="button" onclick="closeModal()">
                        Cancel
                    </button>
                    <button class="btn" type="submit">
                        Save User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('userModal');
        const userForm = document.getElementById('userForm');
        const userSearch = document.getElementById('userSearch');
        const roleFilter = document.getElementById('roleFilter');

        function openCreate() {
            document.getElementById('modalTitle').textContent = 'Add New User';
            document.getElementById('formAction').value = 'create';
            userForm.reset();
            document.getElementById('formUserId').value = '';
            document.getElementById('password').required = true;
            document.getElementById('passwordLabel').textContent = 'Password';
            document.getElementById('passwordHint').textContent = 'Minimum 8 characters.';
            modal.classList.add('open');
        }

        function openEdit(button) {
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formUserId').value = button.dataset.id;
            document.getElementById('fullName').value = button.dataset.name;
            document.getElementById('email').value = button.dataset.email;
            document.getElementById('role').value = button.dataset.role;
            document.getElementById('password').value = '';
            document.getElementById('password').required = false;
            document.getElementById('passwordLabel').textContent = 'New Password (Optional)';
            document.getElementById('passwordHint').textContent = 'Leave blank to keep the current password.';
            modal.classList.add('open');
        }

        function closeModal() {
            modal.classList.remove('open');
        }

        function filterUsers() {
            const search = userSearch.value.trim().toLowerCase();
            const role = roleFilter.value;
            let visible = 0;

            document.querySelectorAll('.user-row').forEach(function (row) {
                const matchesSearch = row.dataset.search.includes(search);
                const matchesRole = role === '' || row.dataset.role === role;
                const show = matchesSearch && matchesRole;
                row.style.display = show ? '' : 'none';

                if (show) {
                    visible++;
                }
            });

            document.getElementById('filterEmpty').style.display = visible === 0 ? 'block' : 'none';
        }

        userSearch.addEventListener('input', filterUsers);
        roleFilter.addEventListener('change', filterUsers);

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
    </script>
</body>
</html>

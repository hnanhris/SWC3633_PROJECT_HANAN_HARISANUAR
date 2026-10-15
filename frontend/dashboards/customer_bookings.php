<?php

$requiredRole = 'customer';

require __DIR__ . '/../includes/auth_guard.php';

$token = (string)$_SESSION['access_token'];

$name = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['customer_booking_csrf'])) $_SESSION['customer_booking_csrf'] = bin2hex(random_bytes(24));

function cbEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function cbApi(string $endpoint, string $token, string $method='GET', ?array $body=null): array {

    $ch=curl_init('http://localhost/event_ticketing_api/api/'.$endpoint);

    $headers=['Authorization: Bearer '.$token,'Accept: application/json'];

    if ($body!==null) $headers[]='Content-Type: application/json';

    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method]);

    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body));

    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);

    $data=is_string($raw)?json_decode($raw,true):null;

    return ['ok'=>$status>=200 && $status<300 && is_array($data) && !empty($data['success']),'data'=>is_array($data)?$data:[],'status'=>$status,'error'=>$err];

}

$error=''; $success='';

if ($_SERVER['REQUEST_METHOD']==='POST') {

    if (!hash_equals((string)$_SESSION['customer_booking_csrf'],(string)($_POST['csrf']??''))) {

        $error='Invalid request token. Refresh the page and try again.';

    } else {

        $id=filter_var($_POST['booking_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);

        $lookup=cbApi('bookings.php',$token);

        if (!$lookup['ok']) $error=$lookup['data']['message']??'Could not verify your bookings.';

        else {

            $owned=null;

            foreach (($lookup['data']['data']??[]) as $b) {

                if (is_array($b) && (int)($b['booking_id']??0)===$id) { $owned=$b; break; }

            }

            if (!$owned) $error='Booking not found in your account.';

            elseif (strtolower((string)($owned['booking_status']??''))!=='confirmed') $error='Only confirmed bookings can be cancelled.';

            else {

                $res=cbApi('bookings.php?id='.$id,$token,'PUT',['quantity'=>(int)$owned['quantity'],'booking_status'=>'cancelled']);

                if ($res['ok']) { header('Location: customer_bookings.php?cancelled=1'); exit; }

                $error=$res['data']['message']??($res['error']?:'Cancellation failed.');

            }

        }

    }

}

if (isset($_GET['created'])) $success='Your booking was created successfully!';

if (isset($_GET['cancelled'])) $success='Your booking was cancelled successfully.';

$result=cbApi('bookings.php',$token);

$bookings=$result['data']['data']??[];

if (!is_array($bookings)) $bookings=[];

if (!$result['ok']) $error=$result['data']['message']??($result['error']?:'Could not load bookings.');

usort($bookings,static function($a,$b){return strcmp((string)($b['booked_at']??''),(string)($a['booked_at']??''));});

$confirmed=0;$cancelled=0;$tickets=0;$value=0.0;

foreach($bookings as $b){$s=strtolower((string)($b['booking_status']??''));if($s==='confirmed'){$confirmed++;$tickets+=(int)($b['quantity']??0);$value+=(float)($b['total_price']??0);}elseif($s==='cancelled')$cancelled++;}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Bookings | EventTix</title>
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
            --muted: #77857a;
            --line: #e5ebe3;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--cream); color: var(--ink); font-family: 'DM Sans', sans-serif; }
        a { text-decoration: none; }
        .site-header { background: #fff; border-bottom: 1px solid var(--line); }
        .nav-inner { max-width: 1320px; margin: auto; padding: 19px 32px; display: flex; align-items: center; gap: 32px; flex-wrap: wrap; }
        .brand { font-weight: 800; font-size: 23px; color: var(--forest); letter-spacing: -1px; white-space: nowrap; }
        .brand span { color: var(--sage); }
        .nav-links { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; flex: 1; }
        .nav-links a { color: #65776a; padding: 11px 16px; border-radius: 11px; font-size: 13px; font-weight: 700; }
        .nav-links a:hover, .nav-links a.active { background: #eaf0e7; color: var(--forest); }
        .account { display: flex; align-items: center; gap: 14px; font-size: 13px; color: var(--muted); }
        .account strong { color: var(--forest); }
        .logout { color: var(--forest); font-weight: 700; border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; }
        .container { max-width: 1320px; margin: 0 auto; padding: 36px 32px 60px; }
        .page-heading { margin-bottom: 24px; }
        .page-heading h1 { font-family: 'Playfair Display', serif; font-size: 34px; margin: 0 0 8px; }
        .muted { color: var(--muted); }
        .page-heading p { margin: 0; }
        .hero { background: linear-gradient(115deg, #244738, #608269); color: #fff; border-radius: 22px; padding: 34px 38px; display: flex; justify-content: space-between; align-items: center; gap: 18px; margin-bottom: 25px; }
        .hero h2 { font-family: 'Playfair Display', serif; font-size: 28px; margin: 0 0 9px; }
        .hero p { margin: 0; color: #e2eae0; font-size: 14px; }
        .hero a { display: inline-block; padding: 13px 20px; background: #fff; color: var(--forest); border-radius: 11px; font-size: 13px; font-weight: 800; white-space: nowrap; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 17px; margin-bottom: 25px; }
        .stat { background: #fff; border: 1px solid var(--line); border-radius: 18px; padding: 24px; box-shadow: 0 8px 25px #24473808; }
        .stat-icon { display: inline-grid; place-items: center; background: #eaf0e7; color: var(--forest); width: 42px; height: 42px; border-radius: 12px; font-size: 20px; margin-bottom: 15px; }
        .stat-label { font-size: 13px; color: var(--muted); }
        .stat-number { margin-top: 8px; font-size: 26px; font-weight: 800; color: var(--forest); overflow-wrap: anywhere; }
        .panel { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 26px; box-shadow: 0 8px 25px #24473808; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 20px; }
        .toolbar h2 { font-family: 'Playfair Display', serif; font-size: 22px; margin: 0; }
        .filters { display: flex; gap: 10px; flex-wrap: wrap; }
        .filters input, .filters select { background: #fff; border: 1px solid #dce5d9; color: var(--ink); padding: 12px 14px; border-radius: 11px; font: inherit; font-size: 13px; }
        .filters input { min-width: 235px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; min-width: 950px; border-collapse: collapse; }
        th, td { padding: 16px 12px; text-align: left; border-bottom: 1px solid #edf1eb; font-size: 13px; }
        th { color: var(--muted); font-size: 12px; font-weight: 700; }
        td { vertical-align: middle; }
        .pill { display: inline-block; padding: 7px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: capitalize; }
        .pill.confirmed { background: #e4f3e9; color: #247044; }
        .pill.cancelled { background: #ffebeb; color: #ae4450; }
        .pill.other { background: #edf0e9; color: #64766a; }
        .cancel { background: #fff5f5; border: 1px solid #efcbd0; color: #ae4450; border-radius: 10px; padding: 10px 13px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; }
        .cancel:hover { background: #ffe7e9; }
        .alert, .success { padding: 14px 17px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; }
        .alert { background: #fff0ee; color: #9e3e38; }
        .success { background: #e7f3e8; color: #246c43; }
        .empty { padding: 36px 10px; color: var(--muted); text-align: center; }
        .note { color: var(--muted); font-size: 12px; line-height: 1.6; margin: 17px 0 0; }
        tr[hidden] { display: none; }
        @media (max-width: 1050px) {
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .nav-inner { gap: 14px; }
        }
        @media (max-width: 700px) {
            .nav-inner { padding: 17px 18px; }
            .nav-links { order: 3; flex-basis: 100%; }
            .nav-links a { padding: 9px 11px; }
            .account { margin-left: auto; flex-wrap: wrap; }
            .container { padding: 25px 18px; }
            .page-heading h1 { font-size: 29px; }
            .hero { flex-direction: column; align-items: flex-start; padding: 27px; }
            .stats { gap: 11px; }
            .stat { padding: 18px; }
            .stat-number { font-size: 21px; }
            .panel { padding: 18px; }
            .filters, .filters input, .filters select { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <div class="nav-inner">
            <a class="brand" href="customer.php">✦ EVENT<span>TIX</span></a>
            <nav class="nav-links" aria-label="Customer navigation">
                <a href="customer.php">Dashboard</a>
                <a href="customer_events.php">Browse Events</a>
                <a class="active" href="customer_bookings.php">My Bookings</a>
            </nav>
            <div class="account">
                <span><?= $name ?> · <strong>Customer</strong></span>
                <a class="logout" href="../logout.php">Logout ↗</a>
            </div>
        </div>
    </header>

    <main class="container">
        <div class="page-heading">
            <h1>My Bookings</h1>
            <p class="muted">Review your tickets and manage your bookings.</p>
        </div>

        <section class="hero">
            <div>
                <h2>Your Event Tickets</h2>
                <p>All your bookings in one convenient place.</p>
            </div>
            <a href="customer_events.php">Explore Events →</a>
        </section>

        <?php if ($error): ?>
            <div class="alert"><?= cbEsc($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success"><?= cbEsc($success) ?></div>
        <?php endif; ?>

        <section class="stats">
            <div class="stat">
                <div class="stat-icon">▤</div>
                <div class="stat-label">Total Bookings</div>
                <div class="stat-number"><?= $result['ok'] ? count($bookings) : '—' ?></div>
            </div>
            <div class="stat">
                <div class="stat-icon">✓</div>
                <div class="stat-label">Confirmed Bookings</div>
                <div class="stat-number"><?= $result['ok'] ? $confirmed : '—' ?></div>
            </div>
            <div class="stat">
                <div class="stat-icon">♧</div>
                <div class="stat-label">Confirmed Tickets</div>
                <div class="stat-number"><?= $result['ok'] ? $tickets : '—' ?></div>
            </div>
            <div class="stat">
                <div class="stat-icon">RM</div>
                <div class="stat-label">Confirmed Booking Value</div>
                <div class="stat-number"><?= $result['ok'] ? 'RM ' . number_format($value, 2) : '—' ?></div>
            </div>
        </section>

        <section class="panel">
            <div class="toolbar">
                <h2>Booking History</h2>
                <div class="filters">
                    <input type="search" id="search" placeholder="Search event or booking ID..." aria-label="Search bookings">
                    <select id="statusFilter" aria-label="Filter booking status">
                        <option value="">All Statuses</option>
                        <option value="confirmed">Confirmed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Booking ID</th>
                            <th>Event</th>
                            <th>Schedule Date &amp; Time</th>
                            <th>Quantity</th>
                            <th>Total Price</th>
                            <th>Status</th>
                            <th>Booked At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="bookingRows">
                        <?php foreach ($bookings as $b): ?>
                            <?php
                                $status = strtolower((string)($b['booking_status'] ?? ''));
                                $pill = in_array($status, ['confirmed', 'cancelled'], true) ? $status : 'other';
                            ?>
                            <tr
                                data-status="<?= cbEsc($status) ?>"
                                data-search="<?= cbEsc(strtolower(($b['event_title'] ?? '') . ' ' . ($b['booking_id'] ?? ''))) ?>"
                            >
                                <td>#<?= cbEsc($b['booking_id'] ?? '') ?></td>
                                <td><?= cbEsc($b['event_title'] ?? '—') ?></td>
                                <td><?= cbEsc($b['start_time'] ?? '—') ?></td>
                                <td><?= (int)($b['quantity'] ?? 0) ?></td>
                                <td>RM <?= number_format((float)($b['total_price'] ?? 0), 2) ?></td>
                                <td>
                                    <span class="pill <?= cbEsc($pill) ?>">
                                        <?= cbEsc(ucfirst($status ?: 'Unknown')) ?>
                                    </span>
                                </td>
                                <td><?= cbEsc($b['booked_at'] ?? '—') ?></td>
                                <td>
                                    <?php if ($status === 'confirmed'): ?>
                                        <form method="post" onsubmit="return confirm('Cancel this booking? This action will release your reserved tickets.');">
                                            <input type="hidden" name="csrf" value="<?= cbEsc($_SESSION['customer_booking_csrf']) ?>">
                                            <input type="hidden" name="booking_id" value="<?= (int)$b['booking_id'] ?>">
                                            <button class="cancel" type="submit">Cancel Booking</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$bookings): ?>
                            <tr>
                                <td class="empty" colspan="8">
                                    <?= $result['ok'] ? 'No bookings yet. Browse events to book your first ticket.' : 'Bookings could not be loaded.' ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <tr id="noMatch" hidden>
                            <td class="empty" colspan="8">No matching bookings found.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="note">Cancellation is subject to the booking API rules. Confirmed booking value represents booked value, not necessarily payments collected.</p>
        </section>
    </main>

    <script>
        const search = document.getElementById('search');
        const statusFilter = document.getElementById('statusFilter');
        const rows = [...document.querySelectorAll('#bookingRows tr[data-status]')];
        const noMatch = document.getElementById('noMatch');

        function filterRows() {
            const query = search.value.toLowerCase().trim();
            const status = statusFilter.value;
            let shown = 0;

            rows.forEach((row) => {
                const visible = (!status || row.dataset.status === status) && row.dataset.search.includes(query);
                row.hidden = !visible;
                if (visible) shown++;
            });

            noMatch.hidden = shown > 0 || rows.length === 0;
        }

        search.addEventListener('input', filterRows);
        statusFilter.addEventListener('change', filterRows);
    </script>
</body>
</html>

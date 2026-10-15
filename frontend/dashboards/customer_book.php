<?php

$requiredRole = 'customer';

require __DIR__ . '/../includes/auth_guard.php';

$token = (string)$_SESSION['access_token'];

$name = htmlspecialchars((string)($_SESSION['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8');

function bookEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function bookApi(string $path, string $token, string $method = 'GET', ?array $payload = null): array {

    $ch = curl_init('http://localhost/event_ticketing_api/api/' . $path);

    $headers = ['Authorization: Bearer '.$token, 'Accept: application/json'];

    if ($payload !== null) $headers[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>18, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CUSTOMREQUEST=>$method]);

    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $raw = curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);

    $decoded = is_string($raw) ? json_decode($raw,true) : null;

    return ['ok'=>$status>=200 && $status<300 && is_array($decoded) && !empty($decoded['success']), 'data'=>is_array($decoded)?$decoded:[], 'status'=>$status, 'error'=>$error];

}

function bookJwtId(string $jwt): int {

    $parts=explode('.', $jwt);

    if (count($parts)!==3) return 0;

    $json=base64_decode(strtr($parts[1], '-_', '+/'),true);

    $claims=is_string($json)?json_decode($json,true):null;

    if (!is_array($claims)) return 0;

    foreach ([$claims['user_id']??null, $claims['data']['user_id']??null, $claims['sub']??null, $claims['id']??null] as $candidate) {

        if (filter_var($candidate,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false) return (int)$candidate;

    }

    return 0;

}

$eventId=filter_input(INPUT_GET,'event_id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;

$event=null; $schedules=[]; $error=''; $success='';

if (!$eventId) $error='Please select an event from Browse Events.';

else {

    $res=bookApi('events.php?id='.$eventId,$token);

    if (!$res['ok']) $error=$res['data']['message']??'Could not load event.';

    else {

        $event=$res['data']['data']??null;

        if (!is_array($event) || strtolower((string)($event['status']??''))!=='published') { $event=null; $error='This event is not available for booking.'; }

        else {

            $sr=bookApi('schedules.php',$token);

            if (!$sr['ok']) $error='Could not load event schedules.';

            else foreach (($sr['data']['data']??[]) as $s) if (is_array($s) && (int)($s['event_id']??0)===$eventId && (int)($s['available_tickets']??0)>0 && strtotime((string)($s['start_time']??''))>=time()) $schedules[]=$s;

            usort($schedules,static fn($a,$b)=>strcmp((string)$a['start_time'],(string)$b['start_time']));

        }

    }

}

$userId=(int)($_SESSION['user_id']??0);

if ($userId<1) $userId=bookJwtId($token);

if ($_SERVER['REQUEST_METHOD']==='POST' && $event && !$error) {

    $selected=filter_input(INPUT_POST,'schedule_id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;

    $quantity=filter_input(INPUT_POST,'quantity',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;

    $chosen=null;

    foreach ($schedules as $s) if ((int)$s['schedule_id']===$selected) { $chosen=$s; break; }

    if (!$chosen) $error='Please choose an available event schedule.';

    elseif ($quantity<1 || $quantity>(int)$chosen['available_tickets']) $error='Ticket quantity exceeds available tickets.';

    elseif ($userId<1) $error='Could not determine your customer ID from the login session. Please log in again or check JWT user_id claim.';

    else {

        $result=bookApi('bookings.php',$token,'POST',['user_id'=>$userId,'schedule_id'=>$selected,'quantity'=>$quantity]);

        if ($result['ok']) {

            header('Location: customer_bookings.php?created=1'); exit;

        }

        $error=$result['data']['message']??($result['error']?:'Booking failed. Please try again.');

    }

}

?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book Tickets | EventTix</title>
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
    --line: #e5e9e1;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--cream); color: var(--ink); font-family: 'DM Sans', sans-serif; }
a { color: inherit; }
.site-header { background: #fff; border-bottom: 1px solid var(--line); }
.header-inner { max-width: 1320px; margin: auto; padding: 17px 34px; display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.brand { font-size: 23px; font-weight: 800; letter-spacing: -1px; text-decoration: none; color: var(--forest); }
.brand span { color: var(--sage); }
.nav-links { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; }
.nav-links a { text-decoration: none; padding: 11px 15px; border-radius: 10px; font-size: 13px; font-weight: 600; color: #607466; }
.nav-links a:hover, .nav-links a.active { background: #e9f0e8; color: var(--forest); }
.nav-links .logout { color: #a04b45; }
.page { max-width: 1320px; padding: 38px 34px 70px; margin: auto; }
.top { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
.top h1 { font-family: 'Playfair Display', serif; font-size: 34px; margin: 0 0 7px; }
.muted { color: #77877b; margin: 0; font-size: 14px; line-height: 1.6; }
.profile { background: white; border: 1px solid var(--line); border-radius: 999px; padding: 12px 17px; font-size: 13px; }
.profile strong { color: var(--forest); }
.back { display: inline-block; color: var(--forest); font-size: 13px; font-weight: 700; text-decoration: none; margin-bottom: 22px; }
.banner { background: linear-gradient(120deg, #244738, #668b6e); border-radius: 22px; color: white; padding: 35px; margin-bottom: 25px; }
.banner h2 { font-family: 'Playfair Display', serif; font-size: 29px; margin: 0 0 10px; }
.banner p { color: #e7f0e5; line-height: 1.7; margin: 0; max-width: 760px; }
.columns { display: grid; grid-template-columns: 1.2fr 1fr; gap: 22px; }
.panel { background: white; border: 1px solid var(--line); border-radius: 20px; padding: 28px; box-shadow: 0 6px 24px #24473809; }
.panel h3 { font-family: 'Playfair Display', serif; font-size: 22px; margin: 0 0 22px; }
.info { padding: 15px 0; border-bottom: 1px solid var(--line); font-size: 14px; overflow-wrap: anywhere; }
.info strong { display: block; color: #7b8b7e; font-size: 12px; margin-bottom: 7px; }
.field { margin-bottom: 21px; }
.field label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 9px; }
.field select, .field input { width: 100%; border: 1px solid #d5dfd4; border-radius: 11px; padding: 13px; font: inherit; background: white; color: var(--ink); }
.field select:focus, .field input:focus { outline: 2px solid var(--sage); outline-offset: 1px; }
.summary { background: #edf3eb; border-radius: 14px; padding: 18px; margin-bottom: 19px; }
.summary div { display: flex; justify-content: space-between; gap: 12px; padding: 9px 0; font-size: 14px; }
.summary .total { border-top: 1px solid #cfddcc; margin-top: 8px; padding-top: 17px; font-size: 19px; font-weight: 800; color: var(--forest); }
.button { display: block; width: 100%; border: none; border-radius: 11px; background: var(--forest); color: white; padding: 15px 20px; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
.button:hover { background: #356149; }
.button:disabled { background: #aebcaf; cursor: not-allowed; }
.warning { background: #fff0e9; color: #974b2e; padding: 15px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
@media (max-width: 850px) {
    .columns { grid-template-columns: 1fr; }
    .header-inner { padding: 16px 20px; }
    .page { padding: 25px 18px 50px; }
    .banner { padding: 26px; }
    .top h1 { font-size: 29px; }
}
    </style>
</head>
<body>
    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="customer.php">✦ EVENT<span>TIX</span></a>
            <nav class="nav-links" aria-label="Customer navigation">
                <a href="customer.php">Dashboard</a>
                <a class="active" href="customer_events.php">Browse Events</a>
                <a href="customer_bookings.php">My Bookings</a>
                <a class="logout" href="../logout.php">Logout</a>
            </nav>
        </div>
    </header>

    <main class="page">
        <div class="top">
            <div>
                <h1>Book Tickets</h1>
                <p class="muted">Select your event schedule and confirm your tickets.</p>
            </div>
            <div class="profile">● <?= $name ?> · <strong>Customer</strong></div>
        </div>

        <a class="back" href="customer_events.php">← Back to Browse Events</a>

        <?php if ($error): ?>
            <div class="warning"><?= bookEsc($error) ?></div>
        <?php endif; ?>

        <?php if ($event): ?>
            <section class="banner">
                <h2><?= bookEsc($event['title'] ?? 'Event') ?></h2>
                <p><?= bookEsc($event['description'] ?? '') ?></p>
            </section>

            <div class="columns">
                <section class="panel">
                    <h3>Event Details</h3>

                    <div class="info">
                        <strong>Category</strong>
                        <?= bookEsc($event['category'] ?? '—') ?>
                    </div>

                    <div class="info">
                        <strong>Venue</strong>
                        <?= bookEsc($event['venue_name'] ?? '—') ?>
                    </div>

                    <div class="info">
                        <strong>Location</strong>
                        <?= bookEsc($event['location'] ?? '—') ?>
                    </div>

                    <div class="info">
                        <strong>Available Schedules</strong>
                        <?= count($schedules) ?> upcoming schedule(s) with tickets
                    </div>
                </section>

                <section class="panel">
                    <h3>Ticket Booking</h3>

                    <?php if (!$schedules): ?>
                        <p class="muted">No upcoming schedules with available tickets.</p>
                    <?php else: ?>
                        <form method="post" action="customer_book.php?event_id=<?= $eventId ?>">
                            <div class="field">
                                <label for="schedule">Select Schedule</label>
                                <select id="schedule" name="schedule_id" required>
                                    <?php foreach ($schedules as $s): ?>
                                        <option
                                            value="<?= (int)$s['schedule_id'] ?>"
                                            data-price="<?= bookEsc((float)$s['ticket_price']) ?>"
                                            data-stock="<?= (int)$s['available_tickets'] ?>"
                                            <?= (int)($_POST['schedule_id'] ?? 0) === (int)$s['schedule_id'] ? 'selected' : '' ?>
                                        >
                                            <?= bookEsc(date('d M Y, h:i A', strtotime((string)$s['start_time']))) ?>
                                            — RM <?= number_format((float)$s['ticket_price'], 2) ?>
                                            (<?= (int)$s['available_tickets'] ?> left)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label for="quantity">Ticket Quantity</label>
                                <input
                                    id="quantity"
                                    name="quantity"
                                    type="number"
                                    min="1"
                                    step="1"
                                    value="<?= bookEsc($_POST['quantity'] ?? 1) ?>"
                                    required
                                >
                            </div>

                            <div class="summary">
                                <div>
                                    <span>Price per Ticket</span>
                                    <strong id="unit">RM 0.00</strong>
                                </div>
                                <div>
                                    <span>Available Tickets</span>
                                    <strong id="stock">—</strong>
                                </div>
                                <div class="total">
                                    <span>Total</span>
                                    <strong id="total">RM 0.00</strong>
                                </div>
                            </div>

                            <button class="button" id="confirm" type="submit">
                                Confirm Booking →
                            </button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </main>

    <script>
        const schedule = document.getElementById('schedule');
        const quantity = document.getElementById('quantity');
        const unit = document.getElementById('unit');
        const stock = document.getElementById('stock');
        const total = document.getElementById('total');
        const confirmBtn = document.getElementById('confirm');

        function recalc() {
            if (!schedule) {
                return;
            }

            const option = schedule.selectedOptions[0];
            const price = Number(option?.dataset.price || 0);
            const available = Number(option?.dataset.stock || 0);
            const qty = Number(quantity.value || 0);

            quantity.max = String(available);
            unit.textContent = 'RM ' + price.toFixed(2);
            stock.textContent = String(available);
            total.textContent = 'RM ' + (price * Math.max(0, qty)).toFixed(2);
            confirmBtn.disabled = !Number.isInteger(qty) || qty < 1 || qty > available;
        }

        if (schedule) {
            schedule.addEventListener('change', recalc);
            quantity.addEventListener('input', recalc);
            recalc();
        }
    </script>
</body>
</html>

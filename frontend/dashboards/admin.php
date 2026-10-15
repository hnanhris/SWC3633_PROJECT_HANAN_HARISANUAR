<?php
$requiredRole = 'admin';
require __DIR__ . '/../includes/auth_guard.php';
$name = htmlspecialchars($_SESSION['full_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');
$apiBase = 'http://localhost/event_ticketing_api/api';
$token = $_SESSION['access_token'] ?? '';
function hananFetch(string $endpoint, string $token, string $apiBase): array {
    if ($token === '') return [];
    $ch = curl_init($apiBase . '/' . $endpoint);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200 || !is_string($response)) return [];
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}
function hananE($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$usersResponse = hananFetch('users.php', $token, $apiBase);
$eventsResponse = hananFetch('events.php?page=1&limit=100', $token, $apiBase);
$venuesResponse = hananFetch('venues.php', $token, $apiBase);
$bookingsResponse = hananFetch('bookings.php', $token, $apiBase);
$users = is_array($usersResponse['data'] ?? null) ? $usersResponse['data'] : [];
$events = is_array($eventsResponse['data'] ?? null) ? $eventsResponse['data'] : [];
$venues = is_array($venuesResponse['data'] ?? null) ? $venuesResponse['data'] : [];
$bookings = is_array($bookingsResponse['data'] ?? null) ? $bookingsResponse['data'] : [];
$totalUsers = $usersResponse['total'] ?? count($users);
$totalEvents = $eventsResponse['total'] ?? count($events);
$totalVenues = $venuesResponse['total'] ?? count($venues);
$totalBookings = $bookingsResponse['total'] ?? count($bookings);
$recentEvents = array_reverse(array_slice($events, -5));
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Workspace | EventTix Hanan</title>
<style>
:root{--forest:#234638;--sage:#78977d;--cream:#faf8f1;--line:#e2e8dc;--ink:#233c32;--muted:#738477;--white:#fff;--pale:#eaf0e7}
*{box-sizing:border-box}body{margin:0;background:var(--cream);font-family:Arial,Helvetica,sans-serif;color:var(--ink)}a{text-decoration:none;color:inherit}
.shell{max-width:1450px;margin:auto;padding:22px 35px 50px}.topnav{display:flex;align-items:center;justify-content:space-between;gap:18px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:16px 24px;box-shadow:0 8px 25px #233c3209}.logo{font-weight:900;font-size:23px;letter-spacing:-1px}.logo span{color:var(--sage)}.navlinks{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.navlinks a{font-size:13px;padding:11px 13px;border-radius:11px;color:#4e6657}.navlinks a:hover,.navlinks .active{background:var(--pale);color:var(--forest);font-weight:700}.navlinks .logout{background:var(--forest);color:#fff}.eyebrow{font-size:11px;letter-spacing:3px;text-transform:uppercase;color:var(--sage);font-weight:800}.heading{display:flex;justify-content:space-between;align-items:center;gap:20px;margin:34px 2px 23px}.heading h1{font-family:Georgia,serif;font-size:39px;font-weight:500;margin:8px 0}.heading p{color:var(--muted);margin:0;font-size:14px}.profile{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 20px;font-size:13px}.profile strong{color:var(--forest)}.hero{display:grid;grid-template-columns:1.6fr .7fr;gap:20px;background:var(--forest);border-radius:24px;padding:32px 38px;color:#fff;overflow:hidden;position:relative}.hero h2{font-family:Georgia,serif;font-size:31px;font-weight:500;line-height:1.25;margin:8px 0 12px}.hero p{color:#d7e7d9;font-size:14px;line-height:1.7;margin:0}.hero .eyebrow{color:#c1d7b9}.hero-aside{border-left:1px solid #ffffff35;padding-left:26px;display:flex;flex-direction:column;justify-content:center}.hero-aside strong{font-size:28px;font-family:Georgia,serif;font-weight:500}.hero-aside small{color:#d7e7d9;line-height:1.7;margin-top:8px}.section-heading{display:flex;justify-content:space-between;align-items:center;margin:32px 0 17px}.section-heading h2{font-family:Georgia,serif;font-size:24px;font-weight:500;margin:0}.section-heading a{font-size:13px;color:var(--forest);font-weight:bold}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.stat{background:#fff;border:1px solid var(--line);border-radius:18px;padding:23px;display:flex;align-items:center;gap:17px}.stat-icon{width:52px;height:52px;border-radius:16px;background:var(--pale);display:grid;place-items:center;font-size:24px;color:var(--forest);flex-shrink:0}.stat label{font-size:12px;color:var(--muted);display:block;margin-bottom:7px}.stat strong{font-family:Georgia,serif;font-size:30px;font-weight:500}.lower{display:grid;grid-template-columns:1.6fr .8fr;gap:20px;margin-top:28px}.panel{background:#fff;border:1px solid var(--line);border-radius:20px;padding:24px;min-width:0}.panel h2{font-family:Georgia,serif;font-size:23px;font-weight:500;margin:0 0 17px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left;min-width:550px}th{font-size:11px;letter-spacing:.5px;color:var(--muted);font-weight:700;border-bottom:1px solid var(--line);padding:13px 10px}td{font-size:12px;padding:16px 10px;border-bottom:1px solid #edf1eb}tr:last-child td{border-bottom:0}.pill{display:inline-block;padding:7px 11px;border-radius:20px;background:var(--pale);color:var(--forest);font-size:11px;text-transform:capitalize}.quicklinks{display:grid;gap:11px}.quicklinks a{border:1px solid var(--line);border-radius:14px;padding:16px;display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700}.quicklinks a:hover{background:var(--pale)}.quicklinks small{display:block;font-weight:400;color:var(--muted);margin-top:5px}.empty{color:var(--muted);font-size:13px;line-height:1.6}.footer{text-align:center;font-size:12px;color:var(--muted);margin-top:35px}
@media(max-width:1050px){.navlinks{justify-content:center}.topnav{flex-wrap:wrap}.stats{grid-template-columns:repeat(2,1fr)}.lower{grid-template-columns:1fr}}@media(max-width:650px){.shell{padding:12px}.topnav{padding:17px}.navlinks a{padding:9px}.heading{align-items:flex-start;flex-direction:column}.heading h1{font-size:32px}.hero{grid-template-columns:1fr;padding:25px}.hero-aside{border-left:0;border-top:1px solid #ffffff35;padding:18px 0 0}.stats{grid-template-columns:1fr}.panel{padding:18px}}
</style></head><body>
<div class="shell">
<header class="topnav"><a class="logo" href="admin.php">✦ EVENT<span>TIX</span></a><nav class="navlinks" aria-label="Admin navigation"><a class="active" href="admin.php">Overview</a><a href="manage_users.php">Users</a><a href="manage_events.php">Events</a><a href="manage_venues.php">Venues</a><a href="manage_bookings.php">Bookings</a><a class="logout" href="../logout.php">Log out ↗</a></nav></header>
<div class="heading"><div><div class="eyebrow">EventTix / Administration</div><h1>Workspace overview</h1><p>Everything you need to manage your event platform, all in one place.</p></div><div class="profile">● <?= $name ?> &nbsp;·&nbsp; <strong>Administrator</strong></div></div>
<section class="hero"><div><div class="eyebrow">Your administration hub</div><h2>Make every event<br>run beautifully.</h2><p>Manage users, venues, events and reservations with your live EventTix data.</p></div><div class="hero-aside"><strong>Welcome back.</strong><small>Your workspace is ready. Use the quick actions below to manage the platform.</small></div></section>
<div class="section-heading"><h2>At a glance</h2><span class="eyebrow">Live API Overview</span></div>
<section class="stats" aria-label="Platform statistics"><div class="stat"><div class="stat-icon">♙</div><div><label>Total users</label><strong><?= hananE($totalUsers) ?></strong></div></div><div class="stat"><div class="stat-icon">◇</div><div><label>Total events</label><strong><?= hananE($totalEvents) ?></strong></div></div><div class="stat"><div class="stat-icon">▦</div><div><label>Total venues</label><strong><?= hananE($totalVenues) ?></strong></div></div><div class="stat"><div class="stat-icon">▤</div><div><label>Total bookings</label><strong><?= hananE($totalBookings) ?></strong></div></div></section>
<div class="lower"><section class="panel"><div class="section-heading" style="margin:0 0 12px"><h2>Recent events</h2><a href="manage_events.php">View all →</a></div><?php if (!$recentEvents): ?><p class="empty">No event data available. Check your API connection and authentication.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>ID</th><th>Event</th><th>Venue</th><th>Category</th><th>Status</th></tr></thead><tbody><?php foreach($recentEvents as $event): ?><tr><td>#<?= hananE($event['event_id'] ?? '-') ?></td><td><?= hananE($event['title'] ?? '-') ?></td><td><?= hananE($event['venue_name'] ?? '-') ?></td><td><?= hananE($event['category'] ?? '-') ?></td><td><span class="pill"><?= hananE($event['status'] ?? '-') ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<aside class="panel"><h2>Quick management</h2><div class="quicklinks"><a href="manage_users.php"><span>Manage users<small>Accounts and access roles</small></span><span>↗</span></a><a href="manage_events.php"><span>Manage events<small>Event details and publishing</small></span><span>↗</span></a><a href="manage_venues.php"><span>Manage venues<small>Locations and capacities</small></span><span>↗</span></a><a href="manage_bookings.php"><span>Manage bookings<small>Reservations and statuses</small></span><span>↗</span></a></div></aside></div>
<div class="footer">EventTix · Hanan Workspace</div>
</div></body></html>

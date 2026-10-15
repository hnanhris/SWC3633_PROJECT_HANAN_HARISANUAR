<?php
session_start();
if (!empty($_SESSION['access_token']) && in_array($_SESSION['role'] ?? '', ['admin','organiser','customer'], true)) {
    header('Location: dashboards/' . $_SESSION['role'] . '.php');
    exit;
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your form session has expired. Refresh the page and try again.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $error = 'Please enter a valid email address and password.';
        } else {
            $ch = curl_init('http://127.0.0.1/event_ticketing_api/api/login.php');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $result = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            $data = is_string($result) ? json_decode($result, true) : null;
            $role = $data['user']['role'] ?? '';
            if ($http === 200 && ($data['success'] ?? false) === true && is_string($data['access_token'] ?? null) && in_array($role, ['admin', 'organiser', 'customer'], true)) {
                session_regenerate_id(true);
                $_SESSION['access_token'] = $data['access_token'];
                $_SESSION['role'] = $role;
                $_SESSION['user_id'] = (int)($data['user']['user_id'] ?? 0);
                $_SESSION['full_name'] = (string)($data['user']['full_name'] ?? 'User');
                header('Location: dashboards/' . $role . '.php');
                exit;
            }
            $error = $curlError
                ? 'Unable to reach the login service. Please check that XAMPP is running.'
                : ($http === 401 ? 'Incorrect email or password.' : 'Login failed. Please try again.');
        }
    }
}
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In | EventTix Hanan</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
<style>
:root{--forest:#244738;--sage:#78977d;--cream:#faf8f1;--ink:#21382c;--muted:#78877c;--line:#dce5d9}
*{box-sizing:border-box}body{margin:0;font-family:'DM Sans',Arial,sans-serif;color:var(--ink);background:var(--cream)}a{text-decoration:none;color:inherit}
.site-header{height:82px;padding:0 max(5%,calc((100vw - 1200px)/2));display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);background:#fffefa}
.brand{font-size:22px;font-weight:800;letter-spacing:1px}.brand span{color:var(--sage)}.brand-mark{display:inline-grid;place-items:center;width:35px;height:35px;background:var(--forest);border-radius:11px;color:white;margin-right:10px;font-size:19px}
.header-note{font-size:12px;color:var(--muted);letter-spacing:.8px}.page{max-width:1180px;margin:auto;padding:48px 26px 60px}
.intro{text-align:center;margin-bottom:34px}.overline{color:var(--sage);font-weight:800;letter-spacing:2.5px;font-size:11px}.intro h1{font-family:'Playfair Display',Georgia,serif;font-weight:600;font-size:clamp(36px,5vw,58px);margin:12px 0 10px}.intro h1 em{color:var(--sage);font-style:normal}.intro p{color:var(--muted);font-size:14px;margin:0}
.content{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(330px,.9fr);gap:0;align-items:stretch;border:1px solid #e0e8dd;border-radius:26px;overflow:hidden;background:white;box-shadow:0 16px 48px #23463812}
.story{border-radius:0;background:var(--forest);min-height:470px;padding:47px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;color:#fff}
.story:before{content:'';position:absolute;width:340px;height:340px;border:1px solid #ffffff38;border-radius:50%;right:-110px;top:-90px;box-shadow:0 0 0 65px #ffffff08,0 0 0 135px #ffffff07}.story:after{content:'✳';position:absolute;right:60px;bottom:90px;font-size:165px;color:#ffffff12;transform:rotate(-20deg)}
.story>*{position:relative;z-index:1}.story-label{font-size:11px;letter-spacing:2px;color:#d1e1cd}.story h2{font-family:'Playfair Display',Georgia,serif;font-weight:500;font-size:clamp(38px,4vw,56px);line-height:1.15;max-width:480px;margin:0}.story h2 span{color:#c7d8ba}.story p{max-width:390px;line-height:1.8;color:#e1ebe0;font-size:14px}.story-bottom{display:flex;gap:12px;flex-wrap:wrap}.story-bottom span{border:1px solid #ffffff50;border-radius:100px;padding:10px 14px;font-size:11px;color:#e9f0e6}
.panel{background:white;border:0;border-radius:0;padding:36px 38px;box-shadow:none}.panel h2{font-family:'Playfair Display',Georgia,serif;font-size:33px;margin:8px 0}.panel .sub{color:var(--muted);font-size:13px;line-height:1.7;margin:0 0 28px}
label{display:block;font-size:12px;font-weight:700;margin:0 0 9px}.field{margin-bottom:20px}.input-wrap{display:flex;align-items:center;background:#fbfcf9;border:1px solid #dce5d9;border-radius:11px}.input-wrap:focus-within{border-color:var(--sage);box-shadow:0 0 0 3px #78977d20}.input-wrap .ico{padding:0 14px;color:var(--sage)}input{width:100%;min-width:0;border:0;background:transparent;padding:15px 13px 15px 0;outline:0;font:inherit;font-size:13px}.eye{background:transparent;border:0;color:var(--forest);padding:12px;cursor:pointer;font-weight:700;font-size:10px}.submit{width:100%;border:0;background:var(--forest);color:white;padding:16px 20px;border-radius:11px;font-weight:700;display:flex;justify-content:space-between;cursor:pointer}.submit:hover{background:#35644c}.alert{background:#fff1ee;color:#a33d35;padding:12px;border-radius:9px;font-size:12px;margin-bottom:18px}.hint{font-size:11px;text-align:center;color:var(--muted);margin-top:23px}.hint b{color:var(--forest)}
.roles{margin-top:32px;background:#eff3e9;border-radius:20px;padding:24px 28px;display:flex;align-items:center;justify-content:space-between;gap:20px}.roles-title{font-family:'Playfair Display',Georgia,serif;font-size:21px}.role-list{display:flex;gap:12px;flex-wrap:wrap}.role{background:white;border:1px solid var(--line);border-radius:10px;padding:11px 18px;min-width:125px}.role strong{display:block;font-size:12px}.role small{color:var(--muted);font-size:10px}.footer{text-align:center;color:#9ca99b;font-size:11px;margin-top:35px}
@media(max-width:850px){.content{grid-template-columns:1fr}.story{min-height:350px}.roles{flex-direction:column;align-items:flex-start}.panel{padding:30px}}@media(max-width:480px){.site-header{padding:0 18px}.header-note{display:none}.page{padding:30px 15px}.story{padding:30px;min-height:340px}.story h2{font-size:39px}.panel{padding:25px}.roles{padding:20px}.role{min-width:100px}}
</style></head><body>
<header class="site-header"><a class="brand" href="index.php"><span class="brand-mark">✳</span>EVENT<span>TIX</span></a><div class="header-note">EVENTS • VENUES • EXPERIENCES</div></header>
<main class="page">
<div class="intro"><span class="overline">WELCOME TO EVENTTIX</span><h1>Your next experience <em>starts here.</em></h1><p>A thoughtfully designed space to discover, organise and celebrate.</p></div>
<div class="content">
<section class="story"><div class="story-label">DISCOVER THE EXTRAORDINARY — 2026</div><div><h2>Make room for <span>meaningful moments.</span></h2><p>From intimate gatherings to unforgettable celebrations, manage your events and bookings in one beautiful place.</p></div><div class="story-bottom"><span>✦ Discover events</span><span>◈ Manage venues</span><span>✓ Book with ease</span></div></section>
<section class="panel"><span class="overline">YOUR ACCOUNT</span><h2>Sign in</h2><p class="sub">Enter your details to access your personal EventTix workspace.</p>
<?php if ($error !== ''): ?><div class="alert" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="login.php"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<div class="field"><label for="email">Email address</label><div class="input-wrap"><span class="ico">✉</span><input id="email" name="email" type="email" placeholder="you@example.com" autocomplete="username" required value="<?= e((string)($_POST['email'] ?? '')) ?>"></div></div>
<div class="field"><label for="password">Password</label><div class="input-wrap"><span class="ico">♧</span><input id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required><button class="eye" type="button" id="togglePassword" aria-label="Show password">SHOW</button></div></div>
<button class="submit" type="submit">Continue to dashboard <span>→</span></button></form><p class="hint">Need an account? <b>Contact your administrator.</b></p></section></div>
<section class="roles"><div class="roles-title">One platform, three experiences.</div><div class="role-list"><div class="role"><strong>Admin</strong><small>Platform management</small></div><div class="role"><strong>Organiser</strong><small>Event management</small></div><div class="role"><strong>Customer</strong><small>Ticket booking</small></div></div></section>
<div class="footer">© <?= date('Y') ?> EventTix · Designed for memorable experiences.</div></main>
<script>const toggle=document.getElementById('togglePassword');const password=document.getElementById('password');toggle.addEventListener('click',()=>{const show=password.type==='password';password.type=show?'text':'password';toggle.textContent=show?'HIDE':'SHOW';toggle.setAttribute('aria-label',show?'Hide password':'Show password');});</script>
</body></html>

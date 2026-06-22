<?php
/**
 * PasarGuard config manager — نسخه تحت وب (تک‌فایل)
 * این فایل را روی هاست PHP آپلود کن و در مرورگر باز کن.
 * همه‌کار با دکمه: ساخت کانفیگ یک‌ماهه، دیدن حجم باقی‌مانده، ریست، حذف.
 */

// ====================== تنظیمات ======================
$BASE       = 'https://sub.vpnservice.cloud:8443';
$USERNAME   = 'Pouria231';
$PASSWORD   = '4BjPc47.v5MVUP2H';
$API_PREFIX = '';                 // اگر API پشت مسیر مخفی بود: '/p4r34mAB'

$PLANS_GB    = [5, 10, 20, 30, 50, 100];   // پلن‌های مجاز (گیگ)
$EXPIRE_DAYS = 30;                          // یک‌ماهه

// رمز ورود به این صفحه (خالی = بدون رمز). حتماً یک رمز بگذار چون این صفحه پنلت را کنترل می‌کند.
$UI_PASSWORD = 'change-me-123';
// =====================================================

$GB = 1073741824;
session_start();

/* ---------------- گیت ساده ورود ---------------- */
if ($UI_PASSWORD !== '') {
    if (isset($_POST['__login'])) {
        if (hash_equals($UI_PASSWORD, (string)($_POST['pass'] ?? ''))) {
            $_SESSION['ok'] = true;
        } else { $loginErr = 'رمز اشتباه است.'; }
    }
    if (isset($_GET['logout'])) { session_destroy(); header('Location: ?'); exit; }
    if (empty($_SESSION['ok'])) { render_login($loginErr ?? null); exit; }
}

/* ---------------- ابزارهای API ---------------- */
function http_req($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $j = json_decode($resp, true);
    return [$code, (json_last_error() === JSON_ERROR_NONE ? $j : $resp), $err];
}

function login() {
    global $BASE, $API_PREFIX, $USERNAME, $PASSWORD;
    list($code, $b, $err) = http_req(
        'POST', "$BASE$API_PREFIX/api/admin/token",
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'password', 'username' => $USERNAME, 'password' => $PASSWORD])
    );
    if ($code === 0)   throw new Exception("اتصال برقرار نشد: $err");
    if ($code === 404) throw new Exception("404: API احتمالا پشت مسیر مخفی است (\$API_PREFIX را تنظیم کن).");
    if (empty($b['access_token'])) throw new Exception("لاگین ناموفق (HTTP $code).");
    return $b['access_token'];
}

function api($method, $path, $token, $payload = null) {
    global $BASE, $API_PREFIX;
    $headers = ["Authorization: Bearer $token"];
    $body = null;
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; $body = json_encode($payload); }
    return http_req($method, "$BASE$API_PREFIX$path", $headers, $body);
}

function gb($bytes) { global $GB; return number_format($bytes / $GB, 2); }

/* ---------------- پردازش اکشن‌ها ---------------- */
$flash = null; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $token = login();
        $action = $_POST['action'];

        if ($action === 'create') {
            $u    = trim($_POST['username'] ?? '');
            $plan = (int)($_POST['plan'] ?? 0);
            if ($u === '') throw new Exception('نام کانفیگ خالی است.');
            if (!in_array($plan, $PLANS_GB, true)) throw new Exception('پلن نامعتبر.');

            // گروه و بازه انقضا از whoami
            list($wc, $me) = api('GET', '/api/admin', $token);
            if ($wc !== 200) throw new Exception("خطا در گرفتن اطلاعات ادمین (HTTP $wc).");
            $group = $me['role']['access']['allowed_group_ids'][0] ?? null;
            if ($group === null) throw new Exception('هیچ گروه مجازی تعریف نشده.');
            $emin = $me['permission_overrides']['expire_min'] ?? 86400;
            $emax = $me['permission_overrides']['expire_max'] ?? 2592000;
            $dur  = (int) max($emin, min($emax, $EXPIRE_DAYS * 86400));

            $payload = [
                'username'   => $u,
                'status'     => 'active',
                'expire'     => time() + $dur,
                'data_limit' => $plan * $GB,
                'group_ids'  => [$group],
            ];
            list($c, $b) = api('POST', '/api/user', $token, $payload);
            if (in_array($c, [200, 201], true)) {
                $flash = "کانفیگ «$u» با پلن {$plan} گیگ (یک‌ماهه) ساخته شد.";
            } else {
                throw new Exception("ساخت ناموفق (HTTP $c): " . json_encode($b, JSON_UNESCAPED_UNICODE));
            }
        }
        elseif ($action === 'delete') {
            $u = $_POST['username'] ?? '';
            list($c, $b) = api('DELETE', '/api/user/' . rawurlencode($u), $token);
            if (in_array($c, [200, 204], true)) $flash = "«$u» حذف شد.";
            else throw new Exception("حذف ناموفق (HTTP $c).");
        }
        elseif ($action === 'reset') {
            $u = $_POST['username'] ?? '';
            list($c, $b) = api('POST', '/api/user/' . rawurlencode($u) . '/reset', $token);
            if (in_array($c, [200, 201], true)) $flash = "مصرف «$u» صفر شد.";
            else throw new Exception("ریست ناموفق (HTTP $c).");
        }
    } catch (Exception $e) {
        $flash = $e->getMessage(); $flashType = 'err';
    }
}

/* ---------------- گرفتن لیست کاربران ---------------- */
$users = []; $listErr = null;
try {
    $token = $token ?? login();
    list($c, $b) = api('GET', '/api/users', $token);
    if ($c === 200) $users = $b['users'] ?? [];
    else $listErr = "خطا در گرفتن لیست (HTTP $c).";
} catch (Exception $e) { $listErr = $e->getMessage(); }

/* ---------------- نمایش صفحه ---------------- */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function render_login($err = null) { ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود</title>
<style>body{font-family:Tahoma,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
.card{background:#1e293b;padding:30px;border-radius:14px;width:300px}
input,button{width:100%;padding:12px;margin-top:10px;border-radius:8px;border:none;box-sizing:border-box;font-size:15px}
button{background:#3b82f6;color:#fff;cursor:pointer}.err{color:#f87171;margin-top:10px}</style></head>
<body><form class="card" method="post"><h3>ورود به پنل مدیریت</h3>
<input type="password" name="pass" placeholder="رمز ورود" autofocus>
<input type="hidden" name="__login" value="1">
<button>ورود</button>
<?php if ($err) echo '<div class="err">'.h($err).'</div>'; ?>
</form></body></html>
<?php }
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>مدیریت کانفیگ PasarGuard</title>
<style>
:root{--bg:#0f172a;--card:#1e293b;--line:#334155;--txt:#e2e8f0;--muted:#94a3b8;--blue:#3b82f6;--red:#ef4444;--green:#22c55e;--amber:#f59e0b}
*{box-sizing:border-box}
body{font-family:Tahoma,Arial,sans-serif;background:var(--bg);color:var(--txt);margin:0;padding:16px}
.wrap{max-width:900px;margin:0 auto}
h1{font-size:20px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:18px}
label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
input,select,button{padding:11px;border-radius:9px;border:1px solid var(--line);background:#0b1220;color:var(--txt);font-size:14px}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}
.row>div{flex:1;min-width:140px}
button{cursor:pointer;border:none}
.btn-blue{background:var(--blue);color:#fff}
.btn-red{background:var(--red);color:#fff}
.btn-amber{background:var(--amber);color:#111}
.flash{padding:12px 14px;border-radius:10px;margin-bottom:16px}
.flash.ok{background:#064e3b;color:#a7f3d0}
.flash.err{background:#7f1d1d;color:#fecaca}
table{width:100%;border-collapse:collapse;margin-top:6px}
th,td{padding:10px;border-bottom:1px solid var(--line);text-align:right;font-size:13px}
th{color:var(--muted);font-weight:normal}
.bar{height:8px;background:#0b1220;border-radius:6px;overflow:hidden;margin-top:4px}
.bar>span{display:block;height:100%;background:var(--green)}
.actions{display:flex;gap:6px}
.actions button{padding:7px 10px;font-size:12px}
.muted{color:var(--muted);font-size:12px}
a.logout{color:var(--muted);font-size:12px;text-decoration:none;float:left}
</style>
</head>
<body>
<div class="wrap">
  <a class="logout" href="?logout=1">خروج</a>
  <h1>مدیریت کانفیگ‌ها — <?=h($USERNAME)?></h1>

  <?php if ($flash): ?>
    <div class="flash <?=$flashType?>"><?=h($flash)?></div>
  <?php endif; ?>

  <!-- فرم ساخت کانفیگ -->
  <div class="card">
    <h3 style="margin-top:0">ساخت کانفیگ جدید (یک‌ماهه)</h3>
    <form method="post" class="row">
      <input type="hidden" name="action" value="create">
      <div>
        <label>نام کانفیگ</label>
        <input type="text" name="username" placeholder="مثلا ali_5g" required style="width:100%">
      </div>
      <div>
        <label>پلن حجمی</label>
        <select name="plan" style="width:100%">
          <?php foreach ($PLANS_GB as $p): ?>
            <option value="<?=$p?>"><?=$p?> گیگ</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:0">
        <button class="btn-blue" type="submit">➕ ساخت کانفیگ</button>
      </div>
    </form>
    <div class="muted" style="margin-top:8px">مدت همه‌ی کانفیگ‌ها: <?=$EXPIRE_DAYS?> روز.</div>
  </div>

  <!-- لیست کانفیگ‌ها -->
  <div class="card">
    <h3 style="margin-top:0">کانفیگ‌های من (<?=count($users)?>)
      <form method="get" style="display:inline"><button class="btn-amber" style="float:left;padding:7px 12px">🔄 بروزرسانی</button></form>
    </h3>
    <?php if ($listErr): ?>
      <div class="flash err"><?=h($listErr)?></div>
    <?php elseif (!$users): ?>
      <div class="muted">هیچ کانفیگی نیست.</div>
    <?php else: ?>
      <table>
        <tr><th>نام</th><th>وضعیت</th><th>حجم (مصرف/کل)</th><th>باقی‌مانده</th><th>انقضا</th><th>عملیات</th></tr>
        <?php foreach ($users as $u):
            $limit = $u['data_limit'] ?? 0; $used = $u['used_traffic'] ?? 0;
            $remain = $limit > 0 ? max(0, $limit - $used) : 0;
            $pct = $limit > 0 ? min(100, round($used / $limit * 100)) : 0;
            $uname = $u['username'] ?? '';
        ?>
        <tr>
          <td><?=h($uname)?></td>
          <td><?=h($u['status'] ?? '')?></td>
          <td>
            <?php if ($limit > 0): ?>
              <?=gb($used)?> / <?=gb($limit)?> GB
              <div class="bar"><span style="width:<?=$pct?>%"></span></div>
            <?php else: ?>
              <?=gb($used)?> GB <span class="muted">(نامحدود)</span>
            <?php endif; ?>
          </td>
          <td><?= $limit > 0 ? gb($remain).' GB' : '∞' ?></td>
          <td class="muted"><?=h($u['expire'] ?? '-')?></td>
          <td>
            <div class="actions">
              <form method="post" onsubmit="return confirm('مصرف <?=h($uname)?> صفر شود؟')">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="username" value="<?=h($uname)?>">
                <button class="btn-amber" type="submit">ریست</button>
              </form>
              <form method="post" onsubmit="return confirm('<?=h($uname)?> حذف شود؟')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="username" value="<?=h($uname)?>">
                <button class="btn-red" type="submit">حذف</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

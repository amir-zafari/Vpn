<?php
/**
 * PasarGuard config manager  (PHP CLI)
 * ساخت و مدیریت کانفیگ‌ها از طریق API پنل با یوزر/پسورد.
 *
 * نمونه‌ها:
 *   php pg.php create ali 10           # ساخت کانفیگ «ali» با پلن ۱۰ گیگ، یک‌ماهه
 *   php pg.php info ali                # حجم مصرف‌شده / باقی‌مانده و انقضای «ali»
 *   php pg.php list                    # لیست همه‌ی کانفیگ‌ها با حجم باقی‌مانده
 *   php pg.php reset ali               # صفر کردن مصرف «ali»
 *   php pg.php delete ali              # حذف «ali»
 *   php pg.php plans                   # نمایش پلن‌های مجاز
 */

// ---------------------- تنظیمات ----------------------
$BASE       = 'https://sub.vpnservice.cloud:8443';
$USERNAME   = 'Pouria231';
$PASSWORD   = '4BjPc47.v5MVUP2H';
$API_PREFIX = '';                 // اگر API پشت مسیر مخفی بود: '/p4r34mAB'

$PLANS_GB    = [5, 10, 20, 30, 50, 100];   // پلن‌های مجاز (گیگابایت)
$EXPIRE_DAYS = 30;                          // یک‌ماهه
// -----------------------------------------------------

$GB = 1073741824;                 // 1 GiB = 1024^3 bytes
if (php_sapi_name() !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }

/* ----------------- ابزارهای پایه ----------------- */

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
    $json = json_decode($resp, true);
    return [$code, (json_last_error() === JSON_ERROR_NONE ? $json : $resp), $err];
}

/** لاگین و گرفتن توکن (در صورت خطا برنامه را متوقف می‌کند) */
function login() {
    global $BASE, $API_PREFIX, $USERNAME, $PASSWORD;
    list($code, $b, $err) = http_req(
        'POST', "$BASE$API_PREFIX/api/admin/token",
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'password', 'username' => $USERNAME, 'password' => $PASSWORD])
    );
    if ($code === 0)   die("✗ اتصال برقرار نشد (شبکه/آدرس/پورت). $err\n");
    if ($code === 404) die("✗ 404: API احتمالا پشت مسیر مخفی است؛ \$API_PREFIX را تنظیم کن.\n");
    if (empty($b['access_token'])) die("✗ لاگین ناموفق (HTTP $code): " . json_encode($b, JSON_UNESCAPED_UNICODE) . "\n");
    return $b['access_token'];
}

/** درخواست authenticated به API */
function api($method, $path, $token, $payload = null) {
    global $BASE, $API_PREFIX;
    $headers = ["Authorization: Bearer $token"];
    $body = null;
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; $body = json_encode($payload); }
    return http_req($method, "$BASE$API_PREFIX$path", $headers, $body);
}

/** گروه مجاز و بازه‌ی انقضا را از whoami می‌گیرد */
function whoami($token) {
    list($code, $b) = api('GET', '/api/admin', $token);
    if ($code !== 200) die("✗ خطا در گرفتن اطلاعات ادمین (HTTP $code).\n");
    return $b;
}

function gb($bytes) { global $GB; return round($bytes / $GB, 2); }

function fmt_user($u) {
    $limit = $u['data_limit'] ?? 0;
    $used  = $u['used_traffic'] ?? 0;
    $remain = $limit > 0 ? max(0, $limit - $used) : null;
    $line  = sprintf("نام: %-15s وضعیت: %-8s", $u['username'] ?? '?', $u['status'] ?? '?');
    if ($limit > 0) {
        $pct = $limit > 0 ? round($used / $limit * 100, 1) : 0;
        $line .= sprintf("| حجم: %s/%s GB  باقی‌مانده: %s GB (%s%% مصرف)",
            gb($used), gb($limit), gb($remain), $pct);
    } else {
        $line .= sprintf("| حجم: %s GB مصرف (نامحدود)", gb($used));
    }
    $exp = $u['expire'] ?? null;
    if ($exp) $line .= " | انقضا: $exp";
    return $line;
}

/* ----------------- دستورات ----------------- */

function cmd_create($args) {
    global $PLANS_GB, $EXPIRE_DAYS, $GB;
    $username = $args[0] ?? null;
    $planGb   = isset($args[1]) ? (int)$args[1] : null;
    if (!$username || !$planGb) die("استفاده: php pg.php create <username> <planGB>\nپلن‌های مجاز: " . implode(', ', $PLANS_GB) . "\n");
    if (!in_array($planGb, $PLANS_GB, true)) die("✗ پلن نامعتبر. فقط: " . implode(', ', $PLANS_GB) . " گیگ مجاز است.\n");

    $token = login();
    $me    = whoami($token);
    $group = $me['role']['access']['allowed_group_ids'][0] ?? null;
    if ($group === null) die("✗ هیچ گروه مجازی برای حساب تو تعریف نشده.\n");

    // انقضا: ۳۰ روز، ولی محدود به بازه‌ی مجاز پنل
    $emin = $me['permission_overrides']['expire_min'] ?? 86400;
    $emax = $me['permission_overrides']['expire_max'] ?? 2592000;
    $dur  = (int) max($emin, min($emax, $EXPIRE_DAYS * 86400));
    $expireTs = time() + $dur;

    $payload = [
        'username'   => $username,
        'status'     => 'active',
        'expire'     => $expireTs,
        'data_limit' => $planGb * $GB,
        'group_ids'  => [$group],
    ];
    list($code, $b) = api('POST', '/api/user', $token, $payload);

    echo "→ ساخت کانفیگ «$username» | پلن: {$planGb}GB | انقضا: ~{$EXPIRE_DAYS} روز | گروه: $group\n";
    if (in_array($code, [200, 201], true)) {
        echo "✓ ساخته شد (id={$b['id']}).\n";
        $sub = $b['subscription_url'] ?? '';
        if ($sub !== '') echo "لینک ساب: " . (strpos($sub, 'http') === 0 ? $sub : $GLOBALS['BASE'] . $sub) . "\n";
        echo fmt_user($b) . "\n";
    } else {
        echo "✗ خطا (HTTP $code): " . json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

function cmd_info($args) {
    $username = $args[0] ?? null;
    if (!$username) die("استفاده: php pg.php info <username>\n");
    $token = login();
    list($code, $b) = api('GET', "/api/user/" . rawurlencode($username), $token);
    if ($code !== 200) die("✗ خطا (HTTP $code): " . json_encode($b, JSON_UNESCAPED_UNICODE) . "\n");
    echo fmt_user($b) . "\n";
    $sub = $b['subscription_url'] ?? '';
    if ($sub !== '') echo "لینک ساب: " . (strpos($sub, 'http') === 0 ? $sub : $GLOBALS['BASE'] . $sub) . "\n";
}

function cmd_list($args) {
    $token = login();
    list($code, $b) = api('GET', '/api/users', $token);
    if ($code !== 200) die("✗ خطا (HTTP $code).\n");
    $users = $b['users'] ?? [];
    echo "تعداد کل: " . ($b['total'] ?? count($users)) . "\n";
    echo str_repeat('-', 70) . "\n";
    foreach ($users as $u) echo fmt_user($u) . "\n";
}

function cmd_reset($args) {
    $username = $args[0] ?? null;
    if (!$username) die("استفاده: php pg.php reset <username>\n");
    $token = login();
    list($code, $b) = api('POST', "/api/user/" . rawurlencode($username) . "/reset", $token);
    echo in_array($code, [200, 201], true)
        ? "✓ مصرف «$username» صفر شد.\n"
        : "✗ خطا (HTTP $code): " . json_encode($b, JSON_UNESCAPED_UNICODE) . "\n";
}

function cmd_delete($args) {
    $username = $args[0] ?? null;
    if (!$username) die("استفاده: php pg.php delete <username>\n");
    $token = login();
    list($code, $b) = api('DELETE', "/api/user/" . rawurlencode($username), $token);
    echo in_array($code, [200, 204], true)
        ? "✓ «$username» حذف شد.\n"
        : "✗ خطا (HTTP $code): " . json_encode($b, JSON_UNESCAPED_UNICODE) . "\n";
}

function cmd_plans() {
    global $PLANS_GB, $EXPIRE_DAYS;
    echo "پلن‌های مجاز (همه یک‌ماهه / {$EXPIRE_DAYS} روزه):\n";
    foreach ($PLANS_GB as $p) echo "  - {$p} گیگ\n";
}

function cmd_help() {
    echo <<<TXT
PasarGuard config manager
دستورها:
  create <username> <planGB>   ساخت کانفیگ یک‌ماهه (پلن: 5,10,20,30,50,100)
  info   <username>            مصرف/باقی‌مانده/انقضای یک کانفیگ
  list                         لیست همه کانفیگ‌ها با حجم باقی‌مانده
  reset  <username>            صفر کردن مصرف
  delete <username>            حذف کانفیگ
  plans                        نمایش پلن‌ها

TXT;
}

/* ----------------- اجرا ----------------- */
$argvv = $argv ?? [];
$cmd  = $argvv[1] ?? 'help';
$rest = array_slice($argvv, 2);

switch ($cmd) {
    case 'create': cmd_create($rest); break;
    case 'info':   cmd_info($rest);   break;
    case 'list':   cmd_list($rest);   break;
    case 'reset':  cmd_reset($rest);  break;
    case 'delete': cmd_delete($rest); break;
    case 'plans':  cmd_plans();        break;
    default:       cmd_help();         break;
}

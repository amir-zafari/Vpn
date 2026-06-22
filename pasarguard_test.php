<?php
/**
 * PasarGuard API tester
 * لاگین با یوزر/پسورد، لیست کاربران/گروه‌ها، و ساخت کانفیگ تستی.
 * اجرا از خط فرمان:   php pasarguard_test.php
 * یا از مرورگر:       فایل را روی یک هاست PHP بگذار و باز کن.
 */

// ---------------------- تنظیمات ----------------------
$BASE     = 'https://sub.vpnservice.cloud:8443';
$USERNAME = 'Pouria231';
$PASSWORD = '4BjPc47.v5MVUP2H';

// اگر API پشت مسیر مخفی بود، این را به '/p4r34mAB' تغییر بده.
$API_PREFIX = '';            // مثلا: '/p4r34mAB'

// آیا کاربر تستی ساخته شود؟ (اگر فقط می‌خواهی لاگین/لیست را ببینی، false کن)
$CREATE_TEST_USER = true;
$TEST_USERNAME    = 'test_' . substr(md5(uniqid()), 0, 6);

// خروجی متنی برای CLI، HTML برای مرورگر
$IS_CLI = (php_sapi_name() === 'cli');
if (!$IS_CLI) { header('Content-Type: text/plain; charset=utf-8'); }
// -----------------------------------------------------

function line($c = '=') { echo str_repeat($c, 60) . "\n"; }

/**
 * درخواست به پنل. خروجی: [http_code, body_string, curl_error]
 */
function req($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,   // -k : نادیده گرفتن گواهی SSL
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, $resp, $err];
}

/** خروجی زیبا: اگر JSON بود مرتب چاپ کن، وگرنه خام */
function show($title, $code, $body, $err) {
    line();
    echo "» $title\n";
    line('-');
    echo "HTTP code: $code\n";
    if ($err) { echo "cURL error: $err\n"; }
    $decoded = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "Body (JSON):\n";
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "Body (raw):\n";
        echo ($body === false || $body === '' ? '(خالی)' : substr($body, 0, 4000)) . "\n";
    }
    echo "\n";
    return $decoded;
}

echo "PasarGuard API Test\n";
echo "Base: $BASE   Prefix: '" . ($API_PREFIX ?: '(none)') . "'\n";
echo "User: $USERNAME\n";

$api = $BASE . $API_PREFIX;

// ===================== 1) لاگین =====================
list($code, $body, $err) = req(
    'POST',
    "$api/api/admin/token",
    ['Content-Type: application/x-www-form-urlencoded'],
    http_build_query([
        'grant_type' => 'password',
        'username'   => $USERNAME,
        'password'   => $PASSWORD,
    ])
);
$login = show('1) LOGIN  →  POST /api/admin/token', $code, $body, $err);

if ($code === 0) {
    line();
    echo "اتصال برقرار نشد (timeout/شبکه). آدرس و پورت و فایروال را چک کن.\n";
    exit(1);
}
if ($code === 404) {
    line();
    echo "404 گرفتی → احتمالا API پشت مسیر مخفی است.\n";
    echo "مقدار \$API_PREFIX را در بالای فایل به '/p4r34mAB' تغییر بده و دوباره اجرا کن.\n";
    exit(1);
}
if (empty($login['access_token'])) {
    line();
    echo "توکن گرفته نشد. معمولا یعنی یوزر/پسورد اشتباه است (HTTP 401).\n";
    exit(1);
}

$token = $login['access_token'];
echo ">> لاگین موفق بود. is_sudo = " . var_export($login['is_sudo'] ?? null, true) . "\n";
$AUTH = ["Authorization: Bearer $token"];

// ===================== 2) اطلاعات ادمین جاری =====================
list($code, $body, $err) = req('GET', "$api/api/admin", $AUTH);
$me = show('2) WHOAMI  →  GET /api/admin', $code, $body, $err);

// گروه‌های مجاز و بازه‌ی انقضای مجاز را از خود whoami برمی‌داریم
$allowedGroups = $me['role']['access']['allowed_group_ids'] ?? [];
$firstGroupId  = $allowedGroups[0] ?? null;
$expireMin     = $me['permission_overrides']['expire_min'] ?? 86400;     // پیش‌فرض ۱ روز
$expireMax     = $me['permission_overrides']['expire_max'] ?? 2592000;   // پیش‌فرض ۳۰ روز
echo ">> گروه مجاز: " . json_encode($allowedGroups) .
     "   |  بازه انقضای مجاز: {$expireMin}..{$expireMax} ثانیه\n\n";

// ===================== 3) لیست گروه‌ها (read_simple) =====================
list($code, $body, $err) = req('GET', "$api/api/groups", $AUTH);
show('3) GROUPS  →  GET /api/groups', $code, $body, $err);

// ===================== 4) لیست کاربران =====================
list($code, $body, $err) = req('GET', "$api/api/users", $AUTH);
show('4) USERS  →  GET /api/users', $code, $body, $err);

// ===================== 5) ساخت کاربر تستی =====================
if ($CREATE_TEST_USER) {
    if ($firstGroupId === null) {
        line();
        echo "هیچ گروه مجازی پیدا نشد؛ نمی‌توان کاربر ساخت.\n";
        exit(1);
    }
    // انقضا باید داخل بازه‌ی مجاز باشد. وسط بازه را انتخاب می‌کنیم تا مطمئن باشیم مجاز است.
    $duration = (int) max($expireMin, min($expireMax, 7 * 86400)); // ~۷ روز، ولی داخل بازه
    $expireTs = time() + $duration;

    $payload = [
        'username'   => $TEST_USERNAME,
        'status'     => 'active',
        'expire'     => $expireTs,                 // timestamp یونیکس داخل بازه مجاز
        'data_limit' => 104857600,                 // ۱۰۰ مگابایت برای تست (از سهمیه ۱ گیگ)
        'group_ids'  => [$firstGroupId],           // گروه مجاز (مثلا 29)
    ];
    list($code, $body, $err) = req(
        'POST',
        "$api/api/user",
        array_merge($AUTH, ['Content-Type: application/json']),
        json_encode($payload)
    );
    echo "Payload ارسالی برای ساخت کاربر:\n";
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $created = show("5) CREATE USER  →  POST /api/user  (username: $TEST_USERNAME)", $code, $body, $err);

    if ($code === 403) {
        echo ">> 403: نقش operator اجازه ساخت کاربر ندارد.\n";
    } elseif ($code === 422) {
        echo ">> 422: یکی از مقادیر طبق قوانین پنل قبول نشد (به پیام detail بالا نگاه کن).\n";
    } elseif (in_array($code, [200, 201], true)) {
        echo ">> کاربر ساخته شد! id = " . ($created['id'] ?? '?') . "\n";
        $sub = $created['subscription_url'] ?? '';
        if ($sub !== '') {
            echo ">> لینک ساب: " . (strpos($sub, 'http') === 0 ? $sub : $BASE . $sub) . "\n";
        } else {
            echo ">> لینک ساب خالی برگشت؛ با GET /api/user/{$TEST_USERNAME} می‌توانی کامل ببینی.\n";
        }
    }
}

line();
echo "تمام شد.\n";

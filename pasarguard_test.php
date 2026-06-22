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
show('2) WHOAMI  →  GET /api/admin', $code, $body, $err);

// ===================== 3) لیست گروه‌ها =====================
list($code, $body, $err) = req('GET', "$api/api/groups", $AUTH);
$groups = show('3) GROUPS  →  GET /api/groups', $code, $body, $err);

// شناسه اولین گروه را برای ساخت کاربر برمی‌داریم
$firstGroupId = null;
if (is_array($groups)) {
    if (isset($groups['groups'][0]['id']))      $firstGroupId = $groups['groups'][0]['id'];
    elseif (isset($groups[0]['id']))            $firstGroupId = $groups[0]['id'];
}

// ===================== 4) لیست کاربران =====================
list($code, $body, $err) = req('GET', "$api/api/users", $AUTH);
show('4) USERS  →  GET /api/users', $code, $body, $err);

// ===================== 5) ساخت کاربر تستی =====================
if ($CREATE_TEST_USER) {
    $payload = [
        'username'   => $TEST_USERNAME,
        'status'     => 'active',
        'expire'     => 0,        // 0 = بدون انقضا
        'data_limit' => 0,        // 0 = بدون محدودیت حجم
        'group_ids'  => $firstGroupId !== null ? [$firstGroupId] : [],
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
        echo ">> 403: نقش operator اجازه ساخت کاربر ندارد. باید از فروشنده دسترسی بگیری.\n";
    } elseif (!empty($created['subscription_url'])) {
        echo ">> لینک ساب کانفیگ ساخته‌شده:\n";
        echo $BASE . $created['subscription_url'] . "\n";
    }
}

line();
echo "تمام شد.\n";

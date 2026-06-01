<?php


$marzban_url = 'https://p2pnl.mhx-movie6.xyz:2087'; 
$marzban_user = 'ali'; 
$marzban_pass = 'ali@ali'; 
$target_username = 'user_7024969184_1760634559'; 


header('Content-Type: text/html; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><title>تست API مرزبان</title>";
echo "<style>body{font-family: sans-serif; padding: 20px; background-color: #f4f4f4;} .container{max-width: 800px; margin: auto; background: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);} pre{background: #eee; padding: 15px; border-radius: 5px; white-space: pre-wrap; word-wrap: break-word; direction: ltr; text-align: left;} .error{color: red; font-weight: bold;} .success{color: green; font-weight: bold;}</style>";
echo "</head><body><div class='container'>";


if (isset($_POST['marzban_url'])) {
    $marzban_url = rtrim(trim($_POST['marzban_url']), '/');
    $marzban_user = trim($_POST['marzban_user']);
    $marzban_pass = trim($_POST['marzban_pass']);
    $target_username = trim($_POST['target_username']);
}


echo "<h1>تست API مرزبان برای دریافت لینک‌های کانفیگ</h1>";
echo "<form method='post'>";
echo "<p><label>آدرس پنل مرزبان: <input type='text' name='marzban_url' value='" . htmlspecialchars($marzban_url) . "' style='width: 300px;' required></label></p>";
echo "<p><label>نام کاربری ادمین: <input type='text' name='marzban_user' value='" . htmlspecialchars($marzban_user) . "' required></label></p>";
echo "<p><label>رمز عبور ادمین: <input type='password' name='marzban_pass' value='" . htmlspecialchars($marzban_pass) . "' required></label></p>";
echo "<p><label>نام کاربری هدف: <input type='text' name='target_username' value='" . htmlspecialchars($target_username) . "' required></label></p>";
echo "<p><button type='submit'>دریافت اطلاعات</button></p>";
echo "</form><hr>";

if (empty($marzban_url) || empty($marzban_user) || empty($marzban_pass) || empty($target_username)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo "<p class='error'>لطفاً تمام فیلدها را پر کنید.</p>";
    }
    echo "</div></body></html>";
    exit;
}


function marzbanApiRequest($url, $endpoint, $method = 'GET', $data = [], $accessToken = null) {
    $full_url = $url . $endpoint;
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($accessToken) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $full_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, 
        CURLOPT_SSL_VERIFYHOST => false, 
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo "<p class='error'>خطای cURL: " . curl_error($ch) . "</p>";
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    
    if ($http_code >= 400) {
        echo "<p class='error'>خطای HTTP {$http_code} از سرور دریافت شد.</p>";
        echo "<p>پاسخ سرور:</p><pre>" . htmlspecialchars($response) . "</pre>";
        return false;
    }

    return json_decode($response, true);
}


function getMarzbanToken($url, $username, $password) {
    $token_url = $url . '/api/admin/token';
    $postData = http_build_query(['username' => $username, 'password' => $password]);
    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $token_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response_body = curl_exec($ch);
    if (curl_errno($ch)) {
        echo "<p class='error'>خطای cURL در گرفتن توکن: " . curl_error($ch) . "</p>";
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $response = json_decode($response_body, true);

    if (isset($response['access_token'])) {
        return $response['access_token'];
    }
    
    echo "<p class='error'>گرفتن توکن ناموفق بود.</p>";
    echo "<p>پاسخ سرور:</p><pre>" . htmlspecialchars($response_body) . "</pre>";
    return false;
}


echo "<h2>نتایج تست:</h2>";

echo "<p>۱. در حال تلاش برای گرفتن توکن از سرور...</p>";
$token = getMarzbanToken($marzban_url, $marzban_user, $marzban_pass);

if ($token) {
    echo "<p class='success'>توکن با موفقیت دریافت شد!</p>";
    echo "<pre style='font-size: 10px;'>" . htmlspecialchars($token) . "</pre>";

    echo "<p>۲. در حال دریافت اطلاعات کاربر '" . htmlspecialchars($target_username) . "'...</p>";
    $user_data = marzbanApiRequest($marzban_url, "/api/user/{$target_username}", 'GET', [], $token);

    if ($user_data && !isset($user_data['detail'])) {
        echo "<p class='success'>اطلاعات کاربر با موفقیت دریافت شد.</p>";
        echo "<p>اطلاعات کامل دریافت شده از سرور:</p>";
        echo "<pre>" . htmlspecialchars(json_encode($user_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";

        if (isset($user_data['links']) && is_array($user_data['links']) && !empty($user_data['links'])) {
            echo "<h3>لینک‌های کانفیگ کاربر:</h3>";
            foreach ($user_data['links'] as $link) {
                echo "<pre>" . htmlspecialchars($link) . "</pre>";
            }
        } else {
            echo "<p class='error'>هیچ لینک کانفیگی (links) برای این کاربر یافت نشد. ممکن است هیچ پروتکلی برای او فعال نباشد.</p>";
        }

    } else {
        echo "<p class='error'>دریافت اطلاعات کاربر ناموفق بود.</p>";
        if ($user_data && isset($user_data['detail'])) {
             echo "<p>دلیل خطا از سرور: " . htmlspecialchars($user_data['detail']) . "</p>";
        }
    }
} else {
    echo "<p class='error'>عملیات به دلیل عدم دریافت توکن متوقف شد.</p>";
}

echo "</div></body></html>";
?>
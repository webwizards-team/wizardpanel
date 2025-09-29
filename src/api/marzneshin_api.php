<?php

 // بازنویسی شده برای دریافت صحیح کانفیگ

function marzneshinApiRequest($endpoint, $server_id, $method = 'GET', $data = []) {
    $stmt = pdo()->prepare("SELECT url FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_url = $stmt->fetchColumn();
    if (!$server_url) return ['error' => 'Server not configured.'];

    $accessTokenResult = getMarzneshinToken($server_id);
    if (is_array($accessTokenResult) && isset($accessTokenResult['error'])) {
        return ['error' => 'Token Error: ' . $accessTokenResult['error']];
    }
    $accessToken = $accessTokenResult;
    
    $url = rtrim($server_url, '/') . $endpoint;
    $headers = ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $accessToken];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);

    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    $response_body = curl_exec($ch);
    curl_close($ch);
    return json_decode($response_body, true);
}

function marzneshinPublicApiRequest($endpoint, $server_id) {
    $stmt = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    if (!$server_info) return false;
    
    $base_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
    $url = $base_url . $endpoint;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function getMarzneshinToken($server_id) {
    $cache_key = 'marzneshin_token_' . $server_id;
    $stmt_cache = pdo()->prepare("SELECT cache_value FROM cache WHERE cache_key = ? AND expire_at > ?");
    $stmt_cache->execute([$cache_key, time()]);
    if ($cached_token = $stmt_cache->fetchColumn()) return $cached_token;

    $stmt = pdo()->prepare("SELECT url, username, password FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    if (!$server_info) return ['error' => "Server info not found for server ID: {$server_id}"];
    
    $url = rtrim($server_info['url'], '/') . '/api/admins/token';
    $postData = http_build_query(['username' => $server_info['username'], 'password' => $server_info['password']]);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($response_body, true);
    if (isset($response['access_token'])) {
        $new_token = $response['access_token'];
        $expire_time = time() + 3500;
        $stmt_insert_cache = pdo()->prepare("INSERT INTO cache (cache_key, cache_value, expire_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expire_at = VALUES(expire_at)");
        $stmt_insert_cache->execute([$cache_key, $new_token, $expire_time]);
        return $new_token;
    }
    
    $error_detail = $response['detail'] ?? $response_body;
    return ['error' => "HTTP {$http_code} - " . (is_string($error_detail) ? $error_detail : json_encode($error_detail))];
}

function getMarzneshinServices($server_id) {
    $response = marzneshinApiRequest('/api/services', $server_id, 'GET');
    return $response['items'] ?? [];
}

function createMarzneshinUser($plan, $chat_id, $plan_id) {
    $server_id = $plan['server_id'];
    $service_id = $plan['marzneshin_service_id'];
    $username = "user_{$chat_id}_" . time();
    
    $stmt = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    if (!$server_info) return false;

    $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
    $userData = [
        'username' => $username,
        'data_limit' => $plan['volume_gb'] * 1024 * 1024 * 1024,
        'expire_date' => date('c', time() + $plan['duration_days'] * 86400),
        'service_ids' => [(int)$service_id],
        'expire_strategy' => 'fixed_date'
    ];

    $response = marzneshinApiRequest('/api/users', $server_id, 'POST', $userData);
    
    if (isset($response['username'])) {
        // استخراج صحیح یزورنیم و پسورد
        $new_username = $response['username'];
        $key = $response['key'];

        // لینک اشتراک و کانفیگ تکی با پارامتر میسازیم
        $subscription_path = "/sub/{$new_username}/{$key}/";
        $links_path = $subscription_path . 'links';

        $full_subscription_url = $base_sub_url . $subscription_path;
        
        $links = [];
        $links_response_raw = marzneshinPublicApiRequest($links_path, $server_id);
        if (is_string($links_response_raw) && !str_contains(strtolower($links_response_raw), 'error')) {
            $links = explode("\n", trim($links_response_raw));
        }

        saveUserService($chat_id, [
            'server_id' => $server_id, 'username' => $new_username, 'plan_id' => $plan_id,
            'sub_url' => $full_subscription_url, 'expire_timestamp' => strtotime($response['expire_date']),
            'volume_gb' => $plan['volume_gb'],
        ]);

        return [
            'username' => $new_username,
            'subscription_url' => $full_subscription_url,
            'links' => array_filter($links),
        ];
    }
    
    error_log("[Marzneshin Create User Failed] Payload: " . json_encode($userData) . " | Response: " . json_encode($response));
    return false;
}

// --- تابع دریافت اطلاعات کاربر ---
function getMarzneshinUser($username, $server_id) {

    $user_response = marzneshinApiRequest("/api/users/{$username}", $server_id, 'GET');

    if (isset($user_response['username'])) {
        $links = [];
        

        if (isset($user_response['key'])) {
             $key = $user_response['key'];

             $links_endpoint = "/sub/{$username}/{$key}/links";
             
             $links_response_raw = marzneshinPublicApiRequest($links_endpoint, $server_id);
             
             if (is_string($links_response_raw) && !str_contains(strtolower($links_response_raw), 'error')) {
                $links = explode("\n", trim($links_response_raw));
             }
        }
       
        return [
            'status' => $user_response['is_active'] ? 'active' : 'disabled',
            'expire' => $user_response['expire_date'] ? strtotime($user_response['expire_date']) : 0,
            'used_traffic' => $user_response['used_traffic'],
            'data_limit' => $user_response['data_limit'],
            'links' => array_filter($links),
        ];
    }

    error_log("[Marzneshin Get User Failed] Username: {$username} | Response: " . json_encode($user_response));
    return false;
}

function modifyMarzneshinUser($username, $server_id, $data) {
    $marzneshinData = [];
    if (isset($data['data_limit'])) {
        $marzneshinData['data_limit'] = $data['data_limit'];
    }
    if (isset($data['expire'])) {
        $marzneshinData['expire_date'] = date('c', $data['expire']);
    }

    $response = marzneshinApiRequest("/api/users/{$username}", $server_id, 'PUT', $marzneshinData);
    return $response && isset($response['username']);
}

function deleteMarzneshinUser($username, $server_id) {
    $response = marzneshinApiRequest("/api/users/{$username}", $server_id, 'DELETE');
    return is_null($response) || (isset($response['detail']) && str_contains($response['detail'], 'not found'));
}
<?php

// --- توابع پایه (بدون تغییر) ---

function getSanaeiCookie($server_id) {
    $cache_key = 'sanaei_cookie_' . $server_id;
    $stmt_cache = pdo()->prepare("SELECT cache_value FROM cache WHERE cache_key = ? AND expire_at > ?");
    $stmt_cache->execute([$cache_key, time()]);
    if ($cached_cookie = $stmt_cache->fetchColumn()) {
        return $cached_cookie;
    }

    $stmt = pdo()->prepare("SELECT url, username, password FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    if (!$server_info) return false;

    $url = rtrim($server_info['url'], '/') . '/login';
    $postData = ['username' => $server_info['username'], 'password' => $server_info['password']];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData), CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    preg_match('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
    if (isset($matches[1])) {
        $cookie = $matches[1];
        $expire_time = time() + 3500;
        $stmt_insert_cache = pdo()->prepare("INSERT INTO cache (cache_key, cache_value, expire_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expire_at = VALUES(expire_at)");
        $stmt_insert_cache->execute([$cache_key, $cookie, $expire_time]);
        return $cookie;
    }
    return false;
}

function sanaeiApiRequest($endpoint, $server_id, $method = 'GET', $data = []) {
    $stmt = pdo()->prepare("SELECT url FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_url = $stmt->fetchColumn();
    if (!$server_url) return ['success' => false, 'msg' => 'Sanaei server is not configured.'];

    $cookie = getSanaeiCookie($server_id);
    if (!$cookie) return ['success' => false, 'msg' => 'Login failed'];

    $url = rtrim($server_url, '/') . $endpoint;
    $headers = ['Cookie: ' . $cookie, 'Accept: application/json'];
    if ($method === 'POST') $headers[] = 'Content-Type: application/json';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}


function getSanaeiInbounds($server_id) {
    $response = sanaeiApiRequest('/panel/api/inbounds/list', $server_id);
    return ($response['success'] && isset($response['obj'])) ? $response['obj'] : [];
}

function _findSanaeiClientInAllInbounds($email_username, $server_id) {
    $inbounds = getSanaeiInbounds($server_id);
    if (empty($inbounds)) return false;

    foreach ($inbounds as $inbound_summary) {
        $inbound_id = $inbound_summary['id'];
        $response = sanaeiApiRequest("/panel/api/inbounds/get/{$inbound_id}", $server_id);
        
        if ($response && $response['success'] && isset($response['obj']['settings'])) {
            $settings = json_decode($response['obj']['settings'], true);
            if (isset($settings['clients'])) {
                foreach ($settings['clients'] as $client) {
                    if (isset($client['email']) && $client['email'] === $email_username) {
                        return ['client' => $client, 'inbound_id' => $inbound_id];
                    }
                }
            }
        }
    }
    return false;
}

function createSanaeiUser($plan, $chat_id, $plan_id) {
    $server_id = $plan['server_id'];
    $inbound_id = $plan['inbound_id'];
    
    $stmt_server = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
    $stmt_server->execute([$server_id]);
    $server_info = $stmt_server->fetch();
    if(!$server_info) return false;

    $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
    $uuid = generateUUID();
    $email = "user_{$chat_id}_" . time();
    $subId = generateUUID(16);
    $expire_time = ($plan['duration_days'] > 0) ? (time() + $plan['duration_days'] * 86400) * 1000 : 0;
    $total_bytes = ($plan['volume_gb'] > 0) ? $plan['volume_gb'] * 1024 * 1024 * 1024 : 0;
    $client_settings = [ "id" => $uuid, "email" => $email, "totalGB" => $total_bytes, "expiryTime" => $expire_time, "enable" => true, "tgId" => (string)$chat_id, "subId" => $subId ];
    $data = ['id' => (int)$inbound_id, 'settings' => json_encode(['clients' => [$client_settings]])];
    $response = sanaeiApiRequest('/panel/api/inbounds/addClient', $server_id, 'POST', $data);

    if (isset($response['success']) && $response['success']) {
        $sub_link = $base_sub_url . '/sub/' . $subId;
        // اصلاحیه: پاس دادن server_id به تابع کمکی
        $links = fetchAndParseSubscriptionUrl($sub_link, $server_id);
        saveUserService($chat_id, [ 'server_id' => $server_id, 'username' => $email, 'plan_id' => $plan_id, 'sub_url' => $sub_link, 'expire_timestamp' => $expire_time > 0 ? $expire_time / 1000 : 0, 'volume_gb' => $plan['volume_gb'], 'sanaei_inbound_id' => $inbound_id, 'sanaei_uuid' => $uuid ]);
        return ['username' => $email, 'subscription_url' => $sub_link, 'links' => $links];
    }
    
    error_log("Failed to create Sanaei user. Response: " . json_encode($response));
    return false;
}

function getSanaeiUser($username, $server_id) {
    $traffic_response = sanaeiApiRequest("/panel/api/inbounds/getClientTraffics/{$username}", $server_id);
    if (!$traffic_response || !$traffic_response['success'] || !isset($traffic_response['obj'])) {
        error_log("Could not fetch user traffic for {$username}.");
        return false;
    }
    $client_traffic_data = $traffic_response['obj'];
    
    $stmt_service = pdo()->prepare("SELECT sub_url FROM services WHERE marzban_username = ? AND server_id = ?");
    $stmt_service->execute([$username, $server_id]);
    $sub_url = $stmt_service->fetchColumn();

    // اصلاحیه: پاس دادن server_id به تابع کمکی
    $links = fetchAndParseSubscriptionUrl($sub_url, $server_id);
    
    return [
        'status' => ($client_traffic_data['enable'] && ($client_traffic_data['expiryTime'] == 0 || $client_traffic_data['expiryTime'] > time() * 1000)) ? 'active' : 'disabled',
        'expire' => $client_traffic_data['expiryTime'] > 0 ? floor($client_traffic_data['expiryTime'] / 1000) : 0,
        'used_traffic' => $client_traffic_data['up'] + $client_traffic_data['down'],
        'data_limit' => $client_traffic_data['totalGB'] ?? 0,
        'links' => $links,
    ];
}

function modifySanaeiUser($username, $server_id, $data) {
    $foundClientData = _findSanaeiClientInAllInbounds($username, $server_id);
    if (!$foundClientData) return false;

    $inbound_id = $foundClientData['inbound_id'];
    $uuid = $foundClientData['client']['id'];
    
    $traffic_response = sanaeiApiRequest("/panel/api/inbounds/getClientTraffics/{$username}", $server_id);
    if (!$traffic_response || !$traffic_response['success'] || !isset($traffic_response['obj'])) return false;
    $currentClientData = $traffic_response['obj'];

    $update_payload = [
        'id' => (int)$inbound_id,
        'settings' => json_encode(['clients' => [[
            'id' => $uuid, 'email' => $username, 'enable' => true,
            'totalGB' => $data['data_limit'] ?? ($currentClientData['totalGB'] ?? 0),
            'expiryTime' => isset($data['expire']) ? $data['expire'] * 1000 : ($currentClientData['expiryTime'] ?? 0),
        ]]])
    ];
    
    $response = sanaeiApiRequest("/panel/api/inbounds/updateClient/{$uuid}", $server_id, 'POST', $update_payload);
    return $response && $response['success'];
}

function deleteSanaeiUser($username, $server_id) {
    $foundClientData = _findSanaeiClientInAllInbounds($username, $server_id);
    if (!$foundClientData) return true;

    $inbound_id = $foundClientData['inbound_id'];
    $uuid = $foundClientData['client']['id'];

    $response = sanaeiApiRequest("/panel/api/inbounds/{$inbound_id}/delClient/{$uuid}", $server_id, 'POST');
    return $response && $response['success'];
}

function generateUUID($length = 36) {
    if ($length === 36) {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    } else {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $randomString;
    }
}
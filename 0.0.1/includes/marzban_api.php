<?php

function marzbanApiRequest($endpoint, $server_id, $method = 'GET', $data = [], $accessToken = null)
{
    $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();

    if (!$server_info) {
        error_log("Marzban server with ID {$server_id} not found.");
        return ['error' => 'Marzban server is not configured.'];
    }

    $url = rtrim($server_info['url'], '/') . $endpoint;

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($accessToken) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);

    switch ($method) {
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

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("Marzban API cURL error for server {$server_id}: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    return json_decode($response, true);
}

function getMarzbanToken($server_id)
{
    $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();

    if (!$server_info) {
        error_log("Marzban credentials are not configured for server ID {$server_id}.");
        return false;
    }

    $cache_key = 'marzban_token_' . $server_id;
    $current_time = time();

    $stmt_cache = pdo()->prepare("SELECT cache_value FROM cache WHERE cache_key = ? AND expire_at > ?");
    $stmt_cache->execute([$cache_key, $current_time]);
    $cached_token = $stmt_cache->fetchColumn();
    if ($cached_token) {
        return $cached_token;
    }

    $url = rtrim($server_info['url'], '/') . '/api/admin/token';
    $postData = http_build_query([
        'username' => $server_info['username'],
        'password' => $server_info['password'],
    ]);

    $headers = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response_body = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log("Marzban Token cURL error for server {$server_id}: " . curl_error($ch));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $response = json_decode($response_body, true);

    if (isset($response['access_token'])) {
        $new_token = $response['access_token'];
        $expire_time = $current_time + 3500;

        $stmt_insert_cache = pdo()->prepare(
            "INSERT INTO cache (cache_key, cache_value, expire_at) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE cache_value = VALUES(cache_value), expire_at = VALUES(expire_at)"
        );
        $stmt_insert_cache->execute([$cache_key, $new_token, $expire_time]);

        return $new_token;
    }

    error_log("Failed to get Marzban access token for server {$server_id}. Response: " . $response_body);
    return false;
}

function createMarzbanUser($plan, $chat_id, $plan_id)
{
    $server_id = $plan['server_id'];
    $accessToken = getMarzbanToken($server_id);
    if (!$accessToken) {
        return false;
    }

    $username = "user_{$chat_id}_" . time();

    $userData = [
        'username' => $username,
        'proxies' => ['vless' => new stdClass()],
        'inbounds' => new stdClass(),
        'expire' => time() + $plan['duration_days'] * 86400,
        'data_limit' => $plan['volume_gb'] * 1024 * 1024 * 1024,
        'data_limit_reset_strategy' => 'no_reset',
    ];

    $response = marzbanApiRequest('/api/user', $server_id, 'POST', $userData, $accessToken);

    if (isset($response['username'])) {
        pdo()
            ->prepare("UPDATE services SET warning_sent = 0 WHERE marzban_username = ? AND server_id = ?")
            ->execute([$response['username'], $server_id]);

        saveUserService($chat_id, [
            'server_id' => $server_id,
            'username' => $response['username'],
            'plan_id' => $plan_id,
            'sub_url' => $response['subscription_url'],
            'expire_timestamp' => $userData['expire'],
            'volume_gb' => $plan['volume_gb'],
        ]);
        return $response;
    }

    error_log("Failed to create Marzban user for chat_id {$chat_id} on server {$server_id}. Response: " . json_encode($response));
    return false;
}

function getMarzbanUser($username, $server_id)
{
    $accessToken = getMarzbanToken($server_id);
    if (!$accessToken) {
        return false;
    }

    return marzbanApiRequest("/api/user/{$username}", $server_id, 'GET', [], $accessToken);
}

function modifyMarzbanUser($username, $server_id, $data)
{
    $accessToken = getMarzbanToken($server_id);
    if (!$accessToken) {
        return false;
    }

    return marzbanApiRequest("/api/user/{$username}", $server_id, 'PUT', $data, $accessToken);
}

function deleteMarzbanUser($username, $server_id)
{
    $accessToken = getMarzbanToken($server_id);
    if (!$accessToken) {
        return false;
    }

    return marzbanApiRequest("/api/user/{$username}", $server_id, 'DELETE', [], $accessToken);
}
?>

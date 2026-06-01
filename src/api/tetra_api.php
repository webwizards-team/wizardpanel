<?php

function createTetraLink($chat_id, $amount, $description, $metadata = []) {
    $settings = getSettings();
    $apiKey = $settings['tetra_api_key'] ?? '';
    if (empty($apiKey)) {
        return ['success' => false, 'error' => "❌ درگاه پرداخت تترا 98 توسط ادمین پیکربندی نشده است."];
    }

    $script_url = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
    
    
    $hash_id = "trx_" . $chat_id . "_" . time(); 
    
    
    $amount_rials = $amount * 10;

    $result = createTetraOrder($apiKey, $amount_rials, $hash_id, $script_url, $description);

    if (isset($result['status']) && $result['status'] == '100') {
        $authority = $result['Authority'];
        
        
        $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, gateway, authority, description, metadata) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$chat_id, $amount, 'tetra', $authority, $description, json_encode($metadata)]);
        
        
        $payment_url = $result['payment_url_web'];
        return ['success' => true, 'url' => $payment_url];
    } else {
        $error_message = $result['message'] ?? 'خطای نامشخص از درگاه تترا 98';
        return ['success' => false, 'error' => "❌ خطا در اتصال به درگاه پرداخت تترا: " . $error_message];
    }
}

function createTetraOrder($apiKey, $amount, $hash_id, $callbackUrl, $description = '', $email = '', $mobile = '') {
    $data = [
        "ApiKey" => $apiKey,
        "Hash_id" => $hash_id,
        "Amount" => $amount, 
        "Description" => $description,
        "Email" => $email,
        "Mobile" => $mobile,
        "CallbackURL" => $callbackUrl,
    ];

    $ch = curl_init("https://tetra98.ir/api/create_order");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function verifyTetraPayment($apiKey, $authority) {
    $data = [
        "ApiKey" => $apiKey,
        "authority" => $authority,
    ];

    $ch = curl_init("https://tetra98.ir/api/verify");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
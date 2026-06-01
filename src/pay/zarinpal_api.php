<?php


function createZarinpalLink($chat_id, $amount, $description, $metadata = []) {
    $settings = getSettings();
    $merchant_id = $settings['zarinpal_merchant_id'] ?? '';
    if (empty($merchant_id)) {
         return ['success' => false, 'error' => "❌ درگاه پرداخت زرین‌پال توسط ادمین پیکربندی نشده است."];
    }

    $script_url = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/verify_payment.php';
    
    $data = [
        "merchant_id" => $merchant_id,
        "amount" => $amount * 10, 
        "callback_url" => $script_url,
        "description" => $description,
        "metadata" => $metadata
    ];
    $jsonData = json_encode($data);

    $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
    curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);
    
    $result = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($result, true);
    
    if (empty($result['errors'])) {
        $authority = $result['data']['authority'];
        
        
        $stmt = pdo()->prepare("INSERT INTO transactions (user_id, amount, gateway, authority, description, metadata) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$chat_id, $amount, 'zarinpal', $authority, $description, json_encode($metadata)]);
        
        $payment_url = 'https://www.zarinpal.com/pg/StartPay/' . $authority;
        return ['success' => true, 'url' => $payment_url];
    } else {
        $error_code = $result['errors']['code'];
        return ['success' => false, 'error' => "❌ خطا در اتصال به درگاه زرین‌پال. کد خطا: {$error_code}"];
    }
}


function verifyZarinpalPayment($merchant_id, $amount_toman, $authority) {
    $data = [
        "merchant_id" => $merchant_id,
        "amount" => (int)$amount_toman * 10, 
        "authority" => $authority,
    ];
    $jsonData = json_encode($data);

    $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/verify.json');
    curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Content-Length: ' . strlen($jsonData)]);

    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}
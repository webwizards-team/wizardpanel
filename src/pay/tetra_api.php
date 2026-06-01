<?php

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
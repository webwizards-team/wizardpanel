<?php

// --- فراخوانی فایل‌های مورد نیاز ---
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// دریافت پارامترها از زرین‌پال
$authority = $_GET['Authority'] ?? null;
$status = $_GET['Status'] ?? null;

if (empty($authority) || empty($status)) {
    die("اطلاعات بازگشتی از درگاه ناقص است.");
}

// پیدا کردن تراکنش در دیتابیس
$stmt = pdo()->prepare("SELECT * FROM transactions WHERE authority = ? AND status = 'pending'");
$stmt->execute([$authority]);
$transaction = $stmt->fetch();

if (!$transaction) {
    die("تراکنش یافت نشد یا قبلاً پردازش شده است.");
}

$settings = getSettings();
$merchant_id = $settings['zarinpal_merchant_id'] ?? '';
$amount = (int)$transaction['amount']; // مبلغ به تومان

if ($status == 'OK') {
    // تراکنش موفق بوده، حالا باید وریفای کنیم
    $data = [
        "merchant_id" => $merchant_id,
        "amount" => $amount * 10, // تبدیل تومان به ریال برای وریفای
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
    $result = json_decode($result, true);

    if (empty($result['errors'])) {
        $code = $result['data']['code'];
        if ($code == 100 || $code == 101) { // 100: موفق, 101: قبلا وریفای شده
            $ref_id = $result['data']['ref_id'];

            // آپدیت وضعیت تراکنش
            $stmt = pdo()->prepare("UPDATE transactions SET status = 'completed', ref_id = ?, verified_at = NOW() WHERE id = ?");
            $stmt->execute([$ref_id, $transaction['id']]);

            // شارژ حساب کاربر
            updateUserBalance($transaction['user_id'], $transaction['amount'], 'add');
            $new_balance_data = getUserData($transaction['user_id']);

            // ارسال پیام موفقیت به کاربر در تلگرام
            $message = "✅ پرداخت شما به مبلغ " . number_format($transaction['amount']) . " تومان با موفقیت انجام و حساب شما شارژ شد.\n\n" .
                       "▫️ شماره پیگیری: `{$ref_id}`\n" .
                       "💰 موجودی جدید: " . number_format($new_balance_data['balance']) . " تومان";
            sendMessage($transaction['user_id'], $message);

            // نمایش پیام موفقیت در مرورگر
            echo "<h1>پرداخت موفق</h1><p>تراکنش شما با موفقیت انجام شد. شماره پیگیری: {$ref_id}. لطفاً به ربات تلگرام بازگردید.</p>";

        } else {
            // آپدیت وضعیت تراکنش به ناموفق
            $stmt = pdo()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?");
            $stmt->execute([$transaction['id']]);
            $error_message = "خطا در وریفای تراکنش. کد خطا: " . $code;
            sendMessage($transaction['user_id'], "❌ تراکنش شما ناموفق بود. " . $error_message);
            echo "<h1>پرداخت ناموفق</h1><p>{$error_message}</p>";
        }
    } else {
        // خطایی در ارتباط با زرین‌پال رخ داده
        $error_message = "خطا در ارتباط با درگاه پرداخت.";
        sendMessage($transaction['user_id'], "❌ " . $error_message);
        echo "<h1>خطا</h1><p>{$error_message}</p>";
    }

} else {
    // کاربر تراکنش را لغو کرده
    $stmt = pdo()->prepare("UPDATE transactions SET status = 'cancelled' WHERE id = ?");
    $stmt->execute([$transaction['id']]);
    sendMessage($transaction['user_id'], "❌ شما تراکنش را لغو کردید.");
    echo "<h1>تراکنش لغو شد</h1><p>شما عملیات پرداخت را لغو کردید. لطفاً به ربات بازگردید.</p>";
}
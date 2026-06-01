<?php


require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$settings = getSettings();




if ($request_method === 'GET') {
    $authority = $_GET['Authority'] ?? null;
    $status = $_GET['Status'] ?? null;

    if (empty($authority) || empty($status)) {
        die("اطلاعات بازگشتی از درگاه زرین‌پال ناقص است.");
    }

    $stmt = pdo()->prepare("SELECT * FROM transactions WHERE authority = ? AND gateway = 'zarinpal' AND status = 'pending'");
    $stmt->execute([$authority]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
        die("تراکنش زرین‌پال یافت نشد یا قبلاً پردازش شده است.");
    }

    if ($status == 'OK') {
        $merchant_id = $settings['zarinpal_merchant_id'] ?? '';
        $result = verifyZarinpalPayment($merchant_id, $transaction['amount'], $authority);

        if (empty($result['errors'])) {
            $code = $result['data']['code'];
            if ($code == 100 || $code == 101) { 
                $ref_id = $result['data']['ref_id'];
                processSuccessfulPayment($transaction, $ref_id);
                echo "<h1>پرداخت موفق</h1><p>تراکنش شما با موفقیت انجام شد. شماره پیگیری: {$ref_id}. لطفاً به ربات تلگرام بازگردید.</p>";
            } else {
                processFailedPayment($transaction, "خطا در وریفای تراکنش زرین‌پال. کد خطا: " . $code);
                echo "<h1>پرداخت ناموفق</h1><p>خطا در وریفای تراکنش. کد خطا: {$code}</p>";
            }
        } else {
            processFailedPayment($transaction, "خطا در ارتباط با درگاه پرداخت زرین‌پال.");
            echo "<h1>خطا</h1><p>خطا در ارتباط با درگاه پرداخت زرین‌پال.</p>";
        }
    } else {
        pdo()->prepare("UPDATE transactions SET status = 'cancelled' WHERE id = ?")->execute([$transaction['id']]);
        sendMessage($transaction['user_id'], "❌ شما تراکنش زرین‌پال را لغو کردید.");
        echo "<h1>تراکنش لغو شد</h1><p>شما عملیات پرداخت را لغو کردید. لطفاً به ربات بازگردید.</p>";
    }
}



elseif ($request_method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $status = $input['status'] ?? null;
    $authority = $input['authority'] ?? null;
    
    if (empty($authority)) {
        http_response_code(400); die("اطلاعات بازگشتی از درگاه تترا 98 ناقص است.");
    }
    
    $stmt = pdo()->prepare("SELECT * FROM transactions WHERE authority = ? AND gateway = 'tetra' AND status = 'pending'");
    $stmt->execute([$authority]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
         http_response_code(404); die("تراکنش تترا 98 یافت نشد یا قبلاً پردازش شده است.");
    }
    
    if ($status == 100) {
        $apiKey = $settings['tetra_api_key'] ?? '';
        $verifyResult = verifyTetraPayment($apiKey, $authority);

        if (isset($verifyResult['status']) && $verifyResult['status'] == 100) {
            processSuccessfulPayment($transaction, $authority); 
            echo "<h1>پرداخت موفق</h1><p>تراکنش شما با موفقیت انجام شد. شماره پیگیری: {$authority}. لطفاً به ربات تلگرام بازگردید.</p>";
        } else {
            $error_message = $verifyResult['message'] ?? 'وریفای ناموفق بود';
            processFailedPayment($transaction, "خطا در وریفای تراکنش تترا. پیام درگاه: " . $error_message);
            echo "<h1>پرداخت ناموفق</h1><p>خطا در وریفای تراکنش با درگاه تترا.</p>";
        }
    } else {
        processFailedPayment($transaction, "تراکنش در درگاه تترا موفقیت آمیز نبود.");
        echo "<h1>پرداخت ناموفق</h1><p>تراکنش در درگاه تترا موفقیت آمیز نبود.</p>";
    }
}




function processSuccessfulPayment($transaction, $ref_id) {
    if (!$transaction || $transaction['status'] !== 'pending') return;

    pdo()->prepare("UPDATE transactions SET status = 'completed', ref_id = ?, verified_at = NOW() WHERE id = ?")->execute([$ref_id, $transaction['id']]);

    $metadata = json_decode($transaction['metadata'], true);
    
    if (isset($metadata['purpose']) && $metadata['purpose'] === 'complete_purchase') {
        $plan_id = $metadata['plan_id'];
        $user_id = $metadata['user_id'];
        $discount_code = $metadata['discount_code'] ?? null;
        $custom_name = $metadata['custom_name'] ?? 'سرویس';
        $is_custom_config = $metadata['is_custom_config'] ?? false;

        $final_price = 0;
        $plan = null;
        if (!$is_custom_config) {
            $plan = getPlanById($plan_id);
            $final_price = (float)$plan['price'];
        } else {
            
        }
        
        $discount_applied = false;
        $discount_object = null;

        if ($discount_code) {
            $stmt_discount = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ?");
            $stmt_discount->execute([$discount_code]);
            $discount_object = $stmt_discount->fetch();
            if ($discount_object) {
                 if ($discount_object['type'] == 'percent') {
                    $final_price = $plan['price'] - ($plan['price'] * $discount_object['value']) / 100;
                } else {
                    $final_price = $plan['price'] - $discount_object['value'];
                }
                $final_price = max(0, $final_price);
                $discount_applied = true;
            }
        }
        
        updateUserBalance($user_id, $transaction['amount'], 'add');

        $purchase_result = $is_custom_config 
            ? completeCustomPurchase($user_id, $metadata['temp_plan_data'], $custom_name, $transaction['amount'])
            : completePurchase($user_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied);


        if ($purchase_result['success']) {
            sendPhoto($user_id, $purchase_result['qr_code_url'], $purchase_result['caption'], $purchase_result['keyboard']);
            sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
        } else {
             sendMessage($user_id, "❌ پرداخت شما موفق بود اما در ایجاد سرویس خطایی رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
        }

    } else { 
        updateUserBalance($transaction['user_id'], $transaction['amount'], 'add');
        $new_balance_data = getUserData($transaction['user_id']);

        $message = "✅ پرداخت شما به مبلغ " . number_format($transaction['amount']) . " تومان با موفقیت انجام و حساب شما شارژ شد.\n\n" .
                   "▫️ شماره پیگیری: `{$ref_id}`\n" .
                   "💰 موجودی جدید: " . number_format($new_balance_data['balance']) . " تومان";
        sendMessage($transaction['user_id'], $message);
    }
}

function processFailedPayment($transaction, $error_message) {
    pdo()->prepare("UPDATE transactions SET status = 'failed' WHERE id = ?")->execute([$transaction['id']]);
    sendMessage($transaction['user_id'], "❌ تراکنش شما ناموفق بود. " . $error_message);
}
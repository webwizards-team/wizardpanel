<?php

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
elseif (function_exists('litespeed_finish_request')) {
    litespeed_finish_request();
}


require_once __DIR__ . '/includes/config.php';

if (($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '') !== SECRET_TOKEN) {
    die;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';


$settings = getSettings();
define('USER_INLINE_KEYBOARD', ($settings['inline_keyboard'] ?? 'on') === 'on');





$apiRequest = false;
$oneTimeEdit = true;
$update = json_decode(file_get_contents('php://input'), true);

if (!$update) {
    die;
}


$chat_id = null;
$first_name = 'کاربر';
$text = '';
$temp_referrer_id = null;

if (isset($update['message']['text'])) {
    $text = trim($update['message']['text']);
    if (strpos($text, '/start ') === 0) {
        $ref_code = trim(str_replace('/start ', '', $text));
        if (is_numeric($ref_code)) {
            $temp_referrer_id = $ref_code;
        }
        $text = '/start';
    }
}

if (isset($update['callback_query'])) {
    $chat_id = $update['callback_query']['message']['chat']['id'];
    $first_name = $update['callback_query']['from']['first_name'];
} elseif (isset($update['message']['chat']['id'])) {
    $chat_id = $update['message']['chat']['id'];
    $first_name = $update['message']['from']['first_name'];
}

if ($chat_id) {
    $user_data = getUserData($chat_id, $first_name, $temp_referrer_id); 
    
    $isAnAdmin = isUserAdmin($chat_id);
    $user_state = $user_data['state'] ?? 'none';
    
    
    
    if ($settings['bot_status'] === 'off' && !$isAnAdmin) {
        sendMessage($chat_id, "🛠 ربات در حال حاضر در دست تعمیر است. لطفا بعدا مراجعه کنید.");
        die;
    }
    if (($user_data['status'] ?? 'active') === 'banned') {
        sendMessage($chat_id, "🚫 شما توسط ادمین از ربات مسدود شده‌اید.");
        die;
    }

    if (!$isAnAdmin && !checkJoinStatus($chat_id)) {
        $channel_id = str_replace('@', '', $settings['join_channel_id']);
        $message = "💡 کاربر گرامی برای استفاده از ربات ابتدا باید در کانال ما عضو شوید.";

        $keyboard = ['inline_keyboard' => [[['text' => ' عضویت در کانال 📢', 'url' => "https://t.me/{$channel_id}"]], [['text' => '✅ عضو شدم', 'callback_data' => 'check_join']]]];
        sendMessage($chat_id, $message, $keyboard);
        die;
    }
}

$cancelKeyboard = ['keyboard' => [[['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];




if (isset($update['callback_query'])) {
    $callback_id = $update['callback_query']['id'];
    $data = $update['callback_query']['data'];
    $message_id = $update['callback_query']['message']['message_id'];
    $from_id = $update['callback_query']['from']['id'];
    $first_name = $update['callback_query']['from']['first_name'];

    if ($data === 'check_join') {
        if (checkJoinStatus($chat_id)) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            deleteMessage($chat_id, $message_id);
            handleMainMenu($chat_id, $first_name, true);
        }
        else {
            apiRequest('answerCallbackQuery', [
                'callback_query_id' => $callback_id,
                'text' => '❌ شما هنوز در کانال عضو نشده‌اید!',
                'show_alert' => true,
            ]);
        }
        die;
    }

    if ($data === 'verify_by_button') {
        $stmt = pdo()->prepare("UPDATE users SET is_verified = 1 WHERE chat_id = ?");
        $stmt->execute([$chat_id]);

        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        editMessageText($chat_id, $message_id, "✅ هویت شما با موفقیت تایید شد. خوش آمدید!");
        handleMainMenu($chat_id, $first_name);
        die;
    }

    $is_verified = $user_data['is_verified'] ?? 0;
    $verification_method = $settings['verification_method'] ?? 'off';

    if ($verification_method !== 'off' && !$is_verified && !$isAnAdmin) {
        apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text' => 'برای استفاده از دکمه‌ها، ابتدا باید هویت خود را تایید کنید.',
            'show_alert' => true,
        ]);
        die;
    }
    
    
    if (strpos($data, 'charge_for_plan_') === 0) {
    $parts = explode('_', $data);
    $amount_to_charge = (int)$parts[3];
    $plan_id_to_buy = (int)$parts[4];
    $discount_code_to_use = (isset($parts[5]) && !empty($parts[5])) ? $parts[5] : null;
    $custom_name_encoded = $parts[6] ?? '';
    $custom_name = base64_decode($custom_name_encoded);
    $is_custom_config = (isset($parts[7]) && $parts[7] === 'custom');

    $description = $is_custom_config ? "تکمیل خرید کانفیگ دلخواه" : "تکمیل خرید پلن #{$plan_id_to_buy}";
    $metadata = [
        "purpose" => "complete_purchase",
        "plan_id" => $plan_id_to_buy,
        "user_id" => $chat_id,
        "custom_name" => $custom_name, 
        "is_custom_config" => $is_custom_config
    ];
    if ($discount_code_to_use) {
        $metadata["discount_code"] = $discount_code_to_use;
    }

    $zarinpal_result = createZarinpalLink($chat_id, $amount_to_charge, $description, $metadata);
    if ($zarinpal_result['success']) {
        $message = "⏳ در حال انتقال به درگاه پرداخت... لطفا صبر کنید.";
        $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $zarinpal_result['url']]]]];
        editMessageText($chat_id, $message_id, $message, $keyboard);
    } else {
        editMessageText($chat_id, $message_id, $zarinpal_result['error']);
    }
    die;
}

elseif (strpos($data, 'tetra_pay_for_plan_') === 0) {
        $parts = explode('_', $data);
        $amount_to_charge = (int)$parts[4];
        $plan_id_to_buy = (int)$parts[5];
        $discount_code_to_use = (isset($parts[6]) && !empty($parts[6])) ? $parts[6] : null;
        $custom_name_encoded = $parts[7] ?? '';
        $custom_name = base64_decode($custom_name_encoded);

        $description = "تکمیل خرید پلن #{$plan_id_to_buy}";
        $metadata = [
            "purpose" => "complete_purchase",
            "plan_id" => $plan_id_to_buy,
            "user_id" => $chat_id,
            "custom_name" => $custom_name,
        ];
        if ($discount_code_to_use) {
            $metadata["discount_code"] = $discount_code_to_use;
        }

        $tetra_result = createTetraLink($chat_id, $amount_to_charge, $description, $metadata);
        if ($tetra_result['success']) {
            $message = "⏳ در حال انتقال به درگاه پرداخت تترا... لطفا صبر کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $tetra_result['url']]]]];
            editMessageText($chat_id, $message_id, $message, $keyboard);
        } else {
            editMessageText($chat_id, $message_id, $tetra_result['error']);
        }
        die;
    }
    
elseif ($data === 'toggle_referral_status' && hasPermission($chat_id, 'manage_referrals')) {
    $settings = getSettings();
    $settings['referral_status'] = ($settings['referral_status'] ?? 'off') == 'on' ? 'off' : 'on';
    saveSettings($settings);
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
    showReferralManagementMenu($chat_id, $message_id);
}
elseif ($data === 'set_referrer_reward' && hasPermission($chat_id, 'manage_referrals')) {
    updateUserData($chat_id, 'admin_awaiting_referrer_reward');
    editMessageText($chat_id, $message_id, "لطفا مبلغ هدیه به **معرف** (کسی که دعوت می‌کند) را به تومان وارد کنید (0 برای غیرفعال کردن):");
}
elseif ($data === 'set_referred_reward' && hasPermission($chat_id, 'manage_referrals')) {
    updateUserData($chat_id, 'admin_awaiting_referred_reward');
    editMessageText($chat_id, $message_id, "لطفا مبلغ هدیه به **کاربر جدید** (دعوت شده) را به تومان وارد کنید (0 برای غیرفعال کردن):");
}
elseif ($data === 'toggle_commission_status' && hasPermission($chat_id, 'manage_referrals')) {
    $settings = getSettings();
    $settings['referral_commission_status'] = ($settings['referral_commission_status'] ?? 'off') == 'on' ? 'off' : 'on';
    saveSettings($settings);
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
    showReferralManagementMenu($chat_id, $message_id);
}
elseif ($data === 'set_commission_rate' && hasPermission($chat_id, 'manage_referrals')) {
    updateUserData($chat_id, 'admin_awaiting_commission_rate');
    editMessageText($chat_id, $message_id, "لطفا درصد کمیسیون از خرید زیرمجموعه را وارد کنید (فقط عدد بین 0 تا 100):");
}
elseif ($data === 'toggle_commission_first_only' && hasPermission($chat_id, 'manage_referrals')) {
    $settings = getSettings();
    $settings['referral_commission_first_only'] = ($settings['referral_commission_first_only'] ?? 'on') == 'on' ? 'off' : 'on';
    saveSettings($settings);
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
    showReferralManagementMenu($chat_id, $message_id);
}
    elseif (strpos($data, 'manual_pay_for_plan_') === 0) {
    $parts = explode('_', $data);
    $amount_to_charge = (int)$parts[4];
    $plan_id_to_buy = (int)$parts[5];
    $discount_code_to_use = (isset($parts[6]) && !empty($parts[6])) ? $parts[6] : null;
    $custom_name_encoded = $parts[7] ?? '';
    $custom_name = base64_decode($custom_name_encoded);
    $is_custom_config = (isset($parts[8]) && $parts[8] === 'custom');

    $state_data = [
        'charge_amount' => $amount_to_charge,
        'purpose' => 'complete_purchase',
        'plan_id' => $plan_id_to_buy,
        'custom_name' => $custom_name, 
        'is_custom_config' => $is_custom_config
    ];
    if ($discount_code_to_use) {
        $state_data['discount_code'] = $discount_code_to_use;
    }

    updateUserData($chat_id, 'awaiting_payment_screenshot', $state_data);

    $settings = getSettings();
    $payment_method = $settings['payment_method'];
    $card_number_display = ($payment_method['copy_enabled'] ?? false) ? "<code>{$payment_method['card_number']}</code>" : $payment_method['card_number'];
    $message = "برای تکمیل خرید به مبلغ <b>" . number_format($amount_to_charge) . " تومان</b>، لطفا مبلغ را به اطلاعات زیر واریز نمایید:\n\n" .
               "💳 شماره کارت:\n" . $card_number_display . "\n" .
               "👤 صاحب حساب: {$payment_method['card_holder']}\n\n" .
               "پس از واریز، لطفا از رسید پرداخت خود اسکرین‌شات گرفته و در همینجا ارسال کنید. پس از تایید، سرویس شما به صورت خودکار ایجاد خواهد شد.";
    editMessageText($chat_id, $message_id, $message);
    die;
}

    
    if ($isAnAdmin) {
        
    if (strpos($data, 'custom_cat_') === 0) {
        $categoryId = str_replace('custom_cat_', '', $data);
        showServersForCustomConfigCategory($chat_id, $categoryId);
        deleteMessage($chat_id, $message_id);
        die;
    }
    elseif ($data === 'manage_gateway_zarinpal') {
        $settings = getSettings();
        $status_icon = ($settings['payment_gateway_status'] ?? 'off') == 'on' ? '✅' : '❌';
        $merchant_id = $settings['zarinpal_merchant_id'] ?? '<i>تنظیم نشده</i>';
        
        $message = "<b>🔵 مدیریت درگاه پرداخت زرین‌پال</b>\n\n" .
                   "▫️ وضعیت کلی: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n" .
                   "▫️ مرچنت کد: <code>{$merchant_id}</code>";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => $status_icon . ' فعال/غیرفعال کردن', 'callback_data' => 'toggle_gateway_status']],
                [['text' => '✏️ تنظیم مرچنت کد', 'callback_data' => 'set_zarinpal_merchant_id']],
                [['text' => '◀️ بازگشت به انتخاب درگاه', 'callback_data' => 'back_to_gateway_menu']],
            ]
        ];
        editMessageText($chat_id, $message_id, $message, $keyboard);
    }
    elseif ($data === 'manage_gateway_tetra') {
        $settings = getSettings();
        $status_icon = ($settings['tetra_status'] ?? 'off') == 'on' ? '✅' : '❌';
        $api_key = $settings['tetra_api_key'] ?? '<i>تنظیم نشده</i>';
        
        $message = "<b>🟣 مدیریت درگاه پرداخت تترا 98</b>\n\n" .
                   "▫️ وضعیت کلی: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n" .
                   "▫️ کلید API: <code>{$api_key}</code>";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => $status_icon . ' فعال/غیرفعال کردن', 'callback_data' => 'toggle_tetra_status']],
                [['text' => '✏️ تنظیم ApiKey', 'callback_data' => 'set_tetra_api_key']],
                [['text' => '◀️ بازگشت به انتخاب درگاه', 'callback_data' => 'back_to_gateway_menu']],
            ]
        ];
        editMessageText($chat_id, $message_id, $message, $keyboard);
    }
    elseif ($data === 'back_to_gateway_menu') {
         $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔵 مدیریت زرین‌پال', 'callback_data' => 'manage_gateway_zarinpal']],
                [['text' => '🟣 مدیریت تترا 98', 'callback_data' => 'manage_gateway_tetra']],
                [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
            ]
        ];
        editMessageText($chat_id, $message_id, "<b>💳 مدیریت درگاه‌های پرداخت</b>\n\nلطفا درگاه مورد نظر برای مدیریت را انتخاب کنید:", $keyboard);
    }
    elseif ($data === 'toggle_tetra_status') {
        $settings = getSettings();
        $settings['tetra_status'] = ($settings['tetra_status'] ?? 'off') == 'on' ? 'off' : 'on';
        saveSettings($settings);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
        $data = 'manage_gateway_tetra'; 
    }
    elseif ($data === 'set_tetra_api_key') {
        updateUserData($chat_id, 'admin_awaiting_tetra_api_key');
        editMessageText($chat_id, $message_id, "لطفا ApiKey درگاه تترا 98 خود را وارد کنید:");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif ($data === 'toggle_custom_config_status' && $isAnAdmin && hasPermission($chat_id, 'manage_custom_config')) {
        $settings = getSettings();
        $settings['custom_config_status'] = ($settings['custom_config_status'] ?? 'off') == 'on' ? 'off' : 'on';
        saveSettings($settings);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
        showCustomConfigManagementMenu($chat_id, $message_id);
        die;
    }
    elseif ($data === 'set_custom_price_day' && $isAnAdmin && hasPermission($chat_id, 'manage_custom_config')) {
        updateUserData($chat_id, 'admin_awaiting_custom_price_day');
        editMessageText($chat_id, $message_id, "لطفا هزینه کانفیگ دلخواه به ازای هر **روز** را به تومان وارد کنید (فقط عدد):");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif ($data === 'set_custom_price_gb' && $isAnAdmin && hasPermission($chat_id, 'manage_custom_config')) {
        updateUserData($chat_id, 'admin_awaiting_custom_price_gb');
        editMessageText($chat_id, $message_id, "لطفا هزینه کانفیگ دلخواه به ازای هر **گیگابایت** را به تومان وارد کنید (فقط عدد):");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif ($data === 'set_custom_max_days' && $isAnAdmin && hasPermission($chat_id, 'manage_custom_config')) {
        updateUserData($chat_id, 'admin_awaiting_custom_max_days');
        editMessageText($chat_id, $message_id, "لطفا حداکثر تعداد روز قابل خرید را وارد کنید (0 برای نامحدود):");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif ($data === 'set_custom_max_gb' && $isAnAdmin && hasPermission($chat_id, 'manage_custom_config')) {
        updateUserData($chat_id, 'admin_awaiting_custom_max_gb');
        editMessageText($chat_id, $message_id, "لطفا حداکثر حجم قابل خرید (به گیگابایت) را وارد کنید (0 برای نامحدود):");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    
        
        if (strpos($data, 'add_balance_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('add_balance_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_amount_for_add_balance', ['target_user_id' => $target_id, 'admin_view' => 'admin']);
            sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی کاربر <code>$target_id</code> اضافه کنید را به تومان وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'show_user_services_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('show_user_services_', '', $data);
            $services = getUserServices($target_id);
            
            $target_user_info = getUserData($target_id);
            $target_user_name = htmlspecialchars($target_user_info['first_name'] ?? "کاربر $target_id");
            
            if (empty($services)) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "کاربر {$target_user_name} هیچ سرویسی ندارد.", 'show_alert' => true]);
            } else {
                $message_text = "<b>لیست سرویس‌های کاربر: {$target_user_name}</b>\n\n";
                $now = time();
                foreach ($services as $service) {
                    $expire_date = date('Y-m-d', $service['expire_timestamp']);
                    $status_icon = $service['expire_timestamp'] < $now ? '❌' : '✅';
                    $message_text .= "{$status_icon} <b>{$service['plan_name']}</b>\n";
                    $message_text .= "▫️ نام کاربری پنل: <code>{$service['marzban_username']}</code>\n";
                    $message_text .= "▫️ تاریخ انقضا: {$expire_date}\n---\n";
                }
                
                
                sendMessage($chat_id, $message_text);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            die;
        }
        elseif (strpos($data, 'deduct_balance_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('deduct_balance_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_amount_for_deduct_balance', ['target_user_id' => $target_id, 'admin_view' => 'admin']);
            sendMessage($chat_id, "لطفا مبلغی که می‌خواهید از موجودی کاربر <code>$target_id</code> کسر کنید را به تومان وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'message_user_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('message_user_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_message_for_user', ['target_user_id' => $target_id, 'admin_view' => 'admin']);
            sendMessage($chat_id, "پیام خود را برای ارسال به کاربر <code>$target_id</code> وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'ban_user_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('ban_user_', '', $data);
            if ($target_id == ADMIN_CHAT_ID) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ شما نمی‌توانید خودتان را مسدود کنید!', 'show_alert' => true]);
            } else {
                setUserStatus($target_id, 'banned');
                sendMessage($target_id, "شما توسط ادمین از ربات مسدود شده‌اید.");
                editMessageText($chat_id, $message_id, $update['callback_query']['message']['text'] . "\n\n---\n✅ کاربر با موفقیت مسدود شد.");
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'کاربر مسدود شد']);
            }
            die;
        }
        elseif (strpos($data, 'unban_user_') === 0 && hasPermission($chat_id, 'manage_users')) {
            $target_id = str_replace('unban_user_', '', $data);
            setUserStatus($target_id, 'active');
            sendMessage($target_id, "✅ شما توسط ادمین از حالت مسدودیت خارج شدید.");
            editMessageText($chat_id, $message_id, $update['callback_query']['message']['text'] . "\n\n---\n✅ کاربر با موفقیت آزاد شد.");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'کاربر آزاد شد']);
            die;
        }
        elseif ($data === 'search_another_user' && hasPermission($chat_id, 'manage_users')) {
            deleteMessage($chat_id, $message_id);
            updateUserData($chat_id, 'admin_awaiting_user_search', ['admin_view' => 'admin']);
            sendMessage($chat_id, "لطفاً شناسه عددی (Chat ID) کاربر بعدی را وارد کنید:", $cancelKeyboard);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        
        
        if (strpos($data, 'delete_cat_') === 0 && hasPermission($chat_id, 'manage_categories')) {
            $cat_id = str_replace('delete_cat_', '', $data);
            pdo()
                ->prepare("DELETE FROM categories WHERE id = ?")
                ->execute([$cat_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ حذف شد']);
            deleteMessage($chat_id, $message_id);
            generateCategoryList($chat_id);
        }
    elseif (strpos($data, 'charge_zarinpal_') === 0) {
    $amount = (int)str_replace('charge_zarinpal_', '', $data);
    $description = "شارژ حساب کاربری - " . $chat_id;
    $zarinpal_result = createZarinpalLink($chat_id, $amount, $description);

    if ($zarinpal_result['success']) {
        $message = "⏳ در حال انتقال به درگاه پرداخت زرین‌پال... لطفا صبر کنید.";
        $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $zarinpal_result['url']]]]];
        editMessageText($chat_id, $message_id, $message, $keyboard);
    } else {
        editMessageText($chat_id, $message_id, $zarinpal_result['error']);
    }
}
    elseif (strpos($data, 'charge_tetra_') === 0) {
    $amount = (int)str_replace('charge_tetra_', '', $data);
    $description = "شارژ حساب کاربری - " . $chat_id;
    $tetra_result = createTetraLink($chat_id, $amount, $description);

    if ($tetra_result['success']) {
        $message = "⏳ در حال انتقال به درگاه پرداخت تترا... لطفا صبر کنید.";
        $keyboard = ['inline_keyboard' => [[['text' => '🚀 ورود به صفحه پرداخت', 'url' => $tetra_result['url']]]]];
        editMessageText($chat_id, $message_id, $message, $keyboard);
    } else {
        editMessageText($chat_id, $message_id, $tetra_result['error']);
    }
}
        elseif ($data === 'toggle_gateway_status') {
            $settings = getSettings();
            $settings['payment_gateway_status'] = ($settings['payment_gateway_status'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            
        }
        elseif ($data === 'set_zarinpal_merchant_id') {
            updateUserData($chat_id, 'admin_awaiting_merchant_id');
            editMessageText($chat_id, $message_id, "لطفا مرچنت کد ۳۶ کاراکتری زرین‌پال خود را وارد کنید:");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif ($data === 'toggle_renewal_status') {
    $settings = getSettings();
    $settings['renewal_status'] = ($settings['renewal_status'] ?? 'off') == 'on' ? 'off' : 'on';
    saveSettings($settings);
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
    showRenewalManagementMenu($chat_id, $message_id);
}
        elseif ($data === 'set_renewal_price_day') {
            updateUserData($chat_id, 'admin_awaiting_renewal_price_day');
            editMessageText($chat_id, $message_id, "لطفا هزینه تمدید به ازای هر **روز** را به تومان وارد کنید (فقط عدد):");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif ($data === 'set_renewal_price_gb') {
            updateUserData($chat_id, 'admin_awaiting_renewal_price_gb');
            editMessageText($chat_id, $message_id, "لطفا هزینه تمدید به ازای هر **گیگابایت** را به تومان وارد کنید (فقط عدد):");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'toggle_cat_') === 0 && hasPermission($chat_id, 'manage_categories')) {
            $cat_id = str_replace('toggle_cat_', '', $data);
            pdo()
                ->prepare("UPDATE categories SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$cat_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد']);
            deleteMessage($chat_id, $message_id);
            generateCategoryList($chat_id);
        }
        elseif (strpos($data, 'delete_plan_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('delete_plan_', '', $data);
            pdo()
                ->prepare("DELETE FROM plans WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ پلن حذف شد']);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'toggle_plan_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('toggle_plan_', '', $data);
            pdo()
                ->prepare("UPDATE plans SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif ($data === 'back_to_plan_list' && hasPermission($chat_id, 'manage_plans')) {
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'open_plan_editor_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('open_plan_editor_', '', $data);
            showPlanEditor($chat_id, $message_id, $plan_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'edit_plan_field_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/edit_plan_field_(\d+)_(\w+)/', $data, $matches);
            $plan_id = $matches[1];
            $field = $matches[2];
            
            $field_map = [
                'name' => ['prompt' => '👇 لطفا نام جدید پلن را وارد کنید:', 'column' => 'name', 'validation' => 'text'],
                'price' => ['prompt' => '👇 لطفا قیمت جدید را به تومان وارد کنید (فقط عدد):', 'column' => 'price', 'validation' => 'numeric'],
                'volume_gb' => ['prompt' => '👇 لطفا حجم جدید را به گیگابایت وارد کنید (فقط عدد):', 'column' => 'volume_gb', 'validation' => 'numeric'],
                'duration_days' => ['prompt' => '👇 لطفا مدت زمان جدید را به روز وارد کنید (فقط عدد):', 'column' => 'duration_days', 'validation' => 'numeric'],
                'purchase_limit' => ['prompt' => '👇 لطفا محدودیت خرید جدید را وارد کنید (0 برای نامحدود):', 'column' => 'purchase_limit', 'validation' => 'numeric_zero'],
            ];

            if (array_key_exists($field, $field_map)) {
                $field_info = $field_map[$field];
                $state_data = [
                    'editing_plan_id' => $plan_id,
                    'editing_field_info' => $field_info,
                    'editor_message_id' => $message_id 
                ];
                updateUserData($chat_id, 'admin_awaiting_plan_edit_input', $state_data);
                showPlanEditor($chat_id, $message_id, $plan_id, $field_info['prompt']);
            }
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'back_to_plan_view_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'edit_plan_field_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/edit_plan_field_(\d+)_(\w+)/', $data, $matches);
            $plan_id = $matches[1];
            $field = $matches[2];

            $state_data = ['editing_plan_id' => $plan_id];

            switch ($field) {
                case 'name':
                    updateUserData($chat_id, 'admin_editing_plan_name', $state_data);
                    sendMessage($chat_id, "لطفا نام جدید پلن را وارد کنید:", $cancelKeyboard);
                    break;
                case 'price':
                    updateUserData($chat_id, 'admin_editing_plan_price', $state_data);
                    sendMessage($chat_id, "لطفا قیمت جدید را به تومان وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'volume':
                    updateUserData($chat_id, 'admin_editing_plan_volume', $state_data);
                    sendMessage($chat_id, "لطفا حجم جدید را به گیگابایت وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'duration':
                    updateUserData($chat_id, 'admin_editing_plan_duration', $state_data);
                    sendMessage($chat_id, "لطفا مدت زمان جدید را به روز وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'limit':
                    updateUserData($chat_id, 'admin_editing_plan_limit', $state_data);
                    sendMessage($chat_id, "لطفا محدودیت خرید جدید را وارد کنید (0 برای نامحدود):", $cancelKeyboard);
                    break;
                case 'category':
                    $categories = getCategories();
                    if (empty($categories)) {
                        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'هیچ دسته‌بندی برای انتخاب وجود ندارد!', 'show_alert' => true]);
                        break;
                    }
                    $keyboard_buttons = [];
                    foreach ($categories as $category) {
                        $keyboard_buttons[] = [['text' => $category['name'], 'callback_data' => "set_plan_category_{$plan_id}_{$category['id']}"]];
                    }
                    editMessageText($chat_id, $message_id, "دسته‌بندی جدید را برای این پلن انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
                    break;
                case 'server':
                    $servers = pdo()
                        ->query("SELECT id, name FROM servers")
                        ->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($servers)) {
                        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'هیچ سروری برای انتخاب وجود ندارد!', 'show_alert' => true]);
                        break;
                    }
                    $keyboard_buttons = [];
                    foreach ($servers as $server) {
                        $keyboard_buttons[] = [['text' => $server['name'], 'callback_data' => "set_plan_server_{$plan_id}_{$server['id']}"]];
                    }
                    editMessageText($chat_id, $message_id, "سرور جدید را برای این پلن انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
                    break;
            }
            if ($field !== 'category' && $field !== 'server') {
                deleteMessage($chat_id, $message_id);
            }
            
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            die;
        }
        elseif (strpos($data, 'set_plan_category_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/set_plan_category_(\d+)_(\d+)/', $data, $matches);
            $plan_id = $matches[1];
            $category_id = $matches[2];
            pdo()
                ->prepare("UPDATE plans SET category_id = ? WHERE id = ?")
                ->execute([$category_id, $plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ دسته‌بندی پلن با موفقیت تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'set_plan_server_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/set_plan_server_(\d+)_(\d+)/', $data, $matches);
            $plan_id = $matches[1];
            $server_id = $matches[2];
            pdo()
                ->prepare("UPDATE plans SET server_id = ? WHERE id = ?")
                ->execute([$server_id, $plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ سرور پلن با موفقیت تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'p_cat_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $category_id = str_replace('p_cat_', '', $data);
            $servers = pdo()
                ->query("SELECT id, name FROM servers WHERE status = 'active'")
                ->fetchAll(PDO::FETCH_ASSOC);
            if (empty($servers)) {
                editMessageText($chat_id, $message_id, "❌ ابتدا باید حداقل یک سرور در بخش «مدیریت سرورها» اضافه کنید.");
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                die;
            }
            $keyboard_buttons = [];
            foreach ($servers as $server) {
                $keyboard_buttons[] = [['text' => $server['name'], 'callback_data' => "p_server_{$server['id']}_cat_{$category_id}"]];
            }
            editMessageText($chat_id, $message_id, "این پلن روی کدام سرور ساخته شود؟", ['inline_keyboard' => $keyboard_buttons]);
        }
        elseif (strpos($data, 'p_server_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/p_server_(\d+)_cat_(\d+)/', $data, $matches);
            $server_id = $matches[1];
            $category_id = $matches[2];
            
            $stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
            $stmt->execute([$server_id]);
            $server_type = $stmt->fetchColumn();

            if ($server_type === 'sanaei') {
                $inbounds = getSanaeiInbounds($server_id);
                if (empty($inbounds)) {
                    editMessageText($chat_id, $message_id, "❌ هیچ اینباند فعالی روی این سرور 3x-ui یافت نشد. لطفا ابتدا یک اینباند در پنل خود بسازید.");
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                    die;
                }
                $keyboard_buttons = [];
                foreach ($inbounds as $inbound) {
                    $keyboard_buttons[] = [['text' => $inbound['remark'] . " (ID: {$inbound['id']})", 'callback_data' => "p_inbound_{$inbound['id']}_server_{$server_id}_cat_{$category_id}"]];
                }
                editMessageText($chat_id, $message_id, "این پلن به کدام اینباند اضافه شود؟", ['inline_keyboard' => $keyboard_buttons]);
            } elseif ($server_type === 'marzneshin') {
                $services = getMarzneshinServices($server_id);
                 if (empty($services)) {
                    editMessageText($chat_id, $message_id, "❌ هیچ سرویسی روی این سرور مرزنشین یافت نشد. لطفا ابتدا یک سرویس در پنل خود بسازید.");
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                    die;
                }
                $keyboard_buttons = [];
                foreach ($services as $service) {
                    $keyboard_buttons[] = [['text' => $service['name'] . " (ID: {$service['id']})", 'callback_data' => "p_service_{$service['id']}_server_{$server_id}_cat_{$category_id}"]];
                }
                editMessageText($chat_id, $message_id, "کاربران این پلن به کدام سرویس اضافه شوند؟", ['inline_keyboard' => $keyboard_buttons]);
            } else {
                $state_data = [
                    'new_plan_category_id' => $category_id,
                    'new_plan_server_id' => $server_id,
                ];
                updateUserData($chat_id, 'awaiting_plan_name', $state_data);
                sendMessage($chat_id, "1/6 - لطفا نام پلن را وارد کنید:", $cancelKeyboard);
                deleteMessage($chat_id, $message_id);
            }
        }
        elseif (strpos($data, 'p_inbound_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/p_inbound_(\d+)_server_(\d+)_cat_(\d+)/', $data, $matches);
            $inbound_id = $matches[1];
            $server_id = $matches[2];
            $category_id = $matches[3];

            $state_data = [
                'new_plan_category_id' => $category_id,
                'new_plan_server_id' => $server_id,
                'new_plan_inbound_id' => $inbound_id,
            ];
            updateUserData($chat_id, 'awaiting_plan_name', $state_data);
            sendMessage($chat_id, "1/6 - لطفا نام پلن را وارد کنید:", $cancelKeyboard);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'p_service_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            preg_match('/p_service_(\d+)_server_(\d+)_cat_(\d+)/', $data, $matches);
            $service_id = $matches[1];
            $server_id = $matches[2];
            $category_id = $matches[3];

            $state_data = [
                'new_plan_category_id' => $category_id,
                'new_plan_server_id' => $server_id,
                'new_plan_marzneshin_service_id' => $service_id,
            ];
            updateUserData($chat_id, 'awaiting_plan_name', $state_data);
            sendMessage($chat_id, "1/6 - لطفا نام پلن را وارد کنید:", $cancelKeyboard);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'copy_toggle_') === 0 && hasPermission($chat_id, 'manage_payment')) {
            $toggle = str_replace('copy_toggle_', '', $data) === 'yes';
            $settings = getSettings();
            $settings['payment_method'] = ['card_number' => $user_data['state_data']['temp_card_number'], 'card_holder' => $user_data['state_data']['temp_card_holder'], 'copy_enabled' => $toggle];
            saveSettings($settings);
            updateUserData($chat_id, 'main_menu');
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تنظیمات ذخیره شد']);
            editMessageText($chat_id, $message_id, "✅ تنظیمات روش پرداخت با موفقیت ذخیره شد.");
            handleMainMenu($chat_id, $first_name);
        }
        elseif (strpos($data, 'approve_') === 0 || strpos($data, 'reject_') === 0) {
            list($action, $request_id) = explode('_', $data);

            $stmt = pdo()->prepare("SELECT * FROM payment_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();

            if (!$request) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'خطا: درخواست یافت نشد.']);
                die;
            }

            if ($request['status'] !== 'pending') {
                $processed_admin_info = getUserData($request['processed_by_admin_id']);
                $processed_admin_name = htmlspecialchars($processed_admin_info['first_name'] ?? 'ادمین');
                $status_fa = $request['status'] == 'approved' ? 'تایید' : 'رد';

                apiRequest('answerCallbackQuery', [
                    'callback_query_id' => $callback_id,
                    'text' => "این درخواست قبلاً توسط {$processed_admin_name} {$status_fa} شده است.",
                    'show_alert' => true,
                ]);
                die;
            }

            $user_id_to_charge = $request['user_id'];
            $amount_to_charge = $request['amount'];
            $admin_who_processed = $update['callback_query']['from']['id'];
            $metadata = json_decode($request['metadata'], true);

            if ($action == 'approve') {
                pdo()->prepare("UPDATE payment_requests SET status = 'approved', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);

                if (isset($metadata['purpose']) && $metadata['purpose'] === 'complete_purchase') {
                    
                    $plan_id = $metadata['plan_id'];
                    $discount_code = $metadata['discount_code'] ?? null;
                    $is_custom_config = $metadata['is_custom_config'] ?? false;
                    $custom_name = $metadata['custom_name'] ?? 'سرویس';

                    if ($is_custom_config) {
                        
                        $temp_plan_data = pdo()->prepare("SELECT state_data FROM users WHERE chat_id = ?")->execute([$user_id_to_charge]) ? json_decode(pdo()->fetchColumn(), true)['temp_custom_plan'] : null;
                        
                        if (!$temp_plan_data) {
                            sendMessage($user_id_to_charge, "❌ خطایی در بازیابی اطلاعات کانفیگ دلخواه رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
                            updateUserBalance($user_id_to_charge, $amount_to_charge, 'add');
                            editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>❌ خطا در بازیابی اطلاعات کانفیگ دلخواه.</b>", null);
                            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا در بازیابی اطلاعات کانفیگ دلخواه.']);
                            die;
                        }
                        
                        $final_price = (float)$temp_plan_data['price'];
                        
                        
                        updateUserBalance($user_id_to_charge, $amount_to_charge, 'add');
                        
                        $purchase_result = completeCustomPurchase($user_id_to_charge, $temp_plan_data, $custom_name, $final_price);
                        
                    } else {
                        
                        $plan = getPlanById($plan_id);
                        $final_price = (float)$plan['price'];
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
                        
                        
                        updateUserBalance($user_id_to_charge, $amount_to_charge, 'add');

                        $purchase_result = completePurchase($user_id_to_charge, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied);
                    }


                    if ($purchase_result['success']) {
                        sendPhoto($user_id_to_charge, $purchase_result['qr_code_url'], $purchase_result['caption']);
                        sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
                        sendMessage($user_id_to_charge, "✅ پرداخت شما تایید و سرویس با موفقیت ایجاد شد.");
                    } else {
                         sendMessage($user_id_to_charge, "❌ پرداخت شما تایید شد اما در ایجاد سرویس خطایی رخ داد. مبلغ پرداخت شده به موجودی شما اضافه شد. لطفاً با پشتیبانی تماس بگیرید.");
                    }
                    updateUserData($user_id_to_charge, 'main_menu');

                } else {
                    
                    updateUserBalance($user_id_to_charge, $amount_to_charge, 'add');
                    $new_balance_data = getUserData($user_id_to_charge);
                    sendMessage($user_id_to_charge, "✅ حساب شما به مبلغ " . number_format($amount_to_charge) . " تومان شارژ شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان");
                }

                editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>✅ توسط شما تایید شد.</b>", null);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ درخواست تایید شد']);

            }
            elseif ($action == 'reject') {
                pdo()->prepare("UPDATE payment_requests SET status = 'rejected', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);

                sendMessage($user_id_to_charge, "❌ درخواست شارژ حساب شما به مبلغ " . number_format($amount_to_charge) . " تومان توسط ادمین رد شد.");

                editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>❌ توسط شما رد شد.</b>", null);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ درخواست رد شد']);
            }
        }
        elseif ($data === 'manage_servers' && hasPermission($chat_id, 'manage_marzban')) {
            $servers = pdo()
                ->query("SELECT id, name FROM servers")
                ->fetchAll(PDO::FETCH_ASSOC);
            $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
            foreach ($servers as $server) {
                $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
            }
            $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']];

            editMessageText($chat_id, $message_id, "<b>🌐 مدیریت سرورها</b>\n\nسرور مورد نظر را برای مشاهده یا حذف انتخاب کنید، یا یک سرور جدید اضافه کنید:", ['inline_keyboard' => $keyboard_buttons]);
        }
        elseif ($data === 'add_server_select_type' && hasPermission($chat_id, 'manage_marzban')) {
            $keyboard = ['inline_keyboard' => [
                [['text' => '🔵 مرزبان (Marzban)', 'callback_data' => 'add_server_type_marzban']],
                [['text' => '🟠 سنایی (3x-ui)', 'callback_data' => 'add_server_type_sanaei']],
                [['text' => '🟢 مرزنشین (Marzneshin)', 'callback_data' => 'add_server_type_marzneshin']],
                [['text' => '◀️ بازگشت', 'callback_data' => 'manage_servers']],
            ]];
            editMessageText($chat_id, $message_id, "لطفا نوع پنل سرور را انتخاب کنید:", $keyboard);
        }
        elseif (strpos($data, 'edit_protocols_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('edit_protocols_', '', $data);
            showMarzbanProtocolEditor($chat_id, $message_id, $server_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'toggle_protocol_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            preg_match('/toggle_protocol_(\d+)_(\w+)/', $data, $matches);
            $server_id = $matches[1];
            $protocol = $matches[2];
            
            $stmt_get = pdo()->prepare("SELECT marzban_protocols FROM servers WHERE id = ?");
            $stmt_get->execute([$server_id]);
            $protocols_json = $stmt_get->fetchColumn();
            
            $current_protocols = $protocols_json ? json_decode($protocols_json, true) : [];
            if (!is_array($current_protocols)) $current_protocols = [];

            if (in_array($protocol, $current_protocols)) {
                $current_protocols = array_diff($current_protocols, [$protocol]);
            } else {
                $current_protocols[] = $protocol;
            }
            
            $new_protocols_json = json_encode(array_values($current_protocols));
            $stmt_update = pdo()->prepare("UPDATE servers SET marzban_protocols = ? WHERE id = ?");
            $stmt_update->execute([$new_protocols_json, $server_id]);
            
            showMarzbanProtocolEditor($chat_id, $message_id, $server_id);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'add_server_type_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            deleteMessage($chat_id, $message_id);
            $type = str_replace('add_server_type_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_server_name', ['selected_server_type' => $type]);
            sendMessage($chat_id, "مرحله ۱/۴: یک نام دلخواه برای شناسایی سرور وارد کنید (مثال: آلمان-هتزنر):", $cancelKeyboard);
        }
                    elseif (strpos($data, 'view_server_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('view_server_', '', $data);
            $stmt = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$server_id]);
            $server = $stmt->fetch();
            if ($server) {
                $panel_type_text = ucfirst($server['type']);
                if ($server['type'] === 'sanaei') $panel_type_text = 'سنایی (3x-ui)';
                if ($server['type'] === 'marzneshin') $panel_type_text = 'مرزنشین';
                
                $msg = "<b>مشخصات سرور: {$server['name']}</b>\n\n";
                $msg .= "▫️ نوع پنل: <b>{$panel_type_text}</b>\n";
                $msg .= "▫️ آدرس مدیریت پنل: <code>{$server['url']}</code>\n";
                
                $keyboard_buttons = [];
                
                
                if ($server['type'] === 'sanaei' || $server['type'] === 'marzban') {
                    $sub_host_text = !empty($server['sub_host']) ? "<code>{$server['sub_host']}</code>" : "<i>پیش‌فرض (مانند آدرس پنل)</i>";
                    $msg .= "▫️ آدرس لینک اشتراک: {$sub_host_text}\n";
                    $keyboard_buttons[] = [['text' => '🔗 ویرایش آدرس ساب', 'callback_data' => "edit_sub_host_{$server_id}"]];
                }
                
                if ($server['type'] === 'marzban') {
                    $keyboard_buttons[] = [['text' => '⚙️ تنظیم پروتکل‌ها', 'callback_data' => "edit_protocols_{$server_id}"]];
                }
                
                $msg .= "▫️ نام کاربری: <code>{$server['username']}</code>\n";

                $keyboard_buttons[] = [['text' => '🗑 حذف این سرور', 'callback_data' => "delete_server_{$server_id}"]];
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به لیست سرورها', 'callback_data' => 'manage_servers']];
                
                $keyboard = ['inline_keyboard' => $keyboard_buttons];
                editMessageText($chat_id, $message_id, $msg, $keyboard);
            }
        }
        elseif (strpos($data, 'edit_sub_host_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('edit_sub_host_', '', $data);
            updateUserData($chat_id, 'admin_awaiting_sub_host', ['editing_server_id' => $server_id]);
            $prompt = "لطفا آدرس کامل و عمومی که برای لینک اشتراک استفاده می‌شود را وارد کنید.\nاین آدرس باید شامل http/https و پورت صحیح باشد (مثال: http://your.domain.com:2096).\n\n💡 برای بازگشت به حالت پیش‌فرض (استفاده از همان آدرس پنل)، کلمه `reset` را ارسال کنید.";
            editMessageText($chat_id, $message_id, $prompt);
        }
        elseif (strpos($data, 'delete_server_') === 0 && hasPermission($chat_id, 'manage_marzban')) {
            $server_id = str_replace('delete_server_', '', $data);
            $stmt_check = pdo()->prepare("SELECT COUNT(*) FROM plans WHERE server_id = ?");
            $stmt_check->execute([$server_id]);
            if ($stmt_check->fetchColumn() > 0) {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ نمی‌توانید این سرور را حذف کنید زیرا یک یا چند پلن به آن متصل هستند.', 'show_alert' => true]);
            }
            else {
                $stmt = pdo()->prepare("DELETE FROM servers WHERE id = ?");
                $stmt->execute([$server_id]);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ سرور با موفقیت حذف شد.']);
                $data = 'manage_servers'; 
            }
        }
        elseif (strpos($data, 'plan_set_sub_') === 0) {
            $show_sub = str_replace('plan_set_sub_', '', $data) === 'yes';
            $state_data = $user_data['state_data'];
            $state_data['temp_plan_data']['show_sub_link'] = $show_sub;
            updateUserData($chat_id, 'awaiting_plan_conf_link_setting', $state_data);
            $keyboard = ['inline_keyboard' => [[['text' => '✅ بله', 'callback_data' => 'plan_set_conf_yes'], ['text' => '❌ خیر', 'callback_data' => 'plan_set_conf_no']]]];
            editMessageText($chat_id, $message_id, "سوال ۲/۲: آیا لینک‌های تکی کانفیگ‌ها به کاربر نمایش داده شود؟\n(پیشنهادی: بله)", $keyboard);
        }
        elseif (strpos($data, 'plan_set_conf_') === 0) {
            $show_conf = str_replace('plan_set_conf_', '', $data) === 'yes';
            $final_plan_data = $user_data['state_data']['temp_plan_data'] ?? null;
            if ($final_plan_data) {
                $final_plan_data['show_conf_links'] = $show_conf;
                $stmt = pdo()->prepare(
                    "INSERT INTO plans (server_id, inbound_id, marzneshin_service_id, category_id, name, price, volume_gb, duration_days, description, show_sub_link, show_conf_links, status, purchase_limit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
                );
                $stmt->execute([
                    $final_plan_data['server_id'],
                    $final_plan_data['inbound_id'] ?? null,
                    $final_plan_data['marzneshin_service_id'] ?? null,
                    $final_plan_data['category_id'],
                    $final_plan_data['name'],
                    $final_plan_data['price'],
                    $final_plan_data['volume_gb'],
                    $final_plan_data['duration_days'],
                    $final_plan_data['description'],
                    $final_plan_data['show_sub_link'],
                    $final_plan_data['show_conf_links'],
                    $final_plan_data['purchase_limit'],
                ]);
                editMessageText($chat_id, $message_id, "✅ پلن جدید با تمام تنظیمات با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
            }
            else {
                editMessageText($chat_id, $message_id, "❌ خطا در ذخیره‌سازی پلن. لطفا مجددا تلاش کنید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
            }
        }
        elseif (strpos($data, 'discount_type_') === 0) {
            $type = str_replace('discount_type_', '', $data);
            $state_data = $user_data['state_data'];
            $state_data['new_discount_type'] = $type;
            updateUserData($chat_id, 'admin_awaiting_discount_value', $state_data);
            $unit = $type == 'percent' ? 'درصد' : 'تومان';
            editMessageText($chat_id, $message_id, "3/4 - لطفاً مقدار تخفیف را به $unit وارد کنید (فقط عدد):");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }
        elseif (strpos($data, 'delete_discount_') === 0) {
            $code_id = str_replace('delete_discount_', '', $data);
            pdo()
                ->prepare("DELETE FROM discount_codes WHERE id = ?")
                ->execute([$code_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ کد تخفیف حذف شد.']);
            deleteMessage($chat_id, $message_id);
        }
        elseif (strpos($data, 'toggle_discount_') === 0) {
            $code_id = str_replace('toggle_discount_', '', $data);
            pdo()
                ->prepare("UPDATE discount_codes SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$code_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت کد تخفیف تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generateDiscountCodeList($chat_id);
        }
        elseif (strpos($data, 'delete_guide_') === 0 && hasPermission($chat_id, 'manage_guides')) {
            $guide_id = str_replace('delete_guide_', '', $data);
            pdo()
                ->prepare("DELETE FROM guides WHERE id = ?")
                ->execute([$guide_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ راهنما حذف شد.']);
            deleteMessage($chat_id, $message_id);
            generateGuideList($chat_id);
        }
        elseif (strpos($data, 'toggle_guide_') === 0 && hasPermission($chat_id, 'manage_guides')) {
            $guide_id = str_replace('toggle_guide_', '', $data);
            pdo()
                ->prepare("UPDATE guides SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")
                ->execute([$guide_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت راهنما تغییر کرد.']);
            deleteMessage($chat_id, $message_id);
            generateGuideList($chat_id);
        }
        elseif (strpos($data, 'reset_plan_count_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('reset_plan_count_', '', $data);
            pdo()
                ->prepare("UPDATE plans SET purchase_count = 0 WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تعداد خرید با موفقیت ریست شد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }

        if (strpos($data, 'set_as_test_plan_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('set_as_test_plan_', '', $data);
            pdo()->exec("UPDATE plans SET is_test_plan = 0");
            pdo()
                ->prepare("UPDATE plans SET is_test_plan = 1, price = 0, status = 'active' WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ این پلن به عنوان پلن تست تنظیم شد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }
        elseif (strpos($data, 'make_plan_normal_') === 0 && hasPermission($chat_id, 'manage_plans')) {
            $plan_id = str_replace('make_plan_normal_', '', $data);
            pdo()
                ->prepare("UPDATE plans SET is_test_plan = 0 WHERE id = ?")
                ->execute([$plan_id]);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ این پلن به یک پلن عادی تبدیل شد.']);
            deleteMessage($chat_id, $message_id);
            generatePlanList($chat_id);
        }

        if (strpos($data, 'admin_notifications_soon') === 0) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'این بخش به زودی فعال خواهد شد.', 'show_alert' => true]);
        }
        elseif (($data == 'user_notifications_menu' || $data == 'config_expire_warning' || $data == 'config_inactive_reminder') && hasPermission($chat_id, 'manage_notifications')) {
            $settings = getSettings();
            $expire_status_icon = ($settings['notification_expire_status'] ?? 'off') == 'on' ? '✅' : '❌';
            $inactive_status_icon = ($settings['notification_inactive_status'] ?? 'off') == 'on' ? '✅' : '❌';

            if ($data == 'user_notifications_menu') {
                $message =
                    "<b>📢 مدیریت اعلان‌های کاربران</b>\n\n" .
                    "<b>- هشدار انقضا:</b> " .
                    ($expire_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "\n" .
                    "<b>- یادآور عدم فعالیت:</b> " .
                    ($inactive_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "\n\n" .
                    "گزینه مورد نظر را برای مدیریت انتخاب کنید:";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '⚙️ تنظیمات هشدار انقضا', 'callback_data' => 'config_expire_warning']],
                        [['text' => '⚙️ تنظیمات یادآور عدم فعالیت', 'callback_data' => 'config_inactive_reminder']],
                        [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']],
                    ],
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
            elseif ($data == 'config_expire_warning') {
                $message =
                    "<b>⚙️ تنظیمات هشدار انقضا</b>\n\nاین پیام زمانی برای کاربر ارسال می‌شود که حجم یا زمان سرویس او رو به اتمام باشد.\n\n" .
                    "▫️وضعیت: <b>" .
                    ($expire_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "</b>\n" .
                    "▫️ارسال هشدار <b>{$settings['notification_expire_days']}</b> روز مانده به انقضا\n" .
                    "▫️ارسال هشدار وقتی حجم کمتر از <b>{$settings['notification_expire_gb']}</b> گیگابایت باشد";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => $expire_status_icon . " فعال/غیرفعال کردن", 'callback_data' => 'toggle_expire_notification']],
                        [['text' => '⏰ تنظیم روز', 'callback_data' => 'set_expire_days'], ['text' => '📊 تنظیم حجم', 'callback_data' => 'set_expire_gb']],
                        [['text' => '✍️ ویرایش متن پیام', 'callback_data' => 'edit_expire_message']],
                        [['text' => '◀️ بازگشت', 'callback_data' => 'user_notifications_menu']],
                    ],
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
            elseif ($data == 'config_inactive_reminder') {
                $message =
                    "<b>⚙️ تنظیمات یادآور عدم فعالیت</b>\n\nاین پیام زمانی برای کاربر ارسال می‌شود که برای مدت طولانی از ربات استفاده نکرده باشد.\n\n" .
                    "▫️وضعیت: <b>" .
                    ($inactive_status_icon == '✅' ? 'فعال' : 'غیرفعال') .
                    "</b>\n" .
                    "▫️ارسال یادآور پس از <b>{$settings['notification_inactive_days']}</b> روز عدم فعالیت";
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => $inactive_status_icon . " فعال/غیرفعال کردن", 'callback_data' => 'toggle_inactive_notification']],
                        [['text' => '⏰ تنظیم روز', 'callback_data' => 'set_inactive_days']],
                        [['text' => '✍️ ویرایش متن پیام', 'callback_data' => 'edit_inactive_message']],
                        [['text' => '◀️ بازگشت', 'callback_data' => 'user_notifications_menu']],
                    ],
                ];
                editMessageText($chat_id, $message_id, $message, $keyboard);
            }
        }
        elseif (strpos($data, 'toggle_expire_notification') === 0 && hasPermission($chat_id, 'manage_notifications')) {
            $settings = getSettings();
            $settings['notification_expire_status'] = ($settings['notification_expire_status'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            $data = 'config_expire_warning';
        }
        elseif (strpos($data, 'toggle_inactive_notification') === 0 && hasPermission($chat_id, 'manage_notifications')) {
            $settings = getSettings();
            $settings['notification_inactive_status'] = ($settings['notification_inactive_status'] ?? 'off') == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ وضعیت تغییر کرد.']);
            $data = 'config_inactive_reminder';
        }
        elseif (in_array($data, ['set_expire_days', 'set_expire_gb', 'edit_expire_message', 'set_inactive_days', 'edit_inactive_message']) && hasPermission($chat_id, 'manage_notifications')) {
            deleteMessage($chat_id, $message_id);
            switch ($data) {
                case 'set_expire_days':
                    updateUserData($chat_id, 'admin_awaiting_expire_days');
                    sendMessage($chat_id, "لطفا تعداد روز مانده به انقضا برای ارسال هشدار را وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'set_expire_gb':
                    updateUserData($chat_id, 'admin_awaiting_expire_gb');
                    sendMessage($chat_id, "لطفا حجم باقیمانده (به گیگابایت) برای ارسال هشدار را وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'edit_expire_message':
                    updateUserData($chat_id, 'admin_awaiting_expire_message');
                    sendMessage($chat_id, "لطفا متن کامل پیام هشدار انقضا را وارد کنید:", $cancelKeyboard);
                    break;
                case 'set_inactive_days':
                    updateUserData($chat_id, 'admin_awaiting_inactive_days');
                    sendMessage($chat_id, "لطفا تعداد روز عدم فعالیت برای ارسال یادآور را وارد کنید (فقط عدد):", $cancelKeyboard);
                    break;
                case 'edit_inactive_message':
                    updateUserData($chat_id, 'admin_awaiting_inactive_message');
                    sendMessage($chat_id, "لطفا متن کامل پیام یادآور عدم فعالیت را وارد کنید:", $cancelKeyboard);
                    break;
            }
        }
        if (
            in_array($user_state, ['admin_awaiting_expire_days', 'admin_awaiting_expire_gb', 'admin_awaiting_expire_message', 'admin_awaiting_inactive_days', 'admin_awaiting_inactive_message']) ||
            in_array($data, ['toggle_expire_notification', 'toggle_inactive_notification', 'manage_servers'])
        ) {
            if ($data === 'manage_servers') {
                $servers = pdo()
                    ->query("SELECT id, name FROM servers")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
                foreach ($servers as $server) {
                    $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']];
                editMessageText($chat_id, $message_id, "<b>🌐 مدیریت سرورها</b>\n\nسرور مورد نظر را برای مشاهده یا حذف انتخاب کنید، یا یک سرور جدید اضافه کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            else {
                $menu_to_refresh = strpos($data, 'inactive') !== false || strpos($user_state, 'inactive') !== false ? 'config_inactive_reminder' : 'config_expire_warning';
                $message_id = sendMessage($chat_id, "درحال بارگذاری مجدد منو...")['result']['message_id'];
                $data = $menu_to_refresh;
            }
        }

        if (strpos($data, 'set_verification_') === 0 && hasPermission($chat_id, 'manage_verification')) {
            $method = str_replace('set_verification_', '', $data);
            $settings = getSettings();
            $settings['verification_method'] = $method;
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ روش احراز هویت تغییر کرد.']);
            showVerificationManagementMenu($chat_id);
            die;
        }
        if ($data == 'toggle_verification_iran_only' && hasPermission($chat_id, 'manage_verification')) {
            $settings = getSettings();
            $settings['verification_iran_only'] = $settings['verification_iran_only'] == 'on' ? 'off' : 'on';
            saveSettings($settings);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تنظیمات ذخیره شد.']);
            showVerificationManagementMenu($chat_id);
            die;
        }

        if ($chat_id == ADMIN_CHAT_ID) {
            if ($data == 'add_admin') {
                $admins = getAdmins();
                if (count($admins) >= 9) {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ حداکثر تعداد ادمین‌ها (۱۰) ثبت شده است.', 'show_alert' => true]);
                }
                else {
                    updateUserData($chat_id, 'admin_awaiting_new_admin_id');
                    editMessageText($chat_id, $message_id, "لطفا شناسه عددی (Chat ID) کاربر مورد نظر را برای افزودن به لیست ادمین‌ها وارد کنید:");
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                }
            }
            elseif (strpos($data, 'edit_admin_permissions_') === 0) {
                $target_admin_id = str_replace('edit_admin_permissions_', '', $data);
                showPermissionEditor($chat_id, $message_id, $target_admin_id);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            elseif (strpos($data, 'toggle_perm_') === 0) {
                $payload = substr($data, strlen('toggle_perm_'));
                $parts = explode('_', $payload, 2);
                if (count($parts) === 2) {
                    $target_admin_id = $parts[0];
                    $permission_key = $parts[1];
                    $admins = getAdmins();
                    if (isset($admins[$target_admin_id])) {
                        $current_permissions = $admins[$target_admin_id]['permissions'] ?? [];
                        if (($key = array_search($permission_key, $current_permissions)) !== false) {
                            unset($current_permissions[$key]);
                        }
                        else {
                            $current_permissions[] = $permission_key;
                        }
                        updateAdminPermissions($target_admin_id, array_values($current_permissions));
                        showPermissionEditor($chat_id, $message_id, $target_admin_id);
                    }
                }
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            elseif (strpos($data, 'delete_admin_confirm_') === 0) {
                $target_admin_id = str_replace('delete_admin_confirm_', '', $data);
                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، حذف کن', 'callback_data' => "delete_admin_do_{$target_admin_id}"]], [['text' => '❌ انصراف', 'callback_data' => "edit_admin_permissions_{$target_admin_id}"]]]];
                editMessageText($chat_id, $message_id, "⚠️ آیا از حذف این ادمین مطمئن هستید؟", $keyboard);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
            }
            elseif (strpos($data, 'delete_admin_do_') === 0) {
                $target_admin_id = str_replace('delete_admin_do_', '', $data);
                $result = removeAdmin($target_admin_id);
                if ($result) {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ ادمین با موفقیت حذف شد.']);
                    $admins = getAdmins();
                    $message = "<b>👨‍💼 مدیریت ادمین‌ها</b>\n\nادمین مورد نظر حذف شد. لیست جدید ادمین‌ها:";
                    $keyboard_buttons = [];
                    if (count($admins) < 9) {
                        $keyboard_buttons[] = [['text' => '➕ افزودن ادمین جدید', 'callback_data' => 'add_admin']];
                    }
                    foreach ($admins as $admin_id => $admin_data) {
                        $admin_name = htmlspecialchars($admin_data['first_name'] ?? "ادمین $admin_id");
                        $keyboard_buttons[] = [['text' => "👤 {$admin_name}", 'callback_data' => "edit_admin_permissions_{$admin_id}"]];
                    }
                    $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']];
                    editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
                }
                else {
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا در حذف ادمین.', 'show_alert' => true]);
                }
            }
            elseif ($data == 'back_to_admin_list') {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                $admins = getAdmins();
                $message = "<b>👨‍💼 مدیریت ادمین‌ها</b>\n\nدر این بخش می‌توانید ادمین‌های ربات و دسترسی‌های آن‌ها را مدیریت کنید. (حداکثر ۱۰ ادمین)";
                $keyboard_buttons = [];
                if (count($admins) < 9) {
                    $keyboard_buttons[] = [['text' => '➕ افزودن ادمین جدید', 'callback_data' => 'add_admin']];
                }
                foreach ($admins as $admin_id => $admin_data) {
                    $admin_name = htmlspecialchars($admin_data['first_name'] ?? "ادمین $admin_id");
                    $keyboard_buttons[] = [['text' => "👤 {$admin_name}", 'callback_data' => "edit_admin_permissions_{$admin_id}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']];
                editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
            }
            elseif ($data == 'back_to_admin_panel') {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
                deleteMessage($chat_id, $message_id);
                handleMainMenu($chat_id, $first_name);
            }
        }
    }

    
    if (strpos($data, 'reply_ticket_') === 0) {
        if ($isAnAdmin && !hasPermission($chat_id, 'view_tickets')) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم برای پاسخ به تیکت‌ها را ندارید.', 'show_alert' => true]);
            die;
        }
        $ticket_id = str_replace('reply_ticket_', '', $data);
        $stmt = pdo()->prepare("SELECT status FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $ticket_status = $stmt->fetchColumn();
        if (!$ticket_status || $ticket_status == 'closed') {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'این تیکت بسته شده است.', 'show_alert' => true]);
        }
        else {
            if ($isAnAdmin) {
                updateUserData($chat_id, 'admin_replying_to_ticket', ['replying_to_ticket' => $ticket_id]);
                sendMessage($chat_id, "لطفا پاسخ خود را برای تیکت <code>$ticket_id</code> وارد کنید:", $cancelKeyboard);
            }
            else {
                updateUserData($chat_id, 'user_replying_to_ticket', ['replying_to_ticket' => $ticket_id]);
                sendMessage($chat_id, "لطفا پاسخ خود را برای تیکت <code>$ticket_id</code> وارد کنید:", $cancelKeyboard);
            }
        }
    }
    elseif (strpos($data, 'approve_renewal_') === 0 || strpos($data, 'reject_renewal_') === 0) {
            list($action, $type, $request_id) = explode('_', $data);

            $stmt = pdo()->prepare("SELECT * FROM renewal_requests WHERE id = ?");
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();

            if (!$request || $request['status'] !== 'pending') {
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'این درخواست قبلا پردازش شده است.', 'show_alert' => true]);
                die;
            }
            
            $admin_who_processed = $update['callback_query']['from']['id'];

            if ($action == 'approve') {
                $result = applyRenewal($request['user_id'], $request['service_username'], $request['days_to_add'], $request['gb_to_add']);
                if ($result['success']) {
                    pdo()->prepare("UPDATE renewal_requests SET status = 'approved', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);
                    sendMessage($request['user_id'], "✅ درخواست تمدید شما برای سرویس `{$request['service_username']}` تایید و با موفقیت اعمال شد.");
                    editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>✅ توسط شما تایید شد.</b>", null);
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تمدید تایید شد.']);
                } else {
                    sendMessage($chat_id, "❌ خطا در اعمال تمدید: " . $result['message']);
                    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'خطا در اعمال تمدید.', 'show_alert' => true]);
                }
            } elseif ($action == 'reject') {
                pdo()->prepare("UPDATE renewal_requests SET status = 'rejected', processed_by_admin_id = ?, processed_at = NOW() WHERE id = ?")->execute([$admin_who_processed, $request_id]);
                sendMessage($request['user_id'], "❌ درخواست تمدید شما برای سرویس `{$request['service_username']}` توسط ادمین رد شد.");
                editMessageCaption($chat_id, $message_id, $update['callback_query']['message']['caption'] . "\n\n<b>❌ توسط شما رد شد.</b>", null);
                apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ درخواست رد شد.']);
            }
        }
    elseif (strpos($data, 'close_ticket_') === 0) {
        if ($isAnAdmin && !hasPermission($chat_id, 'view_tickets')) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'شما دسترسی لازم برای بستن تیکت‌ها را ندارید.', 'show_alert' => true]);
            die;
        }
        $ticket_id = str_replace('close_ticket_', '', $data);
        $stmt = pdo()->prepare("SELECT user_id, user_name FROM tickets WHERE id = ?");
        $stmt->execute([$ticket_id]);
        $ticket_data = $stmt->fetch();
        if ($ticket_data) {
            $stmt_close = pdo()->prepare("UPDATE tickets SET status = 'closed' WHERE id = ?");
            $stmt_close->execute([$ticket_id]);
            $closer_name = $isAnAdmin ? 'ادمین' : $ticket_data['user_name'];
            $message = "✅ تیکت <code>$ticket_id</code> توسط <b>$closer_name</b> بسته شد.";
            sendMessage($ticket_data['user_id'], $message);
            $all_admins = getAdmins();
            foreach ($all_admins as $admin_id => $admin_data) {
                if ($admin_id != $chat_id && hasPermission($admin_id, 'view_tickets')) {
                    sendMessage($admin_id, $message);
                }
            }
            editMessageText($chat_id, $message_id, $update['callback_query']['message']['text'] . "\n\n<b>-- ➖ این تیکت بسته شد ➖ --</b>", null);
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'تیکت با موفقیت بسته شد.']);
        }
        else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'خطا: تیکت یافت نشد.', 'show_alert' => true]);
        }
    }

    
    elseif (strpos($data, 'get_configs_') === 0) {
        $username = str_replace('get_configs_', '', $data);
        
        $stmt_service = pdo()->prepare("SELECT server_id FROM services WHERE owner_chat_id = ? AND marzban_username = ?");
        $stmt_service->execute([$chat_id, $username]);
        $server_id = $stmt_service->fetchColumn();

        if (!$server_id) {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ سرویس یافت نشد.', 'show_alert' => true]);
            die;
        }

        $panel_user = getPanelUser($username, $server_id);
        
        if ($panel_user && !empty($panel_user['links'])) {
            
            $all_links_text = implode("\n\n", $panel_user['links']);
            sendMessage($chat_id, "<b>تمام کانفیگ‌های شما (برای کپی آسان):</b>\n\nبا کلیک روی متن زیر، تمام لینک‌ها به صورت خودکار کپی می‌شوند.\n\n<code>" . htmlspecialchars($all_links_text) . "</code>");
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '✅ تمام کانفیگ‌ها برای شما ارسال شد!']);

        } else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ هیچ لینک کانفیگی برای این سرویس یافت نشد.', 'show_alert' => true]);
        }
        die;
    }
    elseif (strpos($data, 'show_guide_') === 0) {
        $guide_id = str_replace('show_guide_', '', $data);
        $stmt = pdo()->prepare("SELECT * FROM guides WHERE id = ? AND status = 'active'");
        $stmt->execute([$guide_id]);
        $guide = $stmt->fetch();
        if ($guide) {
            deleteMessage($chat_id, $message_id);
            $keyboard = null;
            if (!empty($guide['inline_keyboard'])) {
                $keyboard = json_decode($guide['inline_keyboard'], true);
            }
            if ($guide['content_type'] === 'photo' && !empty($guide['photo_id'])) {
                sendPhoto($chat_id, $guide['photo_id'], $guide['message_text'], $keyboard);
            }
            else {
                sendMessage($chat_id, $guide['message_text'], $keyboard);
            }
        }
        else {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ این راهنما یافت نشد یا غیرفعال شده است.', 'show_alert' => true]);
        }
    }
    elseif (strpos($data, 'charge_manual_') === 0) {
        $amount = (int)str_replace('charge_manual_', '', $data);
        $settings = getSettings();
        $payment_method = $settings['payment_method'] ?? [];
        $card_number = $payment_method['card_number'] ?? '';
        $card_holder = $payment_method['card_holder'] ?? '';
        $copy_enabled = $payment_method['copy_enabled'] ?? false;

        if (empty($card_number)) {
             editMessageText($chat_id, $message_id, "❌ روش پرداخت دستی توسط ادمین تنظیم نشده است.");
             apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
             die;
        }

        $card_number_display = $copy_enabled ? "<code>{$card_number}</code>" : $card_number;
        $message = "برای شارژ حساب به مبلغ <b>" . number_format($amount) . " تومان</b>، لطفا مبلغ را به اطلاعات زیر واریز نمایید:\n\n" .
                   "💳 شماره کارت:\n" . $card_number_display . "\n" .
                   "👤 صاحب حساب: {$card_holder}\n\n" .
                   "پس از واریز، لطفا از رسید پرداخت خود اسکرین‌شات گرفته و در همینجا ارسال کنید.";
        editMessageText($chat_id, $message_id, $message);
        updateUserData($chat_id, 'awaiting_payment_screenshot', ['charge_amount' => $amount]);
    }
    elseif (strpos($data, 'cat_') === 0) {
        $categoryId = str_replace('cat_', '', $data);
        showServersForCategory($chat_id, $categoryId);
        deleteMessage($chat_id, $message_id);
    }
    elseif (strpos($data, 'show_plans_cat_') === 0) {
        preg_match('/show_plans_cat_(\d+)_srv_(\d+)/', $data, $matches);
        $category_id = $matches[1];
        $server_id = $matches[2];
        showPlansForCategoryAndServer($chat_id, $category_id, $server_id);
        deleteMessage($chat_id, $message_id);
    }
    elseif (strpos($data, 'apply_discount_code_') === 0) {
        $parts = explode('_', $data);
        $category_id = $parts[3];
        $server_id = $parts[4]; 
        updateUserData($chat_id, 'user_awaiting_discount_code', [
            'target_category_id' => $category_id,
            'target_server_id' => $server_id 
        ]);
        editMessageText($chat_id, $message_id, "🎁 لطفاً کد تخفیف خود را وارد کنید:");
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif (strpos($data, 'buy_plan_') === 0) {
    $parts = explode('_', $data);
    $plan_id = $parts[2];
    $discount_code = null;
    if (isset($parts[5]) && $parts[3] == 'with' && $parts[4] == 'code') {
        $discount_code = strtoupper($parts[5]);
    }
    
    $plan = getPlanById($plan_id);
    if (!$plan) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ خطا: پلن یافت نشد.']);
        die;
    }

    if ($plan['purchase_limit'] > 0 && $plan['purchase_count'] >= $plan['purchase_limit']) {
        apiRequest('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'text' => '❌ متاسفانه ظرفیت خرید این پلن به اتمام رسیده است.',
            'show_alert' => true,
        ]);
        die;
    }

    
    $state_data = [
        'purchasing_plan_id' => $plan_id,
        'discount_code' => $discount_code
    ];
    updateUserData($chat_id, 'awaiting_service_name', $state_data);
    
    $message = "✅ پلن انتخاب شد.\n\nلطفاً یک نام دلخواه برای این سرویس وارد کنید (مثلاً: سرویس شخصی). این نام در لیست سرویس‌های شما نمایش داده خواهد شد.";
    
  
    deleteMessage($chat_id, $message_id);
    sendMessage($chat_id, $message, $cancelKeyboard);
    apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    die;
}
    elseif ($data == 'back_to_custom_categories') {
        deleteMessage($chat_id, $message_id);
        handleCustomConfigStart($chat_id);
    }
    elseif (strpos($data, 'select_custom_server_cat_') === 0) {
        preg_match('/select_custom_server_cat_(\d+)_srv_(\d+)/', $data, $matches);
        $category_id = $matches[1];
        $server_id = $matches[2];
        
        $state_data = [
            'custom_config_category_id' => $category_id,
            'custom_config_server_id' => $server_id,
        ];
        updateUserData($chat_id, 'awaiting_custom_config_name', $state_data);
        
        $message = "✅ سرور انتخاب شد.\n\nلطفاً یک نام دلخواه برای این کانفیگ وارد کنید (مثلاً: کانفیگ دلخواه من).";
        
        deleteMessage($chat_id, $message_id);
        sendMessage($chat_id, $message, $cancelKeyboard);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif (strpos($data, 'confirm_custom_purchase_') === 0) {
        $state_data = $user_data['state_data'];
        $total_cost = $state_data['temp_custom_plan']['price'];
        $custom_name = $state_data['temp_custom_plan']['name'];
        $encoded_name = base64_encode($custom_name);
        
        if ($user_data['balance'] >= $total_cost) {
            
            editMessageText($chat_id, $message_id, "⏳ در حال ایجاد کانفیگ دلخواه با استفاده از موجودی شما...");
            
            $purchase_result = completeCustomPurchase($chat_id, $state_data['temp_custom_plan'], $custom_name, $total_cost);
            
            if ($purchase_result['success']) {
                $new_balance = number_format($user_data['balance'] - $total_cost);
                $success_msg = "✅ کانفیگ دلخواه شما با موفقیت ایجاد شد.\n\n" .
                               "💰 مبلغ " . number_format($total_cost) . " تومان از حساب شما کسر گردید.\n" .
                               "موجودی جدید: {$new_balance} تومان.";
                sendPhoto($chat_id, $purchase_result['qr_code_url'], $purchase_result['caption'], $purchase_result['keyboard']);
                sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
                deleteMessage($chat_id, $message_id);
            } else {
                editMessageText($chat_id, $message_id, "❌ خطایی در ایجاد سرویس رخ داد: " . $purchase_result['error_message']);
            }
            updateUserData($chat_id, 'main_menu');
            handleMainMenu($chat_id, $first_name);
        } else {
            
            $needed_amount = $total_cost - $user_data['balance'];
            $settings = getSettings();
            
            $keyboard_buttons = [];
            if (($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id'])) {
                $callback_data_online = "charge_for_plan_{$needed_amount}_0_{$encoded_name}_custom";
                $keyboard_buttons[] = [['text' => '🌐 پرداخت آنلاین (زرین‌پال)', 'callback_data' => $callback_data_online]];
            }
            if (!empty($settings['payment_method']['card_number'])) {
                $callback_data_manual = "manual_pay_for_plan_{$needed_amount}_0_{$encoded_name}_custom";
                $keyboard_buttons[] = [['text' => '💳 پرداخت کارت به کارت', 'callback_data' => $callback_data_manual]];
            }

            if (empty($keyboard_buttons)) {
                editMessageText($chat_id, $message_id, "❌ موجودی شما کافی نیست و هیچ روش پرداختی توسط ادمین فعال نشده است.");
            } else {
                $message = "⚠️ موجودی شما کافی نیست!\n\n" .
                           "▫️ هزینه کانفیگ: " . number_format($total_cost) . " تومان\n" .
                           "▫️ موجودی شما: " . number_format($user_data['balance']) . " تومان\n" .
                           "<b>💰 مبلغ مورد نیاز: " . number_format($needed_amount) . " تومان</b>\n\n" .
                           "لطفاً روش پرداخت برای تکمیل خرید را انتخاب کنید:";
                editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
            }
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif ($data === 'confirm_renewal_payment') {
        $state_data = $user_data['state_data'];
        $total_cost = $state_data['renewal_total_cost'];

        if ($user_data['balance'] >= $total_cost) {
            
            editMessageText($chat_id, $message_id, "⏳ در حال تمدید سرویس با استفاده از موجودی شما...");
            updateUserBalance($chat_id, $total_cost, 'deduct');
            
            $result = applyRenewal($chat_id, $state_data['renewal_username'], $state_data['renewal_days'], $state_data['renewal_gb']);
            
            if ($result['success']) {
                $new_balance = number_format($user_data['balance'] - $total_cost);
                $success_msg = "✅ سرویس شما با موفقیت تمدید شد.\n\n" .
                               "💰 مبلغ " . number_format($total_cost) . " تومان از حساب شما کسر گردید.\n" .
                               "موجودی جدید: {$new_balance} تومان.";
                editMessageText($chat_id, $message_id, $success_msg);
            } else {
                editMessageText($chat_id, $message_id, "❌ خطایی در تمدید سرویس رخ داد: " . $result['message']);
                
                updateUserBalance($chat_id, $total_cost, 'add');
            }
            updateUserData($chat_id, 'main_menu');

        } else {
            
            $stmt = pdo()->prepare(
                "INSERT INTO renewal_requests (user_id, service_username, days_to_add, gb_to_add, total_cost) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$chat_id, $state_data['renewal_username'], $state_data['renewal_days'], $state_data['renewal_gb'], $total_cost]);
            $request_id = pdo()->lastInsertId();
            
            $state_data['renewal_request_id'] = $request_id;
            updateUserData($chat_id, 'awaiting_renewal_screenshot', $state_data);

           
            $settings = getSettings();
            $payment_method = $settings['payment_method'] ?? [];
            if (empty($payment_method['card_number'])) {
                editMessageText($chat_id, $message_id, "موجودی شما کافی نیست و روش پرداخت کارت به کارت نیز توسط ادمین تنظیم نشده است. لطفا ابتدا حساب خود را شارژ کنید.");
            } else {
                 $card_number = $payment_method['card_number'] ?? '';
                 $card_holder = $payment_method['card_holder'] ?? '';
                 $copy_enabled = $payment_method['copy_enabled'] ?? false;
                 $card_number_display = $copy_enabled ? "<code>{$card_number}</code>" : $card_number;
                 $message = "موجودی شما کافی نیست. لطفا مبلغ <b>" . number_format($total_cost) . " تومان</b> را به اطلاعات زیر واریز کرده و سپس اسکرین‌شات رسید را ارسال کنید:\n\n" .
                            "💳 شماره کارت:\n" . $card_number_display . "\n" .
                            "👤 صاحب حساب: {$card_holder}";
                 editMessageText($chat_id, $message_id, $message);
            }
        }
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif ($data == 'back_to_categories') {
        deleteMessage($chat_id, $message_id);
        $categories = getCategories(true);
        $keyboard_buttons = [];
        foreach ($categories as $category) {
            $keyboard_buttons[] = [['text' => '🛍 ' . $category['name'], 'callback_data' => 'cat_' . $category['id']]];
        }
        sendMessage($chat_id, "لطفا یکی از دسته‌بندی‌های زیر را انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
    }
            elseif (strpos($data, 'service_details_') === 0) {
                $username = str_replace('service_details_', '', $data);
                if (isset($update['callback_query']['message']['photo'])) {
                    editMessageCaption($chat_id, $message_id, "⏳ در حال دریافت اطلاعات به‌روز سرویس، لطفا صبر کنید...");
                } else {
                    editMessageText($chat_id, $message_id, "⏳ در حال دریافت اطلاعات به‌روز سرویس، لطفا صبر کنید...");
                }

                $stmt_local = pdo()->prepare("SELECT s.*, p.name as plan_name, p.show_sub_link, p.show_conf_links FROM services s JOIN plans p ON s.plan_id = p.id WHERE s.owner_chat_id = ? AND s.marzban_username = ?");
                $stmt_local->execute([$chat_id, $username]);
                $local_service = $stmt_local->fetch();

                if ($local_service) {
                    $stmt_server = pdo()->prepare("SELECT * FROM servers WHERE id = ?");
                    $stmt_server->execute([$local_service['server_id']]);
                    $server_info = $stmt_server->fetch();

                    $dynamic_sub_url = $local_service['sub_url'];
                    if ($server_info) {
                        $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
                        $sub_path = strstr($local_service['sub_url'], '/sub/');
                        if ($sub_path === false) { 
                            $sub_path = parse_url($local_service['sub_url'], PHP_URL_PATH);
                        }
                        $dynamic_sub_url = $base_sub_url . $sub_path;
                    }

                    $panel_user = getPanelUser($username, $local_service['server_id']);

                    if ($panel_user && !isset($panel_user['detail'])) {
                        $qr_code_url = generateQrCodeUrl($dynamic_sub_url);
                        
                        $total_gb_from_db = $local_service['volume_gb'];
                        $used_bytes_from_panel = $panel_user['used_traffic'];
                        
                        $total_text = ($total_gb_from_db > 0) ? "{$total_gb_from_db} گیگابایت" : 'نامحدود';
                        $used_text = formatBytes($used_bytes_from_panel);
                        
                        $remaining_text = 'نامحدود';
                        if ($total_gb_from_db > 0) {
                            $total_bytes_from_db = $total_gb_from_db * 1024 * 1024 * 1024;
                            $remaining_bytes = $total_bytes_from_db - $used_bytes_from_panel;
                            $remaining_text = formatBytes(max(0, $remaining_bytes));
                        }

                        $expire_date = $panel_user['expire'] ? date('Y-m-d', $panel_user['expire']) : 'نامحدود';
                        $status_text = ($panel_user['status'] === 'active' && ($panel_user['expire'] == 0 || $panel_user['expire'] > time())) ? 'فعال' : 'غیرفعال';

                        $caption =
                            "<b>مشخصات سرویس: {$local_service['plan_name']}</b>\n" .
                            "➖➖➖➖➖➖➖➖➖➖\n" .
                            "▫️ وضعیت: <b>{$status_text}</b>\n" .
                            "🗓 تاریخ انقضا: <b>{$expire_date}</b>\n\n" .
                            "📊 حجم کل: " . $total_text . "\n" .
                            "📈 حجم مصرفی: " . $used_text . "\n" .
                            "📉 حجم باقی‌مانده: " . $remaining_text . "\n" .
                            "➖➖➖➖➖➖➖➖➖➖\n";
                            
                        if ($local_service['show_sub_link']) {
                            $caption .= "\n🔗 لینک اشتراک (Subscription):\n<code>" . htmlspecialchars($dynamic_sub_url) . "</code>\n";
                        } else {
                            $caption .= "\n🔗 لینک اشتراک برای این پلن نمایش داده نمی‌شود.\n";
                        }

         
                        $keyboard_buttons = [
                            [['text' => '♻️ تمدید سرویس', 'callback_data' => "renew_service_{$username}"]],
                        ];

                        if ($local_service['show_conf_links'] && !empty($panel_user['links'])) {
                             $keyboard_buttons[0][] = ['text' => '📋 دریافت کانفیگ‌ها', 'callback_data' => "get_configs_{$username}"];
                        }
                        
                        $keyboard_buttons[] = [['text' => '🗑 حذف سرویس', 'callback_data' => "delete_service_confirm_{$username}"]];
                        $keyboard_buttons[] = [['text' => '◀️ بازگشت به لیست', 'callback_data' => 'back_to_services']];
                     

                        $keyboard = ['inline_keyboard' => $keyboard_buttons];

                        deleteMessage($chat_id, $message_id);
                        sendPhoto($chat_id, $qr_code_url, trim($caption), $keyboard);
                    } else {
                        editMessageText($chat_id, $message_id, "❌ خطایی در دریافت اطلاعات سرویس از سرور رخ داد یا سرویس یافت نشد. ممکن است توسط ادمین حذف شده باشد.");
                    }
                } else {
                    editMessageText($chat_id, $message_id, "❌ سرویس در دیتابیس ربات یافت نشد.");
                }
            }
    elseif (strpos($data, 'renew_service_') === 0) {
        $settings = getSettings();
        if (($settings['renewal_status'] ?? 'off') !== 'on') {
            apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '❌ قابلیت تمدید سرویس در حال حاضر غیرفعال است.', 'show_alert' => true]);
            die;
        }

        $username = str_replace('renew_service_', '', $data);
        updateUserData($chat_id, 'user_awaiting_renewal_days', ['renewal_username' => $username]);
        
        $price_day = number_format($settings['renewal_price_per_day'] ?? 1000);
        $message = "<b>تمدید سرویس</b>\n\n" .
                   "۱. چند **روز** به اعتبار سرویس شما اضافه شود؟\n\n" .
                   "▫️ هزینه هر روز: {$price_day} تومان\n" .
                   "💡 برای رد شدن و عدم تمدید زمان، عدد `0` را وارد کنید.";
        
        editMessageCaption($chat_id, $message_id, $message, null);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    }
    elseif (strpos($data, 'delete_service_confirm_') === 0) {
        $username = str_replace('delete_service_confirm_', '', $data);
        $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، حذف کن', 'callback_data' => "delete_service_do_{$username}"], ['text' => '❌ خیر، لغو', 'callback_data' => "service_details_{$username}"]]]];
        editMessageCaption($chat_id, $message_id, "⚠️ <b>آیا از حذف این سرویس مطمئن هستید؟</b>\nاین عمل غیرقابل بازگشت است و تمام اطلاعات سرویس پاک خواهد شد.", $keyboard);
    }
    elseif (strpos($data, 'delete_service_do_') === 0) {
        $username = str_replace('delete_service_do_', '', $data);
        editMessageCaption($chat_id, $message_id, "⏳ در حال حذف سرویس...");

        $stmt = pdo()->prepare("SELECT server_id FROM services WHERE owner_chat_id = ? AND marzban_username = ?");
        $stmt->execute([$chat_id, $username]);
        $server_id = $stmt->fetchColumn();

        if ($server_id) {
            $result_panel = deletePanelUser($username, $server_id);
            deleteUserService($chat_id, $username, $server_id);
            if ($result_panel) {
                editMessageCaption($chat_id, $message_id, "✅ سرویس شما با موفقیت حذف شد.");
            }
            else {
                editMessageCaption($chat_id, $message_id, "⚠️ سرویس از لیست شما حذف شد، اما ممکن است در حذف از پنل اصلی مشکلی رخ داده باشد. لطفا به پشتیبانی اطلاع دهید.");
                error_log("Failed to delete panel user {$username} on server {$server_id}. Response: " . json_encode($result_panel));
            }
        }
        else {
            editMessageCaption($chat_id, $message_id, "❌ خطایی در یافتن اطلاعات سرور برای این سرویس رخ داد.");
        }
    }
    elseif ($data == 'back_to_services') {
        deleteMessage($chat_id, $message_id);
        $services = getUserServices($chat_id);
        if (empty($services)) {
            sendMessage($chat_id, "شما هیچ سرویس فعالی ندارید.");
        }
        else {
            $keyboard_buttons = [];
            $now = time();
            foreach ($services as $service) {
                $expire_date = date('Y-m-d', $service['expire_timestamp']);
                $status_icon = $service['expire_timestamp'] < $now ? '❌' : '✅';
                $button_text = "{$status_icon} {$service['plan_name']} (انقضا: {$expire_date})";
                $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => 'service_details_' . $service['marzban_username']]];
            }
            sendMessage($chat_id, "سرویس مورد نظر خود را برای مشاهده جزئیات انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
        }
    }

    if (!USER_INLINE_KEYBOARD && !$apiRequest) {
        handleMainMenu($chat_id, $first_name, true);
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
    elseif ($apiRequest) {
        apiRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
        die;
    }
}




if (isset($update['message']) || USER_INLINE_KEYBOARD) {
    $is_verified = $user_data['is_verified'] ?? 0;
    $verification_method = $settings['verification_method'] ?? 'off';

    if ($verification_method !== 'off' && !$is_verified && !$isAnAdmin) {
        $is_phone_verification_action = isset($update['message']['contact']);

        if (!$is_phone_verification_action) {
            if ($verification_method === 'phone') {
                $message = "سلام! برای استفاده از امکانات ربات، لطفاً با کلیک روی دکمه زیر شماره تلفن خود را با ما به اشتراک بگذارید.";
                $keyboard = ['keyboard' => [[['text' => '🔒 اشتراک‌گذاری شماره تلفن', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
                sendMessage($chat_id, $message, $keyboard);
                die;
            }
            elseif ($verification_method === 'button') {
                $message = "سلام! برای اطمینان از اینکه شما یک کاربر واقعی هستید، لطفاً روی دکمه زیر کلیک کنید.";
                $keyboard = ['inline_keyboard' => [[['text' => '✅ تایید می‌کنم', 'callback_data' => 'verify_by_button']]]];
                sendMessage($chat_id, $message, $keyboard);
                die;
            }
        }
    }

    if (isset($update['message']['photo'])) {
        if ($user_state == 'awaiting_payment_screenshot') {
            $state_data = $user_data['state_data'];
            $amount = $state_data['charge_amount'];
            $user_id = $update['message']['from']['id'];
            $photo_id = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
            
            
            $metadata_to_save = null;
            if (isset($state_data['purpose']) && $state_data['purpose'] === 'complete_purchase') {
                $metadata_to_save = json_encode([
    'purpose' => 'complete_purchase',
    'plan_id' => $state_data['plan_id'],
    'discount_code' => $state_data['discount_code'] ?? null,
    'custom_name' => $state_data['custom_name'] ?? 'سرویس', 
    'is_custom_config' => $state_data['is_custom_config'] ?? false
]);
}
     

            $stmt = pdo()->prepare("INSERT INTO payment_requests (user_id, amount, photo_file_id, metadata) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $amount, $photo_id, $metadata_to_save]);
            $request_id = pdo()->lastInsertId();

            $caption = "<b>درخواست شارژ حساب جدید</b>\n\n" . "👤 کاربر: " . htmlspecialchars($first_name) . "\n" . "🆔 شناسه: <code>$user_id</code>\n" . "💰 مبلغ: " . number_format($amount) . " تومان\n" . "▫️ شماره درخواست: #{$request_id}";
            $keyboard = ['inline_keyboard' => [[['text' => '✅ تایید', 'callback_data' => "approve_{$request_id}"], ['text' => '❌ رد', 'callback_data' => "reject_{$request_id}"]]]];

            $all_admins = getAdmins();
            $all_admins[ADMIN_CHAT_ID] = [];
            foreach (array_keys($all_admins) as $admin_id) {
                if (hasPermission($admin_id, 'manage_payment')) {
                    sendPhoto($admin_id, $photo_id, $caption, $keyboard);
                }
            }

            sendMessage($chat_id, "✅ رسید شما برای ادمین ارسال شد. پس از بررسی، نتیجه به شما اطلاع داده خواهد شد.");
            updateUserData($chat_id, 'main_menu');
            handleMainMenu($chat_id, $first_name);
            die;
        }
    }

    if (isset($update['message']['contact'])) {
        $contact = $update['message']['contact'];

        if ($contact['user_id'] != $chat_id) {
            sendMessage($chat_id, "❌ لطفا فقط شماره تلفن خود را از طریق دکمه مخصوص به اشتراک بگذارید.");
            die;
        }

        $phone_number = $contact['phone_number'];
        $settings = getSettings();
        $is_valid = true;

        if ($settings['verification_iran_only'] === 'on') {
            $cleaned_phone = ltrim($phone_number, '+');
            if (strpos($cleaned_phone, '98') !== 0) {
                $is_valid = false;
            }
        }

        if ($is_valid) {
            $stmt = pdo()->prepare("UPDATE users SET is_verified = 1, phone_number = ? WHERE chat_id = ?");
            $stmt->execute([$phone_number, $chat_id]);
            sendMessage($chat_id, "✅ احراز هویت شما با موفقیت انجام شد. از همراهی شما سپاسگزاریم!");
            handleMainMenu($chat_id, $first_name);
        }
        else {
            $message = "❌ متاسفانه شماره ارسالی شما مورد تایید نیست. این ربات فقط برای شماره‌های ایران (+98) فعال است.";
            $keyboard = ['keyboard' => [[['text' => '🔒 اشتراک‌گذاری شماره تلفن', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
            sendMessage($chat_id, $message, $keyboard);
        }
        die;
    }

    if (!isset($update['message']['text']) && !isset($update['message']['forward_from']) && $user_state !== 'admin_awaiting_guide_content' && !USER_INLINE_KEYBOARD) {
        die;
    }

    $text = trim($update['message']['text'] ?? ($update['callback_query']['data'] ?? ''));

if ($text == '/start') {
    updateUserData($chat_id, 'main_menu', ['admin_view' => 'user']);
    handleMainMenu($chat_id, $first_name, true);
    die;
}

    if ($text == 'لغو' || $text == '◀️ بازگشت به منوی اصلی') {
        $admin_view_mode = $user_data['state_data']['admin_view'] ?? 'user';

        if ($isAnAdmin && (strpos($user_state, 'admin_') === 0 || $admin_view_mode === 'admin')) {
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
            handleMainMenu($chat_id, $first_name, false);
        }
        else {
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'user']);
            handleMainMenu($chat_id, $first_name, false);
        }
        die;
    }

    if (isset($update['message']['forward_from']) || isset($update['message']['forward_from_chat'])) {
        if ($isAnAdmin && $user_state == 'admin_awaiting_forward_message' && hasPermission($chat_id, 'broadcast')) {
            $user_ids = getAllUsers();
            $from_chat_id = $update['message']['chat']['id'];
            $message_id = $update['message']['message_id'];
            $success_count = 0;
            sendMessage($chat_id, "⏳ در حال شروع فروارد همگانی...");
            foreach ($user_ids as $user_id) {
                $result = forwardMessage($user_id, $from_chat_id, $message_id);
                $decoded_result = json_decode($result, true);
                if ($decoded_result && $decoded_result['ok']) {
                    $success_count++;
                }
                usleep(100000);
            }
            sendMessage($chat_id, "✅ پیام شما با موفقیت به $success_count کاربر فروارد شد.");
            updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
            handleMainMenu($chat_id, $first_name);
        }
        die;
    }

    if ($user_state !== 'main_menu') {
        switch ($user_state) {
            case 'admin_awaiting_referrer_reward':
    if (hasPermission($chat_id, 'manage_referrals') && is_numeric($text) && $text >= 0) {
        $settings = getSettings();
        $settings['referral_reward_referrer'] = (int)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ مبلغ با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showReferralManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
    }
    break;

case 'admin_awaiting_referred_reward':
    if (hasPermission($chat_id, 'manage_referrals') && is_numeric($text) && $text >= 0) {
        $settings = getSettings();
        $settings['referral_reward_referred'] = (int)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ مبلغ با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showReferralManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
    }
    break;

case 'admin_awaiting_commission_rate':
    if (hasPermission($chat_id, 'manage_referrals') && is_numeric($text) && $text >= 0 && $text <= 100) {
        $settings = getSettings();
        $settings['referral_commission_rate'] = (float)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ درصد کمیسیون با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showReferralManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط یک عدد بین 0 تا 100 وارد کنید.");
    }
    break;
            case 'admin_awaiting_tetra_api_key':
            if ($isAnAdmin) {
                $settings = getSettings();
                $settings['tetra_api_key'] = trim($text);
                saveSettings($settings);
                sendMessage($chat_id, "✅ کلید API تترا 98 با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                
                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '🔵 مدیریت زرین‌پال', 'callback_data' => 'manage_gateway_zarinpal']],
                        [['text' => '🟣 مدیریت تترا 98', 'callback_data' => 'manage_gateway_tetra']],
                        [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
                    ]
                ];
                sendMessage($chat_id, "منوی مدیریت درگاه‌ها:", $keyboard);
            }
            break;
            
            case 'admin_awaiting_custom_price_day':
                if ($isAnAdmin && hasPermission($chat_id, 'manage_custom_config') && is_numeric($text) && $text >= 0) {
                    $settings = getSettings();
                    $settings['custom_price_per_day'] = (int)$text;
                    saveSettings($settings);
                    sendMessage($chat_id, "✅ قیمت روز با موفقیت تنظیم شد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                    showCustomConfigManagementMenu($chat_id);
                } else {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
                }
                break;
            case 'admin_awaiting_custom_price_gb':
                if ($isAnAdmin && hasPermission($chat_id, 'manage_custom_config') && is_numeric($text) && $text >= 0) {
                    $settings = getSettings();
                    $settings['custom_price_per_gb'] = (int)$text;
                    saveSettings($settings);
                    sendMessage($chat_id, "✅ قیمت حجم با موفقیت تنظیم شد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                    showCustomConfigManagementMenu($chat_id);
                } else {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
                }
                break;
            case 'admin_awaiting_custom_max_days':
                if ($isAnAdmin && hasPermission($chat_id, 'manage_custom_config') && is_numeric($text) && $text >= 0) {
                    $settings = getSettings();
                    $settings['custom_max_days'] = (int)$text;
                    saveSettings($settings);
                    sendMessage($chat_id, "✅ حداکثر روز با موفقیت تنظیم شد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                    showCustomConfigManagementMenu($chat_id);
                } else {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
                }
                break;
            case 'admin_awaiting_custom_max_gb':
                if ($isAnAdmin && hasPermission($chat_id, 'manage_custom_config') && is_numeric($text) && $text >= 0) {
                    $settings = getSettings();
                    $settings['custom_max_gb'] = (int)$text;
                    saveSettings($settings);
                    sendMessage($chat_id, "✅ حداکثر حجم با موفقیت تنظیم شد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                    showCustomConfigManagementMenu($chat_id);
                } else {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
                }
                break;
            
            case 'awaiting_custom_config_name':
                $custom_name = trim($text);
                
                if (empty($custom_name) || mb_strlen($custom_name) > 50) {
                    sendMessage($chat_id, "❌ نام وارد شده نامعتبر است. لطفاً یک نام کوتاه‌تر (حداکثر 50 کاراکتر) وارد کنید.", $cancelKeyboard);
                    break;
                }
                
                $state_data = $user_data['state_data'];
                $state_data['custom_config_name'] = $custom_name;
                updateUserData($chat_id, 'awaiting_custom_config_volume', $state_data);
                
                $settings = getSettings();
                $max_gb = (int)($settings['custom_max_gb'] ?? 0);
                $max_gb_text = $max_gb == 0 ? 'نامحدود' : "حداکثر {$max_gb} گیگابایت";
                
                sendMessage($chat_id, "۱/۳ - نام کانفیگ: <b>{$custom_name}</b>\n\nلطفاً حجم مورد نظر خود را به **گیگابایت (GB)** وارد کنید. ({$max_gb_text})", $cancelKeyboard);
                break;

            case 'awaiting_custom_config_volume':
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح و مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $volume_gb = (int)$text;
                $settings = getSettings();
                $max_gb = (int)($settings['custom_max_gb'] ?? 0);
                
                if ($max_gb > 0 && $volume_gb > $max_gb) {
                    sendMessage($chat_id, "❌ حداکثر حجم قابل خرید {$max_gb} گیگابایت است. لطفا مقدار کمتری وارد کنید.", $cancelKeyboard);
                    break;
                }
                
                $state_data = $user_data['state_data'];
                $state_data['custom_config_volume'] = $volume_gb;
                updateUserData($chat_id, 'awaiting_custom_config_days', $state_data);
                
                $max_days = (int)($settings['custom_max_days'] ?? 0);
                $max_days_text = $max_days == 0 ? 'نامحدود' : "حداکثر {$max_days} روز";
                
                sendMessage($chat_id, "۲/۳ - حجم: <b>{$volume_gb} GB</b>\n\nلطفاً تعداد **روز** مورد نظر خود را وارد کنید. ({$max_days_text})", $cancelKeyboard);
                break;

            case 'awaiting_custom_config_days':
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح و مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $duration_days = (int)$text;
                $settings = getSettings();
                $max_days = (int)($settings['custom_max_days'] ?? 0);
                
                if ($max_days > 0 && $duration_days > $max_days) {
                    sendMessage($chat_id, "❌ حداکثر روز قابل خرید {$max_days} روز است. لطفا مقدار کمتری وارد کنید.", $cancelKeyboard);
                    break;
                }
                
                $state_data = $user_data['state_data'];
                $volume_gb = $state_data['custom_config_volume'];
                $custom_name = $state_data['custom_config_name'];
                
                $price_day = (int)($settings['custom_price_per_day'] ?? 1000);
                $price_gb = (int)($settings['custom_price_per_gb'] ?? 2000);
                
                $cost_days = $duration_days * $price_day;
                $cost_gb = $volume_gb * $price_gb;
                $total_cost = $cost_days + $cost_gb;
                
                
                $temp_plan = [
                    'id' => 0, 
                    'server_id' => $state_data['custom_config_server_id'],
                    'category_id' => $state_data['custom_config_category_id'],
                    'inbound_id' => null,
                    'marzneshin_service_id' => null,
                    'name' => $custom_name,
                    'price' => $total_cost,
                    'volume_gb' => $volume_gb,
                    'duration_days' => $duration_days,
                    'show_sub_link' => true,
                    'show_conf_links' => true,
                ];
                
                $state_data['temp_custom_plan'] = $temp_plan;
                updateUserData($chat_id, 'user_confirming_custom_config', $state_data);
                
                $summary = "۳/۳ - روز: <b>{$duration_days} روز</b>\n\n" .
                           "<b>خلاصه کانفیگ دلخواه شما:</b>\n" .
                           "▫️ نام: <b>" . htmlspecialchars($custom_name) . "</b>\n" .
                           "▫️ حجم: <b>{$volume_gb} گیگابایت</b> (هزینه: " . number_format($cost_gb) . " تومان)\n" .
                           "▫️ مدت: <b>{$duration_days} روز</b> (هزینه: " . number_format($cost_days) . " تومان)\n\n" .
                           "💰 هزینه کل: <b>" . number_format($total_cost) . " تومان</b>\n\n" .
                           "موجودی فعلی شما: " . number_format($user_data['balance']) . " تومان\n\n" .
                           "آیا تایید می‌کنید؟";

                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، پرداخت کن', 'callback_data' => 'confirm_custom_purchase_0']]]];
                sendMessage($chat_id, $summary, $keyboard);
                break;
             
            case 'awaiting_service_name':
    $custom_name = trim($text);
    if (empty($custom_name) || mb_strlen($custom_name) > 50) {
        sendMessage($chat_id, "❌ نام وارد شده نامعتبر است. لطفاً یک نام کوتاه‌تر (حداکثر 50 کاراکتر) وارد کنید.", $cancelKeyboard);
        break;
    }

    $state_data = $user_data['state_data'];
    $plan_id = $state_data['purchasing_plan_id'];
    $discount_code = $state_data['discount_code'] ?? null;
    
    $plan = getPlanById($plan_id);
    if (!$plan) {
        sendMessage($chat_id, "❌ خطایی رخ داد. پلن یافت نشد.");
        updateUserData($chat_id, 'main_menu');
        break;
    }

    
    $final_price = (float)$plan['price'];
    $discount_applied = false;
    $discount_object = null;
    if ($discount_code) {
        $stmt = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ? AND status = 'active' AND usage_count < max_usage");
        $stmt->execute([$discount_code]);
        $discount = $stmt->fetch();
        if ($discount) {
            if ($discount['type'] == 'percent') {
                $final_price = $plan['price'] - ($plan['price'] * $discount['value']) / 100;
            } else {
                $final_price = $plan['price'] - $discount['value'];
            }
            $final_price = max(0, $final_price);
            $discount_applied = true;
            $discount_object = $discount;
        }
    }
    
    $user_balance = $user_data['balance'];

    if ($user_balance >= $final_price) {
        sendMessage($chat_id, "⏳ نام سرویس تایید شد. لطفاً صبر کنید... در حال ایجاد سرویس شما هستیم.");
        $purchase_result = completePurchase($chat_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied);
        
        if ($purchase_result['success']) {
            sendPhoto($chat_id, $purchase_result['qr_code_url'], $purchase_result['caption'], $purchase_result['keyboard']);
            sendMessage(ADMIN_CHAT_ID, $purchase_result['admin_notification']);
        } else {
            sendMessage($chat_id, $purchase_result['error_message']);
            sendMessage(ADMIN_CHAT_ID, "⚠️ <b>خطای ساخت سرویس</b>\n\nکاربر با شناسه <code>$chat_id</code> قصد خرید پلن '{$plan['name']}' را داشت اما ارتباط با پنل ناموفق بود.");
        }
        updateUserData($chat_id, 'main_menu');
        handleMainMenu($chat_id, $first_name);

    } else {
    
    
        $needed_amount = $final_price - $user_balance;
        $settings = getSettings();
        
        $encoded_name = base64_encode($custom_name);
    
        $keyboard_buttons = [];
        
        if (!empty($settings['payment_method']['card_number'])) {
            $callback_data_manual = "manual_pay_for_plan_{$needed_amount}_{$plan_id}";
            if ($discount_code) $callback_data_manual .= "_{$discount_code}";
            $callback_data_manual .= "_{$encoded_name}";
            $keyboard_buttons[] = [['text' => '💳 پرداخت کارت به کارت', 'callback_data' => $callback_data_manual]];
        }
        
        if (($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id'])) {
            $callback_data_online = "charge_for_plan_{$needed_amount}_{$plan_id}";
            if ($discount_code) $callback_data_online .= "_{$discount_code}";
            $callback_data_online .= "_{$encoded_name}";
            $keyboard_buttons[] = [['text' => '🔵 پرداخت با زرین‌پال', 'callback_data' => $callback_data_online]];
        }
        
        if (($settings['tetra_status'] ?? 'off') == 'on' && !empty($settings['tetra_api_key'])) {
            $callback_data_tetra = "tetra_pay_for_plan_{$needed_amount}_{$plan_id}";
            if ($discount_code) $callback_data_tetra .= "_{$discount_code}";
            $callback_data_tetra .= "_{$encoded_name}";
            $keyboard_buttons[] = [['text' => '🟣 پرداخت با تترا 98', 'callback_data' => $callback_data_tetra]];
        }

        if (empty($keyboard_buttons)) {
            sendMessage($chat_id, "❌ موجودی شما کافی نیست و هیچ روش پرداختی توسط ادمین فعال نشده است.");
        } else {
            $message = "⚠️ موجودی شما کافی نیست!\n\n" .
                       "▫️ قیمت پلن: " . number_format($final_price) . " تومان\n" .
                       "▫️ موجودی شما: " . number_format($user_balance) . " تومان\n" .
                       "<b>💰 مبلغ مورد نیاز: " . number_format($needed_amount) . " تومان</b>\n\n" .
                       "لطفاً روش پرداخت برای تکمیل خرید را انتخاب کنید:";
            sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
        }
    }
    break;
            
            case 'admin_awaiting_user_search':
                if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                    if (!is_numeric($text)) {
                        sendMessage($chat_id, "❌ شناسه عددی نامعتبر است. لطفاً فقط عدد وارد کنید.", $cancelKeyboard);
                        break;
                    }
                    $target_user = getUserData($text, '');
                    if (!$target_user || !isset($target_user['chat_id'])) {
                        sendMessage($chat_id, "❌ کاربری با این شناسه یافت نشد. لطفاً شناسه را بررسی کرده و مجدداً تلاش کنید.", $cancelKeyboard);
                        break;
                    }
                    
                    $chat_info_response = apiRequest('getChat', ['chat_id' => $target_user['chat_id']]);
                    $chat_info = json_decode($chat_info_response, true);
                    
                    $profile_link_html = '';
                    if ($chat_info['ok'] && !empty($chat_info['result']['username'])) {
                        $username = $chat_info['result']['username'];
                        $profile_link_html = "👤 حساب کاربری: <a href='https://t.me/{$username}'>@{$username}</a>\n";
                    } else {
                        $profile_link_html = "👤 حساب کاربری: <a href='tg://user?id={$target_user['chat_id']}'>مشاهده پروفایل (بدون یوزرنیم)</a>\n";
                    }
                    

                    
                    $balance = $target_user['balance'] ?? 0;
                    $status_text = ($target_user['status'] ?? 'active') === 'active' ? 'فعال ✅' : 'مسدود 🚫';

                    $message = "<b>اطلاعات کاربر:</b> " . htmlspecialchars($target_user['first_name']) . "\n\n" .
                               "▫️ شناسه: <code>{$target_user['chat_id']}</code>\n" .
                               $profile_link_html . 
                               "💰 موجودی: " . number_format($balance) . " تومان\n" .
                               "▫️ وضعیت: <b>{$status_text}</b>\n\n" .
                               "لطفاً عملیات مورد نظر را انتخاب کنید:";

                    $status_button_text = ($target_user['status'] ?? 'active') === 'active' ? '🚫 مسدود کردن' : '✅ آزاد کردن';
                    $status_callback = ($target_user['status'] ?? 'active') === 'active' ? "ban_user_{$target_user['chat_id']}" : "unban_user_{$target_user['chat_id']}";

                    $keyboard = ['inline_keyboard' => [
                        [
                            ['text' => '➕ افزایش موجودی', 'callback_data' => "add_balance_{$target_user['chat_id']}"],
                            ['text' => '➖ کاهش موجودی', 'callback_data' => "deduct_balance_{$target_user['chat_id']}"]
                        ],
                        [
                            ['text' => '✉️ ارسال پیام', 'callback_data' => "message_user_{$target_user['chat_id']}"],
                            ['text' => '🔧 سرویس‌های کاربر', 'callback_data' => "show_user_services_{$target_user['chat_id']}"]
                        ],
                        [
                             ['text' => $status_button_text, 'callback_data' => $status_callback]
                        ],
                        [
                            ['text' => '🔎 جستجوی کاربر دیگر', 'callback_data' => 'search_another_user']
                        ]
                    ]];

                    sendMessage($chat_id, $message, $keyboard);
                    
                    updateUserData($chat_id, 'admin_awaiting_user_search', ['admin_view' => 'admin']);
                }
                break;
            
            case 'admin_awaiting_renewal_price_day':
    if ($isAnAdmin && is_numeric($text) && $text >= 0) {
        $settings = getSettings();
        $settings['renewal_price_per_day'] = (int)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ قیمت با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showRenewalManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
    }
    break;
    
                case 'admin_awaiting_merchant_id':
                if ($isAnAdmin && strlen($text) === 36) {
                    $settings = getSettings();
                    $settings['zarinpal_merchant_id'] = $text;
                    saveSettings($settings);
                    sendMessage($chat_id, "✅ مرچنت کد با موفقیت ذخیره شد.");
                    updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                 
                } else {
                    sendMessage($chat_id, "❌ مرچنت کد نامعتبر است. باید دقیقا ۳۶ کاراکتر باشد.");
                }
                break;
    
            case 'admin_awaiting_renewal_price_gb':
    if ($isAnAdmin && is_numeric($text) && $text >= 0) {
        $settings = getSettings();
        $settings['renewal_price_per_gb'] = (int)$text;
        saveSettings($settings);
        sendMessage($chat_id, "✅ قیمت با موفقیت تنظیم شد.");
        updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
        showRenewalManagementMenu($chat_id);
    } else {
        sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.");
    }
    break;
    
            case 'admin_awaiting_category_name':
                if (!hasPermission($chat_id, 'manage_categories')) {
                    break;
                }
                $stmt = pdo()->prepare("INSERT INTO categories (name) VALUES (?)");
                $stmt->execute([$text]);
                sendMessage($chat_id, "✅ دسته‌بندی « $text » با موفقیت اضافه شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'awaiting_plan_name':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_name'] = $text;
                updateUserData($chat_id, 'awaiting_plan_price', $state_data);
                sendMessage($chat_id, "2/6 - لطفا قیمت پلن را به تومان وارد کنید (فقط عدد):", $cancelKeyboard);
                break;

            case 'awaiting_plan_price':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_price'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_volume', $state_data);
                sendMessage($chat_id, "3/6 - لطفا حجم پلن را به گیگابایت (GB) وارد کنید (فقط عدد):", $cancelKeyboard);
                break;

            case 'awaiting_plan_volume':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_volume'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_duration', $state_data);
                sendMessage($chat_id, "4/6 - لطفا مدت زمان پلن را به روز وارد کنید (فقط عدد):", $cancelKeyboard);
                break;

            case 'awaiting_plan_duration':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_plan_duration'] = (int)$text;
                updateUserData($chat_id, 'awaiting_plan_description', $state_data);
                $keyboard = ['keyboard' => [[['text' => 'رد شدن'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "5/6 - در صورت تمایل، توضیحات مختصری برای پلن وارد کنید (اختیاری):", $keyboard);
                break;

            case 'awaiting_plan_description':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                $description = $text == 'رد شدن' ? '' : $text;
                $state_data = $user_data['state_data'];

                $state_data['new_plan_description'] = $description;
                updateUserData($chat_id, 'awaiting_plan_purchase_limit', $state_data);

                $keyboard = ['keyboard' => [[['text' => '0 (نامحدود)'], ['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "6/6 - تعداد مجاز خرید برای این پلن را وارد کنید (فقط عدد).\n\nبرای فروش نامحدود، عدد `0` را وارد کنید.", $keyboard);
                break;

                        case 'awaiting_plan_purchase_limit':
                if (!hasPermission($chat_id, 'manage_plans')) {
                    break;
                }
                if (!is_numeric($text) || (int)$text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح (مثبت یا صفر) وارد کنید.", $cancelKeyboard);
                    break;
                }

                $state_data = $user_data['state_data'];
                $new_plan_data = [
                    'server_id' => $state_data['new_plan_server_id'],
                    'inbound_id' => $state_data['new_plan_inbound_id'] ?? null,
                    'marzneshin_service_id' => $state_data['new_plan_marzneshin_service_id'] ?? null,
                    'category_id' => $state_data['new_plan_category_id'],
                    'name' => $state_data['new_plan_name'],
                    'price' => $state_data['new_plan_price'],
                    'volume_gb' => $state_data['new_plan_volume'],
                    'duration_days' => $state_data['new_plan_duration'],
                    'description' => $state_data['new_plan_description'],
                    'purchase_limit' => (int)$text,
                ];

                updateUserData($chat_id, 'awaiting_plan_sub_link_setting', ['temp_plan_data' => $new_plan_data]);

                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله', 'callback_data' => 'plan_set_sub_yes'], ['text' => '❌ خیر', 'callback_data' => 'plan_set_sub_no']]]];
                sendMessage($chat_id, "سوال ۱/۲: آیا لینک اشتراک (Subscription) به کاربر نمایش داده شود؟\n(پیشنهادی: بله)", $keyboard);
                break;
            
                case 'admin_awaiting_sub_host':
                if (!hasPermission($chat_id, 'manage_marzban')) break;

                $state_data = $user_data['state_data'];
                $server_id = $state_data['editing_server_id'];
                $new_sub_host = null;
                $message_text = "";
                
                if (strtolower($text) === 'reset') {
                    $new_sub_host = null;
                    $message_text = "✅ آدرس لینک اشتراک با موفقیت به حالت پیش‌فرض بازنشانی شد.";
                } elseif (!filter_var($text, FILTER_VALIDATE_URL)) {
                    sendMessage($chat_id, "❌ آدرس وارد شده نامعتبر است. لطفا آدرس را به همراه http یا https و پورت صحیح وارد کنید.", $cancelKeyboard);
                    break;
                } else {
                    $new_sub_host = rtrim($text, '/');
                    $message_text = "✅ آدرس لینک اشتراک با موفقیت روی `{$new_sub_host}` تنظیم شد.";
                }

                $stmt = pdo()->prepare("UPDATE servers SET sub_host = ? WHERE id = ?");
                $stmt->execute([$new_sub_host, $server_id]);
                
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                sendMessage($chat_id, $message_text);
                
                $servers = pdo()->query("SELECT id, name FROM servers")->fetchAll(PDO::FETCH_ASSOC);
                $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
                foreach ($servers as $server) {
                    $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
                }
                $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']];
                sendMessage($chat_id, "<b>🌐 لیست سرورها به‌روز شد.</b>", ['inline_keyboard' => $keyboard_buttons]);
                break;
            
            case 'admin_awaiting_card_number':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                if (!preg_match('/^\d{16}$/', str_replace(['-', ' '], '', $text))) {
                    sendMessage($chat_id, "❌ شماره کارت نامعتبر است. لطفا یک شماره ۱۶ رقمی صحیح وارد کنید.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_card_holder', ['temp_card_number' => $text]);
                sendMessage($chat_id, "مرحله ۲/۳: نام و نام خانوادگی صاحب حساب را وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_card_holder':
                if (!hasPermission($chat_id, 'manage_payment')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['temp_card_holder'] = $text;
                updateUserData($chat_id, 'admin_awaiting_copy_toggle', $state_data);
                $keyboard = ['inline_keyboard' => [[['text' => '✅ فعال', 'callback_data' => 'copy_toggle_yes'], ['text' => '❌ غیرفعال', 'callback_data' => 'copy_toggle_no']]]];
                sendMessage($chat_id, "مرحله ۳/۳: آیا کاربر بتواند با کلیک روی شماره کارت آن را کپی کند؟", $keyboard);
                break;

            case 'admin_awaiting_server_name':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['temp_server_name'] = $text;
                updateUserData($chat_id, 'admin_awaiting_server_url', $state_data);
                sendMessage($chat_id, "مرحله ۲/۴: لطفا آدرس کامل پنل را وارد کنید (مثال: https://example.com:2053):", $cancelKeyboard);
                break;
            case 'admin_awaiting_server_url':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                if (!filter_var($text, FILTER_VALIDATE_URL)) {
                    sendMessage($chat_id, "❌ آدرس وارد شده نامعتبر است. لطفا آدرس را به همراه http یا https وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['temp_server_url'] = rtrim($text, '/');
                updateUserData($chat_id, 'admin_awaiting_server_user', $state_data);
                sendMessage($chat_id, "مرحله ۳/۴: لطفا نام کاربری ادمین پنل را وارد کنید:", $cancelKeyboard);
                break;
            case 'admin_awaiting_server_user':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['temp_server_user'] = $text;
                updateUserData($chat_id, 'admin_awaiting_server_pass', $state_data);
                sendMessage($chat_id, "مرحله ۴/۴: لطفا رمز عبور ادمین پنل را وارد کنید:", $cancelKeyboard);
                break;
            case 'admin_awaiting_server_pass':
                if (!hasPermission($chat_id, 'manage_marzban')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $stmt = pdo()->prepare("INSERT INTO servers (name, url, username, password, type) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$state_data['temp_server_name'], $state_data['temp_server_url'], $state_data['temp_server_user'], $text, $state_data['selected_server_type']]);
                $new_server_id = pdo()->lastInsertId();
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                sendMessage($chat_id, "✅ سرور جدید با موفقیت ذخیره شد.\n\n⏳ در حال تست ارتباط با سرور...");

                $tokenResult = false;
                $connection_error = null;

                if ($state_data['selected_server_type'] === 'marzban') {
                    $tokenResult = getMarzbanToken($new_server_id);
                } elseif ($state_data['selected_server_type'] === 'sanaei') {
                    $tokenResult = getSanaeiCookie($new_server_id);
                } elseif ($state_data['selected_server_type'] === 'marzneshin') {
                    $tokenResult = getMarzneshinToken($new_server_id);
                    
                    if (is_array($tokenResult) && isset($tokenResult['error'])) {
                        $connection_error = $tokenResult['error'];
                        $tokenResult = false; 
                    }
                }

                if ($tokenResult) {
                    sendMessage($chat_id, "✅ ارتباط با سرور '{$state_data['temp_server_name']}' با موفقیت برقرار شد.");
                } else {
                    $error_message = "⚠️ <b>هشدار:</b> ربات نتوانست به سرور جدید متصل شود. لطفا اطلاعات وارد شده را بررسی کرده و در صورت نیاز سرور را حذف و مجدداً اضافه کنید.";
                    if ($connection_error) {
                       $error_message .= "\n\n<b>جزئیات خطا:</b>\n<code>" . htmlspecialchars($connection_error) . "</code>";
                    }
                    sendMessage($chat_id, $error_message);
                }
                handleMainMenu($chat_id, $first_name);
                break;
                
                        case 'admin_awaiting_plan_edit_input':
                if (!hasPermission($chat_id, 'manage_plans')) break;

                $state_data = $user_data['state_data'];
                $plan_id = $state_data['editing_plan_id'];
                $field_info = $state_data['editing_field_info'];
                $editor_message_id = $state_data['editor_message_id'];
                $column = $field_info['column'];
                $validation = $field_info['validation'];
                $value = $text;
                $user_message_id = $update['message']['message_id'];
                
                $is_valid = false;
                if ($validation === 'text' && !empty($value)) {
                    $is_valid = true;
                } elseif (($validation === 'numeric' || $validation === 'numeric_zero') && is_numeric($value) && $value >= 0) {
                    $is_valid = true;
                }

                if (!$is_valid) {
                    showPlanEditor($chat_id, $editor_message_id, $plan_id, "❌ ورودی نامعتبر! " . $field_info['prompt']);
                    deleteMessage($chat_id, $user_message_id);
                    break;
                }
                
                $stmt = pdo()->prepare("UPDATE plans SET `{$column}` = ? WHERE id = ?");
                $stmt->execute([$value, $plan_id]);
                
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                showPlanEditor($chat_id, $editor_message_id, $plan_id, "✅ مقدار با موفقیت به‌روز شد.");
                deleteMessage($chat_id, $user_message_id);
                break;

                case 'awaiting_charge_amount':
        if (!is_numeric($text) || $text <= 0) {
            sendMessage($chat_id, "❌ لطفا یک مبلغ معتبر (عدد مثبت) به تومان وارد کنید.", $cancelKeyboard);
            break;
        }
        $amount = (int)$text;
        $settings = getSettings();
        
        $keyboard_buttons = [];
       
        if (!empty($settings['payment_method']['card_number'])) {
            $keyboard_buttons[] = [['text' => '💳 پرداخت کارت به کارت', 'callback_data' => "charge_manual_{$amount}"]];
        }

        if (($settings['payment_gateway_status'] ?? 'off') == 'on' && !empty($settings['zarinpal_merchant_id'])) {
            $keyboard_buttons[] = [['text' => '🔵 پرداخت با زرین‌پال', 'callback_data' => "charge_zarinpal_{$amount}"]];
        }
        
        if (($settings['tetra_status'] ?? 'off') == 'on' && !empty($settings['tetra_api_key'])) {
            $keyboard_buttons[] = [['text' => '🟣 پرداخت با تترا 98', 'callback_data' => "charge_tetra_{$amount}"]];
        }
        
        if (empty($keyboard_buttons)) {
            sendMessage($chat_id, "متاسفانه هیچ روش پرداختی توسط ادمین فعال نشده است.");
            updateUserData($chat_id, 'main_menu');
            handleMainMenu($chat_id, $first_name);
        } else {
            $message = "لطفا روش پرداخت برای شارژ به مبلغ " . number_format($amount) . " تومان را انتخاب کنید:";
            sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
        }
        break;

            case 'awaiting_ticket_subject':
                updateUserData($chat_id, 'awaiting_ticket_message', ['ticket_subject' => $text]);
                sendMessage($chat_id, "✅ موضوع ثبت شد.\n\nحالا لطفا متن پیام خود را به طور کامل وارد کنید:", $cancelKeyboard);
                break;

            case 'awaiting_ticket_message':
                $state_data = $user_data['state_data'];
                $subject = $state_data['ticket_subject'];
                $ticket_id = 'T' . time();

                $stmt = pdo()->prepare("INSERT INTO tickets (id, user_id, user_name, subject, status) VALUES (?, ?, ?, ?, 'open')");
                $stmt->execute([$ticket_id, $chat_id, $first_name, $subject]);

                $stmt2 = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, message_text) VALUES (?, 'user', ?)");
                $stmt2->execute([$ticket_id, $text]);

                $admin_message =
                    "<b>🎫 تیکت پشتیبانی جدید</b>\n\n" . "▫️ شماره تیکت: <code>$ticket_id</code>\n" . "👤 از طرف: $first_name (<code>$chat_id</code>)\n" . "▫️ موضوع: <b>$subject</b>\n\n" . "✉️ پیام:\n" . htmlspecialchars($text);
                $admin_keyboard = ['inline_keyboard' => [[['text' => '💬 پاسخ', 'callback_data' => "reply_ticket_{$ticket_id}"], ['text' => '✖️ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]]]];
                $all_admins = getAdmins();
                $all_admins[ADMIN_CHAT_ID] = [];
                foreach (array_keys($all_admins) as $admin_id) {
                    if (hasPermission($admin_id, 'view_tickets')) {
                        sendMessage($admin_id, $admin_message, $admin_keyboard);
                    }
                }
                sendMessage($chat_id, "✅ تیکت شما با شماره <code>$ticket_id</code> با موفقیت ثبت شد. به زودی توسط پشتیبانی پاسخ داده خواهد شد.");
                updateUserData($chat_id, 'main_menu');
                handleMainMenu($chat_id, $first_name);
                break;

            case 'user_replying_to_ticket':
                $state_data = $user_data['state_data'];
                $ticket_id = $state_data['replying_to_ticket'];

                $stmt = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, message_text) VALUES (?, 'user', ?)");
                $stmt->execute([$ticket_id, $text]);
                $stmt_update = pdo()->prepare("UPDATE tickets SET status = 'user_reply' WHERE id = ?");
                $stmt_update->execute([$ticket_id]);

                $admin_message = "<b>💬 پاسخ جدید از کاربر</b>\n\n" . "▫️ شماره تیکت: <code>$ticket_id</code>\n" . "👤 کاربر: $first_name (<code>$chat_id</code>)\n\n" . "✉️ پیام:\n" . htmlspecialchars($text);
                $admin_keyboard = ['inline_keyboard' => [[['text' => '💬 پاسخ مجدد', 'callback_data' => "reply_ticket_{$ticket_id}"], ['text' => '✖️ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]]]];
                $all_admins = getAdmins();
                $all_admins[ADMIN_CHAT_ID] = [];
                foreach (array_keys($all_admins) as $admin_id) {
                    if (hasPermission($admin_id, 'view_tickets')) {
                        sendMessage($admin_id, $admin_message, $admin_keyboard);
                    }
                }
                sendMessage($chat_id, "✅ پاسخ شما ارسال شد.");
                updateUserData($chat_id, 'main_menu');
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_replying_to_ticket':
                if (!$isAnAdmin) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $ticket_id = $state_data['replying_to_ticket'];

                $stmt = pdo()->prepare("SELECT user_id FROM tickets WHERE id = ?");
                $stmt->execute([$ticket_id]);
                $target_user_id = $stmt->fetchColumn();

                if ($target_user_id) {
                    $stmt_insert = pdo()->prepare("INSERT INTO ticket_conversations (ticket_id, sender, message_text) VALUES (?, 'admin', ?)");
                    $stmt_insert->execute([$ticket_id, $text]);
                    $stmt_update = pdo()->prepare("UPDATE tickets SET status = 'admin_reply' WHERE id = ?");
                    $stmt_update->execute([$ticket_id]);

                    $user_message = "<b>💬 پاسخ از پشتیبانی</b>\n\n" . "▫️ شماره تیکت: <code>$ticket_id</code>\n\n" . "✉️ پیام:\n" . htmlspecialchars($text);
                    $user_keyboard = ['inline_keyboard' => [[['text' => '💬 پاسخ مجدد', 'callback_data' => "reply_ticket_{$ticket_id}"], ['text' => '✖️ بستن تیکت', 'callback_data' => "close_ticket_{$ticket_id}"]]]];
                    sendMessage($target_user_id, $user_message, $user_keyboard);
                    sendMessage($chat_id, "✅ پاسخ شما برای کاربر ارسال شد.");
                }
                else {
                    sendMessage($chat_id, "❌ خطایی در ارسال پاسخ رخ داد. تیکت یا کاربر یافت نشد.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_add_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_amount_for_add_balance', ['target_user_id' => $text]);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی کاربر اضافه کنید را به تومان وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_amount_for_add_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ عددی و مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_id = $state_data['target_user_id'];
                updateUserBalance($target_id, (int)$text, 'add');
                $new_balance_data = getUserData($target_id, '');
                sendMessage($chat_id, "✅ مبلغ " . number_format($text) . " تومان با موفقیت به موجودی کاربر <code>$target_id</code> اضافه شد.");
                sendMessage($target_id, "✅ مبلغ " . number_format($text) . " تومان توسط ادمین به موجودی شما اضافه شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_deduct_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_amount_for_deduct_balance', ['target_user_id' => $text]);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید از موجودی کاربر کسر کنید را به تومان وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_amount_for_deduct_balance':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ عددی و مثبت وارد کنید.", $cancelKeyboard);
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_id = $state_data['target_user_id'];
                $target_user_data = getUserData($target_id, '');
                if ($target_user_data['balance'] < (int)$text) {
                    sendMessage($chat_id, "❌ موجودی کاربر برای کسر این مبلغ کافی نیست.\nموجودی فعلی: " . number_format($target_user_data['balance']) . " تومان", $cancelKeyboard);
                    break;
                }
                updateUserBalance($target_id, (int)$text, 'deduct');
                $new_balance_data = getUserData($target_id, '');
                sendMessage($chat_id, "✅ مبلغ " . number_format($text) . " تومان با موفقیت از موجودی کاربر <code>$target_id</code> کسر شد.");
                sendMessage($target_id, "❗️ مبلغ " . number_format($text) . " تومان توسط ادمین از موجودی شما کسر شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_message':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_message_for_user', ['target_user_id' => $text]);
                sendMessage($chat_id, "پیام خود را برای ارسال به کاربر <code>$text</code> وارد کنید:", $cancelKeyboard);
                break;

            case 'admin_awaiting_message_for_user':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $target_id = $state_data['target_user_id'];
                $message_to_send = "<b>پیامی از طرف پشتیبانی:</b>\n\n" . htmlspecialchars($text);
                $result = sendMessage($target_id, $message_to_send);
                $decoded_result = json_decode($result, true);
                if ($decoded_result && $decoded_result['ok']) {
                    sendMessage($chat_id, "✅ پیام شما با موفقیت به کاربر <code>$target_id</code> ارسال شد.");
                }
                else {
                    sendMessage($chat_id, "❌ ارسال پیام به کاربر <code>$target_id</code> ناموفق بود. ممکن است کاربر ربات را بلاک کرده باشد.");
                }
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_ban':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                if ($text == ADMIN_CHAT_ID) {
                    sendMessage($chat_id, "❌ شما نمی‌توانید خودتان را مسدود کنید!", $cancelKeyboard);
                    break;
                }
                setUserStatus($text, 'banned');
                sendMessage($chat_id, "✅ کاربر با شناسه <code>$text</code> با موفقیت مسدود شد.");
                sendMessage($text, "شما توسط ادمین از ربات مسدود شده‌اید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_user_id_for_unban':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || !getUserData($text, '')) {
                    sendMessage($chat_id, "❌ شناسه عددی نامعتبر است یا کاربری با این شناسه یافت نشد.", $cancelKeyboard);
                    break;
                }
                setUserStatus($text, 'active');
                sendMessage($chat_id, "✅ کاربر با شناسه <code>$text</code> با موفقیت از حالت مسدودیت خارج شد.");
                sendMessage($text, "✅ شما توسط ادمین از حالت مسدودیت خارج شدید. می‌توانید از ربات استفاده کنید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_broadcast_message':
                if (!hasPermission($chat_id, 'broadcast')) {
                    break;
                }
                $user_ids = getAllUsers();
                $success_count = 0;
                sendMessage($chat_id, "⏳ در حال شروع ارسال پیام همگانی...");
                foreach ($user_ids as $user_id) {
                    $result = sendMessage($user_id, $text);
                    $decoded_result = json_decode($result, true);
                    if ($decoded_result && $decoded_result['ok']) {
                        $success_count++;
                    }
                    usleep(100000);
                }
                sendMessage($chat_id, "✅ پیام شما با موفقیت به $success_count کاربر ارسال شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_join_channel_id':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (strpos($text, '@') !== 0) {
                    sendMessage($chat_id, "❌ شناسه کانال باید با @ شروع شود (مثال: @YourChannel).", $cancelKeyboard);
                    break;
                }
                $settings = getSettings();
                $settings['join_channel_id'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ کانال عضویت اجباری با موفقیت روی <code>$text</code> تنظیم شد.\nفراموش نکنید که ربات باید در این کانال ادمین باشد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_welcome_gift_amount':
                if (!hasPermission($chat_id, 'manage_settings')) {
                    break;
                }
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ عددی (مثبت یا صفر) به تومان وارد کنید.", $cancelKeyboard);
                    break;
                }
                $settings = getSettings();
                $settings['welcome_gift_balance'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ هدیه عضویت برای کاربران جدید روی " . number_format($text) . " تومان تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_bulk_data_amount':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک حجم معتبر (عدد مثبت) به گیگابایت وارد کنید.", $cancelKeyboard);
                    break;
                }
                sendMessage($chat_id, "⏳ عملیات افزودن حجم به تمام سرویس‌ها شروع شد. این فرآیند ممکن است کمی طول بکشد...");
                $data_to_add_gb = (float)$text;
                $bytes_to_add = $data_to_add_gb * 1024 * 1024 * 1024;
                $all_services = pdo()
                    ->query("SELECT marzban_username, server_id FROM services")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $success_count = 0;
                $fail_count = 0;
                foreach ($all_services as $service) {
                    $username = $service['marzban_username'];
                    $server_id = $service['server_id'];
                    if (!$server_id) {
                        $fail_count++;
                        continue;
                    }

                    $current_user_data = getPanelUser($username, $server_id);
                    if ($current_user_data && !isset($current_user_data['detail'])) {
                        $current_limit = $current_user_data['data_limit'];
                        if ($current_limit > 0) {
                            $new_limit = $current_limit + $bytes_to_add;
                            $result = modifyPanelUser($username, $server_id, ['data_limit' => $new_limit]);
                            if ($result && !isset($result['detail'])) {
                                $success_count++;
                            }
                            else {
                                $fail_count++;
                            }
                        }
                    }
                    else {
                        $fail_count++;
                    }
                    usleep(100000);
                }
                sendMessage($chat_id, "✅ عملیات با موفقیت انجام شد.\nحجم <b>{$data_to_add_gb} گیگابایت</b> به <b>{$success_count}</b> سرویس اضافه گردید.\nتعداد ناموفق: {$fail_count}");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_bulk_time_amount':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا تعداد روز معتبر (عدد مثبت) را وارد کنید.", $cancelKeyboard);
                    break;
                }
                sendMessage($chat_id, "⏳ عملیات افزودن زمان به تمام سرویس‌ها شروع شد. این فرآیند ممکن است کمی طول بکشد...");
                $days_to_add = (int)$text;
                $seconds_to_add = $days_to_add * 86400;
                $all_services = pdo()
                    ->query("SELECT marzban_username, server_id FROM services")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $success_count = 0;
                $fail_count = 0;
                foreach ($all_services as $service) {
                    $username = $service['marzban_username'];
                    $server_id = $service['server_id'];
                    if (!$server_id) {
                        $fail_count++;
                        continue;
                    }

                    $current_user_data = getPanelUser($username, $server_id);
                    if ($current_user_data && !isset($current_user_data['detail'])) {
                        $current_expire = $current_user_data['expire'] ?? 0;
                        if ($current_expire > 0) {
                            $new_expire = $current_expire < time() ? time() + $seconds_to_add : $current_expire + $seconds_to_add;
                            $result = modifyPanelUser($username, $server_id, ['expire' => $new_expire]);
                            if ($result && !isset($result['detail'])) {
                                $success_count++;
                            }
                            else {
                                $fail_count++;
                            }
                        }
                    }
                    else {
                        $fail_count++;
                    }
                    usleep(100000);
                }
                sendMessage($chat_id, "✅ عملیات با موفقیت انجام شد.\nمدت <b>{$days_to_add} روز</b> به <b>{$success_count}</b> سرویس اضافه گردید.\nتعداد ناموفق: {$fail_count}");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_new_admin_id':
                if ($chat_id != ADMIN_CHAT_ID) {
                    break;
                }
                if (!is_numeric($text)) {
                    sendMessage($chat_id, "❌ شناسه وارد شده نامعتبر است. لطفا فقط عدد وارد کنید.", $cancelKeyboard);
                    break;
                }
                $target_id = (int)$text;
                if ($target_id == ADMIN_CHAT_ID) {
                    sendMessage($chat_id, "❌ شما نمی‌توانید خودتان را به عنوان ادمین اضافه کنید.", $cancelKeyboard);
                    break;
                }
                $admins = getAdmins();
                if (isset($admins[$target_id])) {
                    sendMessage($chat_id, "❌ این کاربر در حال حاضر ادمین است.", $cancelKeyboard);
                    break;
                }
                $stmt_check_user = pdo()->prepare("SELECT COUNT(*) FROM users WHERE chat_id = ?");
                $stmt_check_user->execute([$target_id]);
                if ($stmt_check_user->fetchColumn() == 0) {
                    sendMessage($chat_id, "❌ کاربری با این شناسه یافت نشد. این کاربر باید حداقل یک بار ربات را استارت کرده باشد.", $cancelKeyboard);
                    break;
                }
                $response = apiRequest('getChat', ['chat_id' => $target_id]);
                $chat_info = json_decode($response, true);
                $target_first_name = "کاربر {$target_id}";
                if ($chat_info['ok'] && isset($chat_info['result']['first_name'])) {
                    $target_first_name = $chat_info['result']['first_name'];
                }
                else {
                    sendMessage($chat_id, "⚠️ نتوانستم نام کاربر را از تلگرام دریافت کنم. با نام پیش‌فرض ثبت شد.");
                }
                addAdmin($target_id, $target_first_name);
                sendMessage($chat_id, "✅ کاربر <code>$target_id</code> (" . htmlspecialchars($target_first_name) . ") با موفقیت به لیست ادمین‌ها اضافه شد. حالا دسترسی‌های او را مشخص کنید.");
                sendMessage($target_id, "🎉 تبریک! شما توسط ادمین اصلی به عنوان ادمین ربات انتخاب شدید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                showAdminManagementMenu($chat_id);
                break;

            case 'admin_awaiting_discount_code':
                updateUserData($chat_id, 'admin_awaiting_discount_type', ['new_discount_code' => $text]);
                $keyboard = ['inline_keyboard' => [[['text' => 'درصدی ٪', 'callback_data' => 'discount_type_percent']], [['text' => 'مبلغ ثابت (تومان)', 'callback_data' => 'discount_type_amount']]]];
                sendMessage($chat_id, "2/4 - نوع تخفیف را مشخص کنید:", $keyboard);
                break;

            case 'admin_awaiting_discount_value':
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً فقط یک عدد مثبت وارد کنید.");
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['new_discount_value'] = (int)$text;
                updateUserData($chat_id, 'admin_awaiting_discount_usage', $state_data);
                sendMessage($chat_id, "4/4 - حداکثر تعداد استفاده از این کد را وارد کنید (فقط عدد):", $cancelKeyboard);
                break;

            case 'admin_awaiting_discount_usage':
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفاً فقط یک عدد مثبت وارد کنید.");
                    break;
                }
                $discount_data = $user_data['state_data'];
                $stmt = pdo()->prepare("INSERT INTO discount_codes (code, type, value, max_usage) VALUES (?, ?, ?, ?)");
                $stmt->execute([$discount_data['new_discount_code'], $discount_data['new_discount_type'], $discount_data['new_discount_value'], (int)$text]);
                sendMessage($chat_id, "✅ کد تخفیف `{$discount_data['new_discount_code']}` با موفقیت ایجاد شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $current_first_name = $update['message']['from']['first_name'];
                handleMainMenu($chat_id, $current_first_name);
                break;

                case 'user_awaiting_discount_code':
                $code = strtoupper(trim($text));
                $category_id = $user_data['state_data']['target_category_id'];
                $server_id = $user_data['state_data']['target_server_id'];
                
                $stmt = pdo()->prepare("SELECT * FROM discount_codes WHERE code = ? AND status = 'active' AND usage_count < max_usage");
                $stmt->execute([$code]);
                $discount = $stmt->fetch();
                if (!$discount) {
                    sendMessage($chat_id, "❌ کد تخفیف وارد شده نامعتبر یا منقضی شده است.");
         
                    showPlansForCategoryAndServer($chat_id, $category_id, $server_id); 
                    updateUserData($chat_id, 'main_menu');
                    break;
                }

              
                $plan_stmt = pdo()->prepare("SELECT * FROM plans WHERE category_id = ? AND server_id = ? AND status = 'active' AND is_test_plan = 0");
                $plan_stmt->execute([$category_id, $server_id]);
                $active_plans = $plan_stmt->fetchAll(PDO::FETCH_ASSOC);

                $user_balance = $user_data['balance'] ?? 0;
                $message = "✅ کد تخفیف `{$code}` با موفقیت اعمال شد!\n\n";
                $message .= "🛍️ <b>پلن‌ها با قیمت جدید:</b>\nموجودی شما: " . number_format($user_balance) . " تومان\n\n";
                $keyboard_buttons = [];
                foreach ($active_plans as $plan) {
                    $original_price = $plan['price'];
                    $discounted_price = 0;
                    if ($discount['type'] == 'percent') {
                        $discounted_price = $original_price - ($original_price * $discount['value']) / 100;
                    }
                    else {
                        $discounted_price = $original_price - $discount['value'];
                    }
                    $discounted_price = max(0, $discounted_price);
                    $button_text = "{$plan['name']} | " . number_format($original_price) . " ⬅️ " . number_format($discounted_price) . " تومان";
                    $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => "buy_plan_{$plan['id']}_with_code_{$code}"]];
                }
             
                $keyboard_buttons[] = [['text' => '◀️ بازگشت', 'callback_data' => "show_plans_cat_{$category_id}_srv_{$server_id}"]];
                sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
                updateUserData($chat_id, 'main_menu');
                break;

            case 'admin_awaiting_bulk_balance_amount':
                if (!hasPermission($chat_id, 'manage_users')) {
                    break;
                }
                if (!is_numeric($text) || $text <= 0) {
                    sendMessage($chat_id, "❌ لطفا یک مبلغ معتبر (عدد مثبت) به تومان وارد کنید.", $cancelKeyboard);
                    break;
                }
                $amount_to_add = (int)$text;
                sendMessage($chat_id, "⏳ عملیات افزایش موجودی همگانی شروع شد...");
                $updated_users_count = increaseAllUsersBalance($amount_to_add);
                sendMessage($chat_id, "✅ عملیات با موفقیت انجام شد.\nمبلغ <b>" . number_format($amount_to_add) . " تومان</b> به موجودی <b>{$updated_users_count}</b> کاربر فعال اضافه گردید.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_guide_button_name':
                if (!hasPermission($chat_id, 'manage_guides')) {
                    break;
                }
                updateUserData($chat_id, 'admin_awaiting_guide_content', ['new_guide_button_name' => $text]);
                sendMessage($chat_id, "2/3 - عالی! حالا محتوای راهنما را ارسال کنید.\n\nمی‌توانید یک <b>متن خالی</b> یا یک <b>عکس همراه با کپشن</b> ارسال کنید.", $cancelKeyboard);
                break;

            case 'admin_awaiting_guide_content':
                if (!hasPermission($chat_id, 'manage_guides')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                if (isset($update['message']['photo'])) {
                    $state_data['new_guide_content_type'] = 'photo';
                    $state_data['new_guide_photo_id'] = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
                    $state_data['new_guide_message_text'] = $update['message']['caption'] ?? '';
                }
                else {
                    $state_data['new_guide_content_type'] = 'text';
                    $state_data['new_guide_photo_id'] = null;
                    $state_data['new_guide_message_text'] = $text;
                }
                updateUserData($chat_id, 'admin_awaiting_guide_inline_buttons', $state_data);
                $msg =
                    "3/3 - محتوا ذخیره شد. در صورت تمایل، دکمه‌های شیشه‌ای (لینک) را برای نمایش زیر پیام وارد کنید.\n\n<b>فرمت ارسال:</b>\nهر دکمه در یک خط جداگانه به شکل زیر:\n<code>متن دکمه - https://example.com</code>\n\nمثال:\n<code>کانال تلگرام - https://t.me/channel\nسایت ما - https://google.com</code>\n\nاگر نمی‌خواهید دکمه‌ای داشته باشید، کلمه `رد شدن` را ارسال کنید.";
                $keyboard = ['keyboard' => [[['text' => 'رد شدن']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, $msg, $keyboard);
                break;

            case 'admin_awaiting_test_limit':
                if (!hasPermission($chat_id, 'manage_test_config')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا یک عدد صحیح و مثبت (حداقل ۱) وارد کنید.", $cancelKeyboard);
                    break;
                }
                $settings = getSettings();
                $settings['test_config_usage_limit'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ تعداد مجاز برای هر کاربر روی <b>{$text}</b> بار تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_guide_inline_buttons':
                if (!hasPermission($chat_id, 'manage_guides')) {
                    break;
                }
                $state_data = $user_data['state_data'];
                $inline_keyboard = null;

                if ($text !== 'رد شدن') {
                    $lines = explode("\n", $text);
                    $buttons = [];
                    foreach ($lines as $line) {
                        $parts = explode(' - ', trim($line), 2);
                        if (count($parts) === 2 && filter_var(trim($parts[1]), FILTER_VALIDATE_URL)) {
                            $buttons[] = [['text' => trim($parts[0]), 'url' => trim($parts[1])]];
                        }
                    }
                    if (!empty($buttons)) {
                        $inline_keyboard = json_encode(['inline_keyboard' => $buttons]);
                    }
                }

                $stmt = pdo()->prepare("INSERT INTO guides (button_name, content_type, message_text, photo_id, inline_keyboard) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$state_data['new_guide_button_name'], $state_data['new_guide_content_type'], $state_data['new_guide_message_text'], $state_data['new_guide_photo_id'], $inline_keyboard]);

                sendMessage($chat_id, "✅ راهنمای جدید با موفقیت ایجاد شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name);
                break;

            case 'admin_awaiting_expire_days':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و مثبت وارد کنید.");
                    break;
                }
                $settings = getSettings();
                $settings['notification_expire_days'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ با موفقیت روی <b>{$text}</b> روز تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_expire_warning';
                break;

            case 'admin_awaiting_expire_gb':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و مثبت وارد کنید.");
                    break;
                }
                $settings = getSettings();
                $settings['notification_expire_gb'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ با موفقیت روی <b>{$text}</b> گیگابایت تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_expire_warning';
                break;

            case 'admin_awaiting_expire_message':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                $settings = getSettings();
                $settings['notification_expire_message'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ متن پیام هشدار انقضا با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_expire_warning';
                break;

            case 'admin_awaiting_inactive_days':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                if (!is_numeric($text) || $text < 1) {
                    sendMessage($chat_id, "❌ لطفا فقط عدد صحیح و مثبت وارد کنید.");
                    break;
                }
                $settings = getSettings();
                $settings['notification_inactive_days'] = (int)$text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ با موفقیت روی <b>{$text}</b> روز تنظیم شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_inactive_reminder';
                break;

            case 'admin_awaiting_inactive_message':
                if (!hasPermission($chat_id, 'manage_notifications')) {
                    break;
                }
                $settings = getSettings();
                $settings['notification_inactive_message'] = $text;
                saveSettings($settings);
                sendMessage($chat_id, "✅ متن پیام یادآور با موفقیت ذخیره شد.");
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                $data = 'config_inactive_reminder';
                break;
                
            case 'user_awaiting_renewal_days':
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح (مثبت یا صفر) وارد کنید.");
                    break;
                }
                $state_data = $user_data['state_data'];
                $state_data['renewal_days'] = (int)$text;
                updateUserData($chat_id, 'user_awaiting_renewal_gb', $state_data);

                $settings = getSettings();
                $price_gb = number_format($settings['renewal_price_per_gb'] ?? 2000);
                $message = "<b>تمدید سرویس</b>\n\n" .
                           "۲. چند **گیگابایت** به حجم سرویس شما اضافه شود؟\n\n" .
                           "▫️ هزینه هر گیگ: {$price_gb} تومان\n" .
                           "💡 برای رد شدن و عدم تمدید حجم، عدد `0` را وارد کنید.";
                sendMessage($chat_id, $message);
                break;

            case 'user_awaiting_renewal_gb':
                if (!is_numeric($text) || $text < 0) {
                    sendMessage($chat_id, "❌ لطفا فقط یک عدد صحیح (مثبت یا صفر) وارد کنید.");
                    break;
                }
                $state_data = $user_data['state_data'];
                $days_to_add = $state_data['renewal_days'];
                $gb_to_add = (int)$text;
                
                if ($days_to_add == 0 && $gb_to_add == 0) {
                    sendMessage($chat_id, "شما هیچ مقداری برای تمدید وارد نکردید. عملیات لغو شد.");
                    updateUserData($chat_id, 'main_menu');
                    handleMainMenu($chat_id, $first_name);
                    break;
                }
                
                $settings = getSettings();
                $cost_days = $days_to_add * (int)($settings['renewal_price_per_day'] ?? 1000);
                $cost_gb = $gb_to_add * (int)($settings['renewal_price_per_gb'] ?? 2000);
                $total_cost = $cost_days + $cost_gb;

                $state_data['renewal_gb'] = $gb_to_add;
                $state_data['renewal_total_cost'] = $total_cost;
                updateUserData($chat_id, 'user_confirming_renewal', $state_data);

                $summary = "<b>خلاصه درخواست تمدید شما:</b>\n\n" .
                           "▫️ افزایش زمان: <b>{$days_to_add} روز</b>\n" .
                           "▫️ افزایش حجم: <b>{$gb_to_add} گیگابایت</b>\n\n" .
                           "💰 هزینه کل: <b>" . number_format($total_cost) . " تومان</b>\n\n" .
                           "موجودی فعلی شما: " . number_format($user_data['balance']) . " تومان\n\n" .
                           "آیا تایید می‌کنید؟";

                $keyboard = ['inline_keyboard' => [[['text' => '✅ بله، پرداخت کن', 'callback_data' => 'confirm_renewal_payment']]]];
                sendMessage($chat_id, $summary, $keyboard);
                break;
            
            case 'awaiting_renewal_screenshot':
                if (isset($update['message']['photo'])) {
                    $state_data = $user_data['state_data'];
                    $photo_id = $update['message']['photo'][count($update['message']['photo']) - 1]['file_id'];
                    
                    $stmt = pdo()->prepare("UPDATE renewal_requests SET photo_file_id = ? WHERE id = ?");
                    $stmt->execute([$photo_id, $state_data['renewal_request_id']]);
                    

                    $request_id = $state_data['renewal_request_id'];
                    $caption = "<b>درخواست تمدید سرویس جدید</b>\n\n" .
                               "👤 کاربر: " . htmlspecialchars($first_name) . " (<code>{$chat_id}</code>)\n" .
                               "▫️ سرویس: <code>{$state_data['renewal_username']}</code>\n" .
                               "⏰ تمدید زمان: {$state_data['renewal_days']} روز\n" .
                               "📊 تمدید حجم: {$state_data['renewal_gb']} گیگ\n" .
                               "💰 هزینه: " . number_format($state_data['renewal_total_cost']) . " تومان\n" .
                               "▫️ شماره درخواست: #R-{$request_id}";
                    
                    $keyboard = ['inline_keyboard' => [[
                        ['text' => '✅ تایید تمدید', 'callback_data' => "approve_renewal_{$request_id}"],
                        ['text' => '❌ رد تمدید', 'callback_data' => "reject_renewal_{$request_id}"]
                    ]]];

                    $all_admins = getAdmins();
                    $all_admins[ADMIN_CHAT_ID] = [];
                    foreach (array_keys($all_admins) as $admin_id) {
                        if (hasPermission($admin_id, 'manage_payment')) {
                           sendPhoto($admin_id, $photo_id, $caption, $keyboard);
                        }
                    }

                    sendMessage($chat_id, "✅ رسید شما برای ادمین ارسال شد. پس از بررسی، سرویس شما تمدید خواهد شد.");
                    updateUserData($chat_id, 'main_menu');
                    handleMainMenu($chat_id, $first_name);
                }
                break;
        }
        die;
    }

    switch ($text) {
        case '🛒 خرید سرویس':
            if ($settings['sales_status'] === 'off') {
                sendMessage($chat_id, "🛍 بخش فروش موقتا توسط مدیر غیرفعال شده است.");
                break;
            }
            $categories = getCategories(true);
            if (empty($categories)) {
                sendMessage($chat_id, "متاسفانه در حال حاضر هیچ سرویسی برای فروش موجود نیست.");
            }
            else {
                $keyboard_buttons = [];
                foreach ($categories as $category) {
                    $keyboard_buttons[] = [['text' => '🛍 ' . $category['name'], 'callback_data' => 'cat_' . $category['id']]];
                }
                sendMessage($chat_id, "لطفا یکی از دسته‌بندی‌های زیر را انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '🛠 کانفیگ دلخواه':
            handleCustomConfigStart($chat_id);
            break;

        case '👑 ورود به پنل مدیریت':
            if ($isAnAdmin) {
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'admin']);
                handleMainMenu($chat_id, $first_name, true);
            }
            break;
            
        case '🤝 زیرمجموعه‌گیری':
            showReferralMenu($chat_id);
            break;

        case '↩️ بازگشت به منوی کاربری':
            if ($isAnAdmin) {
                updateUserData($chat_id, 'main_menu', ['admin_view' => 'user']);
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '🗂 مدیریت دسته‌بندی‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_categories')) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن دسته‌بندی']], [['text' => '📋 لیست دسته‌بندی‌ها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "گزینه مورد نظر را برای مدیریت دسته‌بندی‌ها انتخاب کنید:", $keyboard);
            }
            break;
            
        case '🤝 مدیریت زیرمجموعه‌گیری':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_referrals')) {
                showReferralManagementMenu($chat_id);
            }
            break;

        case '➕ افزودن دسته‌بندی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_categories')) {
                updateUserData($chat_id, 'admin_awaiting_category_name', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا نام دسته‌بندی جدید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '📋 لیست دسته‌بندی‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_categories')) {
                generateCategoryList($chat_id);
            }
            break;

        case '📝 مدیریت پلن‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_plans')) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن پلن']], [['text' => '📋 لیست پلن‌ها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "گزینه مورد نظر را برای مدیریت پلن‌ها انتخاب کنید:", $keyboard);
            }
            break;

        case '➕ افزودن پلن':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_plans')) {
                $categories = getCategories();
                if (empty($categories)) {
                    sendMessage($chat_id, "❌ ابتدا باید حداقل یک دسته‌بندی ایجاد کنید!");
                    break;
                }
                $keyboard_buttons = [];
                foreach ($categories as $category) {
                    $keyboard_buttons[] = [['text' => $category['name'], 'callback_data' => 'p_cat_' . $category['id']]];
                }
                sendMessage($chat_id, "این پلن را به کدام دسته‌بندی می‌خواهید اضافه کنید؟", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '📋 لیست پلن‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_plans')) {
                generatePlanList($chat_id);
            }
            break;

        case '📋 لیست کدهای تخفیف':
            if ($isAnAdmin) {
                generateDiscountCodeList($chat_id);
            }
            break;

        case '👥 مدیریت کاربران':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                $keyboard = [
                    'keyboard' => [
                        [['text' => '🔎 جستجوی کاربر (مدیریت فردی)']],
                        [['text' => '➕ افزودن حجم همگانی'], ['text' => '➕ افزودن زمان همگانی']],
                        [['text' => '💰 افزایش موجودی همگانی']],
                        [['text' => '◀️ بازگشت به منوی اصلی']],
                    ],
                    'resize_keyboard' => true,
                ];
                sendMessage($chat_id, "لطفاً نوع عملیات مدیریت کاربران را انتخاب کنید:", $keyboard);
            }
            break;

        case '➕ افزایش موجودی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_add_balance', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید موجودی‌اش را افزایش دهید، وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➖ کاهش موجودی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_deduct_balance', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید از موجودی‌اش کسر کنید، وارد کنید:", $cancelKeyboard);
            }
            break;

        case '💰 افزایش موجودی همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_balance_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی تمام کاربران فعال اضافه شود را به تومان وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن حجم همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_data_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مقدار حجمی که می‌خواهید به تمام سرویس‌ها اضافه شود را به گیگابایت (GB) وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن زمان همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_time_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا تعداد روزی که می‌خواهید به تمام سرویس‌ها اضافه شود را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '✉️ ارسال پیام به کاربر':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_message', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید به او پیام دهید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '🚫 مسدود کردن کاربر':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_ban', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید مسدود کنید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '✅ آزاد کردن کاربر':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_id_for_unban', ['admin_view' => 'admin']);
                sendMessage($chat_id, "شناسه عددی کاربری که می‌خواهید از مسدودیت خارج کنید را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '🔎 جستجوی کاربر (مدیریت فردی)':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_user_search', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفاً شناسه عددی (Chat ID) کاربر مورد نظر را برای جستجو وارد کنید:", $cancelKeyboard);
            }
            break;
            
        case '💰 افزایش موجودی همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_balance_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مبلغی که می‌خواهید به موجودی تمام کاربران فعال اضافه شود را به تومان وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن حجم همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_data_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مقدار حجمی که می‌خواهید به تمام سرویس‌ها اضافه شود را به گیگابایت (GB) وارد کنید:", $cancelKeyboard);
            }
            break;

        case '➕ افزودن زمان همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_users')) {
                updateUserData($chat_id, 'admin_awaiting_bulk_time_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا تعداد روزی که می‌خواهید به تمام سرویس‌ها اضافه شود را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '📣 ارسال همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'broadcast')) {
                $keyboard = ['keyboard' => [[['text' => '✍️ ارسال پیام همگانی'], ['text' => '▶️ فروارد همگانی']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "نوع ارسال همگانی را انتخاب کنید:", $keyboard);
            }
            break;

        case '✍️ ارسال پیام همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'broadcast')) {
                updateUserData($chat_id, 'admin_awaiting_broadcast_message', ['admin_view' => 'admin']);
                sendMessage($chat_id, "پیامی که می‌خواهید به تمام کاربران ارسال شود را وارد کنید:", $cancelKeyboard);
            }
            break;

        case '▶️ فروارد همگانی':
            if ($isAnAdmin && hasPermission($chat_id, 'broadcast')) {
                updateUserData($chat_id, 'admin_awaiting_forward_message', ['admin_view' => 'admin']);
                sendMessage($chat_id, "پیامی که می‌خواهید به تمام کاربران فروارد شود را به همینجا فروارد کنید:", $cancelKeyboard);
            }
            break;

        case '⚙️ تنظیمات کلی ربات':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $bot_status_text = $settings['bot_status'] == 'on' ? '🔴 خاموش کردن ربات' : '🟢 روشن کردن ربات';
                $inline_keyboard_text = $settings['inline_keyboard'] == 'on' ? '🔴 غیرفعال کردن کیبورد شیشه ای' : '🟢 فعال کردن کیبورد شیشه ای';
                $sales_status_text = $settings['sales_status'] == 'on' ? '🔴 خاموش کردن فروش' : '🟢 روشن کردن فروش';
                $join_status_text = $settings['join_channel_status'] == 'on' ? '🔴 غیرفعال کردن جوین' : '🟢 فعال کردن جوین';
                $message = "<b>⚙️ تنظیمات کلی ربات:</b>";
                $keyboard = [
                    'keyboard' => [
                        [['text' => $bot_status_text]],
                        [['text' => $inline_keyboard_text]],
                        [['text' => $sales_status_text]],
                        [['text' => $join_status_text], ['text' => '📢 تنظیم کانال جوین']],
                        [['text' => '🎁 تنظیم هدیه عضویت']],
                        [['text' => '◀️ بازگشت به منوی اصلی']],
                    ],
                    'resize_keyboard' => true,
                ];
                sendMessage($chat_id, $message, $keyboard);
            }
            break;

        case '🎁 تنظیم هدیه عضویت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                updateUserData($chat_id, 'admin_awaiting_welcome_gift_amount', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا مبلغ هدیه برای کاربران جدید را به تومان وارد کنید (برای غیرفعال کردن عدد 0 را وارد کنید):", $cancelKeyboard);
            }
            break;

        case '🔴 غیرفعال کردن کیبورد شیشه ای':
        case '🟢 فعال کردن کیبورد شیشه ای':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['inline_keyboard'] = $settings['inline_keyboard'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت کیبورد ربات با موفقیت تغییر کرد.\nمجدد /start کنید.");
            }
            break;

        case '🔴 خاموش کردن ربات':
        case '🟢 روشن کردن ربات':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['bot_status'] = $settings['bot_status'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت کلی ربات با موفقیت تغییر کرد.");
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '🔴 خاموش کردن فروش':
        case '🟢 روشن کردن فروش':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['sales_status'] = $settings['sales_status'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت فروش با موفقیت تغییر کرد.");
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '🔴 غیرفعال کردن جوین':
        case '🟢 فعال کردن جوین':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                $settings['join_channel_status'] = $settings['join_channel_status'] == 'on' ? 'off' : 'on';
                saveSettings($settings);
                sendMessage($chat_id, "✅ وضعیت عضویت اجباری با موفقیت تغییر کرد.");
                handleMainMenu($chat_id, $first_name);
            }
            break;

        case '📢 تنظیم کانال جوین':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_settings')) {
                updateUserData($chat_id, 'admin_awaiting_join_channel_id', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا شناسه کانال مورد نظر را به همراه @ وارد کنید (مثال: @YourChannel)\n\n<b>توجه:</b> ربات باید در کانال ادمین باشد.", $cancelKeyboard);
            }
            break;

        case '🌐 مدیریت سرورها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_marzban')) {
                $servers = pdo()
                    ->query("SELECT id, name FROM servers")
                    ->fetchAll(PDO::FETCH_ASSOC);
                $keyboard_buttons = [[['text' => '➕ افزودن سرور جدید', 'callback_data' => 'add_server_select_type']]];
                foreach ($servers as $server) {
                    $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "view_server_{$server['id']}"]];
                }
                sendMessage($chat_id, "<b>🌐 مدیریت سرورها</b>\n\nسرور مورد نظر را برای مشاهده یا حذف انتخاب کنید، یا یک سرور جدید اضافه کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '💳 مدیریت پرداخت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_payment')) {
                updateUserData($chat_id, 'admin_awaiting_card_number', ['admin_view' => 'admin']);
                sendMessage($chat_id, "مرحله ۱/۳: شماره کارت ۱۶ رقمی را وارد کنید:", $cancelKeyboard);
            }
            break;
        
        case '💳 مدیریت درگاه پرداخت':
        if ($isAnAdmin && hasPermission($chat_id, 'manage_payment')) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🔵 مدیریت زرین‌پال', 'callback_data' => 'manage_gateway_zarinpal']],
                    [['text' => '🟣 مدیریت تترا 98', 'callback_data' => 'manage_gateway_tetra']],
                    [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
                ]
            ];
            sendMessage($chat_id, "<b>💳 مدیریت درگاه‌های پرداخت</b>\n\nلطفا درگاه مورد نظر برای مدیریت را انتخاب کنید:", $keyboard);
        }
        break;
        
        case '📊 آمار کلی':
            if ($isAnAdmin && hasPermission($chat_id, 'view_stats')) {
                $total_users = pdo()
                    ->query("SELECT COUNT(*) FROM users")
                    ->fetchColumn();
                $banned_users = pdo()
                    ->query("SELECT COUNT(*) FROM users WHERE status = 'banned'")
                    ->fetchColumn();
                $active_users = $total_users - $banned_users;
                $total_services = pdo()
                    ->query("SELECT COUNT(*) FROM services")
                    ->fetchColumn();
                $total_tickets = pdo()
                    ->query("SELECT COUNT(*) FROM tickets")
                    ->fetchColumn();
                $stats_message =
                    "<b>📊 آمار کلی ربات</b>\n\n" .
                    "👥 <b>آمار کاربران:</b>\n" .
                    "▫️ کل کاربران: <b>{$total_users}</b> نفر\n" .
                    "▫️ کاربران فعال: <b>{$active_users}</b> نفر\n" .
                    "▫️ کاربران مسدود: <b>{$banned_users}</b> نفر\n\n" .
                    "🛍 <b>آمار فروش و پشتیبانی:</b>\n" .
                    "▫️ کل سرویس‌های فروخته شده: <b>{$total_services}</b> عدد\n" .
                    "▫️ کل تیکت‌های پشتیبانی: <b>{$total_tickets}</b> عدد";
                sendMessage($chat_id, $stats_message);
            }
            break;

        case '💰 آمار درآمد':
            if ($isAnAdmin && hasPermission($chat_id, 'view_stats')) {
                $income_stats = calculateIncomeStats();
                $income_message =
                    "<b>💰 آمار درآمد ربات</b>\n\n" .
                    "▫️ درآمد امروز: <b>" .
                    number_format($income_stats['today']) .
                    "</b> تومان\n" .
                    "▫️ درآمد این هفته: <b>" .
                    number_format($income_stats['week']) .
                    "</b> تومان\n" .
                    "▫️ درآمد این ماه: <b>" .
                    number_format($income_stats['month']) .
                    "</b> تومان\n" .
                    "▫️ درآمد امسال: <b>" .
                    number_format($income_stats['year']) .
                    "</b> تومان";
                sendMessage($chat_id, $income_message);
            }
            break;

        case '👨‍💼 مدیریت ادمین‌ها':
            if ($chat_id == ADMIN_CHAT_ID) {
                showAdminManagementMenu($chat_id);
            }
            break;

        case '🎁 مدیریت کد تخفیف':
            if ($isAnAdmin) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن کد تخفیف']], [['text' => '📋 لیست کدهای تخفیف']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "🎁 بخش مدیریت کدهای تخفیف:", $keyboard);
            }
            break;
        
        case '🔄 مدیریت تمدید':
            if ($isAnAdmin) {
                showRenewalManagementMenu($chat_id);
            }
            break;
            
        case 'تعیین قیمت دلخواه':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_custom_config')) {
                showCustomConfigManagementMenu($chat_id);
            }
            break;

        case '➕ افزودن کد تخفیف':
            if ($isAnAdmin) {
                updateUserData($chat_id, 'admin_awaiting_discount_code', ['admin_view' => 'admin']);
                sendMessage($chat_id, "1/4 - لطفاً کد تخفیف را وارد کنید (مثال: EID1404):", $cancelKeyboard);
            }
            break;

        case '📚 مدیریت راهنما':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_guides')) {
                $keyboard = ['keyboard' => [[['text' => '➕ افزودن راهنمای جدید']], [['text' => '📋 لیست راهنماها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, "بخش مدیریت راهنما:", $keyboard);
            }
            break;

        case '➕ افزودن راهنمای جدید':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_guides')) {
                updateUserData($chat_id, 'admin_awaiting_guide_button_name', ['admin_view' => 'admin']);
                sendMessage($chat_id, "1/3 - لطفاً نام راهنما را وارد کنید (این نام روی دکمه شیشه‌ای به کاربر نمایش داده می‌شود):", $cancelKeyboard);
            }
            break;

        case '📋 لیست راهنماها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_guides')) {
                generateGuideList($chat_id);
            }
            break;

        case '👤 حساب کاربری':
            $balance = $user_data['balance'] ?? 0;
            $services = getUserServices($chat_id);
            $total_services = count($services);
            $active_services_count = 0;
            $expired_services_count = 0;
            $now = time();
            foreach ($services as $service) {
                if ($service['expire_timestamp'] < $now) {
                    $expired_services_count++;
                }
                else {
                    $active_services_count++;
                }
            }
            $account_info = "<b>اطلاعات حساب کاربری شما </b> 👤\n\n";
            $account_info .= "▫️ نام: " . htmlspecialchars($first_name) . "\n";
            $account_info .= "▫️ شناسه کاربری: <code>" . $chat_id . "</code>\n";
            $account_info .= "💰 موجودی حساب: <b>" . number_format($balance) . " تومان</b>\n\n";
            $account_info .= "<b>آمار سرویس‌های شما:</b>\n";
            $account_info .= "▫️ کل سرویس‌های خریداری شده: <b>" . $total_services . "</b> عدد\n";
            $account_info .= "▫️ سرویس‌های فعال: <b>" . $active_services_count . "</b> عدد\n";
            $account_info .= "▫️ سرویس‌های منقضی شده: <b>" . $expired_services_count . "</b> عدد";
            sendMessage($chat_id, $account_info);
            break;

        case '💳 شارژ حساب':
            updateUserData($chat_id, 'awaiting_charge_amount');
            sendMessage($chat_id, "لطفا مبلغی که قصد دارید حساب خود را شارژ کنید به تومان وارد نمایید:", $cancelKeyboard);
            break;

        case '🔧 سرویس‌های من':
            $services = getUserServices($chat_id);
            if (empty($services)) {
                sendMessage($chat_id, "شما هیچ سرویس فعالی ندارید.");
            }
            else {
                $keyboard_buttons = [];
                $now = time();
                foreach ($services as $service) {
                    $expire_date = date('Y-m-d', $service['expire_timestamp']);
                    $status_icon = $service['expire_timestamp'] < $now ? '❌' : '✅';
                    $button_text = "{$status_icon} {$service['plan_name']} (انقضا: {$expire_date})";
                    $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => 'service_details_' . $service['marzban_username']]];
                }
                sendMessage($chat_id, "سرویس مورد نظر خود را برای مشاهده جزئیات انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
            }
            break;

        case '📨 پشتیبانی':
            updateUserData($chat_id, 'awaiting_ticket_subject');
            sendMessage($chat_id, "لطفا موضوع تیکت پشتیبانی خود را به صورت خلاصه وارد کنید:", $cancelKeyboard);
            break;

        case '📚 راهنما':
            showGuideSelectionMenu($chat_id);
            break;

        case '🧪 دریافت کانفیگ تست':
            $test_plan = getTestPlan();
            if (!$test_plan) {
                sendMessage($chat_id, "❌ دریافت کانفیگ تست در حال حاضر توسط مدیر غیرفعال شده است.");
                break;
            }

            $settings = getSettings();
            $usage_limit = (int)($settings['test_config_usage_limit'] ?? 1);

            if ($user_data['test_config_count'] >= $usage_limit) {
                sendMessage($chat_id, "❌ شما قبلا از حداکثر تعداد کانفیگ تست خود استفاده کرده‌اید.");
                break;
            }

            $message =
                "<b>🧪 مشخصات کانفیگ تست رایگان</b>\n\n" .
                "▫️ نام پلن: <b>{$test_plan['name']}</b>\n" .
                "▫️ حجم: <b>{$test_plan['volume_gb']} GB</b>\n" .
                "▫️ مدت اعتبار: <b>{$test_plan['duration_days']} روز</b>\n\n" .
                "برای دریافت این کانفیگ رایگان، روی دکمه زیر کلیک کنید.";
            $keyboard = ['inline_keyboard' => [[['text' => '✅ دریافت تست رایگان', 'callback_data' => 'buy_plan_' . $test_plan['id']]]]];
            sendMessage($chat_id, $message, $keyboard);
            break;

        case '🧪 مدیریت کانفیگ تست':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_test_config')) {
                $settings = getSettings();
                $usage_limit = $settings['test_config_usage_limit'] ?? 1;
                $message =
                    "<b>🧪 مدیریت کانفیگ تست</b>\n\n" .
                    "در این بخش می‌توانید تعداد دفعاتی که هر کاربر می‌تواند پلن تست را دریافت کند، مدیریت نمایید.\n\n" .
                    "▫️ تعداد مجاز فعلی: <b>{$usage_limit}</b> بار\n\n" .
                    "<b>نکته:</b> برای تعریف پلن تست، حجم و زمان آن، از بخش «مدیریت پلن‌ها» اقدام کنید.";
                $keyboard = ['keyboard' => [[['text' => '🔢 تنظیم تعداد مجاز'], ['text' => '🔄 ریست کردن دریافت‌ها']], [['text' => '◀️ بازگشت به منوی اصلی']]], 'resize_keyboard' => true];
                sendMessage($chat_id, $message, $keyboard);
            }
            break;

        case '🔢 تنظیم تعداد مجاز':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_test_config')) {
                updateUserData($chat_id, 'admin_awaiting_test_limit', ['admin_view' => 'admin']);
                sendMessage($chat_id, "لطفا حداکثر تعداد دفعاتی که هر کاربر می‌تواند کانفیگ تست بگیرد را وارد کنید (فقط عدد):", $cancelKeyboard);
            }
            break;

        case '🔄 ریست کردن دریافت‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_test_config')) {
                $count = resetAllUsersTestCount();
                sendMessage($chat_id, "✅ شمارنده دریافت تست برای <b>{$count}</b> کاربر با موفقیت ریست شد. اکنون همه می‌توانند دوباره تست دریافت کنند.");
            }
            break;

        case '📢 مدیریت اعلان‌ها':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_notifications')) {
                $keyboard = ['inline_keyboard' => [[['text' => '🔔 اعلان‌های کاربران', 'callback_data' => 'user_notifications_menu']], [['text' => '👨‍💼 اعلان‌های مدیران (به زودی)', 'callback_data' => 'admin_notifications_soon']]]];
                sendMessage($chat_id, "کدام دسته از اعلان‌ها را می‌خواهید مدیریت کنید؟", $keyboard);
            }
            break;

        case '🔐 مدیریت احراز هویت':
            if ($isAnAdmin && hasPermission($chat_id, 'manage_verification')) {
                showVerificationManagementMenu($chat_id);
            }
            break;

        default:
            if ($user_state === 'main_menu' && !$apiRequest) {
                sendMessage($chat_id, "دستور شما را متوجه نشدم. لطفا از دکمه‌های موجود استفاده کنید.");
            }
            break;
    }
}
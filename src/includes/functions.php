<?php

if (!defined('BOT_USERNAME')) {
    define('BOT_USERNAME', '');
}

require_once __DIR__ . '/../api/marzban_api.php';
require_once __DIR__ . '/../api/sanaei_api.php';
require_once __DIR__ . '/../api/marzneshin_api.php';
require_once __DIR__ . '/../pay/tetra_api.php';
require_once __DIR__ . '/../pay/zarinpal_api.php';






function handleKeyboard($keyboard, $handleMainMenu = false) {

    if (USER_INLINE_KEYBOARD) {
        if (is_null($keyboard)) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '◀️ بازگشت به منوی اصلی',
                            'callback_data' => '◀️ بازگشت به منوی اصلی'
                        ]
                    ]
                ]
            ];
        }
        else {
            if (isset($keyboard['keyboard'])) {
                $keyboard = convertToInlineKeyboard($keyboard);
            }
            if (!array_str_contains($keyboard, ['بازگشت', 'برگشت', 'back']) && !$handleMainMenu) {
                $keyboard['inline_keyboard'][] = [
                    [
                        'text' => '◀️ بازگشت به منوی اصلی',
                        'callback_data' => '◀️ بازگشت به منوی اصلی'
                    ]
                ];
            }
        }
    }

    if (is_null($keyboard)) {
        return null;
    }
    else {
        return json_encode($keyboard);
    }
}

function convertToInlineKeyboard($keyboard) {
    $inlineKeyboard = [];

    if (isset($keyboard['keyboard'])) {
        foreach ($keyboard['keyboard'] as $row) {
            $inlineRow = [];
            foreach ($row as $button) {
                if (isset($button['text'])) {
                    $inlineRow[] = [
                        'text' => $button['text'],
                        'callback_data' => $button['text']
                    ];
                }
            }
            if (!empty($inlineRow)) {
                $inlineKeyboard[] = $inlineRow;
            }
        }
    }
    else {
        return null;
    }

    return ['inline_keyboard' => $inlineKeyboard];
}

function array_str_contains(array $array, string|array $needle): bool {
    if (is_array($needle)) {
        foreach ($needle as $n) {
            if (array_str_contains($array, $n)) {
                return true;
            }
        }
        return false;
    }

    foreach ($array as $item) {
        if (is_array($item)) {
            if (array_str_contains($item, $needle)) {
                return true;
            }
        }
        elseif (is_string($item) && stripos($item, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function sendMessage($chat_id, $text, $keyboard = null, $handleMainMenu = false) {
    $params = ['chat_id' => $chat_id, 'text' => $text, 'reply_markup' => handleKeyboard($keyboard, $handleMainMenu), 'parse_mode' => 'HTML'];

    global $update, $oneTimeEdit;
    if (USER_INLINE_KEYBOARD && isset($update['callback_query']['message']['message_id']) && $oneTimeEdit) {
        $oneTimeEdit = false;
        $params['message_id'] = $update['callback_query']['message']['message_id'];
        $result = apiRequest('editMessageText', $params);
        $decoded_result = json_decode($result, true);
        if (!$decoded_result || !$decoded_result['ok']) {
            unset($params['message_id']);
            return apiRequest('sendMessage', $params);
        }
        return $result;
    }
    else {
        return apiRequest('sendMessage', $params);
    }
}

function forwardMessage($to_chat_id, $from_chat_id, $message_id) {
    $params = ['chat_id' => $to_chat_id, 'from_chat_id' => $from_chat_id, 'message_id' => $message_id];
    return apiRequest('forwardMessage', $params);
}

function sendPhoto($chat_id, $photo, $caption, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'photo' => $photo, 'caption' => $caption, 'reply_markup' => handleKeyboard($keyboard), 'parse_mode' => 'HTML'];
    return apiRequest('sendPhoto', $params);
}

function editMessageText($chat_id, $message_id, $text, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text, 'reply_markup' => handleKeyboard($keyboard), 'parse_mode' => 'HTML'];

    global $oneTimeEdit;
    if (USER_INLINE_KEYBOARD && $oneTimeEdit) {
        $oneTimeEdit = false;
        return apiRequest('editMessageText', $params);
    }
    else {
    
        unset($params['message_id']);
        return apiRequest('sendMessage', $params);
    }
}

function editMessageCaption($chat_id, $message_id, $caption, $keyboard = null) {
    $params = ['chat_id' => $chat_id, 'message_id' => $message_id, 'caption' => $caption, 'reply_markup' => handleKeyboard($keyboard), 'parse_mode' => 'HTML'];
    return apiRequest('editMessageCaption', $params);
}

function deleteMessage($chat_id, $message_id) {
    global $update, $oneTimeEdit;
    if (USER_INLINE_KEYBOARD && !$oneTimeEdit && isset($update['callback_query']['message']['message_id']) && $update['callback_query']['message']['message_id'] == $message_id) return false;

    $params = ['chat_id' => $chat_id, 'message_id' => $message_id];
    return apiRequest('deleteMessage', $params);
}

function apiRequest($method, $params = []) {
    global $apiRequest;
    $apiRequest = true;

    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('cURL error in apiRequest: ' . curl_error($ch));
    }
    curl_close($ch);
    return $response;
}






function getUserData($chat_id, $first_name = 'کاربر', $temp_referrer_id = null) {
    
    $stmt = pdo()->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();

    if (!$user) { 
        error_log("--- New User Detected --- CHAT_ID: {$chat_id}, Referrer_ID: {$temp_referrer_id}"); 

        $settings = getSettings();
        $final_referrer_id = null;
        
        if ($temp_referrer_id && $settings['referral_status'] === 'on' && $temp_referrer_id != $chat_id) {
            error_log("Referral system is ON. Checking referrer: {$temp_referrer_id}"); 
            $stmt_check_referrer = pdo()->prepare("SELECT chat_id FROM users WHERE chat_id = ?");
            $stmt_check_referrer->execute([$temp_referrer_id]);
            if ($stmt_check_referrer->fetch()) {
                $final_referrer_id = $temp_referrer_id;
                error_log("Referrer FOUND in DB: {$final_referrer_id}"); 
            } else {
                error_log("Referrer NOT FOUND in DB."); 
            }
        }

        
        $initial_balance = (int)($settings['welcome_gift_balance'] ?? 0);
        $reward_referred = 0;
        if ($final_referrer_id) {
            $reward_referred = (int)($settings['referral_reward_referred'] ?? 0);
            $initial_balance += $reward_referred;
        }

        
        $referral_link = "https://t.me/" . BOT_USERNAME . "?start=" . $chat_id;
        $stmt_insert = pdo()->prepare("INSERT INTO users (chat_id, first_name, balance, user_state, referrer_id, referral_link) VALUES (?, ?, ?, 'main_menu', ?, ?)");
        $stmt_insert->execute([$chat_id, $first_name, $initial_balance, $final_referrer_id, $referral_link]);
        
        
        if ($final_referrer_id) {
            $reward_referrer = (int)($settings['referral_reward_referrer'] ?? 0);
            if ($reward_referrer > 0) {
                error_log("Attempting to give referrer ({$final_referrer_id}) a reward of {$reward_referrer}"); 
                updateUserBalance($final_referrer_id, $reward_referrer, 'add');
                
                $message_to_referrer = "🎉 تبریک! یک کاربر جدید از طریق لینک شما عضو شد و مبلغ " . number_format($reward_referrer) . " تومان به عنوان هدیه به موجودی شما اضافه شد.";
                $send_result = sendMessage($final_referrer_id, $message_to_referrer);
                error_log("sendMessage result for referrer: " . $send_result); 
            }
        }
        
        if ($reward_referred > 0) {
            error_log("Attempting to send message to new user ({$chat_id}) for referral reward of {$reward_referred}"); 
            $message_to_referred = "🎁 شما از طریق لینک دعوت وارد شدید! مبلغ " . number_format($reward_referred) . " تومان به عنوان هدیه به حساب شما اضافه شد.";
            sendMessage($chat_id, $message_to_referred);
        }
        
        if ((int)($settings['welcome_gift_balance'] ?? 0) > 0) {
            error_log("Attempting to send welcome gift message to new user ({$chat_id})"); 
            $message_welcome = "🎁 به عنوان هدیه خوش‌آمدگویی، مبلغ " . number_format($settings['welcome_gift_balance']) . " تومان به حساب شما اضافه شد.";
            sendMessage($chat_id, $message_welcome);
        }
        
        $stmt = pdo()->prepare("SELECT * FROM users WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
        $user = $stmt->fetch();
    }

    
    $user['state_data'] = json_decode($user['state_data'] ?? '[]', true);
    $user['state'] = $user['user_state'];
    return $user;
}

function updateUserData($chat_id, $state, $data = []) {
    $state_data_json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $stmt = pdo()->prepare("UPDATE users SET user_state = ?, state_data = ? WHERE chat_id = ?");
    $stmt->execute([$state, $state_data_json, $chat_id]);
}

function updateUserBalance($chat_id, $amount, $operation = 'add') {
    if ($operation == 'add') {
        $stmt = pdo()->prepare("UPDATE users SET balance = balance + ? WHERE chat_id = ?");
    }
    else {
        $stmt = pdo()->prepare("UPDATE users SET balance = balance - ? WHERE chat_id = ?");
    }
    $stmt->execute([$amount, $chat_id]);
}

function setUserStatus($chat_id, $status) {
    $stmt = pdo()->prepare("UPDATE users SET status = ? WHERE chat_id = ?");
    $stmt->execute([$status, $chat_id]);
}

function getAllUsers() {
    return pdo()
        ->query("SELECT chat_id FROM users WHERE status = 'active'")
        ->fetchAll(PDO::FETCH_COLUMN);
}

function increaseAllUsersBalance($amount) {
    $stmt = pdo()->prepare("UPDATE users SET balance = balance + ? WHERE status = 'active'");
    $stmt->execute([$amount]);
    return $stmt->rowCount();
}

function resetAllUsersTestCount() {
    $stmt = pdo()->prepare("UPDATE users SET test_config_count = 0");
    $stmt->execute();
    return $stmt->rowCount();
}


function getAdmins() {
    $stmt = pdo()->prepare("SELECT * FROM admins WHERE is_super_admin = 0");
    $stmt->execute();
    $admins_from_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $admins = [];
    foreach ($admins_from_db as $admin) {
        $admin['permissions'] = json_decode($admin['permissions'], true);
        $admins[$admin['chat_id']] = $admin;
    }

    return $admins;
}

function addAdmin($chat_id, $first_name) {
    $stmt = pdo()->prepare("INSERT INTO admins (chat_id, first_name, permissions, is_super_admin) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$chat_id, $first_name, json_encode([]), 0]);
}

function removeAdmin($chat_id) {
    $stmt = pdo()->prepare("DELETE FROM admins WHERE chat_id = ? AND is_super_admin = 0");
    return $stmt->execute([$chat_id]);
}

function updateAdminPermissions($chat_id, $permissions) {
    $stmt = pdo()->prepare("UPDATE admins SET permissions = ? WHERE chat_id = ?");
    return $stmt->execute([json_encode($permissions), $chat_id]);
}

function isUserAdmin($chat_id) {
    if ($chat_id == ADMIN_CHAT_ID) {
        return true;
    }
    $stmt = pdo()->prepare("SELECT COUNT(*) FROM admins WHERE chat_id = ? AND is_super_admin = 0");
    $stmt->execute([$chat_id]);
    return $stmt->fetchColumn() > 0;
}

function hasPermission($chat_id, $permission) {
    if ($chat_id == ADMIN_CHAT_ID) {
        return true;
    }

    $stmt = pdo()->prepare("SELECT permissions FROM admins WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $result = $stmt->fetch();

    if ($result && $result['permissions']) {
        $permissions = json_decode($result['permissions'], true);
        return in_array('all', $permissions) || in_array($permission, $permissions);
    }
    return false;
}


function getSettings() {
    $stmt = pdo()->query("SELECT * FROM settings");
    $settings_from_db = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $defaults = [
        'bot_status' => 'on',
        'sales_status' => 'on',
        'join_channel_id' => '',
        'join_channel_status' => 'off',
        'welcome_gift_balance' => '0',
        'payment_method' => json_encode(['card_number' => '', 'card_holder' => '', 'copy_enabled' => false]),
        'notification_expire_status' => 'off',
        'notification_expire_days' => '3',
        'notification_expire_gb' => '1',
        'notification_expire_message' => '❗️کاربر گرامی، حجم یا زمان سرویس شما رو به اتمام است. لطفاً جهت تمدید اقدام نمایید.',
        'notification_inactive_status' => 'off',
        'notification_inactive_days' => '30',
        'notification_inactive_message' => '👋 سلام! مدت زیادی است که به ما سر نزده‌اید. برای مشاهده جدیدترین سرویس‌ها و پیشنهادات وارد ربات شوید.',
        'verification_method' => 'off',
        'verification_iran_only' => 'off',
        'inline_keyboard' => 'on',
        'custom_config_status' => 'off',
        'custom_price_per_day' => '1000',
        'custom_price_per_gb' => '2000',
        'custom_max_days' => '0',
        'custom_max_gb' => '0'
    ];

    foreach ($defaults as $key => $value) {
        if (!isset($settings_from_db[$key])) {
            $stmt = pdo()->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt->execute([$key, $value]);
            $settings_from_db[$key] = $value;
        }
    }

    $settings_from_db['payment_method'] = json_decode($settings_from_db['payment_method'], true);

    return $settings_from_db;
}

function saveSettings($settings) {
    foreach ($settings as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $stmt = pdo()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }
}


function getCategories($only_active = false) {
    $sql = "SELECT * FROM categories";
    if ($only_active) {
        $sql .= " WHERE status = 'active'";
    }
    return pdo()
        ->query($sql)
        ->fetchAll(PDO::FETCH_ASSOC);
}

function getPlans() {
    return pdo()
        ->query("SELECT * FROM plans WHERE is_test_plan = 0")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function getPlansForCategory($category_id) {
    $stmt = pdo()->prepare("SELECT * FROM plans WHERE category_id = ? AND status = 'active' AND is_test_plan = 0");
    $stmt->execute([$category_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getPlanById($plan_id) {
    $stmt = pdo()->prepare("SELECT * FROM plans WHERE id = ?");
    $stmt->execute([$plan_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getTestPlan() {
    return pdo()
        ->query("SELECT * FROM plans WHERE is_test_plan = 1 AND status = 'active' LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
}

function getUserServices($chat_id) {
    $stmt = pdo()->prepare("
        SELECT s.*, p.name as plan_name 
        FROM services s
        JOIN plans p ON s.plan_id = p.id
        WHERE s.owner_chat_id = ?
        ORDER BY s.id DESC
    ");
    $stmt->execute([$chat_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveUserService($chat_id, $serviceData) {
    $stmt = pdo()->prepare("INSERT INTO services (owner_chat_id, server_id, marzban_username, custom_name, plan_id, sub_url, expire_timestamp, volume_gb) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$chat_id, $serviceData['server_id'], $serviceData['username'], $serviceData['custom_name'], $serviceData['plan_id'], $serviceData['sub_url'], $serviceData['expire_timestamp'], $serviceData['volume_gb']]);
}

function deleteUserService($chat_id, $username, $server_id) {
    $stmt = pdo()->prepare("DELETE FROM services WHERE owner_chat_id = ? AND marzban_username = ? AND server_id = ?");
    return $stmt->execute([$chat_id, $username, $server_id]);
}





function getPermissionMap() {
    return [
        'manage_categories' => '🗂 مدیریت دسته‌بندی‌ها',
        'manage_plans' => '📝 مدیریت پلن‌ها',
        'manage_users' => '👥 مدیریت کاربران',
        'broadcast' => '📣 ارسال همگانی',
        'view_stats' => '📊 آمارها',
        'manage_payment' => '💳 مدیریت پرداخت',
        'manage_marzban' => '🌐 مدیریت سرورها',
        'manage_settings' => '⚙️ تنظیمات کلی ربات',
        'view_tickets' => '📨 مشاهده تیکت‌ها',
        'manage_guides' => '📚 مدیریت راهنما',
        'manage_test_config' => '🧪 مدیریت کانفیگ تست',
        'manage_notifications' => '📢 مدیریت اعلان‌ها',
        'manage_verification' => '🔐 مدیریت احراز هویت',
        'manage_custom_config' => 'تعیین قیمت دلخواه',
        'manage_referrals' => '🤝 مدیریت زیرمجموعه‌گیری',
    ];
}

function checkJoinStatus($user_id) {
    $settings = getSettings();
    $channel_id = $settings['join_channel_id'];
    if ($settings['join_channel_status'] !== 'on' || empty($channel_id)) {
        return true;
    }
    $response = apiRequest('getChatMember', ['chat_id' => $channel_id, 'user_id' => $user_id]);
    $data = json_decode($response, true);
    if ($data && $data['ok']) {
        return in_array($data['result']['status'], ['member', 'administrator', 'creator']);
    }
    return false;
}

function generateQrCodeUrl($text) {
    return 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($text);
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes <= 0) {
        return "0 GB";
    }
    return round(floatval($bytes) / pow(1024, 3), $precision) . ' GB';
}

function calculateIncomeStats() {
    $stats = [
        'today' =>
            pdo()
                ->query("SELECT SUM(p.price) FROM services s JOIN plans p ON s.plan_id = p.id WHERE DATE(s.purchase_date) = CURDATE()")
                ->fetchColumn() ?? 0,
        'week' =>
            pdo()
                ->query("SELECT SUM(p.price) FROM services s JOIN plans p ON s.plan_id = p.id WHERE s.purchase_date >= CURDATE() - INTERVAL 7 DAY")
                ->fetchColumn() ?? 0,
        'month' =>
            pdo()
                ->query("SELECT SUM(p.price) FROM services s JOIN plans p ON s.plan_id = p.id WHERE MONTH(s.purchase_date) = MONTH(CURDATE()) AND YEAR(s.purchase_date) = YEAR(CURDATE())")
                ->fetchColumn() ?? 0,
        'year' =>
            pdo()
                ->query("SELECT SUM(p.price) FROM services s JOIN plans p ON s.plan_id = p.id WHERE YEAR(s.purchase_date) = YEAR(CURDATE())")
                ->fetchColumn() ?? 0,
    ];
    return $stats;
}





function generateGuideList($chat_id) {
    $stmt = pdo()->query("SELECT id, button_name, status FROM guides ORDER BY id DESC");
    $guides = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($guides)) {
        sendMessage($chat_id, "هیچ راهنمایی یافت نشد.");
        return;
    }

    sendMessage($chat_id, "<b>📚 لیست راهنماها:</b>");

    foreach ($guides as $guide) {
        $guide_id = $guide['id'];
        $status_icon = $guide['status'] == 'active' ? '✅' : '❌';
        $status_action_text = $guide['status'] == 'active' ? 'غیرفعال کردن' : 'فعال کردن';

        $info_message = "{$status_icon} <b>دکمه:</b> {$guide['button_name']}";

        $keyboard = ['inline_keyboard' => [[['text' => "🗑 حذف", 'callback_data' => "delete_guide_{$guide_id}"], ['text' => $status_action_text, 'callback_data' => "toggle_guide_{$guide_id}"]]]];

        sendMessage($chat_id, $info_message, $keyboard);
    }
}

function showGuideSelectionMenu($chat_id) {
    $stmt = pdo()->query("SELECT id, button_name FROM guides WHERE status = 'active' ORDER BY id ASC");
    $guides = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($guides)) {
        sendMessage($chat_id, "در حال حاضر هیچ راهنمایی برای نمایش وجود ندارد.");
        return;
    }

    $keyboard_buttons = [];
    foreach ($guides as $guide) {
        $keyboard_buttons[] = [['text' => $guide['button_name'], 'callback_data' => 'show_guide_' . $guide['id']]];
    }

    $message = "لطفا راهنمای مورد نظر خود را انتخاب کنید:";
    sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function generateDiscountCodeList($chat_id) {
    $stmt = pdo()->query("SELECT * FROM discount_codes ORDER BY id DESC");
    $codes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($codes)) {
        sendMessage($chat_id, "هیچ کد تخفیفی یافت نشد.");
        return;
    }

    sendMessage($chat_id, "<b>🎁 لیست کدهای تخفیف:</b>\nبرای مدیریت، روی دکمه‌های زیر هر مورد کلیک کنید.");

    foreach ($codes as $code) {
        $code_id = $code['id'];
        $status_icon = $code['status'] == 'active' ? '✅' : '❌';
        $status_action_text = $code['status'] == 'active' ? 'غیرفعال کردن' : 'فعال کردن';

        $type_text = $code['type'] == 'percent' ? 'درصد' : 'تومان';
        $value_text = number_format($code['value']);

        $usage_text = "{$code['usage_count']} / {$code['max_usage']}";

        $info_message = "{$status_icon} <b>کد: <code>{$code['code']}</code></b>\n" . "▫️ نوع تخفیف: {$value_text} {$type_text}\n" . "▫️ میزان استفاده: {$usage_text}";

        $keyboard = ['inline_keyboard' => [[['text' => "🗑 حذف", 'callback_data' => "delete_discount_{$code_id}"], ['text' => $status_action_text, 'callback_data' => "toggle_discount_{$code_id}"]]]];

        sendMessage($chat_id, $info_message, $keyboard);
    }
}

function generateCategoryList($chat_id) {
    $categories = getCategories();
    if (empty($categories)) {
        sendMessage($chat_id, "هیچ دسته‌بندی‌ای یافت نشد.");
        return;
    }

    sendMessage($chat_id, "<b>🗂 لیست دسته‌بندی‌ها:</b>\nبرای مدیریت هر مورد، از دکمه‌های زیر آن استفاده کنید.");

    foreach ($categories as $category) {
        $status_icon = $category['status'] == 'active' ? '✅' : '❌';
        $status_action = $category['status'] == 'active' ? 'غیرفعال کردن' : 'فعال کردن';

        $message_text = "{$status_icon} <b>{$category['name']}</b>";

        $keyboard = ['inline_keyboard' => [[['text' => "🗑 حذف", 'callback_data' => "delete_cat_{$category['id']}"], ['text' => $status_action, 'callback_data' => "toggle_cat_{$category['id']}"]]]];

        sendMessage($chat_id, $message_text, $keyboard);
    }
}

function generatePlanList($chat_id) {
    $plans = pdo()
        ->query("SELECT p.*, s.name as server_name, s.type as server_type FROM plans p LEFT JOIN servers s ON p.server_id = s.id ORDER BY p.is_test_plan DESC, p.id ASC")
        ->fetchAll(PDO::FETCH_ASSOC);
    $categories_raw = getCategories();
    $categories = array_column($categories_raw, 'name', 'id');

    if (empty($plans)) {
        sendMessage($chat_id, "هیچ پلنی یافت نشد.");
        return;
    }
    sendMessage($chat_id, "<b>📝 لیست پلن‌ها:</b>\nبرای مدیریت، روی دکمه‌های زیر هر مورد کلیک کنید.");

    foreach ($plans as $plan) {
        $plan_id = $plan['id'];
        $cat_name = $categories[$plan['category_id']] ?? 'نامشخص';
        $server_name = $plan['server_name'] ?? '<i>سرور حذف شده</i>';
        $status_icon = $plan['status'] == 'active' ? '✅' : '❌';
        $status_action = $plan['status'] == 'active' ? 'غیرفعال کردن' : 'فعال کردن';

        $plan_info = "";
        if ($plan['is_test_plan']) {
            $plan_info .= "🧪 <b>(پلن تست) {$plan['name']}</b>\n";
        }
        else {
            $plan_info .= "{$status_icon} <b>{$plan['name']}</b>\n";
        }

        $plan_info .= "▫️ سرور: <b>{$server_name}</b>\n";
        
        if ($plan['server_type'] === 'sanaei' && !empty($plan['inbound_id'])) {
            $plan_info .= "▫️ اینباند: <b>{$plan['inbound_id']}</b>\n";
        } elseif ($plan['server_type'] === 'marzneshin' && !empty($plan['marzneshin_service_id'])) {
            $plan_info .= "▫️ سرویس: <b>{$plan['marzneshin_service_id']}</b>\n";
        }
        
        $plan_info .= "▫️ دسته‌بندی: {$cat_name}\n" . "▫️ قیمت: " . number_format($plan['price']) . " تومان\n" . "▫️ حجم: {$plan['volume_gb']} گیگابایت | " . "مدت: {$plan['duration_days']} روز\n";

        if ($plan['purchase_limit'] > 0) {
            $plan_info .= "📈 تعداد خرید: <b>{$plan['purchase_count']} / {$plan['purchase_limit']}</b>\n";
        }

        $keyboard_buttons = [];
        
        $keyboard_buttons[] = [['text' => "🗑 حذف", 'callback_data' => "delete_plan_{$plan_id}"], ['text' => $status_action, 'callback_data' => "toggle_plan_{$plan_id}"], ['text' => "✏️ ویرایش", 'callback_data' => "open_plan_editor_{$plan_id}"]];

        if ($plan['is_test_plan']) {
            $keyboard_buttons[] = [['text' => '↔️ تبدیل به پلن عادی', 'callback_data' => "make_plan_normal_{$plan_id}"]];
        }
        else {
            $keyboard_buttons[] = [['text' => '🧪 تنظیم به عنوان پلن تست', 'callback_data' => "set_as_test_plan_{$plan_id}"]];
        }

        if ($plan['purchase_limit'] > 0) {
            $keyboard_buttons[] = [['text' => '🔄 ریست کردن تعداد خرید', 'callback_data' => "reset_plan_count_{$plan_id}"]];
        }

        sendMessage($chat_id, $plan_info, ['inline_keyboard' => $keyboard_buttons]);
    }
}

function showServersForCategory($chat_id, $category_id) {
    $category_stmt = pdo()->prepare("SELECT name FROM categories WHERE id = ?");
    $category_stmt->execute([$category_id]);
    $category_name = $category_stmt->fetchColumn();
    if (!$category_name) {
        sendMessage($chat_id, "خطا: دسته‌بندی یافت نشد.");
        return;
    }

    
    $stmt = pdo()->prepare("
        SELECT DISTINCT s.id, s.name 
        FROM servers s
        JOIN plans p ON s.id = p.server_id
        WHERE p.category_id = ? AND p.status = 'active' AND s.status = 'active'
    ");
    $stmt->execute([$category_id]);
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($servers)) {
        sendMessage($chat_id, "متاسفانه در حال حاضر هیچ سروری در این دسته‌بندی پلن فعال ندارد.");
        return;
    }

    $message = "🛍️ <b>دسته‌بندی «{$category_name}»</b>\n\nلطفاً سرور (لوکیشن) مورد نظر خود را انتخاب کنید:";
    $keyboard_buttons = [];
    foreach ($servers as $server) {
      
        $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "show_plans_cat_{$category_id}_srv_{$server['id']}"]];
    }
    $keyboard_buttons[] = [['text' => '◀️ بازگشت به دسته‌بندی‌ها', 'callback_data' => 'back_to_categories']];
    sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function showServersForCustomConfigCategory($chat_id, $category_id) {
    $category_stmt = pdo()->prepare("SELECT name FROM categories WHERE id = ?");
    $category_stmt->execute([$category_id]);
    $category_name = $category_stmt->fetchColumn();
    if (!$category_name) {
        sendMessage($chat_id, "خطا: دسته‌بندی یافت نشد.");
        return;
    }

    
    $stmt = pdo()->query("
        SELECT id, name
        FROM servers
        WHERE status = 'active'
    ");
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($servers)) {
        sendMessage($chat_id, "متاسفانه در حال حاضر هیچ سرور فعالی برای ساخت کانفیگ دلخواه موجود نیست.");
        return;
    }

    $message = "🛠️ <b>کانفیگ دلخواه - دسته‌بندی «{$category_name}»</b>\n\nلطفاً سرور (لوکیشن) مورد نظر خود را انتخاب کنید:";
    $keyboard_buttons = [];
    foreach ($servers as $server) {
        $keyboard_buttons[] = [['text' => "🖥 {$server['name']}", 'callback_data' => "select_custom_server_cat_{$category_id}_srv_{$server['id']}"]];
    }
    $keyboard_buttons[] = [['text' => '◀️ بازگشت به دسته‌بندی‌ها', 'callback_data' => 'back_to_custom_categories']];
    sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function showAdminManagementMenu($chat_id) {
    $admins = getAdmins();
    $message = "<b>👨‍💼 مدیریت ادمین‌ها</b>\n\nدر این بخش می‌توانید ادمین‌های ربات و دسترسی‌های آن‌ها را مدیریت کنید. (حداکثر ۱۰ ادمین)";
    $keyboard_buttons = [];

    if (count($admins) < 10) {
        $keyboard_buttons[] = [['text' => '➕ افزودن ادمین جدید', 'callback_data' => 'add_admin']];
    }

    foreach ($admins as $admin_id => $admin_data) {
        if ($admin_id == ADMIN_CHAT_ID) {
            continue;
        }
        $admin_name = htmlspecialchars($admin_data['first_name'] ?? "ادمین $admin_id");
        $keyboard_buttons[] = [['text' => "👤 {$admin_name}", 'callback_data' => "edit_admin_permissions_{$admin_id}"]];
    }

    $keyboard_buttons[] = [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']];
    sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function showPermissionEditor($chat_id, $message_id, $target_admin_id) {
    $admins = getAdmins();
    $target_admin = $admins[$target_admin_id] ?? null;
    if (!$target_admin) {
        editMessageText($chat_id, $message_id, "❌ خطا: ادمین مورد نظر یافت نشد.");
        return;
    }

    $admin_name = htmlspecialchars($target_admin['first_name'] ?? "ادمین $target_admin_id");
    $message = "<b>ویرایش دسترسی‌های: {$admin_name}</b>\n\nبا کلیک روی هر دکمه، دسترسی آن را فعال یا غیرفعال کنید.";

    $permission_map = getPermissionMap();
    $current_permissions = $target_admin['permissions'] ?? [];
    $keyboard_buttons = [];
    $row = [];

    foreach ($permission_map as $key => $name) {
        $has_perm = in_array($key, $current_permissions);
        $icon = $has_perm ? '✅' : '❌';
        $row[] = ['text' => "{$icon} {$name}", 'callback_data' => "toggle_perm_{$target_admin_id}_{$key}"];
        if (count($row) == 2) {
            $keyboard_buttons[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $keyboard_buttons[] = $row;
    }

    $keyboard_buttons[] = [['text' => '🗑 حذف این ادمین', 'callback_data' => "delete_admin_confirm_{$target_admin_id}"]];
    $keyboard_buttons[] = [['text' => '◀️ بازگشت به لیست ادمین‌ها', 'callback_data' => 'back_to_admin_list']];

    editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function handleMainMenu($chat_id, $first_name, $is_start_command = false) {

    $isAnAdmin = isUserAdmin($chat_id);
    $user_data = getUserData($chat_id, $first_name);
    $admin_view_mode = $user_data['state_data']['admin_view'] ?? 'user';

    if ($is_start_command) {
        $message = "سلام $first_name عزیز!\nبه ربات فروش کانفیگ خوش آمدید. 🌹";
    }
    else {
        $message = "به منوی اصلی بازگشتید. لطفا گزینه مورد نظر را انتخاب کنید.";
    }

    $keyboard_buttons = [[['text' => '🛒 خرید سرویس'], ['text' => '🛠 کانفیگ دلخواه']], [['text' => '💳 شارژ حساب'], ['text' => '👤 حساب کاربری']], [['text' => '🔧 سرویس‌های من'], ['text' => '📨 پشتیبانی']], [['text' => '🤝 زیرمجموعه‌گیری']]];

    $test_plan = getTestPlan();
    if ($test_plan) {
        array_splice($keyboard_buttons, 1, 0, [[['text' => '🧪 دریافت کانفیگ تست']]]);
    }

    $stmt = pdo()->query("SELECT COUNT(*) FROM guides WHERE status = 'active'");
    if ($stmt->fetchColumn() > 0) {
        $keyboard_buttons[] = [['text' => '📚 راهنما']];
    }

    if ($isAnAdmin) {
        if ($admin_view_mode === 'admin') {
            if ($is_start_command) {
                $message = "ادمین عزیز، به پنل مدیریت خوش آمدید.";
            }
            else {
                $message = "به پنل مدیریت بازگشتید.";
            }
            $admin_keyboard = [];
            $rows = array_fill(0, 7, []);
            if (hasPermission($chat_id, 'manage_categories')) {
                $rows[0][] = ['text' => '🗂 مدیریت دسته‌بندی‌ها'];
            }
            if (hasPermission($chat_id, 'manage_plans')) {
                $rows[0][] = ['text' => '📝 مدیریت پلن‌ها'];
            }
            if (hasPermission($chat_id, 'manage_users')) {
                $rows[1][] = ['text' => '👥 مدیریت کاربران'];
            }
            if (hasPermission($chat_id, 'broadcast')) {
                $rows[1][] = ['text' => '📣 ارسال همگانی'];
            }
            if (hasPermission($chat_id, 'view_stats')) {
                $rows[2][] = ['text' => '📊 آمار کلی'];
                $rows[2][] = ['text' => '💰 آمار درآمد'];
            }
            if (hasPermission($chat_id, 'manage_payment')) {
                $rows[3][] = ['text' => '💳 مدیریت پرداخت'];
                $rows[3][] = ['text' => '💳 مدیریت درگاه پرداخت']; 
            }
            if (hasPermission($chat_id, 'manage_marzban')) {
                $rows[4][] = ['text' => '🌐 مدیریت سرورها'];
            }
            if (hasPermission($chat_id, 'manage_settings')) {
                $rows[5][] = ['text' => '⚙️ تنظیمات کلی ربات'];
            }
            if (hasPermission($chat_id, 'manage_guides')) {
                $rows[5][] = ['text' => '📚 مدیریت راهنما'];
            }
            if (hasPermission($chat_id, 'manage_notifications')) {
                $rows[5][] = ['text' => '📢 مدیریت اعلان‌ها'];
            }
            if (hasPermission($chat_id, 'manage_test_config')) {
                $rows[6][] = ['text' => '🧪 مدیریت کانفیگ تست'];
            }
            if ($chat_id == ADMIN_CHAT_ID) {
                $rows[6][] = ['text' => '👨‍💼 مدیریت ادمین‌ها'];
            }
            if (hasPermission($chat_id, 'manage_verification')) {
                $rows[7][] = ['text' => '🔐 مدیریت احراز هویت'];
            }
            $rows[7][] = ['text' => '🎁 مدیریت کد تخفیف'];
            if (hasPermission($chat_id, 'manage_custom_config')) {
                $rows[8][] = ['text' => 'تعیین قیمت دلخواه'];
            }
            if (hasPermission($chat_id, 'manage_referrals')) { 
                $rows[8][] = ['text' => '🤝 مدیریت زیرمجموعه‌گیری'];
            }
            $rows[9][] = ['text' => '🔄 مدیریت تمدید'];
            foreach ($rows as $row) {
                if (!empty($row)) {
                    $admin_keyboard[] = $row;
                }
            }
            $admin_keyboard[] = [['text' => '↩️ بازگشت به منوی کاربری']];
            $keyboard_buttons = $admin_keyboard;
        }
        else {
            $keyboard_buttons[] = [['text' => '👑 ورود به پنل مدیریت']];
        }
    }

    $keyboard = ['keyboard' => $keyboard_buttons, 'resize_keyboard' => true];

    $stmt = pdo()->prepare("SELECT inline_keyboard FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $inline_keyboard = $stmt->fetch()['inline_keyboard'];
    if (USER_INLINE_KEYBOARD && ($inline_keyboard != 1 || $is_start_command)) {
        $stmt = pdo()->prepare("UPDATE users SET inline_keyboard = '1' WHERE chat_id = ?");
        $stmt->execute([$chat_id]);

        $delMsgId = json_decode(apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => '🏠',
            'reply_markup' => json_encode(['remove_keyboard' => true])
        ]), true)['result']['message_id'];
    }
    elseif (!USER_INLINE_KEYBOARD && $inline_keyboard == 1) {
        $stmt = pdo()->prepare("UPDATE users SET inline_keyboard = '0' WHERE chat_id = ?");
        $stmt->execute([$chat_id]);
    }

    sendMessage($chat_id, $message, $keyboard, true);

    if (isset($delMsgId)) {
        apiRequest('deleteMessage', [
            'chat_id' => $chat_id,
            'message_id' => $delMsgId
        ]);
    }

}

function showVerificationManagementMenu($chat_id) {
    $settings = getSettings();
    $current_method = $settings['verification_method'];
    $iran_only_icon = $settings['verification_iran_only'] == 'on' ? '🇮🇷' : '🌎';

    $method_text = 'غیرفعال';
    if ($current_method == 'phone') {
        $method_text = 'شماره تلفن';
    }
    elseif ($current_method == 'button') {
        $method_text = 'دکمه شیشه‌ای';
    }

    $message = "<b>🔐 مدیریت احراز هویت کاربران</b>\n\n" . "در این بخش می‌توانید روش تایید هویت کاربران قبل از استفاده از ربات را مشخص کنید.\n\n" . "▫️ روش فعلی: <b>" . $method_text . "</b>";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => ($current_method == 'off' ? '✅' : '') . ' غیرفعال', 'callback_data' => 'set_verification_off'],
                ['text' => ($current_method == 'phone' ? '✅' : '') . ' 📞 شماره تلفن', 'callback_data' => 'set_verification_phone'],
                ['text' => ($current_method == 'button' ? '✅' : '') . ' 🔘 دکمه شیشه‌ای', 'callback_data' => 'set_verification_button'],
            ],
            [],
            [['text' => '◀️ بازگشت به پنل مدیریت', 'callback_data' => 'back_to_admin_panel']],
        ],
    ];

    if ($current_method == 'phone') {
        $keyboard['inline_keyboard'][1][] = ['text' => $iran_only_icon . " محدودیت شماره (ایران/همه)", 'callback_data' => 'toggle_verification_iran_only'];
    }

    global $update;
    $message_id = $update['callback_query']['message']['message_id'] ?? null;
    if ($message_id) {
        editMessageText($chat_id, $message_id, $message, $keyboard);
    }
    else {
        sendMessage($chat_id, $message, $keyboard);
    }
}





function getPanelUser($username, $server_id) {
    $stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $type = $stmt->fetchColumn();

    switch ($type) {
        case 'marzban':
            return getMarzbanUser($username, $server_id);
        case 'sanaei':
            return getSanaeiUser($username, $server_id);
        case 'marzneshin':
            return getMarzneshinUser($username, $server_id);
        default:
            return false;
    }
}

function createPanelUser($plan, $chat_id, $plan_id) {
    $stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $stmt->execute([$plan['server_id']]);
    $type = $stmt->fetchColumn();

    switch ($type) {
        case 'marzban':
            return createMarzbanUser($plan, $chat_id, $plan_id);
        case 'sanaei':
            return createSanaeiUser($plan, $chat_id, $plan_id);
        case 'marzneshin':
            return createMarzneshinUser($plan, $chat_id, $plan_id);
        default:
            return false;
    }
}

function deletePanelUser($username, $server_id) {
    $stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $type = $stmt->fetchColumn();

    switch ($type) {
        case 'marzban':
            return deleteMarzbanUser($username, $server_id);
        case 'sanaei':
            return deleteSanaeiUser($username, $server_id);
        case 'marzneshin':
            return deleteMarzneshinUser($username, $server_id);
        default:
            return false;
    }
}

function modifyPanelUser($username, $server_id, $data) {
    $stmt = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $type = $stmt->fetchColumn();

    switch ($type) {
        case 'marzban':
            return modifyMarzbanUser($username, $server_id, $data);
        case 'sanaei':
            return modifySanaeiUser($username, $server_id, $data);
        case 'marzneshin':
            return modifyMarzneshinUser($username, $server_id, $data);
        default:
            return false;
    }
}

function showPlanEditor($chat_id, $message_id, $plan_id, $prompt = null)
{
    $plan = getPlanById($plan_id);
    if (!$plan) {
        editMessageText($chat_id, $message_id, "❌ خطا: پلن مورد نظر یافت نشد.");
        return;
    }

    $status_icon = $plan['status'] == 'active' ? '✅' : '❌';
    $message_text = "<b> ویرایش پلن: {$plan['name']}</b> {$status_icon}\n";
    $message_text .= "➖➖➖➖➖➖➖➖➖➖\n";
    $message_text .= "▫️ نام: <code>{$plan['name']}</code>\n";
    $message_text .= "▫️ قیمت: <code>" . number_format($plan['price']) . "</code> تومان\n";
    $message_text .= "▫️ حجم: <code>{$plan['volume_gb']}</code> گیگابایت\n";
    $message_text .= "▫️ مدت: <code>{$plan['duration_days']}</code> روز\n";
    $message_text .= "▫️ محدودیت خرید: <code>" . ($plan['purchase_limit'] == 0 ? 'نامحدود' : $plan['purchase_limit']) . "</code>\n";
    $message_text .= "➖➖➖➖➖➖➖➖➖➖";

    if ($prompt) {
        $message_text .= "\n\n<b>" . $prompt . "</b>";
    }

    $keyboard = [
        'inline_keyboard' => [
            [['text' => '✏️ نام', 'callback_data' => "edit_plan_field_{$plan_id}_name"], ['text' => '💰 قیمت', 'callback_data' => "edit_plan_field_{$plan_id}_price"]],
            [['text' => '📊 حجم', 'callback_data' => "edit_plan_field_{$plan_id}_volume_gb"], ['text' => '⏰ مدت', 'callback_data' => "edit_plan_field_{$plan_id}_duration_days"]],
            [['text' => '📈 محدودیت خرید', 'callback_data' => "edit_plan_field_{$plan_id}_purchase_limit"]],
            [['text' => '◀️ بازگشت به لیست پلن‌ها', 'callback_data' => "back_to_plan_list"]],
        ],
    ];

    editMessageText($chat_id, $message_id, $message_text, $keyboard);
}

function fetchAndParseSubscriptionUrl($sub_url, $server_id) {
    if (empty($sub_url)) {
        return [];
    }
    
    $stmt = pdo()->prepare("SELECT url, sub_host FROM servers WHERE id = ?");
    $stmt->execute([$server_id]);
    $server_info = $stmt->fetch();
    if (!$server_info) return [];
    
        $base_sub_url = !empty($server_info['sub_host']) ? rtrim($server_info['sub_host'], '/') : rtrim($server_info['url'], '/');
    
    $stmt_type = pdo()->prepare("SELECT type FROM servers WHERE id = ?");
    $stmt_type->execute([$server_id]);
    $server_type = $stmt_type->fetchColumn();

    $sub_path = '';
   
    if ($server_type === 'marzban' || $server_type === 'sanaei') {
        $sub_path_raw = strstr($sub_url, '/sub/');
        if ($sub_path_raw !== false) {
            $sub_path = $sub_path_raw;
        }
    }
    
    
    if (empty($sub_path)) {
        $sub_path = parse_url($sub_url, PHP_URL_PATH);
    }

    $full_correct_url = $base_sub_url . $sub_path;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $full_correct_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log("Failed to fetch subscription URL {$full_correct_url}. HTTP Code: {$http_code}");
        return [];
    }

    $decoded_links = base64_decode($response_body);
    if ($decoded_links === false) {
        $decoded_links = $response_body;
    }
    
    $links_array = preg_split("/\r\n|\n|\r/", trim($decoded_links));
    
    return array_filter($links_array);
}

function showPlansForCategoryAndServer($chat_id, $category_id, $server_id) {
    
    $category_name = pdo()->prepare("SELECT name FROM categories WHERE id = ?")->execute([$category_id]) ? pdo()->lastInsertId() : 'نامشخص';
    $server_name = pdo()->prepare("SELECT name FROM servers WHERE id = ?")->execute([$server_id]) ? pdo()->lastInsertId() : 'نامشخص';


    $stmt = pdo()->prepare("SELECT * FROM plans WHERE category_id = ? AND server_id = ? AND status = 'active' AND is_test_plan = 0");
    $stmt->execute([$category_id, $server_id]);
    $active_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($active_plans)) {
        sendMessage($chat_id, "متاسفانه پلن فعالی برای این سرور یافت نشد.");
        return;
    }

    $user_balance = getUserData($chat_id)['balance'] ?? 0;
    $message = "🛍️ <b>پلن‌های سرور «{$server_name}»</b>\nموجودی شما: " . number_format($user_balance) . " تومان\n\nلطفا پلن مورد نظر خود را انتخاب کنید:";
    $keyboard_buttons = [];
    foreach ($active_plans as $plan) {
        $button_text = "{$plan['name']} | {$plan['volume_gb']}GB | " . number_format($plan['price']) . " تومان";
        $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => "buy_plan_{$plan['id']}"]];
    }
    
    $keyboard_buttons[] = [['text' => '🎁 اعمال کد تخفیف', 'callback_data' => "apply_discount_code_{$category_id}_{$server_id}"]];
    
    $keyboard_buttons[] = [['text' => '◀️ بازگشت به انتخاب سرور', 'callback_data' => 'cat_' . $category_id]];
    sendMessage($chat_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function applyRenewal($chat_id, $username, $days_to_add, $gb_to_add) {
    $stmt = pdo()->prepare("SELECT server_id FROM services WHERE owner_chat_id = ? AND marzban_username = ?");
    $stmt->execute([$chat_id, $username]);
    $server_id = $stmt->fetchColumn();

    if (!$server_id) {
        return ['success' => false, 'message' => 'سرویس در دیتابیس ربات یافت نشد.'];
    }

    $current_user_data = getPanelUser($username, $server_id);
    if (!$current_user_data || isset($current_user_data['detail'])) {
        return ['success' => false, 'message' => 'اطلاعات سرویس از پنل دریافت نشد.'];
    }

    $update_data = [];

    
    if ($days_to_add > 0) {
        $seconds_to_add = $days_to_add * 86400;
        $current_expire = $current_user_data['expire'] ?? 0;
        
        $new_expire = ($current_expire > 0 && $current_expire > time()) ? $current_expire + $seconds_to_add : time() + $seconds_to_add;
        $update_data['expire'] = $new_expire;
    }

    
    if ($gb_to_add > 0) {
        $bytes_to_add = $gb_to_add * 1024 * 1024 * 1024;
        $current_limit = $current_user_data['data_limit'] ?? 0;
        if ($current_limit > 0) { 
            $new_limit = $current_limit + $bytes_to_add;
            $update_data['data_limit'] = $new_limit;
        }
    }

    if (empty($update_data)) {
         return ['success' => false, 'message' => 'هیچ تغییری برای اعمال وجود نداشت.'];
    }

    $result = modifyPanelUser($username, $server_id, $update_data);
    
    
    if ($result && !isset($result['detail'])) {
        if(isset($update_data['expire'])){
             pdo()->prepare("UPDATE services SET expire_timestamp = ? WHERE marzban_username = ? AND server_id = ?")->execute([$update_data['expire'], $username, $server_id]);
        }
        if(isset($update_data['data_limit'])){
             $new_volume_gb = ($update_data['data_limit'] / (1024*1024*1024));
             pdo()->prepare("UPDATE services SET volume_gb = ? WHERE marzban_username = ? AND server_id = ?")->execute([$new_volume_gb, $username, $server_id]);
        }
        return ['success' => true];
    }

    return ['success' => false, 'message' => 'خطا در ارتباط با پنل برای اعمال تغییرات.'];
}

function showRenewalManagementMenu($chat_id, $message_id = null) {
    $settings = getSettings();
    $status_icon = ($settings['renewal_status'] ?? 'off') == 'on' ? '✅' : '❌';
    $message = "<b>🔄 مدیریت تمدید سرویس</b>\n\n" .
               "▫️ وضعیت کلی: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n" .
               "▫️ هزینه هر روز تمدید: <b>" . number_format($settings['renewal_price_per_day'] ?? 1000) . " تومان</b>\n" .
               "▫️ هزینه هر گیگابایت تمدید: <b>" . number_format($settings['renewal_price_per_gb'] ?? 2000) . " تومان</b>";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => $status_icon . ' فعال/غیرفعال کردن', 'callback_data' => 'toggle_renewal_status']],
            [['text' => '💰 تنظیم قیمت روز', 'callback_data' => 'set_renewal_price_day']],
            [['text' => '📊 تنظیم قیمت حجم', 'callback_data' => 'set_renewal_price_gb']],
            [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
        ]
    ];

    if ($message_id) {
        editMessageText($chat_id, $message_id, $message, $keyboard);
    } else {
        sendMessage($chat_id, $message, $keyboard);
    }
}

function showCustomConfigManagementMenu($chat_id, $message_id = null) {
    $settings = getSettings();
    $status_icon = ($settings['custom_config_status'] ?? 'off') == 'on' ? '✅' : '❌';
    $max_days = (int)($settings['custom_max_days'] ?? 0);
    $max_gb = (int)($settings['custom_max_gb'] ?? 0);

    $message = "<b>⚙️ تنظیم کانفیگ دلخواه</b>\n\n" .
               "▫️ وضعیت کلی: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n" .
               "▫️ قیمت هر روز: <b>" . number_format($settings['custom_price_per_day'] ?? 1000) . " تومان</b>\n" .
               "▫️ قیمت هر گیگابایت: <b>" . number_format($settings['custom_price_per_gb'] ?? 2000) . " تومان</b>\n" .
               "▫️ حداکثر روز قابل خرید: <b>" . ($max_days == 0 ? 'نامحدود' : "{$max_days} روز") . "</b>\n" .
               "▫️ حداکثر حجم قابل خرید: <b>" . ($max_gb == 0 ? 'نامحدود' : "{$max_gb} گیگابایت") . "</b>";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => $status_icon . ' فعال/غیرفعال کردن', 'callback_data' => 'toggle_custom_config_status']],
            [['text' => '💰 تنظیم قیمت روز', 'callback_data' => 'set_custom_price_day'], ['text' => '📊 تنظیم قیمت حجم', 'callback_data' => 'set_custom_price_gb']],
            [['text' => '⏰ تنظیم حداکثر روز', 'callback_data' => 'set_custom_max_days'], ['text' => '📈 تنظیم حداکثر حجم', 'callback_data' => 'set_custom_max_gb']],
            [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
        ]
    ];

    if ($message_id) {
        editMessageText($chat_id, $message_id, $message, $keyboard);
    } else {
        sendMessage($chat_id, $message, $keyboard);
    }
}

function showMarzbanProtocolEditor($chat_id, $message_id, $server_id) {
    $stmt_server = pdo()->prepare("SELECT name, marzban_protocols FROM servers WHERE id = ?");
    $stmt_server->execute([$server_id]);
    $server = $stmt_server->fetch();

    if (!$server) {
        editMessageText($chat_id, $message_id, "❌ سرور یافت نشد.");
        return;
    }

    $all_protocols = ['vless', 'vmess', 'trojan', 'shadowsocks'];
    
    $enabled_protocols = $server['marzban_protocols'] ? json_decode($server['marzban_protocols'], true) : ['vless']; 
    if (!is_array($enabled_protocols)) $enabled_protocols = ['vless'];
    
    $message = "<b>⚙️ تنظیم پروتکل‌های سرور: {$server['name']}</b>\n\n";
    $message .= "پروتکل‌هایی را که می‌خواهید برای کاربران جدید در این سرور ایجاد شوند، انتخاب کنید.";
    
    $keyboard_buttons = [];
    $row = [];
    foreach ($all_protocols as $protocol) {
        $icon = in_array($protocol, $enabled_protocols) ? '✅' : '❌';
        $row[] = ['text' => "{$icon} " . ucfirst($protocol), 'callback_data' => "toggle_protocol_{$server_id}_{$protocol}"];
        if (count($row) == 2) {
            $keyboard_buttons[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $keyboard_buttons[] = $row;
    }
    
    $keyboard_buttons[] = [['text' => '◀️ بازگشت به سرور', 'callback_data' => "view_server_{$server_id}"]];
    
    editMessageText($chat_id, $message_id, $message, ['inline_keyboard' => $keyboard_buttons]);
}

function completePurchase($user_id, $plan_id, $custom_name, $final_price, $discount_code, $discount_object, $discount_applied) {
    $plan = getPlanById($plan_id);
    $user_data = getUserData($user_id);
    $first_name = $user_data['first_name'];

    
    $settings = getSettings();
    $referrer_id = $user_data['referrer_id'] ?? null;
    $should_give_commission = false;
    $commission_rate = 0;

    if ($referrer_id && $settings['referral_commission_status'] === 'on' && $final_price > 0 && $plan['is_test_plan'] != 1) {
        $stmt_check_purchase = pdo()->prepare("SELECT COUNT(*) FROM services WHERE owner_chat_id = ?");
        $stmt_check_purchase->execute([$user_id]);
        $purchase_count = $stmt_check_purchase->fetchColumn();
        
        
        $is_first_purchase = ($purchase_count == 0);

        if ($settings['referral_commission_first_only'] === 'off' || ($settings['referral_commission_first_only'] === 'on' && $is_first_purchase)) {
            $commission_rate = (float)($settings['referral_commission_rate'] ?? 0);
            if ($commission_rate > 0) {
                $should_give_commission = true;
            }
        }
    }
    

    
    $plan['full_username'] = preg_replace('/[^a-zA-Z0-9_.]/', '', $custom_name) . '_user' . $user_id . '_' . time();

    $panel_user_data = createPanelUser($plan, $user_id, $plan_id);
    
    if ($panel_user_data && isset($panel_user_data['username'])) {
        if ($plan['is_test_plan'] == 1) {
            pdo()->prepare("UPDATE users SET test_config_count = test_config_count + 1 WHERE chat_id = ?")->execute([$user_id]);
        } else {
            updateUserBalance($user_id, $final_price, 'deduct');
        }

        if ($plan['purchase_limit'] > 0) {
            pdo()->prepare("UPDATE plans SET purchase_count = purchase_count + 1 WHERE id = ?")->execute([$plan_id]);
        }

        if ($discount_applied && $discount_object) {
            pdo()->prepare("UPDATE discount_codes SET usage_count = usage_count + 1 WHERE id = ?")->execute([$discount_object['id']]);
        }
        
        $expire_timestamp = $panel_user_data['expire'] ?? (isset($panel_user_data['expire_date']) ? strtotime($panel_user_data['expire_date']) : (time() + $plan['duration_days'] * 86400));
        
        saveUserService($user_id, [
            'server_id' => $plan['server_id'],
            'username' => $panel_user_data['username'],
            'custom_name' => $custom_name,
            'plan_id' => $plan_id,
            'sub_url' => $panel_user_data['subscription_url'],
            'expire_timestamp' => $expire_timestamp,
            'volume_gb' => $plan['volume_gb'],
        ]);

        
        if ($should_give_commission) {
            $commission_amount = ($final_price * $commission_rate) / 100;
            updateUserBalance($referrer_id, $commission_amount, 'add');
            
            pdo()->prepare("INSERT INTO referral_logs (referrer_id, referred_id, commission_amount, purchase_amount) VALUES (?, ?, ?, ?)")
                ->execute([$referrer_id, $user_id, $commission_amount, $final_price]);

            sendMessage($referrer_id, "💰 یکی از زیرمجموعه‌های شما خریدی به مبلغ " . number_format($final_price) . " تومان انجام داد و مبلغ " . number_format($commission_amount) . " تومان به عنوان کمیسیون به موجودی شما اضافه شد.");
        }
        
        
        $new_balance = $user_data['balance'] - $final_price;
        $sub_link = $panel_user_data['subscription_url'];
        $qr_code_url = generateQrCodeUrl($sub_link);

        $caption = "✅ <b>خرید شما با موفقیت انجام شد.</b>\n";
        if ($discount_applied) {
            $caption .= "🏷 قیمت اصلی: " . number_format($plan['price']) . " تومان\n";
            $caption .= "💰 قیمت با تخفیف: <b>" . number_format($final_price) . " تومان</b>\n";
        }
        $caption .= "\n▫️ نام سرویس: <b>" . htmlspecialchars($custom_name) . "</b>\n\n";

        if ($plan['show_sub_link']) {
            $caption .= "🔗 لینک اشتراک (Subscription):\n<code>" . htmlspecialchars($sub_link) . "</code>\n\n";
        }
        
        $caption .= "💰 موجودی جدید شما: " . number_format($new_balance) . " تومان";

        $profile_link_html = "👤 کاربر: " . htmlspecialchars($first_name) . " (<code>$user_id</code>)\n";

        $admin_notification = "✅ <b>خرید جدید</b>\n\n";
        $admin_notification .= $profile_link_html;
        $admin_notification .= "🛍️ پلن: {$plan['name']}\n";
        $admin_notification .= "💬 نام سرویس: " . htmlspecialchars($custom_name) . "\n";

        if ($discount_applied) {
            $admin_notification .= "💵 قیمت اصلی: " . number_format($plan['price']) . " تومان\n";
            $admin_notification .= "🏷 کد تخفیف: <code>{$discount_code}</code>\n";
            $admin_notification .= "💳 مبلغ پرداخت شده: <b>" . number_format($final_price) . " تومان</b>";
        } else {
            $admin_notification .= "💳 مبلغ پرداخت شده: " . number_format($final_price) . " تومان";
        }
        
        $keyboard_buttons = [];
        if ($plan['show_conf_links'] && !empty($panel_user_data['links'])) {
            $keyboard_buttons[] = [['text' => '📋 دریافت کانفیگ‌ها', 'callback_data' => "get_configs_{$panel_user_data['username']}"]];
        }

        return [
            'success' => true,
            'caption' => $caption,
            'qr_code_url' => $qr_code_url,
            'keyboard' => ['inline_keyboard' => $keyboard_buttons],
            'admin_notification' => $admin_notification,
        ];
    }
    
    return [
        'success' => false,
        'error_message' => "❌ متاسفانه در ایجاد سرویس شما مشکلی پیش آمد. لطفا با پشتیبانی تماس بگیرید. مبلغی از حساب شما کسر نشده است."
    ];
}

function completeCustomPurchase($user_id, $temp_plan, $custom_name, $final_price) {
    $user_data = getUserData($user_id);
    $first_name = $user_data['first_name'];

    
    $temp_plan['full_username'] = preg_replace('/[^a-zA-Z0-9_.]/', '', $custom_name) . '_custom' . $user_id . '_' . time();

    
    $panel_user_data = createPanelUser($temp_plan, $user_id, $temp_plan['id']);
    
    if ($panel_user_data && isset($panel_user_data['username'])) {
        
        
        
        updateUserBalance($user_id, $final_price, 'deduct');

        $expire_timestamp = $panel_user_data['expire'] ?? (isset($panel_user_data['expire_date']) ? strtotime($panel_user_data['expire_date']) : (time() + $temp_plan['duration_days'] * 86400));
        
        saveUserService($user_id, [
            'server_id' => $temp_plan['server_id'],
            'username' => $panel_user_data['username'],
            'custom_name' => $custom_name,
            'plan_id' => $temp_plan['id'], 
            'sub_url' => $panel_user_data['subscription_url'],
            'expire_timestamp' => $expire_timestamp,
            'volume_gb' => $temp_plan['volume_gb'],
        ]);
        
        $new_balance = $user_data['balance'] - $final_price;
        $sub_link = $panel_user_data['subscription_url'];
        $qr_code_url = generateQrCodeUrl($sub_link);

        $caption = "✅ <b>کانفیگ دلخواه شما با موفقیت ایجاد شد.</b>\n";
        $caption .= "\n▫️ نام سرویس: <b>" . htmlspecialchars($custom_name) . "</b>\n\n";

        if ($temp_plan['show_sub_link']) {
            $caption .= "🔗 لینک اشتراک (Subscription):\n<code>" . htmlspecialchars($sub_link) . "</code>\n\n";
        }
        
        $caption .= "💰 موجودی جدید شما: " . number_format($new_balance) . " تومان";

        $chat_info_response = apiRequest('getChat', ['chat_id' => $user_id]);
        $chat_info = json_decode($chat_info_response, true);
        
        $profile_link_html = "👤 کاربر: " . htmlspecialchars($first_name) . " (<code>$user_id</code>)\n";

        $admin_notification = "✅ <b>خرید کانفیگ دلخواه جدید</b>\n\n";
        $admin_notification .= $profile_link_html;
        $admin_notification .= "💬 نام سرویس: " . htmlspecialchars($custom_name) . "\n";
        $admin_notification .= "📊 حجم: {$temp_plan['volume_gb']} GB | ⏰ مدت: {$temp_plan['duration_days']} روز\n";
        $admin_notification .= "💳 مبلغ پرداخت شده: <b>" . number_format($final_price) . " تومان</b>";
        
        $keyboard_buttons = [];
        if ($temp_plan['show_conf_links'] && !empty($panel_user_data['links'])) {
            $keyboard_buttons[] = [['text' => '📋 دریافت کانفیگ‌ها', 'callback_data' => "get_configs_{$panel_user_data['username']}"]];
        }

        return [
            'success' => true,
            'caption' => $caption,
            'qr_code_url' => $qr_code_url,
            'keyboard' => ['inline_keyboard' => $keyboard_buttons],
            'admin_notification' => $admin_notification,
        ];
    }
    
    return [
        'success' => false,
        'error_message' => "❌ متاسفانه در ایجاد سرویس شما مشکلی پیش آمد. لطفا با پشتیبانی تماس بگیرید. مبلغی از حساب شما کسر نشده است."
    ];
}

function handleCustomConfigStart($chat_id) {
    $settings = getSettings();
    if (($settings['custom_config_status'] ?? 'off') !== 'on') {
        sendMessage($chat_id, "❌ قابلیت کانفیگ دلخواه در حال حاضر توسط مدیر غیرفعال شده است.");
        return;
    }
    
    $categories = getCategories(true);
    if (empty($categories)) {
        sendMessage($chat_id, "متاسفانه در حال حاضر هیچ دسته‌بندی فعالی برای کانفیگ دلخواه موجود نیست.");
        return;
    }
    
    $keyboard_buttons = [];
    foreach ($categories as $category) {
        $keyboard_buttons[] = [['text' => '🛍 ' . $category['name'], 'callback_data' => 'custom_cat_' . $category['id']]];
    }
    
    sendMessage($chat_id, "لطفا دسته‌بندی مورد نظر برای کانفیگ دلخواه خود را انتخاب کنید:", ['inline_keyboard' => $keyboard_buttons]);
}

function showReferralMenu($chat_id) {
    $settings = getSettings();
    if (($settings['referral_status'] ?? 'off') !== 'on') {
        sendMessage($chat_id, "❌ سیستم زیرمجموعه‌گیری در حال حاضر غیرفعال است.");
        return;
    }
    
    $user = getUserData($chat_id);
    $referral_link = $user['referral_link'] ?? "https://t.me/" . BOT_USERNAME . "?start=" . $chat_id;

    $stmt_sub_count = pdo()->prepare("SELECT COUNT(*) FROM users WHERE referrer_id = ?");
    $stmt_sub_count->execute([$chat_id]);
    $sub_count = $stmt_sub_count->fetchColumn() ?: 0;
    
    $stmt_commission = pdo()->prepare("SELECT SUM(commission_amount) FROM referral_logs WHERE referrer_id = ?");
    $stmt_commission->execute([$chat_id]);
    $commission_total = $stmt_commission->fetchColumn() ?: 0;

    $message = "<b>🤝 سیستم زیرمجموعه‌گیری</b>\n\n";
    $message .= "با دعوت دوستان خود به ربات، هم شما و هم دوستانتان هدیه بگیرید و از خریدهایشان کسب درآمد کنید!\n\n";
    $message .= "🔗 <b>لینک دعوت اختصاصی شما:</b>\n<code>" . $referral_link . "</code>\n\n";
    $message .= "<b>آمار شما:</b>\n";
    $message .= "▫️ تعداد زیرمجموعه‌ها: <b>" . number_format($sub_count) . " نفر</b>\n";
    $message .= "▫️ کل درآمد شما از کمیسیون: <b>" . number_format($commission_total) . " تومان</b>\n\n";
    
    $reward_referrer = (int)($settings['referral_reward_referrer'] ?? 0);
    $reward_referred = (int)($settings['referral_reward_referred'] ?? 0);
    $commission_rate = (float)($settings['referral_commission_rate'] ?? 0);

    $message .= "<b>🎁 جوایز فعلی:</b>\n";
    $message .= "▫️ هدیه به شما (به ازای هر عضویت): " . number_format($reward_referrer) . " تومان\n";
    $message .= "▫️ هدیه به دوست شما (پس از عضویت): " . number_format($reward_referred) . " تومان\n";
    if ($settings['referral_commission_status'] === 'on' && $commission_rate > 0) {
        $commission_type = ($settings['referral_commission_first_only'] === 'on') ? "فقط از اولین خرید" : "از تمام خریدها";
        $message .= "▫️ کمیسیون خرید: <b>" . $commission_rate . "%</b> (" . $commission_type . ")";
    }

    sendMessage($chat_id, $message);
}

function showReferralManagementMenu($chat_id, $message_id = null) {
    $settings = getSettings();
    $status_icon = ($settings['referral_status'] ?? 'off') == 'on' ? '✅' : '❌';
    $commission_status_icon = ($settings['referral_commission_status'] ?? 'off') == 'on' ? '✅' : '❌';
    $commission_type_icon = ($settings['referral_commission_first_only'] ?? 'on') == 'on' ? '1️⃣' : '♾️';
    $commission_type_text = ($settings['referral_commission_first_only'] ?? 'on') == 'on' ? 'فقط خرید اول' : 'تمام خریدها';

    $message = "<b>🤝 مدیریت سیستم زیرمجموعه‌گیری</b>\n\n";
    $message .= "<b>بخش پاداش ثبت‌نام:</b>\n";
    $message .= "▫️ وضعیت کلی: " . ($status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
    $message .= "▫️ هدیه به معرف: <b>" . number_format($settings['referral_reward_referrer'] ?? 0) . " تومان</b>\n";
    $message .= "▫️ هدیه به کاربر جدید: <b>" . number_format($settings['referral_reward_referred'] ?? 0) . " تومان</b>\n\n";

    $message .= "<b>بخش کمیسیون خرید:</b>\n";
    $message .= "▫️ وضعیت کمیسیون: " . ($commission_status_icon == '✅' ? '<b>فعال</b>' : '<b>غیرفعال</b>') . "\n";
    $message .= "▫️ درصد کمیسیون: <b>" . ($settings['referral_commission_rate'] ?? 5) . "%</b>\n";
    $message .= "▫️ نوع کمیسیون: <b>" . $commission_type_text . "</b>";

    $keyboard = [
        'inline_keyboard' => [
            [['text' => $status_icon . ' فعال/غیرفعال کردن کل سیستم', 'callback_data' => 'toggle_referral_status']],
            [['text' => '💰 تنظیم هدیه معرف', 'callback_data' => 'set_referrer_reward'], ['text' => '🎁 تنظیم هدیه کاربر جدید', 'callback_data' => 'set_referred_reward']],
            [['text' => $commission_status_icon . ' فعال/غیرفعال کردن کمیسیون', 'callback_data' => 'toggle_commission_status']],
            [['text' => '📊 تنظیم درصد کمیسیون', 'callback_data' => 'set_commission_rate']],
            [['text' => $commission_type_icon . ' تغییر نوع کمیسیون', 'callback_data' => 'toggle_commission_first_only']],
            [['text' => '◀️ بازگشت به پنل', 'callback_data' => 'back_to_admin_panel']],
        ]
    ];

    if ($message_id) {
        editMessageText($chat_id, $message_id, $message, $keyboard);
    } else {
        sendMessage($chat_id, $message, $keyboard);
    }
}
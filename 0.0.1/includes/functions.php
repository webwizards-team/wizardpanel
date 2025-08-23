<?php

// =====================================================================
// ---                 توابع اصلی API تلگرام                         ---
// =====================================================================


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
    if (USER_INLINE_KEYBOARD && $update['callback_query']['message']['message_id'] && $oneTimeEdit) {
        $oneTimeEdit = false;
        $params['message_id'] = $update['callback_query']['message']['message_id'];
        $result = apiRequest('editMessageText', $params);
        if (!json_decode($result, true)['ok']) {
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
    if ($oneTimeEdit) {
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

// =====================================================================
// ---           توابع مدیریت داده (بازنویسی شده برای MySQL)         ---
// =====================================================================

// --- مدیریت کاربران ---
function getUserData($chat_id, $first_name = 'کاربر') {
    pdo()
        ->prepare("UPDATE users SET last_seen_at = CURRENT_TIMESTAMP, reminder_sent = 0 WHERE chat_id = ?")
        ->execute([$chat_id]);

    $stmt = pdo()->prepare("SELECT * FROM users WHERE chat_id = ?");
    $stmt->execute([$chat_id]);
    $user = $stmt->fetch();

    if (!$user) {
        $settings = getSettings();
        $welcome_gift = (int)($settings['welcome_gift_balance'] ?? 0);

        $stmt = pdo()->prepare("INSERT INTO users (chat_id, first_name, balance, user_state) VALUES (?, ?, ?, 'main_menu')");
        $stmt->execute([$chat_id, $first_name, $welcome_gift]);

        if ($welcome_gift > 0) {
            sendMessage($chat_id, "🎁 به عنوان هدیه خوش‌آمدگویی، مبلغ " . number_format($welcome_gift) . " تومان به حساب شما اضافه شد.");
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

// --- مدیریت ادمین‌ها ---
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

// --- مدیریت تنظیمات ---
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
        'marzban_panel' => json_encode([]),
        'notification_expire_status' => 'off',
        'notification_expire_days' => '3',
        'notification_expire_gb' => '1',
        'notification_expire_message' => '❗️کاربر گرامی، حجم یا زمان سرویس شما رو به اتمام است. لطفاً جهت تمدید اقدام نمایید.',
        'notification_inactive_status' => 'off',
        'notification_inactive_days' => '30',
        'notification_inactive_message' => '👋 سلام! مدت زیادی است که به ما سر نزده‌اید. برای مشاهده جدیدترین سرویس‌ها و پیشنهادات وارد ربات شوید.',
        'verification_method' => 'off',
        'verification_iran_only' => 'off',
        'inline_keyboard' => 'on'
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

// --- مدیریت دسته‌بندی‌ها، پلن‌ها و سرویس‌ها ---
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
    $stmt = pdo()->prepare("INSERT INTO services (owner_chat_id, server_id, marzban_username, plan_id, sub_url, expire_timestamp, volume_gb) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$chat_id, $serviceData['server_id'], $serviceData['username'], $serviceData['plan_id'], $serviceData['sub_url'], $serviceData['expire_timestamp'], $serviceData['volume_gb']]);
}

function deleteUserService($chat_id, $username, $server_id) {
    $stmt = pdo()->prepare("DELETE FROM services WHERE owner_chat_id = ? AND marzban_username = ? AND server_id = ?");
    return $stmt->execute([$chat_id, $username, $server_id]);
}

// =====================================================================
// ---                        توابع کمکی و عمومی                     ---
// =====================================================================

function getPermissionMap() {
    return [
        'manage_categories' => '🗂 مدیریت دسته‌بندی‌ها',
        'manage_plans' => '📝 مدیریت پلن‌ها',
        'manage_users' => '👥 مدیریت کاربران',
        'broadcast' => '📣 ارسال همگانی',
        'view_stats' => '📊 آمارها',
        'manage_payment' => '💳 مدیریت پرداخت',
        'manage_marzban' => '🌐 مدیریت مرزبان',
        'manage_settings' => '⚙️ تنظیمات کلی ربات',
        'view_tickets' => '📨 مشاهده تیکت‌ها',
        'manage_guides' => '📚 مدیریت راهنما',
        'manage_test_config' => '🧪 مدیریت کانفیگ تست',
        'manage_notifications' => '📢 مدیریت اعلان‌ها',
        'manage_verification' => '🔐 مدیریت احراز هویت',
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

// =====================================================================
// ---                       توابع نمایش منوها                       ---
// =====================================================================

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
        ->query("SELECT p.*, s.name as server_name FROM plans p LEFT JOIN servers s ON p.server_id = s.id ORDER BY p.is_test_plan DESC, p.id ASC")
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

        $plan_info .= "▫️ سرور: <b>{$server_name}</b>\n" . "▫️ دسته‌بندی: {$cat_name}\n" . "▫️ قیمت: " . number_format($plan['price']) . " تومان\n" . "▫️ حجم: {$plan['volume_gb']} گیگابایت | " . "مدت: {$plan['duration_days']} روز\n";

        if ($plan['purchase_limit'] > 0) {
            $plan_info .= "📈 تعداد خرید: <b>{$plan['purchase_count']} / {$plan['purchase_limit']}</b>\n";
        }

        $keyboard_buttons = [];
        // دکمه‌های اصلی مدیریت
        $keyboard_buttons[] = [['text' => "🗑 حذف", 'callback_data' => "delete_plan_{$plan_id}"], ['text' => $status_action, 'callback_data' => "toggle_plan_{$plan_id}"], ['text' => "✏️ ویرایش", 'callback_data' => "edit_plan_{$plan_id}"]];

        // دکمه‌های شرطی
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

function showPlansForCategory($chat_id, $category_id) {
    $category_stmt = pdo()->prepare("SELECT name FROM categories WHERE id = ?");
    $category_stmt->execute([$category_id]);
    $category_name = $category_stmt->fetchColumn();
    if (!$category_name) {
        sendMessage($chat_id, "خطا: دسته‌بندی یافت نشد.");
        return;
    }

    $active_plans = getPlansForCategory($category_id);
    if (empty($active_plans)) {
        sendMessage($chat_id, "متاسفانه در حال حاضر هیچ پلنی در این دسته‌بندی موجود نیست.");
        return;
    }

    $user_balance = getUserData($chat_id)['balance'] ?? 0;
    $message = "🛍️ <b>پلن‌های دسته‌بندی «{$category_name}»</b>\nموجودی شما: " . number_format($user_balance) . " تومان\n\nلطفا پلن مورد نظر خود را انتخاب کنید:";
    $keyboard_buttons = [];
    foreach ($active_plans as $plan) {
        $button_text = "{$plan['name']} | {$plan['volume_gb']}GB | " . number_format($plan['price']) . " تومان";
        $keyboard_buttons[] = [['text' => $button_text, 'callback_data' => "buy_plan_{$plan['id']}"]];
    }
    $keyboard_buttons[] = [['text' => '🎁 اعمال کد تخفیف', 'callback_data' => 'apply_discount_code_' . $category_id]];
    $keyboard_buttons[] = [['text' => '◀️ بازگشت به دسته‌بندی‌ها', 'callback_data' => 'back_to_categories']];
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

    $keyboard_buttons = [[['text' => '🛒 خرید سرویس']], [['text' => '💳 شارژ حساب'], ['text' => '👤 حساب کاربری']], [['text' => '🔧 سرویس‌های من'], ['text' => '📨 پشتیبانی']]];

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
            }
            if (hasPermission($chat_id, 'manage_marzban')) {
                $rows[3][] = ['text' => '🌐 مدیریت مرزبان'];
            }
            if (hasPermission($chat_id, 'manage_settings')) {
                $rows[4][] = ['text' => '⚙️ تنظیمات کلی ربات'];
            }
            if (hasPermission($chat_id, 'manage_guides')) {
                $rows[4][] = ['text' => '📚 مدیریت راهنما'];
            }
            if (hasPermission($chat_id, 'manage_notifications')) {
                $rows[4][] = ['text' => '📢 مدیریت اعلان‌ها'];
            }
            if (hasPermission($chat_id, 'manage_test_config')) {
                $rows[5][] = ['text' => '🧪 مدیریت کانفیگ تست'];
            }
            if ($chat_id == ADMIN_CHAT_ID) {
                $rows[5][] = ['text' => '👨‍💼 مدیریت ادمین‌ها'];
            }
            if (hasPermission($chat_id, 'manage_verification')) {
                $rows[6][] = ['text' => '🔐 مدیریت احراز هویت'];
            }
            $rows[6][] = ['text' => '🎁 مدیریت کد تخفیف'];
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
    if (USER_INLINE_KEYBOARD && $inline_keyboard != 1) {
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

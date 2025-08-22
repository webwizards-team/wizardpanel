<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

define('DEFAULT_BOT_TOKEN', 'TOKEN');
define('DEFAULT_ADMIN_CHAT_ID', 123456789);
define('DEFAULT_DB_NAME', 'NAME');

if (file_exists(__DIR__ . '/includes/config.php')) {
    require_once __DIR__ . '/includes/config.php';
}

// --- بررسی وضعیت نصب ---
$already_installed = false;
if (
    defined('BOT_TOKEN') && BOT_TOKEN !== DEFAULT_BOT_TOKEN &&
    defined('ADMIN_CHAT_ID') && ADMIN_CHAT_ID != DEFAULT_ADMIN_CHAT_ID &&
    defined('DB_NAME') && DB_NAME !== DEFAULT_DB_NAME
) {
    $already_installed = true;
}


// --- متغیرهای اولیه ---
$configFile = __DIR__ . '/includes/config.php';
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$botFileUrl = $protocol . $_SERVER['HTTP_HOST'] . preg_replace('/\/install\.php$/', '', $_SERVER['PHP_SELF']) . '/bot.php';

$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$errors = [];
$successMessages = [];

// --- تابع برای نوشتن در فایل کانفیگ ---
function updateConfigValue($filePath, $key, $value) {
    if (!is_writable($filePath)) {
        return false;
    }
    $content = file_get_contents($filePath);
    $pattern = "/(define\s*\(\s*'$key'\s*,\s*)[^;)]*(\s*\);)/";
    $replacementValue = is_numeric($value) ? $value : "'" . addslashes($value) . "'";
    $replacement = "\${1}" . $replacementValue . "\${2}";
    $newContent = preg_replace($pattern, $replacement, $content, 1);
    if ($newContent === null || $newContent === $content) {
        return false;
    }
    return file_put_contents($filePath, $newContent);
}

// --- توابع دیتابیس ---
function getDbBaseSchemaSQL() {
    return "
    CREATE TABLE IF NOT EXISTS `users` ( `chat_id` BIGINT NOT NULL, `first_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `balance` INT NOT NULL DEFAULT 0, `user_state` VARCHAR(100) NOT NULL DEFAULT 'main_menu', `state_data` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active', `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`chat_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `admins` ( `chat_id` BIGINT NOT NULL, `first_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `permissions` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (`chat_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `categories` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `servers` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE, `url` VARCHAR(255) NOT NULL, `username` VARCHAR(100) NOT NULL, `password` VARCHAR(100) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    CREATE TABLE IF NOT EXISTS `plans` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `server_id` INT NOT NULL, `category_id` INT NOT NULL, `name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `price` INT NOT NULL, `volume_gb` INT NOT NULL, `duration_days` INT NOT NULL, `description` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, `show_sub_link` TINYINT(1) NOT NULL DEFAULT 1, `show_conf_links` TINYINT(1) NOT NULL DEFAULT 0, `status` VARCHAR(20) NOT NULL DEFAULT 'active', FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE, FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `services` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `server_id` INT NOT NULL, `owner_chat_id` BIGINT NOT NULL, `marzban_username` VARCHAR(255) NOT NULL, `plan_id` INT NOT NULL, `sub_url` TEXT NOT NULL, `purchase_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `expire_timestamp` BIGINT NOT NULL, `volume_gb` INT NOT NULL, UNIQUE KEY `marzban_username_unique` (`marzban_username`, `server_id`), FOREIGN KEY (owner_chat_id) REFERENCES users(chat_id) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `settings` ( `setting_key` VARCHAR(100) NOT NULL, `setting_value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, PRIMARY KEY (`setting_key`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `tickets` ( `id` VARCHAR(50) NOT NULL, `user_id` BIGINT NOT NULL, `user_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `subject` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `status` VARCHAR(50) NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `ticket_conversations` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `ticket_id` VARCHAR(50) NOT NULL, `sender` VARCHAR(50) NOT NULL, `message_text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `cache` ( `cache_key` VARCHAR(100) NOT NULL, `cache_value` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, `expire_at` BIGINT NOT NULL, PRIMARY KEY (`cache_key`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `discount_codes` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `code` VARCHAR(50) NOT NULL UNIQUE, `type` VARCHAR(20) NOT NULL, `value` INT NOT NULL, `max_usage` INT NOT NULL, `usage_count` INT NOT NULL DEFAULT 0, `expire_date` DATE NULL DEFAULT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    CREATE TABLE IF NOT EXISTS `guides` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `button_name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE, `content_type` VARCHAR(20) NOT NULL, `message_text` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, `photo_id` VARCHAR(255) NULL, `inline_keyboard` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    CREATE TABLE IF NOT EXISTS `payment_requests` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` BIGINT NOT NULL, `amount` INT NOT NULL, `photo_file_id` VARCHAR(255) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pending', `processed_by_admin_id` BIGINT NULL DEFAULT NULL, `processed_at` TIMESTAMP NULL DEFAULT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
}
function columnExists($pdo, $tableName, $columnName) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) { return false; }
}
function runDbUpgrades($pdo) {
    $messages = [];
    if (!columnExists($pdo, 'plans', 'server_id')) {
        $pdo->exec("ALTER TABLE `plans` ADD `server_id` INT NOT NULL AFTER `id`;");
        $messages[] = "✅ ستون `server_id` به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'server_id')) {
        $pdo->exec("ALTER TABLE `services` ADD `server_id` INT NOT NULL AFTER `id`;");
        $pdo->exec("ALTER TABLE `services` DROP INDEX `marzban_username_unique`, ADD UNIQUE `marzban_username_unique` (`marzban_username`, `server_id`);");
        $messages[] = "✅ ستون `server_id` به جدول `services` اضافه شد.";
    }

    return $messages;
}

if (!$already_installed && $step === 2) {
    $bot_token = trim($_POST['bot_token'] ?? '');
    $admin_id = trim($_POST['admin_id'] ?? '');
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_name = trim($_POST['db_name'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = trim($_POST['db_pass'] ?? '');

    if (empty($bot_token)) $errors[] = 'توکن ربات الزامی است.';
    if (empty($admin_id) || !is_numeric($admin_id)) $errors[] = 'آیدی عددی ادمین الزامی و باید عدد باشد.';
    if (empty($db_name)) $errors[] = 'نام دیتابیس الزامی است.';
    if (empty($db_user)) $errors[] = 'نام کاربری دیتابیس الزامی است.';
    if (!file_exists($configFile)) $errors[] = 'فایل کانفیگ در مسیر includes/config.php یافت نشد!';
    elseif (!is_writable($configFile)) $errors[] = 'فایل کانفیگ قابل نوشتن نیست! لطفاً دسترسی (Permission) فایل includes/config.php را روی 666 یا 777 تنظیم کنید.';

    if (empty($errors)) {
        updateConfigValue($configFile, 'BOT_TOKEN', $bot_token);
        updateConfigValue($configFile, 'ADMIN_CHAT_ID', $admin_id);
        updateConfigValue($configFile, 'DB_HOST', $db_host);
        updateConfigValue($configFile, 'DB_NAME', $db_name);
        updateConfigValue($configFile, 'DB_USER', $db_user);
        updateConfigValue($configFile, 'DB_PASS', $db_pass);
        
        try {
            $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            $pdo->exec("USE `$db_name`");
            
            $pdo->exec(getDbBaseSchemaSQL());
            $successMessages[] = "✅ ساختار پایه جداول با موفقیت بررسی/ایجاد شد.";

            $upgradeMessages = runDbUpgrades($pdo);
            $successMessages = array_merge($successMessages, $upgradeMessages);
            if (empty($upgradeMessages)) {
                $successMessages[] = "ℹ️ دیتابیس شما از قبل به‌روز بود.";
            }
            
        } catch (PDOException $e) {
            $errors[] = "خطا در اتصال به دیتابیس یا اجرای کوئری‌ها: " . $e->getMessage();
        }

        if (empty($errors)) {
            
            $apiUrl = "https://api.telegram.org/bot" . $bot_token . "/setWebhook";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['url' => $botFileUrl],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            
            if(curl_errno($ch)){
                $errors[] = 'خطای cURL وبهوک: ' . curl_error($ch);
            }
            curl_close($ch);
        
            
            $response_data = json_decode($response, true);
            if (!$response || !$response_data || !$response_data['ok']) {
                $errors[] = 'خطا در ثبت وبهوک: ' . ($response_data['description'] ?? 'پاسخ نامعتبر از تلگرام. مطمئن شوید توکن صحیح است.');
            }
        }
        
        if (empty($errors)) {
            $successMessages[] = "✅ نصب/ارتقا با موفقیت انجام شد! ربات شما اکنون فعال است.";
            
            $already_installed = true; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نصب و راه‌اندازی ربات</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; background-color: #f4f7f6; color: #333; line-height: 1.6; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 40px auto; padding: 30px; background-color: #fff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2c3e50; text-align: center; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 8px; color: #555; }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; transition: border-color: 0.3s; }
        input[type="text"]:focus, input[type="password"]:focus { border-color: #3498db; outline: none; }
        .btn { display: block; width: 100%; padding: 12px; background-color: #3498db; color: #fff; border: none; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background-color: 0.3s; }
        .btn:hover { background-color: #2980b9; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>نصب و راه‌اندازی ربات</h1>

        <?php if ($already_installed): ?>
            <?php if (!empty($successMessages)): ?>
                <div class="alert alert-success">
                    <strong>عملیات با موفقیت انجام شد!</strong>
                    <ul style="padding-right: 20px;">
                        <?php foreach ($successMessages as $msg): ?>
                            <li><?php echo $msg; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                 <div class="alert alert-info">
                    <strong>توجه:</strong> به نظر می‌رسد ربات قبلاً نصب و پیکربندی شده است.
                </div>
            <?php endif; ?>
            <div class="alert alert-danger">
                <strong>اقدام امنیتی فوری و بسیار مهم:</strong>
                <br>
                برای جلوگیری از دسترسی غیرمجاز و بازنویسی تنظیمات، لطفاً همین الان فایل <code>install.php</code> را از هاست خود **حذف** کنید.
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <strong>خطا!</strong>
                    <ul style="padding-right: 20px;">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="alert alert-info">
                آدرس وبهوک شما به صورت خودکار به آدرس زیر تنظیم خواهد شد: <br><code><?php echo htmlspecialchars($botFileUrl); ?></code>
            </div>
            <form action="install.php" method="post">
                <input type="hidden" name="step" value="2">
                
                <div class="form-group">
                    <label for="bot_token">توکن ربات (Bot Token)</label>
                    <input type="text" id="bot_token" name="bot_token" required value="<?php echo htmlspecialchars($_POST['bot_token'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="admin_id">آیدی عددی ادمین اصلی</label>
                    <input type="text" id="admin_id" name="admin_id" required value="<?php echo htmlspecialchars($_POST['admin_id'] ?? ''); ?>">
                </div>

                <hr style="margin: 30px 0; border: none; border-top: 1px solid #eee;">

                <div class="form-group">
                    <label for="db_host">هاست دیتابیس (معمولاً localhost)</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
                </div>

                <div class="form-group">
                    <label for="db_name">نام دیتابیس (Database Name)</label>
                    <input type="text" id="db_name" name="db_name" required value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="db_user">نام کاربری دیتابیس (Database User)</label>
                    <input type="text" id="db_user" name="db_user" required value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="db_pass">رمز عبور دیتابیس (Database Password)</label>
                    <input type="password" id="db_pass" name="db_pass" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
                </div>
                
                <button type="submit" class="btn">نصب و راه‌اندازی</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

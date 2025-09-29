<?php

// --- بخش حذف خودکار ---
if (isset($_GET['action']) && $_GET['action'] === 'self_delete') {
    if (file_exists(__FILE__) && is_writable(__FILE__)) {
        unlink(__FILE__);
    }
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- متغیرهای اولیه ---
$configFile = __DIR__ . '/includes/config.php';
$botFileUrl = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/bot.php';

$step = isset($_POST['step']) ? (int)$_POST['step'] : 1;
$errors = [];
$successMessages = [];

function generateRandomString(int $length = 32): string {
    return bin2hex(random_bytes($length / 2));
}

// --- تابع برای نوشتن در فایل کانفیگ ---
function updateConfigValue($filePath, $key, $value) {
    if (!file_exists($filePath) || !is_writable($filePath)) {
        return false;
    }
    $content = file_get_contents($filePath);
    $pattern = "/(define\s*\(\s*'$key'\s*,\s*)[^;)]*(\s*\);)/";
    $replacementValue = is_numeric($value) ? $value : "'" . addslashes($value) . "'";
    $replacement = "\${1}" . $replacementValue . "\${2}";
    
    if (preg_match($pattern, $content)) {
        $newContent = preg_replace($pattern, $replacement, $content, 1);
    } else {
        $newContent = $content . PHP_EOL . "define('{$key}', {$replacementValue});" . PHP_EOL;
    }

    return file_put_contents($filePath, $newContent);
}

// --- کد SQL برای ساخت جداول پایه ---
function getDbBaseSchemaSQL() {
    return "
    CREATE TABLE IF NOT EXISTS `users` ( `chat_id` BIGINT NOT NULL, `first_name` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci, `balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00, `user_state` VARCHAR(255) DEFAULT 'main_menu', `state_data` TEXT, `status` VARCHAR(20) NOT NULL DEFAULT 'active', `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`chat_id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `admins` ( `chat_id` BIGINT NOT NULL PRIMARY KEY, `first_name` VARCHAR(255), `permissions` TEXT, `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `categories` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `servers` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(255) NOT NULL, `url` VARCHAR(255) NOT NULL, `username` VARCHAR(255) NOT NULL, `password` VARCHAR(255) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `plans` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `server_id` INT NOT NULL, `category_id` INT NOT NULL, `name` VARCHAR(255) NOT NULL, `price` DECIMAL(10,2) NOT NULL, `volume_gb` INT NOT NULL, `duration_days` INT NOT NULL, `description` TEXT, `show_sub_link` TINYINT(1) NOT NULL DEFAULT 1, `show_conf_links` TINYINT(1) NOT NULL DEFAULT 1, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `services` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `server_id` INT NOT NULL, `owner_chat_id` BIGINT NOT NULL, `marzban_username` VARCHAR(255) NOT NULL, `plan_id` INT NOT NULL, `sub_url` TEXT, `purchase_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `expire_timestamp` BIGINT, `volume_gb` INT, `warning_sent` TINYINT(1) NOT NULL DEFAULT 0 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `settings` ( `setting_key` VARCHAR(255) NOT NULL PRIMARY KEY, `setting_value` TEXT ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `tickets` ( `id` VARCHAR(50) NOT NULL PRIMARY KEY, `user_id` BIGINT NOT NULL, `user_name` VARCHAR(255), `subject` VARCHAR(255), `status` VARCHAR(20) NOT NULL DEFAULT 'open', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `ticket_conversations` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `ticket_id` VARCHAR(50) NOT NULL, `sender` VARCHAR(10) NOT NULL, `message_text` TEXT, `sent_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `cache` ( `cache_key` VARCHAR(255) NOT NULL PRIMARY KEY, `cache_value` TEXT, `expire_at` INT ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `discount_codes` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `code` VARCHAR(50) NOT NULL UNIQUE, `type` VARCHAR(10) NOT NULL, `value` DECIMAL(10,2) NOT NULL, `max_usage` INT NOT NULL, `usage_count` INT NOT NULL DEFAULT 0, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `guides` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `button_name` VARCHAR(255) NOT NULL, `content_type` VARCHAR(10) NOT NULL, `message_text` TEXT, `photo_id` VARCHAR(255) DEFAULT NULL, `inline_keyboard` TEXT, `status` VARCHAR(20) NOT NULL DEFAULT 'active' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `payment_requests` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `user_id` BIGINT NOT NULL, `amount` DECIMAL(10,2) NOT NULL, `photo_file_id` VARCHAR(255) NOT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pending', `processed_by_admin_id` BIGINT, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `processed_at` TIMESTAMP NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS `renewal_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `service_username` varchar(255) NOT NULL,
  `days_to_add` int(11) NOT NULL,
  `gb_to_add` int(11) NOT NULL,
  `total_cost` decimal(10,2) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `photo_file_id` varchar(255) DEFAULT NULL,
  `processed_by_admin_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `authority` varchar(50) NOT NULL,
  `ref_id` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `authority` (`authority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
}

function columnExists($pdo, $tableName, $columnName) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$tableName` LIKE ?");
        $stmt->execute([$columnName]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function runDbUpgrades($pdo) {
    $messages = [];

    // --- تغییر نام ستون state به user_state برای جلوگیری از تداخل با کلمات کلیدی SQL ---
    if (columnExists($pdo, 'users', 'state') && !columnExists($pdo, 'users', 'user_state')) {
        $pdo->exec("ALTER TABLE `users` CHANGE `state` `user_state` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT 'main_menu';");
        $messages[] = "✅ ستون `state` در جدول `users` به `user_state` تغییر نام یافت.";
    }

    // --- ارتقا برای پشتیبانی از چند پنل ---
    if (!columnExists($pdo, 'servers', 'type')) {
        $pdo->exec("ALTER TABLE `servers` ADD `type` VARCHAR(20) NOT NULL DEFAULT 'marzban' AFTER `password`;");
        $messages[] = "✅ ستون `type` برای پشتیبانی از چند نوع پنل به جدول `servers` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'inbound_id')) {
        $pdo->exec("ALTER TABLE `plans` ADD `inbound_id` INT NULL DEFAULT NULL AFTER `category_id`;");
        $messages[] = "✅ ستون `inbound_id` برای پنل سنایی به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'marzneshin_service_id')) {
        $pdo->exec("ALTER TABLE `plans` ADD `marzneshin_service_id` INT NULL DEFAULT NULL AFTER `inbound_id`;");
        $messages[] = "✅ ستون `marzneshin_service_id` برای پنل مرزنشین به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'sanaei_inbound_id')) {
        $pdo->exec("ALTER TABLE `services` ADD `sanaei_inbound_id` INT NULL DEFAULT NULL AFTER `volume_gb`;");
        $messages[] = "✅ ستون `sanaei_inbound_id` برای پنل سنایی به جدول `services` اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'sanaei_uuid')) {
        $pdo->exec("ALTER TABLE `services` ADD `sanaei_uuid` VARCHAR(255) NULL DEFAULT NULL AFTER `sanaei_inbound_id`;");
        $messages[] = "✅ ستون `sanaei_uuid` برای پنل سنایی به جدول `services` اضافه شد.";
    }
    
    // --- ارتقاهای مربوط به اعلان‌ها و ردیابی کاربران ---
    if (!columnExists($pdo, 'users', 'last_seen_at')) {
        $pdo->exec("ALTER TABLE `users` ADD `last_seen_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`;");
        $messages[] = "✅ ستون `last_seen_at` برای ردیابی آخرین فعالیت کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'reminder_sent')) {
        $pdo->exec("ALTER TABLE `users` ADD `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `last_seen_at`;");
        $messages[] = "✅ ستون `reminder_sent` برای ارسال یادآور عدم فعالیت اضافه شد.";
    }
    if (!columnExists($pdo, 'services', 'warning_sent')) {
        $pdo->exec("ALTER TABLE `services` ADD `warning_sent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `volume_gb`;");
        $messages[] = "✅ ستون `warning_sent` برای ارسال هشدار انقضا به جدول `services` اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'test_config_count')) {
        $pdo->exec("ALTER TABLE `users` ADD `test_config_count` INT NOT NULL DEFAULT 0 AFTER `status`;");
        $messages[] = "✅ ستون `test_config_count` برای کانفیگ تست به جدول `users` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'is_test_plan')) {
        $pdo->exec("ALTER TABLE `plans` ADD `is_test_plan` TINYINT(1) NOT NULL DEFAULT 0 AFTER `show_conf_links`;");
        $messages[] = "✅ ستون `is_test_plan` برای کانفیگ تست به جدول `plans` اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'purchase_limit')) {
        $pdo->exec("ALTER TABLE `plans` ADD `purchase_limit` INT NOT NULL DEFAULT 0 AFTER `is_test_plan`;");
        $messages[] = "✅ ستون `purchase_limit` برای محدودیت خرید پلن‌ها اضافه شد.";
    }
    if (!columnExists($pdo, 'plans', 'purchase_count')) {
        $pdo->exec("ALTER TABLE `plans` ADD `purchase_count` INT NOT NULL DEFAULT 0 AFTER `purchase_limit`;");
        $messages[] = "✅ ستون `purchase_count` برای شمارش خرید پلن‌ها اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'is_verified')) {
        $pdo->exec("ALTER TABLE `users` ADD `is_verified` TINYINT(1) NOT NULL DEFAULT 0 AFTER `test_config_count`;");
        $messages[] = "✅ ستون `is_verified` برای وضعیت احراز هویت کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'phone_number')) {
        $pdo->exec("ALTER TABLE `users` ADD `phone_number` VARCHAR(20) NULL DEFAULT NULL AFTER `is_verified`;");
        $messages[] = "✅ ستون `phone_number` برای ذخیره شماره تلفن کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'admins', 'is_super_admin')) {
        $pdo->exec("ALTER TABLE `admins` ADD `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0;");
        $messages[] = "✅ ستون `is_super_admin` برای مدیریت ادمین اصلی اضافه شد.";
    }
    if (!columnExists($pdo, 'users', 'inline_keyboard')) {
        $pdo->exec("ALTER TABLE `users` ADD `inline_keyboard` TINYINT(1) NOT NULL DEFAULT 0;");
        $messages[] = "✅ ستون `inline_keyboard` برای مدیریت نوع کیبورد کاربران اضافه شد.";
    }
    if (!columnExists($pdo, 'servers', 'sub_host')) {
        $pdo->exec("ALTER TABLE `servers` ADD `sub_host` VARCHAR(255) NULL DEFAULT NULL AFTER `url`;");
        $messages[] = "✅ ستون `sub_host` برای لینک اشتراک سفارشی به جدول `servers` اضافه شد.";
    }

    return $messages;
}


if ($step === 2) {
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
    
    if (!is_dir(__DIR__ . '/includes')) mkdir(__DIR__ . '/includes', 0755, true);
    if (!file_exists($configFile)) file_put_contents($configFile, "<?php" . PHP_EOL);
    
    if (!is_writable($configFile)) $errors[] = 'فایل کانفیگ قابل نوشتن نیست! لطفاً دسترسی (Permission) فایل includes/config.php را روی 666 یا 777 تنظیم کنید.';

    if (empty($errors)) {
        $config_content = '<?php' . PHP_EOL . PHP_EOL;
        $config_content .= "define('DB_HOST', '{$db_host}');" . PHP_EOL;
        $config_content .= "define('DB_NAME', '{$db_name}');" . PHP_EOL;
        $config_content .= "define('DB_USER', '{$db_user}');" . PHP_EOL;
        $config_content .= "define('DB_PASS', '{$db_pass}');" . PHP_EOL . PHP_EOL;
        $config_content .= "define('BOT_TOKEN', '{$bot_token}');" . PHP_EOL;
        $config_content .= "define('ADMIN_CHAT_ID', {$admin_id});" . PHP_EOL;
        $secretToken = generateRandomString(64);
        $config_content .= "define('SECRET_TOKEN', '{$secretToken}');" . PHP_EOL;
        file_put_contents($configFile, $config_content);

        try {
            $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
            $pdo->exec("USE `$db_name`");

            $pdo->exec(getDbBaseSchemaSQL());
            $successMessages[] = "✅ ساختار پایه جداول با موفقیت بررسی/ایجاد شد.";

            $upgradeMessages = runDbUpgrades($pdo);
            $successMessages = array_merge($successMessages, $upgradeMessages);
            if (empty($upgradeMessages)) {
                $successMessages[] = "ℹ️ دیتابیس شما از قبل به‌روز بود.";
            }
            
            $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
                ('bot_status', 'on'), ('sales_status', 'on'), ('join_channel_status', 'off'), ('join_channel_id', '@'),
                ('welcome_gift_balance', '0'), ('inline_keyboard', 'on'), ('verification_method', 'off'),
                ('verification_iran_only', 'off'), ('test_config_usage_limit', '1'), ('notification_expire_status', 'off'),
                ('notification_expire_days', '3'), ('notification_expire_gb', '1'), ('notification_inactive_status', 'off'),
                ('notification_inactive_days', '30'),
                ('renewal_status', 'off'), ('renewal_price_per_day', '1000'), ('renewal_price_per_gb', '2000'), ('payment_gateway_status', 'off'), ('zarinpal_merchant_id', '');");
            $successMessages[] = "✅ تنظیمات پیش‌فرض با موفقیت افزوده شد.";

        } catch (PDOException $e) {
            $errors[] = "خطا در اتصال به دیتابیس یا اجرای کوئری‌ها: " . $e->getMessage();
        }

        if (empty($errors)) {
            $apiUrl = "https://api.telegram.org/bot$bot_token/setWebhook?secret_token=$secretToken&url=" . urlencode($botFileUrl);
            $response = @file_get_contents($apiUrl);
            $response_data = json_decode($response, true);
            if (!$response || !$response_data['ok']) {
                $errors[] = 'خطا در ثبت وبهوک: ' . ($response_data['description'] ?? 'پاسخ نامعتبر از تلگرام. مطمئن شوید توکن صحیح است.');
            }
        }

        if (empty($errors)) {
            $successMessages[] = "✅ نصب/ارتقا با موفقیت انجام شد! ربات شما اکنون فعال است.";
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
        .container { max-width: 600px; margin: 40px auto; padding: 30px; background-color: #fff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); }
        h1 { color: #2c3e50; text-align: center; border-bottom: 2px solid #3498db; padding-bottom: 10px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: bold; margin-bottom: 8px; color: #555; }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; transition: border-color 0.3s; }
        input[type="text"]:focus, input[type="password"]:focus { border-color: #3498db; outline: none; }
        .btn { display: block; width: 100%; padding: 12px; background-color: #3498db; color: #fff; border: none; border-radius: 4px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background-color 0.3s; }
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

    <?php if (!empty($successMessages)): ?>
        <div class="alert alert-success">
            <strong>عملیات با موفقیت انجام شد!</strong>
            <ul style="padding-right: 20px;">
                <?php foreach ($successMessages as $msg): ?>
                    <li><?php echo $msg; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="alert alert-danger">
            <strong>مرحله نهایی و بسیار مهم:</strong> برای امنیت کامل، این فایل نصب تا چند ثانیه دیگر به صورت **خودکار حذف خواهد شد**.
        </div>
    <?php else: ?>
        <div class="alert alert-info">
            آدرس وبهوک شما به صورت خودکار به آدرس زیر تنظیم خواهد شد:
            <br><code><?php echo htmlspecialchars($botFileUrl); ?></code>
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

<?php if (!empty($successMessages)): ?>
    <script>
        setTimeout(function () {
            fetch('install.php?action=self_delete')
                .then(function () {
                    console.log('Self-delete command sent.');
                })
                .catch(function (error) {
                    console.error('Could not send self-delete command:', error);
                });
        }, 3000); // 3 seconds delay
    </script>
<?php endif; ?>

</body>
</html>
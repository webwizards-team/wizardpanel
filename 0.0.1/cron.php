<?php

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// --- فراخوانی فایل‌های مورد نیاز ---
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/marzban_api.php';

echo "Cron job started at " . date('Y-m-d H:i:s') . "\n";

// --- تابع بررسی هشدارهای انقضا ---
function checkExpirationWarnings()
{
    $settings = getSettings();
    $status = $settings['notification_expire_status'] ?? 'off';
    $days = (int) ($settings['notification_expire_days'] ?? 3);
    $gb = (int) ($settings['notification_expire_gb'] ?? 1);
    $message = $settings['notification_expire_message'] ?? '';

    if ($status === 'off' || empty($message)) {
        echo "-> Expiration warnings are disabled. Skipping.\n";
        return;
    }

    echo "-> Checking for expiration warnings...\n";

    $services = pdo()
        ->query("SELECT id, owner_chat_id, marzban_username, server_id FROM services WHERE warning_sent = 0 AND expire_timestamp > " . time())
        ->fetchAll(PDO::FETCH_ASSOC);
    if (empty($services)) {
        echo "   - No services to check for expiration.\n";
        return;
    }

    $threshold_time = time() + $days * 86400;
    $threshold_gb_bytes = $gb * 1024 * 1024 * 1024;
    $sent_count = 0;

    foreach ($services as $service) {
        if (empty($service['server_id'])) {
            echo "   - Service ID {$service['id']} has no server_id. Skipping.\n";
            continue;
        }

        $user_info = getMarzbanUser($service['marzban_username'], $service['server_id']);
        if (!$user_info || isset($user_info['detail'])) {
            echo "   - Could not fetch Marzban info for user {$service['marzban_username']} on server {$service['server_id']}. Skipping.\n";
            continue;
        }

        $expire_ts = $user_info['expire'] ?? 0;
        $data_limit = $user_info['data_limit'] ?? 0;
        $used_traffic = $user_info['used_traffic'] ?? 0;
        $data_remaining = $data_limit - $used_traffic;

        $warn = false;
        $reason = "";
        if ($expire_ts > 0 && $expire_ts < $threshold_time) {
            $warn = true;
            $reason = " (Reason: Time limit)";
        }
        if ($data_limit > 0 && $data_remaining < $threshold_gb_bytes) {
            $warn = true;
            $reason .= " (Reason: Data limit)";
        }

        if ($warn) {
            sendMessage($service['owner_chat_id'], $message);

            pdo()
                ->prepare("UPDATE services SET warning_sent = 1 WHERE id = ?")
                ->execute([$service['id']]);
            echo "   - Warning sent to user {$service['owner_chat_id']} for service {$service['marzban_username']}" . trim($reason) . "\n";
            $sent_count++;
            usleep(200000);
        }
    }
    echo "   - Total expiration warnings sent: {$sent_count}\n";
}

// --- تابع بررسی کاربران غیرفعال ---
function checkInactiveUsers()
{
    $settings = getSettings();
    $status = $settings['notification_inactive_status'] ?? 'off';
    $days = (int) ($settings['notification_inactive_days'] ?? 30);
    $message = $settings['notification_inactive_message'] ?? '';

    if ($status === 'off' || empty($message)) {
        echo "-> Inactivity reminders are disabled. Skipping.\n";
        return;
    }

    echo "-> Checking for inactive users...\n";
    $inactive_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    $stmt = pdo()->prepare("SELECT chat_id FROM users WHERE status = 'active' AND last_seen_at IS NOT NULL AND last_seen_at < ? AND reminder_sent = 0");
    $stmt->execute([$inactive_date]);
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($users)) {
        echo "   - No inactive users found.\n";
        return;
    }

    $sent_count = 0;
    foreach ($users as $chat_id) {
        sendMessage($chat_id, $message);
        pdo()
            ->prepare("UPDATE users SET reminder_sent = 1 WHERE chat_id = ?")
            ->execute([$chat_id]);
        echo "   - Inactivity reminder sent to user {$chat_id}\n";
        $sent_count++;
        usleep(200000);
    }
    echo "   - Total inactivity reminders sent: {$sent_count}\n";
}

// --- اجرای توابع ---
try {
    checkExpirationWarnings();
    checkInactiveUsers();
} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
}

echo "Cron job finished at " . date('Y-m-d H:i:s') . "\n";
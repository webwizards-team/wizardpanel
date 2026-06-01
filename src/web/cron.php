<?php
// ==========================================
// اسکریپت مانیتورینگ خودکار حجم نمایندگان (Cron Job)
// ==========================================
// این فایل باید در کرون‌جاب سرور (مثلاً هر ۵ دقیقه یک‌بار) تنظیم شود:
// */5 * * * * php /path/to/web/cron.php
// ==========================================

// جلوگیری از اجرای فایل توسط کاربران عادی (فقط خط فرمان یا با پسورد خاص)
if (php_sapi_name() !== 'cli' && (!isset($_GET['key']) || $_GET['key'] !== 'QAWSEDRFqawsedrf123')) {
    die('دسترسی غیرمجاز.');
}

date_default_timezone_set('Asia/Tehran');

if (!file_exists('reseller_config.php')) {
    die("خطا: فایل کانفیگ یافت نشد.");
}
require_once 'reseller_config.php';

function formatBytes($bytes, $decimals = 2) {
    if ($bytes <= 0) return "0 MB";
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / (1024 ** $i), $decimals) . ' ' . $units[$i];
}

// کلاس مدیریت اتصالات API در کرون
class CronPanelAPI {
    private $panelUrl;
    private $username;
    private $password;
    private $cookieFile;

    public function __construct($panelUrl, $username, $password) {
        $this->panelUrl = rtrim($panelUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'cron_reseller_sanaei_' . md5($username));
    }

    private function executeCurl($url, $postData = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);

        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (is_array($postData)) {
                $postData = http_build_query($postData);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            if (is_string($postData) && (strpos($postData, '{') === 0 || strpos($postData, '[') === 0)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
            }
        }

        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    public function login() {
        $postData = ['username' => $this->username, 'password' => $this->password];
        $res = json_decode($this->executeCurl($this->panelUrl . "/login", $postData), true);
        return isset($res['success']) && $res['success'];
    }

    public function getInbounds() {
        $res = json_decode($this->executeCurl($this->panelUrl . "/panel/api/inbounds/list"), true);
        return (isset($res['success']) && $res['success'] && isset($res['obj'])) ? $res['obj'] : [];
    }

    public function updateInbound($inbound) {
        $url = $this->panelUrl . "/panel/api/inbounds/update/" . $inbound['id'];
        $res = json_decode($this->executeCurl($url, json_encode($inbound)), true);
        return $res['success'] ?? false;
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) @unlink($this->cookieFile);
    }
}

echo "=== شروع مانیتورینگ حجم نمایندگان - " . date('Y-m-d H:i:s') . " ===\n\n";

try {
    // واکشی تمام سرورها
    $panels = $pdo->query("SELECT * FROM panels")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($panels as $p) {
        echo "----------------------------------------\n";
        echo "سرور: {$p['name']} ({$p['url']})\n";
        
        $api = new CronPanelAPI($p['url'], $p['username'], $p['password']);
        if (!$api->login()) {
            echo "[خطا] اتصال به سرور برقرار نشد!\n";
            continue;
        }

        $inbounds = $api->getInbounds();
        if (empty($inbounds)) {
            echo "[هشدار] هیچ اینباندی در این سرور یافت نشد.\n";
            continue;
        }

        // واکشی تمام نمایندگان این سرور
        $stmt = $pdo->prepare("SELECT * FROM resellers WHERE panel_id = ?");
        $stmt->execute([$p['id']]);
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($admins)) {
            echo "هیچ نماینده‌ای روی این سرور تعریف نشده است.\n";
            continue;
        }

        // بررسی تک‌تک اینباندها
        foreach ($inbounds as $inbound) {
            $inboundId = $inbound['id'];
            $settings = json_decode($inbound['settings'], true);
            if (!isset($settings['clients'])) continue;

            $clientStatsMap = [];
            if (isset($inbound['clientStats'])) {
                foreach ($inbound['clientStats'] as $stat) {
                    $clientStatsMap[$stat['email']] = $stat;
                }
            }

            $inboundUpdated = false;

            // بررسی نمایندگان متصل به این اینباند
            foreach ($admins as $admin) {
                if ($admin['inbound_id'] != $inboundId) continue;

                $adminPrefix = $admin['username'] . '_';
                $adminLimitGB = floatval($admin['traffic_limit']);
                
                // اگر حجم نامحدود باشد، نیازی به بررسی نیست
                if ($adminLimitGB <= 0) {
                    echo "[نامحدود] نماینده '{$admin['username']}': لیمیت حجم ندارد.\n";
                    continue;
                }

                $adminUsedBytes = floatval($admin['historical_traffic'] ?? 0);
                $activeClientsCount = 0;
                
                // محاسبه مصرف و تعداد کلاینت‌های فعال این نماینده
                foreach ($settings['clients'] as $cl) {
                    if (strpos($cl['email'], $adminPrefix) === 0) {
                        $email = $cl['email'];
                        $stat = $clientStatsMap[$email] ?? ['up' => 0, 'down' => 0];
                        $adminUsedBytes += ($stat['up'] + $stat['down']);
                        if (!isset($cl['enable']) || $cl['enable'] === true) {
                            $activeClientsCount++;
                        }
                    }
                }

                $adminLimitBytes = $adminLimitGB * 1073741824;
                $isOver = ($adminUsedBytes >= $adminLimitBytes);

                if ($isOver) {
                    echo "[اتمام حجم] نماینده '{$admin['username']}': مصرف " . formatBytes($adminUsedBytes) . " از " . formatBytes($adminLimitBytes) . "\n";
                    
                    if ($activeClientsCount > 0) {
                        // غیرفعال‌سازی تمامی کلاینت‌های فعال نماینده در حافظه
                        $disabledCount = 0;
                        foreach ($settings['clients'] as &$cl) {
                            if (strpos($cl['email'], $adminPrefix) === 0 && (!isset($cl['enable']) || $cl['enable'] === true)) {
                                $cl['enable'] = false;
                                $disabledCount++;
                            }
                        }
                        if ($disabledCount > 0) {
                            $inboundUpdated = true;
                            echo ">> تعداد {$disabledCount} کانفیگ نماینده با موفقیت خاموش شدند.\n";
                        }
                    } else {
                        echo ">> تمامی کانفیگ‌ها از قبل خاموش بوده‌اند.\n";
                    }
                } else {
                    echo "[فعال] نماینده '{$admin['username']}': مصرف " . formatBytes($adminUsedBytes) . " از " . formatBytes($adminLimitBytes) . " (" . $activeClientsCount . " فعال)\n";
                }
            }

            // اگر تغییری در کلاینت‌های اینباند ایجاد شده بود، آن را ذخیره می‌کنیم
            if ($inboundUpdated) {
                $inbound['settings'] = json_encode($settings);
                if ($api->updateInbound($inbound)) {
                    echo ">> تغییرات اینباند ID: {$inboundId} با موفقیت در پنل سنایی ثبت شد.\n";
                } else {
                    echo "[خطا] ثبت تغییرات اینباند در پنل سنایی ناموفق بود.\n";
                }
            }
        }
    }

} catch (PDOException $e) {
    echo "[خطای دیتابیس] " . $e->getMessage() . "\n";
}

echo "\n=== پایان مانیتورینگ حجم نمایندگان - " . date('Y-m-d H:i:s') . " ===\n";
?>

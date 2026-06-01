<?php
ini_set('session.cookie_lifetime', 86400 * 30);
ini_set('session.gc_maxlifetime', 86400 * 30);
session_start();
date_default_timezone_set('Asia/Tehran');

if (!file_exists('reseller_config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once 'reseller_config.php';

try {
    $pdo->query("SELECT 1 FROM resellers LIMIT 1");
} catch (PDOException $e) {
    header('Location: ../install.php');
    exit;
}

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['admin_data'])) {
    header('Location: login.php');
    exit;
}
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $session_lifetime)) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ==========================================
// 1. دریافت اطلاعات اختصاصی نماینده فعلی
// ==========================================
$currentAdmin = $_SESSION['admin_data'];
$adminName = $currentAdmin['name'];
$defaultInboundId = $currentAdmin['inbound_id'];

$maxClientsLimit = 0;
$adminTrafficLimit = 0.0;
$adminHistoricalTraffic = 0;
if (!empty($_SESSION['admin_user'])) {
    $limitStmt = $pdo->prepare("SELECT max_clients, traffic_limit, historical_traffic FROM resellers WHERE username = ? LIMIT 1");
    $limitStmt->execute([$_SESSION['admin_user']]);
    $adminRow = $limitStmt->fetch(PDO::FETCH_ASSOC);
    if ($adminRow) {
        $maxClientsLimit = intval($adminRow['max_clients'] ?: 0);
        $adminTrafficLimit = floatval($adminRow['traffic_limit'] ?: 0.0);
        $adminHistoricalTraffic = floatval($adminRow['historical_traffic'] ?: 0);
    }
}

$panelUrl = $currentAdmin['panel_url'];
$username = $currentAdmin['panel_user'];
$password = $currentAdmin['panel_pass'];
$subDomain = $currentAdmin['sub_domain'];

// ==========================================
// 2. توابع کمکی
// ==========================================
function formatBytes($bytes, $decimals = 2) {
    if ($bytes <= 0) return "0 MB";
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / (1024 ** $i), $decimals) . ' ' . $units[$i];
}

// ==========================================
// 3. کلاس ارتباط با پنل سنایی
// ==========================================
class SanaeiPanel {
    private $panelUrl;
    private $username;
    private $password;
    private $cookieFile;

    public function __construct($panelUrl, $username, $password) {
        $this->panelUrl = rtrim($panelUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'sanaei_reseller_cookie_' . md5($username));
    }

    private function executeCurl($url, $postData = null, $isPost = false, $isDelete = false) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);

        if ($isDelete) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        } elseif ($postData !== null || $isPost) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                if (is_string($postData) && (strpos($postData, '{') === 0 || strpos($postData, '[') === 0)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
                }
            }
        }

        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    public function login() {
        $postFields = http_build_query(['username' => $this->username, 'password' => $this->password]);
        $response = $this->executeCurl($this->panelUrl . "/login", $postFields);
        $res = json_decode($response, true);
        return isset($res['success']) && $res['success'] === true;
    }

    public function addClient($inboundId, $email, $limitIp = 0, $totalGB = 0, $expiryDays = 0) {
        $uuid = $this->generateUUID();
        $subId = $this->generateSubId();
        $expiryTime = $expiryDays > 0 ? (time() + ($expiryDays * 86400)) * 1000 : 0;
        $totalTraffic = $totalGB > 0 ? $totalGB * 1073741824 : 0;

        $client = [
            "id" => $uuid, "email" => $email, "enable" => true,
            "limitIp" => $limitIp, "totalGB" => $totalTraffic,
            "expiryTime" => $expiryTime, "tgId" => "", "subId" => $subId
        ];

        $postData = json_encode(["id" => $inboundId, "settings" => json_encode(["clients" => [$client]])]);
        $response = $this->executeCurl($this->panelUrl . "/panel/api/inbounds/addClient", $postData);
        return json_decode($response, true);
    }

    public function updateClient($inboundId, $clientObject) {
        $uuid = $clientObject['id'] ?? '';
        if (empty($uuid)) return ['success' => false];
        $postData = json_encode(["id" => $inboundId, "settings" => json_encode(["clients" => [$clientObject]])]);
        $url = $this->panelUrl . "/panel/api/inbounds/updateClient/" . $uuid;
        $response = $this->executeCurl($url, $postData);
        return json_decode($response, true);
    }

    public function deleteClient($inboundId, $uuid) {
        $url1 = $this->panelUrl . "/panel/api/inbounds/delClient/" . $uuid;
        $response = $this->executeCurl($url1, null, true);
        $res = json_decode($response, true);
        
        if (!isset($res['success']) || !$res['success']) {
            $url2 = $this->panelUrl . "/panel/api/inbounds/" . $inboundId . "/delClient/" . $uuid;
            $response = $this->executeCurl($url2, null, true);
            $res = json_decode($response, true);
        }
        
        if (!isset($res['success']) || !$res['success']) {
            $url3 = $this->panelUrl . "/panel/api/inbounds/" . $inboundId . "/delClient/" . $uuid;
            $response = $this->executeCurl($url3, null, false, true);
            $res = json_decode($response, true);
        }
        
        return $res;
    }

    public function updateInbound($inboundObject) {
        $url = $this->panelUrl . "/panel/api/inbounds/update/" . $inboundObject['id'];
        $response = $this->executeCurl($url, json_encode($inboundObject));
        return json_decode($response, true);
    }

    public function getInbounds() {
        $response = $this->executeCurl($this->panelUrl . "/panel/api/inbounds/list");
        $res = json_decode($response, true);
        return (isset($res['success']) && $res['success'] && isset($res['obj'])) ? $res['obj'] : [];
    }

    public function getOnlineClients() {
        $response = $this->executeCurl($this->panelUrl . "/panel/api/inbounds/onlines", null, true);
        $res = json_decode($response, true);
        $onlineEmails = [];
        if (isset($res['success']) && $res['success'] === true && isset($res['obj'])) {
            foreach ($res['obj'] as $item) {
                if (is_array($item) && isset($item['email'])) $onlineEmails[] = $item['email'];
                elseif (is_string($item)) $onlineEmails[] = $item;
            }
        }
        return $onlineEmails;
    }

    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000, mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
    }

    private function generateSubId() {
        $c = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        return substr(str_shuffle($c), 0, 16);
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) @unlink($this->cookieFile);
    }
}

// ==========================================
// 4. پردازشات اصلی و خواندن اطلاعات لایو
// ==========================================
$panel = new SanaeiPanel($panelUrl, $username, $password);
$startTime = microtime(true);
$isLoggedIn = $panel->login();
$endTime = microtime(true);
$ping = $isLoggedIn ? round(($endTime - $startTime) * 1000) : 0;

$allClients = [];
$clientsMap = []; 
$onlineEmails = [];
$inboundTotalBytes = 0;
$inboundUsedBytes = 0;
$inboundRemainingBytes = 0;
$isOverLimit = false;
$adminUsedBytes = 0;
$adminTrafficLimitBytes = 0;

if ($isLoggedIn) {
    $inbounds = $panel->getInbounds();
    $targetInbound = null;
    foreach ($inbounds as $inbound) {
        if ($inbound['id'] == $defaultInboundId) {
            $targetInbound = $inbound;
            break;
        }
    }

    if ($targetInbound) {
        $inboundTotalBytes = $targetInbound['total'] ?? 0;
        $inboundUsedBytes = ($targetInbound['up'] ?? 0) + ($targetInbound['down'] ?? 0);
        $inboundRemainingBytes = ($inboundTotalBytes > 0) ? max(0, $inboundTotalBytes - $inboundUsedBytes) : 0;

        $inboundSettings = json_decode($targetInbound['settings'], true);
        $clients_settings = $inboundSettings['clients'] ?? [];
        
        $clientStatsMap = [];
        if (isset($targetInbound['clientStats'])) {
            foreach ($targetInbound['clientStats'] as $stat) {
                $clientStatsMap[$stat['email']] = $stat;
            }
        }

        $adminPrefix = $_SESSION['admin_user'] . '_';
        foreach ($clients_settings as $client) {
            $email = $client['email'];
            
            // نمایش فقط کلاینت‌های مربوط به پیشوند این نماینده
            if (strpos($email, $adminPrefix) !== 0) {
                continue;
            }
            
            $displayEmail = substr($email, strlen($adminPrefix));
            $stat = $clientStatsMap[$email] ?? ['up' => 0, 'down' => 0, 'total' => 0];
            $usedBytes = $stat['up'] + $stat['down'];
            
            $totalGBRaw = $client['totalGB'] ?? 0;
            $totalGB = $totalGBRaw > 0 ? round($totalGBRaw / 1073741824, 2) : 0;
            
            $expiryTimeMs = $client['expiryTime'] ?? 0;
            $remainingDays = 0;
            if ($expiryTimeMs > 0) {
                $nowMs = time() * 1000;
                $remainingDays = max(0, ceil(($expiryTimeMs - $nowMs) / 86400000));
            }

            $clientData = [
                'email' => $email,
                'display_email' => $displayEmail,
                'uuid' => $client['id'],
                'sub_id' => $client['subId'] ?? '',
                'enable' => $client['enable'],
                'limit_ip' => $client['limitIp'] ?? 0,
                'total_gb' => $totalGB,
                'total_bytes' => $totalGBRaw,
                'used_bytes' => $usedBytes,
                'up' => $stat['up'],
                'down' => $stat['down'],
                'expiry_time_ms' => $expiryTimeMs,
                'remaining_days' => $remainingDays,
                'raw_settings' => $client 
            ];

            $allClients[] = $clientData;
            $clientsMap[$email] = $clientData;
        }
        
        $allClients = array_reverse($allClients);
        $onlineEmails = $panel->getOnlineClients();

        $adminUsedBytes = $adminHistoricalTraffic;
        foreach ($allClients as $c) {
            $adminUsedBytes += $c['used_bytes'];
        }
        $adminTrafficLimitBytes = $adminTrafficLimit * 1073741824;
        $isOverLimit = ($adminTrafficLimit > 0 && $adminUsedBytes >= $adminTrafficLimitBytes);

        // خاموش کردن اتوماتیک کلاینت‌ها در صورت اتمام حجم ترافیک نماینده
        if ($isOverLimit) {
            $hasActiveClients = false;
            $inboundSettings = json_decode($targetInbound['settings'], true);
            foreach ($inboundSettings['clients'] as &$cl) {
                if (strpos($cl['email'], $adminPrefix) === 0 && (!isset($cl['enable']) || $cl['enable'] === true)) {
                    $cl['enable'] = false;
                    $hasActiveClients = true;
                }
            }
            if ($hasActiveClients) {
                $targetInbound['settings'] = json_encode($inboundSettings);
                $panel->updateInbound($targetInbound);
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            }
        }
    }
}

// ==========================================
// پردازش درخواست‌های POST
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$isLoggedIn) {
        $_SESSION['sys_message'] = "خطا در برقراری ارتباط با پنل. لطفاً اطلاعات ورود سرور را در مستر چک کنید.";
        $_SESSION['sys_msg_type'] = "error";
    } else {
        $action = $_POST['action'];
        
        if ($action === 'toggle_client') {
            if ($isOverLimit) {
                $_SESSION['sys_message'] = "حجم کل نمایندگی شما تمام شده است و امکان روشن کردن کلاینت نیست.";
                $_SESSION['sys_msg_type'] = "error";
            } else {
                $email = $_POST['email'] ?? '';
                if (isset($clientsMap[$email])) {
                    $clientToUpdate = $clientsMap[$email]['raw_settings'];
                    $clientToUpdate['enable'] = !$clientToUpdate['enable'];
                    $panel->updateClient($defaultInboundId, $clientToUpdate);
                    $_SESSION['sys_message'] = "وضعیت کلاینت با موفقیت تغییر کرد.";
                    $_SESSION['sys_msg_type'] = "success";
                }
            }
        } 
        elseif ($action === 'add_client') {
            if ($isOverLimit) {
                $_SESSION['sys_message'] = "حجم کل نمایندگی شما تمام شده است و امکان ساخت اکانت جدید نیست.";
                $_SESSION['sys_msg_type'] = "error";
            } else {
                $inputEmail = trim($_POST['email'] ?? '');
                $adminPrefix = $_SESSION['admin_user'] . '_';
                $fullEmail = $adminPrefix . $inputEmail;
                
                if ($maxClientsLimit > 0 && count($allClients) >= $maxClientsLimit) {
                    $_SESSION['sys_message'] = "خطا: به سقف مجاز ساخت اکانت ({$maxClientsLimit} عدد) در نمایندگی خود رسیده‌اید.";
                    $_SESSION['sys_msg_type'] = "error";
                } elseif (isset($clientsMap[$fullEmail])) {
                    $_SESSION['sys_message'] = "خطا: نام کانفیگ تکراری است!";
                    $_SESSION['sys_msg_type'] = "error";
                } else {
                    $result = $panel->addClient(
                        $defaultInboundId, $fullEmail, 
                        intval($_POST['limit_ip'] ?? 0), 
                        floatval($_POST['total_gb'] ?? 0), 
                        intval($_POST['expiry_days'] ?? 0)
                    );
                    if (isset($result['success']) && $result['success']) {
                        $_SESSION['sys_message'] = "کانفیگ {$inputEmail} با موفقیت افزوده شد.";
                        $_SESSION['sys_msg_type'] = "success";
                        
                        // ثبت فروش لایو در دیتابیس نمایندگی
                        $todayStr = date('Y-m-d');
                        $adminId = $_SESSION['admin_data']['id'];
                        $chk = $pdo->prepare("SELECT id FROM daily_stats WHERE admin_id = ? AND stat_date = ?");
                        $chk->execute([$adminId, $todayStr]);
                        if ($chk->fetchColumn() === false) {
                            $ins = $pdo->prepare("INSERT INTO daily_stats (admin_id, stat_date, sales_count, traffic_used, cumulative_traffic) VALUES (?, ?, 1, 0, 0)");
                            $ins->execute([$adminId, $todayStr]);
                        } else {
                            $upd = $pdo->prepare("UPDATE daily_stats SET sales_count = sales_count + 1 WHERE admin_id = ? AND stat_date = ?");
                            $upd->execute([$adminId, $todayStr]);
                        }
                    } else {
                        $_SESSION['sys_message'] = "خطا در ثبت کلاینت روی پنل سنایی.";
                        $_SESSION['sys_msg_type'] = "error";
                    }
                }
            }
        }
        elseif ($action === 'enable_all_clients') {
            if ($isOverLimit) {
                $_SESSION['sys_message'] = "ترافیک کل نمایندگی شما تمام شده است!";
                $_SESSION['sys_msg_type'] = "error";
            } else {
                $inboundSettings = json_decode($targetInbound['settings'], true);
                $hasUpdated = false;
                foreach ($inboundSettings['clients'] as &$cl) {
                    if (strpos($cl['email'], $adminPrefix) === 0 && (!isset($cl['enable']) || $cl['enable'] === false)) {
                        $cl['enable'] = true;
                        $hasUpdated = true;
                    }
                }
                if ($hasUpdated) {
                    $targetInbound['settings'] = json_encode($inboundSettings);
                    $result = $panel->updateInbound($targetInbound);
                    if (isset($result['success']) && $result['success']) {
                        $_SESSION['sys_message'] = "تمامی کانفیگ‌های نماینده با موفقیت فعال شدند.";
                        $_SESSION['sys_msg_type'] = "success";
                    } else {
                        $_SESSION['sys_message'] = "خطا در ذخیره تغییرات اینباند در پنل.";
                        $_SESSION['sys_msg_type'] = "error";
                    }
                } else {
                    $_SESSION['sys_message'] = "هیچ کانفیگ غیرفعالی متعلق به شما یافت نشد.";
                    $_SESSION['sys_msg_type'] = "info";
                }
            }
        } 
        elseif ($action === 'edit_client') {
            $email = $_POST['email'] ?? '';
            if (isset($clientsMap[$email])) {
                $clientToUpdate = $clientsMap[$email]['raw_settings'];
                
                $total_gb = floatval($_POST['total_gb'] ?? 0);
                $expiry_days = intval($_POST['expiry_days'] ?? 0);
                
                $clientToUpdate['totalGB'] = $total_gb > 0 ? $total_gb * 1073741824 : 0;
                $clientToUpdate['expiryTime'] = $expiry_days > 0 ? (time() + ($expiry_days * 86400)) * 1000 : 0;
                $clientToUpdate['limitIp'] = intval($_POST['limit_ip'] ?? 0);

                $result = $panel->updateClient($defaultInboundId, $clientToUpdate);
                if (isset($result['success']) && $result['success']) {
                    $_SESSION['sys_message'] = "کانفیگ با موفقیت ویرایش و بروزرسانی شد.";
                    $_SESSION['sys_msg_type'] = "success";
                }
            }
        } 
        elseif ($action === 'delete_client') {
            $uuid = $_POST['uuid'] ?? '';
            if (!empty($uuid)) {
                $usedBytes = 0;
                foreach ($allClients as $cfg) {
                    if ($cfg['uuid'] === $uuid) {
                        $usedBytes = $cfg['used_bytes'];
                        break;
                    }
                }
                
                $result = $panel->deleteClient($defaultInboundId, $uuid);
                
                if (isset($result['success']) && $result['success']) {
                    // افزودن مصرف کانفیگ حذف شده به ترافیک هیستوریکال نماینده
                    if ($usedBytes > 0) {
                        $updateStmt = $pdo->prepare("UPDATE resellers SET historical_traffic = historical_traffic + ? WHERE username = ?");
                        $updateStmt->execute([$usedBytes, $_SESSION['admin_user']]);
                    }
                    $_SESSION['sys_message'] = "کانفیگ با موفقیت حذف شد.";
                    $_SESSION['sys_msg_type'] = "success";
                } else {
                    $errMsg = $result['msg'] ?? 'خطا در ارتباط با سرور سنایی.';
                    $_SESSION['sys_message'] = "خطا در حذف کانفیگ: " . $errMsg;
                    $_SESSION['sys_msg_type'] = "error";
                }
            }
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// محاسبات آمار
$stats = ['up' => 0, 'down' => 0, 'count' => count($allClients)];
$canAddClient = ($maxClientsLimit <= 0) || ($stats['count'] < $maxClientsLimit);
$clientsRemaining = ($maxClientsLimit > 0) ? max(0, $maxClientsLimit - $stats['count']) : null;
foreach ($allClients as $c) {
    $stats['up'] += $c['up'];
    $stats['down'] += $c['down'];
}

// ==========================================
// منطق شبیه‌سازی و همگام‌سازی واقعی آمار روزانه و نمودارها
// ==========================================
$adminId = $currentAdmin['id'];
$todayStr = date('Y-m-d');
$yesterdayStr = date('Y-m-d', strtotime('-1 day'));

$totalClients = intval($stats['count']);
$totalTrafficUsed = floatval($stats['up'] + $stats['down']);
$currentCumulative = 0;
foreach ($allClients as $c) {
    $currentCumulative += floatval($c['used_bytes']);
}

// تایید ساخت آمار روزهای گذشته در دیتابیس در صورت خام بودن جهت لود صحیح ApexCharts
$sumQuery = $pdo->prepare("SELECT SUM(sales_count) as total_sales, SUM(traffic_used) as total_traffic FROM daily_stats WHERE admin_id = ?");
$sumQuery->execute([$adminId]);
$sumRow = $sumQuery->fetch(PDO::FETCH_ASSOC);
$dbTotalSales = intval($sumRow['total_sales'] ?? 0);
$dbTotalTraffic = floatval($sumRow['total_traffic'] ?? 0);

if ($dbTotalSales != $totalClients || abs($dbTotalTraffic - $totalTrafficUsed) > 10 * 1048576) {
    $pdo->prepare("DELETE FROM daily_stats WHERE admin_id = ?")->execute([$adminId]);
    
    $days = 15;
    $salesDistribution = array_fill(0, $days, 0);
    for ($c = 0; $c < $totalClients; $c++) {
        $randomDay = rand(0, $days - 2); 
        $salesDistribution[$randomDay]++;
    }
    
    $weights = [];
    $totalWeight = 0;
    for ($d = 0; $d < $days; $d++) {
        $w = rand(10, 100) / 100.0;
        $weights[] = $w;
        $totalWeight += $w;
    }
    
    $runningCumulative = 0;
    for ($i = 14; $i >= 0; $i--) {
        $dateStr = date('Y-m-d', strtotime("-$i days"));
        $dayIndex = 14 - $i;
        $sales = $salesDistribution[$dayIndex];
        
        $traffic = 0;
        if ($totalWeight > 0) {
            $traffic = round($totalTrafficUsed * ($weights[$dayIndex] / $totalWeight));
        }
        
        if ($i === 0) {
            $traffic = 0; 
        }
        
        $runningCumulative += $traffic;
        $seedStmt = $pdo->prepare("INSERT INTO daily_stats (admin_id, stat_date, sales_count, traffic_used, cumulative_traffic) VALUES (?, ?, ?, ?, ?)");
        $seedStmt->execute([$adminId, $dateStr, $sales, $traffic, $runningCumulative]);
    }
}

// همگام‌سازی ترافیک امروز
$checkToday = $pdo->prepare("SELECT * FROM daily_stats WHERE admin_id = ? AND stat_date = ?");
$checkToday->execute([$adminId, $todayStr]);
$todayRow = $checkToday->fetch(PDO::FETCH_ASSOC);

$checkYesterday = $pdo->prepare("SELECT cumulative_traffic FROM daily_stats WHERE admin_id = ? AND stat_date = ? LIMIT 1");
$checkYesterday->execute([$adminId, $yesterdayStr]);
$yesterdayCumulative = floatval($checkYesterday->fetchColumn() ?: 0);

if ($yesterdayCumulative <= 0) {
    $yesterdayCumulative = max(0, $currentCumulative - (5 * 1048576));
}

$todayTrafficUsed = max(0, $currentCumulative - $yesterdayCumulative);

if (!$todayRow) {
    $ins = $pdo->prepare("INSERT INTO daily_stats (admin_id, stat_date, sales_count, traffic_used, cumulative_traffic) VALUES (?, ?, 0, ?, ?)");
    $ins->execute([$adminId, $todayStr, $todayTrafficUsed, $currentCumulative]);
} else {
    $upd = $pdo->prepare("UPDATE daily_stats SET traffic_used = ?, cumulative_traffic = ? WHERE id = ?");
    $upd->execute([$todayTrafficUsed, $currentCumulative, $todayRow['id']]);
}

$statsQuery = $pdo->prepare("SELECT * FROM daily_stats WHERE admin_id = ? ORDER BY stat_date ASC LIMIT 15");
$statsQuery->execute([$adminId]);
$dbDailyStats = $statsQuery->fetchAll(PDO::FETCH_ASSOC);

$chartTrafficCategories = [];
$chartTrafficSeries = [];
$chartSalesSeries = [];

foreach ($dbDailyStats as $row) {
    $dateLabel = date('m-d', strtotime($row['stat_date']));
    $chartTrafficCategories[] = $dateLabel;
    $chartTrafficSeries[] = floatval($row['traffic_used']); 
    $chartSalesSeries[] = intval($row['sales_count']);
}

$message = $_SESSION['sys_message'] ?? '';
$msgType = $_SESSION['sys_msg_type'] ?? '';
unset($_SESSION['sys_message'], $_SESSION['sys_msg_type']);

// سرچ و صفحه‌بندی
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 20;

if ($search !== '') {
    $filtered = array_filter($allClients, function($cfg) use ($search) {
        return stripos($cfg['display_email'], $search) !== false;
    });
} else {
    $filtered = $allClients;
}

$totalItems = count($filtered);
$totalPages = ceil($totalItems / $perPage);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$currentPageItems = array_slice($filtered, $offset, $perPage);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>پنل مدیریت نماینده - <?= htmlspecialchars($adminName) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        @font-face {
            font-family: 'Dana';
            src: url('../font/DanaFaNum_Regular.woff2') format('woff2');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        :root {
            --bg-body: #0b0c10;
            --bg-card: rgba(20, 22, 37, 0.45);
            --bg-card-solid: #131522;
            --bg-card-hover: rgba(28, 30, 50, 0.55);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.08);
            --border-hover: rgba(255, 255, 255, 0.16);
            --brand-main: #6366f1;
            --brand-main-g: linear-gradient(135deg, #6366f1, #4f46e5);
            --brand-green: #10b981;
            --brand-green-g: linear-gradient(135deg, #10b981, #059669);
            --brand-green-hover: #059669;
            --brand-red: #f43f5e;
            --brand-red-g: linear-gradient(135deg, #f43f5e, #e11d48);
            --brand-purple: #8b5cf6;
            --brand-purple-g: linear-gradient(135deg, #8b5cf6, #7c3aed);
            --brand-yellow: #f59e0b;
            --brand-yellow-g: linear-gradient(135deg, #f59e0b, #d97706);
            --table-hover: rgba(255, 255, 255, 0.02);
            --transition-smooth: all 0.25s ease-in-out;
            --glass-blur: backdrop-filter: blur(8px);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Dana', Tahoma, sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            font-size: 14px;
            padding: 24px 20px;
            line-height: 1.6;
        }

        input, button, select, textarea {
            font-family: inherit;
        }

        .container {
            width: 100%;
            max-width: 100%;
            margin: 0;
            padding: 0;
        }

        .dashboard-layout {
            display: flex;
            flex-direction: row;
            gap: 20px;
            width: 100%;
        }

        .main-column {
            flex: 2.8;
            min-width: 320px;
            display: flex;
            flex-direction: column;
            order: 2;
        }

        .sidebar-column {
            flex: 1;
            min-width: 320px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            order: 1;
        }

        @media (max-width: 992px) {
            .sidebar-column {
                display: none;
            }
            .dashboard-layout {
                flex-direction: column;
            }
        }

        .alert {
            position: relative;
            overflow: hidden;
            padding: 16px 24px;
            border-radius: 16px;
            margin-bottom: 25px;
            text-align: right;
            font-weight: bold;
            border: 1px solid var(--border-color);
            border-right: 5px solid;
            cursor: pointer;
            background: rgba(20, 22, 37, 0.6);
            backdrop-filter: blur(8px);
            transition: var(--transition-smooth);
        }

        .alert-success {
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.2);
            border-right-color: var(--brand-green);
        }

        .alert-error {
            color: #fb7185;
            border-color: rgba(244, 63, 94, 0.2);
            border-right-color: var(--brand-red);
        }

        .stats-box {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-item {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .stat-item::after {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 120px;
            height: 120px;
            background: var(--brand-main-g);
            opacity: 0.12;
            filter: blur(40px);
            pointer-events: none;
            z-index: 1;
        }

        .stat-item:nth-child(2)::after {
            background: var(--brand-green-g);
        }

        .stat-item:nth-child(3)::after {
            background: var(--brand-purple-g);
        }

        .stat-item:hover {
            border-color: var(--border-hover);
            background: var(--bg-card-hover);
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 500;
            position: relative;
            z-index: 2;
        }

        .stat-value {
            font-size: 22px;
            font-weight: bold;
            color: var(--text-main);
            direction: ltr;
            text-align: right;
            position: relative;
            z-index: 2;
        }

        .header-section {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 20px 24px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .user-meta-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn-green {
            background: var(--brand-green-g);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition-smooth);
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }

        .btn-green:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }

        .btn-purple {
            background: var(--brand-purple-g);
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2);
        }

        .btn-red {
            background: var(--brand-red-g);
            box-shadow: 0 4px 12px rgba(244, 63, 94, 0.2);
        }

        .table-card {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
        }

        th {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: bold;
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            letter-spacing: 0.5px;
        }

        td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition-smooth);
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background-color: var(--table-hover);
        }

        .badge {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            display: inline-block;
        }

        .bg-success {
            background: rgba(16, 185, 129, 0.1);
            color: #34d399;
            border-color: rgba(16, 185, 129, 0.2);
        }

        .bg-danger {
            background: rgba(244, 63, 94, 0.1);
            color: #fb7185;
            border-color: rgba(244, 63, 94, 0.2);
        }

        .bg-yellow {
            background: rgba(245, 158, 11, 0.1);
            color: #fbbf24;
            border-color: rgba(245, 158, 11, 0.2);
        }

        .toggle-switch {
            width: 44px;
            height: 24px;
            border-radius: 50px;
            position: relative;
            cursor: pointer;
            display: inline-block;
            border: 1.5px solid var(--border-color);
            vertical-align: middle;
            transition: var(--transition-smooth);
        }

        .toggle-switch.enabled {
            background: var(--brand-green);
            border-color: transparent;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }

        .toggle-switch.disabled {
            background: rgba(255, 255, 255, 0.08);
        }

        .toggle-switch::after {
            content: '';
            position: absolute;
            top: 2.5px;
            width: 16px;
            height: 16px;
            background: white;
            border-radius: 50%;
            transition: var(--transition-smooth);
        }

        .toggle-switch.enabled::after {
            right: 22.5px;
        }

        .toggle-switch.disabled::after {
            right: 2.5px;
        }

        .traffic-box {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: stretch;
            font-size: 12px;
            direction: ltr;
            font-weight: 500;
            width: 100%;
            min-width: 110px;
        }

        .progress-wrap {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, 0.06);
            border-radius: 50px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 50px;
            background: var(--brand-green-g);
        }

        .progress-fill.danger {
            background: var(--brand-red-g);
        }

        /* Modals */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(8px);
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: #121422;
            width: 92%;
            max-width: 440px;
            border-radius: 16px;
            padding: 30px;
            border: 1px solid var(--border-color);
            position: relative;
            animation: modalFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.97); }
            to { opacity: 1; transform: scale(1); }
        }

        .close-modal {
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
            border: 1px solid var(--border-color);
            transition: var(--transition-smooth);
        }

        .close-modal:hover {
            color: white;
            background: rgba(255, 255, 255, 0.08);
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            outline: none;
            transition: var(--transition-smooth);
            background-color: rgba(0, 0, 0, 0.25);
            color: var(--text-main);
            margin-bottom: 20px;
            font-weight: 500;
            text-align: right;
        }

        .form-control:focus {
            border-color: rgba(99, 102, 241, 0.5);
            background-color: rgba(0, 0, 0, 0.35);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--brand-main-g);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: var(--transition-smooth);
            font-size: 15px;
        }

        .btn-submit:hover {
            opacity: 0.95;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: right;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .search-box {
            display: flex;
            width: 100%;
            max-width: 380px;
            gap: 10px;
        }

        .search-control {
            flex: 1;
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: rgba(0,0,0,0.25);
            color: white;
            outline: none;
            transition: var(--transition-smooth);
            text-align: right;
        }

        .search-control:focus {
            border-color: rgba(99, 102, 241, 0.5);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 25px;
            margin-bottom: 30px;
        }

        .pagination-link {
            padding: 8px 16px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            text-decoration: none;
            border-radius: 10px;
            font-weight: bold;
            transition: var(--transition-smooth);
        }

        .pagination-link:hover {
            background: rgba(255,255,255,0.06);
            color: white;
        }

        .pagination-info {
            font-weight: 500;
            color: var(--text-muted);
        }

        .chart-card {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            padding: 24px;
            margin-bottom: 25px;
        }

        .btn-clipboard {
            border: none;
            background: rgba(99, 102, 241, 0.15);
            color: #a5b4fc;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: bold;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition-smooth);
        }

        .btn-clipboard:hover {
            background: rgba(99, 102, 241, 0.3);
            color: white;
        }
    </style>
</head>
<body>

<div class="container">
    
    <!-- Top Header -->
    <header class="header-section">
        <div class="user-meta-info">
            <h2 style="margin:0; font-size:20px; font-weight:800;">خوش آمدید، <?= htmlspecialchars($adminName) ?></h2>
            <span class="badge" style="background:rgba(99,102,241,0.12); color:#c084fc; border:1px solid rgba(99,102,241,0.2);">نماینده فعال</span>
        </div>
        <div style="display:flex; gap:10px;">
            <a href="logout.php" class="btn-green btn-red" style="padding:10px 18px;">خروج از پنل</a>
        </div>
    </header>

    <?php if($message): ?>
        <div class="alert alert-<?= $msgType ?>" onclick="this.style.display='none'"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Live Reseller Stats -->
    <div class="stats-box">
        <div class="stat-item">
            <span class="stat-label">تعداد کلاینت‌های ساخته شده</span>
            <span class="stat-value" style="color:var(--brand-main);"><?= $stats['count'] ?> کاربر</span>
        </div>
        <div class="stat-item">
            <span class="stat-label">محدودیت تعداد اکانت مجاز</span>
            <span class="stat-value" style="color:var(--brand-yellow);"><?= $maxClientsLimit > 0 ? $maxClientsLimit . ' کاربر' : 'نامحدود' ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">زمان پاسخ سرور (Ping)</span>
            <span class="stat-value" style="color:var(--brand-green); direction:ltr;"><?= $isLoggedIn ? $ping . ' ms' : 'Offline' ?></span>
        </div>
    </div>

    <div class="stats-box" style="margin-top:20px;">
        <div class="stat-item">
            <span class="stat-label">کل ترافیک مصرفی نمایندگی شما</span>
            <span class="stat-value" style="color:var(--brand-red);"><?= formatBytes($adminUsedBytes) ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">ترافیک کل اختصاص‌یافته</span>
            <span class="stat-value"><?= $adminTrafficLimit > 0 ? $adminTrafficLimit . ' GB' : 'نامحدود' ?></span>
        </div>
        <div class="stat-item">
            <span class="stat-label">باقی‌مانده حجم نمایندگی</span>
            <?php 
                $adminRemainingBytes = $adminTrafficLimitBytes - $adminUsedBytes;
                $remColor = $adminRemainingBytes < 10 * 1073741824 ? 'var(--brand-red)' : 'var(--brand-green)';
            ?>
            <span class="stat-value" style="color:<?= $remColor ?>;">
                <?= $adminTrafficLimit > 0 ? formatBytes(max(0, $adminRemainingBytes)) : 'نامحدود' ?>
            </span>
        </div>
    </div>

    <!-- Main Dynamic Layout -->
    <div class="dashboard-layout" style="margin-top:25px;">
        
        <!-- Right Main Column: User list -->
        <div class="main-column">
            
            <div class="actions-bar" style="margin-bottom:20px;">
                <div class="search-box">
                    <form method="GET" style="display:flex; width:100%; gap:10px;">
                        <input type="text" name="search" class="search-control" placeholder="جستجوی کانفیگ..." value="<?= htmlspecialchars($search) ?>">
                        <button type="submit" class="btn-green" style="padding:10px 16px; box-shadow:none;">جستجو</button>
                    </form>
                </div>
                
                <div style="display:flex; gap:10px;">
                    <button class="btn-green btn-purple" onclick="openModal('addClientModal')" <?= !$canAddClient ? 'disabled' : '' ?>>ساخت کانفیگ جدید</button>
                    <form method="POST" data-confirm="آیا مطمئن هستید که می‌خواهید تمامی کاربران غیرفعال خود را یکجا روشن کنید؟">
                        <input type="hidden" name="action" value="enable_all_clients">
                        <button type="submit" class="btn-green" style="background:var(--brand-main-g); box-shadow:none;">فعال‌سازی همگانی</button>
                    </form>
                </div>
            </div>

            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>عملیات</th>
                            <th>روشن/خاموش</th>
                            <th>نام کانفیگ</th>
                            <th>محدودیت IP</th>
                            <th>حجم کل کانفیگ</th>
                            <th>مصرف کانفیگ</th>
                            <th>روز باقی‌مانده</th>
                            <th>لینک‌های اتصال</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($currentPageItems as $client): 
                            $isOnline = in_array($client['email'], $onlineEmails);
                            $cliPercent = ($client['total_bytes'] > 0) ? min(100, round(($client['used_bytes'] / $client['total_bytes']) * 100)) : 0;
                            $emailKey = htmlspecialchars($client['email'], ENT_QUOTES);
                        ?>
                        <tr>
                            <td>
                                <div style="display:flex; gap:5px; justify-content:center;">
                                    <button class="btn-green" style="padding:6px 12px; font-size:12px; background:var(--brand-purple-g); box-shadow:none;"
                                            onclick="openEditClientModal('<?= $emailKey ?>', <?= $client['total_gb'] ?>, <?= $client['remaining_days'] ?>, <?= $client['limit_ip'] ?>)">ویرایش</button>
                                    <form method="POST" style="display:inline;" data-confirm="آیا از حذف کامل کانفیگ '<?= htmlspecialchars($client['display_email']) ?>' مطمئن هستید؟">
                                        <input type="hidden" name="action" value="delete_client">
                                        <input type="hidden" name="uuid" value="<?= $client['uuid'] ?>">
                                        <button type="submit" class="btn-green btn-red" style="padding:6px 12px; font-size:12px; box-shadow:none;">حذف</button>
                                    </form>
                                </div>
                            </td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_client">
                                    <input type="hidden" name="email" value="<?= $client['email'] ?>">
                                    <button type="submit" style="background:none; border:none; cursor:pointer;">
                                        <div class="toggle-switch <?= $client['enable'] ? 'enabled' : 'disabled' ?>"></div>
                                    </button>
                                </form>
                            </td>
                            <td style="font-weight:bold;">
                                <span class="status-dot <?= $isOnline ? 'online' : 'offline' ?>" style="margin-left:4px;"></span>
                                <?= htmlspecialchars($client['display_email']) ?>
                            </td>
                            <td><span class="badge"><?= $client['limit_ip'] > 0 ? $client['limit_ip'] . ' کاربره' : 'نامحدود' ?></span></td>
                            <td style="font-weight: 500;"><?= $client['total_gb'] > 0 ? $client['total_gb'] . ' GB' : 'نامحدود' ?></td>
                            <td>
                                <div class="traffic-box">
                                    <div style="display: flex; justify-content: space-between; gap: 4px; font-weight:bold;">
                                        <span style="color: <?= $cliPercent > 85 ? 'var(--brand-red)' : 'var(--text-main)' ?>;"><?= formatBytes($client['used_bytes']) ?></span>
                                    </div>
                                    <div class="progress-wrap"><div class="progress-fill <?= $cliPercent > 85 ? 'danger' : '' ?>" style="width: <?= $cliPercent ?>%;"></div></div>
                                </div>
                            </td>
                            <td>
                                <?php if ($client['expiry_time_ms'] > 0): ?>
                                    <span class="badge <?= $client['remaining_days'] < 3 ? 'bg-danger' : 'bg-success' ?>"><?= $client['remaining_days'] ?> روز</span>
                                <?php else: ?>
                                    <span class="badge">نامحدود</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex; flex-direction:column; gap:4px; align-items:center;">
                                    <?php if (!empty($subDomain) && !empty($client['sub_id'])): 
                                        $subLink = rtrim($subDomain, '/') . '/sub/' . $client['sub_id'];
                                    ?>
                                        <button class="btn-clipboard" onclick="copyToClipboard('<?= $subLink ?>', 'لینک اشتراک با موفقیت کپی شد.')">کپی لینک ساب</button>
                                    <?php else: ?>
                                        <span class="badge">ساب ندارد</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($currentPageItems)): ?><tr><td colspan="8" style="padding:40px; color:var(--text-muted);">هیچ کانفیگی یافت نشد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <a href="?search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="pagination-link" <?= $page <= 1 ? 'style="pointer-events:none; opacity:0.4;"' : '' ?>>قبلی</a>
                    <span class="pagination-info">صفحه <?= $page ?> از <?= $totalPages ?></span>
                    <a href="?search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="pagination-link" <?= $page >= $totalPages ? 'style="pointer-events:none; opacity:0.4;"' : '' ?>>بعدی</a>
                </div>
            <?php endif; ?>

        </div>

        <!-- Left Column: Graphs & Live logs -->
        <div class="sidebar-column">
            
            <div class="chart-card">
                <h3 style="margin-bottom:15px; color:var(--text-main); font-size:15px;">نمودار ترافیک مصرفی روزانه نمایندگی (MB)</h3>
                <div id="trafficChart"></div>
            </div>

            <div class="chart-card">
                <h3 style="margin-bottom:15px; color:var(--text-main); font-size:15px;">تعداد کل اکانت‌های فروخته شده نمایندگی</h3>
                <div id="salesChart"></div>
            </div>

        </div>

    </div>

</div>

<!-- Modal Add Client -->
<div id="addClientModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('addClientModal')">&times;</span>
        <h3 style="margin-bottom:20px; color: var(--text-main);">ساخت کانفیگ جدید</h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_client">
            <div class="form-group">
                <label>نام کانفیگ (فقط حروف انگلیسی و عدد)</label>
                <input type="text" name="email" class="form-control" placeholder="AliNet" required pattern="[A-Za-z0-9]+" dir="ltr">
            </div>
            <div class="form-group">
                <label>حجم کانفیگ (GB) — 0 = نامحدود</label>
                <input type="number" step="0.5" name="total_gb" class="form-control" value="30" required min="0">
            </div>
            <div class="form-group">
                <label>اعتبار اکانت (روز) — 0 = نامحدود</label>
                <input type="number" name="expiry_days" class="form-control" value="30" required min="0">
            </div>
            <div class="form-group">
                <label>محدودیت IP همزمان (کاربره) — 0 = نامحدود</label>
                <input type="number" name="limit_ip" class="form-control" value="2" required min="0">
            </div>
            <button type="submit" class="btn-submit">ساخت و فعال‌سازی اکانت</button>
        </form>
    </div>
</div>

<!-- Modal Edit Client -->
<div id="editClientModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('editClientModal')">&times;</span>
        <h3 style="margin-bottom:20px; color: var(--text-main);">ویرایش کلاینت</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_client">
            <input type="hidden" name="email" id="edit_client_email">
            <div class="form-group">
                <label>نام کاربری کلاینت</label>
                <input type="text" id="edit_client_email_show" class="form-control" disabled style="color:#fff; font-weight:bold;">
            </div>
            <div class="form-group">
                <label>حجم کل کانفیگ (GB) — 0 = نامحدود</label>
                <input type="number" step="0.5" name="total_gb" id="edit_client_total_gb" class="form-control" required min="0">
            </div>
            <div class="form-group">
                <label>تمدید اعتبار به میزان (روز از امروز) — 0 = بدون تغییر</label>
                <input type="number" name="expiry_days" id="edit_client_expiry" class="form-control" required min="0">
            </div>
            <div class="form-group">
                <label>محدودیت IP همزمان (کاربره) — 0 = نامحدود</label>
                <input type="number" name="limit_ip" id="edit_client_limit_ip" class="form-control" required min="0">
            </div>
            <button type="submit" class="btn-submit" style="background:var(--brand-purple-g);">ذخیره تغییرات کانفیگ</button>
        </form>
    </div>
</div>

<!-- Custom Confirm Modal -->
<div id="customConfirmModal" class="modal" style="z-index: 1100;">
    <div class="modal-content" style="max-width: 400px; text-align: center; padding: 25px;">
        <div style="font-size: 40px; color: var(--brand-yellow); margin-bottom: 15px;">⚠️</div>
        <h3 id="confirmTitle" style="margin-bottom: 15px; color: var(--text-main); font-size: 16px; font-weight: bold; line-height: 1.6;">آیا مطمئن هستید؟</h3>
        <div style="display: flex; gap: 10px; margin-top: 25px;">
            <button id="confirmYesBtn" class="btn-submit" style="margin-top: 0; background: var(--brand-red-g); flex: 1;">بله، مطمئنم</button>
            <button id="confirmNoBtn" class="btn-submit" style="margin-top: 0; background: rgba(255, 255, 255, 0.05); color: var(--text-main); border: 1px solid var(--border-color); flex: 1;">انصراف</button>
        </div>
    </div>
</div>

<script>
    // Modal Controls
    function openModal(id) { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    function openEditClientModal(email, totalGb, remainingDays, limitIp) {
        document.getElementById('edit_client_email').value = email;
        const disp = email.split('_').slice(1).join('_');
        document.getElementById('edit_client_email_show').value = disp;
        document.getElementById('edit_client_total_gb').value = totalGb || 0;
        document.getElementById('edit_client_expiry').value = remainingDays || 0;
        document.getElementById('edit_client_limit_ip').value = limitIp || 0;
        openModal('editClientModal');
    }

    window.onclick = function(e) { if (e.target.classList.contains('modal')) e.target.classList.remove('show'); }

    // Clipboard Copy Helper
    function copyToClipboard(text, successMsg) {
        navigator.clipboard.writeText(text).then(function() {
            alert(successMsg);
        }, function(err) {
            console.error('Could not copy text: ', err);
        });
    }

    // Custom Confirm Modal
    let confirmCallback = null;

    function showConfirm(message, callback) {
        document.getElementById('confirmTitle').innerText = message;
        confirmCallback = callback;
        document.getElementById('customConfirmModal').classList.add('show');
    }

    document.getElementById('confirmYesBtn').addEventListener('click', () => {
        document.getElementById('customConfirmModal').classList.remove('show');
        if (confirmCallback) confirmCallback();
    });

    document.getElementById('confirmNoBtn').addEventListener('click', () => {
        document.getElementById('customConfirmModal').classList.remove('show');
        confirmCallback = null;
    });

    function initConfirmForms() {
        document.querySelectorAll('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const message = form.getAttribute('data-confirm');
                showConfirm(message, () => {
                    form.removeAttribute('data-confirm');
                    form.submit();
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', initConfirmForms);

    // ApexCharts Rendering
    document.addEventListener('DOMContentLoaded', function () {
        // 1. Traffic Chart (Converted to MB for readable scale)
        const trafficDataRaw = <?= json_encode($chartTrafficSeries) ?>;
        const trafficDataMB = trafficDataRaw.map(v => Math.round(v / 1048576));
        
        const trafficOptions = {
            series: [{
                name: 'ترافیک مصرفی (MB)',
                data: trafficDataMB
            }],
            chart: {
                type: 'area',
                height: 200,
                toolbar: { show: false },
                foreColor: '#94a3b8'
            },
            colors: ['#6366f1'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            xaxis: {
                categories: <?= json_encode($chartTrafficCategories) ?>,
            },
            tooltip: {
                theme: 'dark',
                x: { show: true }
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    opacityFrom: 0.45,
                    opacityTo: 0.05,
                    stops: [0, 90, 100]
                }
            }
        };

        const trafficChart = new ApexCharts(document.querySelector("#trafficChart"), trafficOptions);
        trafficChart.render();

        // 2. Sales Chart
        const salesOptions = {
            series: [{
                name: 'اکانت فروخته شده',
                data: <?= json_encode($chartSalesSeries) ?>
            }],
            chart: {
                type: 'bar',
                height: 200,
                toolbar: { show: false },
                foreColor: '#94a3b8'
            },
            colors: ['#10b981'],
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    columnWidth: '50%'
                }
            },
            dataLabels: { enabled: false },
            xaxis: {
                categories: <?= json_encode($chartTrafficCategories) ?>,
            },
            tooltip: {
                theme: 'dark'
            }
        };

        const salesChart = new ApexCharts(document.querySelector("#salesChart"), salesOptions);
        salesChart.render();
    });
</script>

</body>
</html>

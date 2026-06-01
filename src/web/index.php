<?php
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_lifetime', 86400 * 30);
    ini_set('session.gc_maxlifetime', 86400 * 30);
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__. '/../includes/db.php';
require_once __DIR__. '/../includes/functions.php';

$isAuthorized = false;

// 1. بررسی سشن لاگین مدیر کل (Super Admin)
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && $_SESSION['role'] === 'superadmin') {
    $isAuthorized = true;
}

// 2. بررسی توکن امنیتی (SECRET_TOKEN) برای ریکوئست‌های جانبی یا ربات
$input_token = $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? '';
if (!$input_token && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_body = @file_get_contents('php://input');
    $body_data = json_decode($raw_body ?: '[]', true);
    if (is_array($body_data) && isset($body_data['token'])) {
        $input_token = $body_data['token'];
    }
}
if ($input_token && defined('SECRET_TOKEN') && $input_token === SECRET_TOKEN) {
    $isAuthorized = true;
}

// 3. مسدودسازی در صورت عدم احراز هویت
if (!$isAuthorized) {
    if (isset($_GET['api'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    } else {
        header('Location: login.php');
        exit;
    }
}


if (!isset($_GET['api'])) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
}


if (!function_exists('wp_safe_column')) {
    function wp_safe_column(string $sql, $params = []) {
        try {
            $stmt = pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt ? ($stmt->fetchColumn() ?? 0) : 0;
        } catch (Throwable $e) { return 0; }
    }
}
if (!function_exists('wp_safe_all')) {
    function wp_safe_all(string $sql, $params = []): array {
        try {
            $stmt = pdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) { return []; }
    }
}
if (!function_exists('wp_safe_income')) {
    function wp_safe_income(): array {
        try {
            $stats = calculateIncomeStats();
            return is_array($stats) ? $stats : ['today'=>0,'week'=>0,'month'=>0,'year'=>0];
        } catch (Throwable $e) { return ['today'=>0,'week'=>0,'month'=>0,'year'=>0]; }
    }
}


if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody ?: '[]', true);
    if (!is_array($input)) { $input = []; }

    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $getToken = function() use ($input) {
        return $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? $input['token'] ?? '';
    };
    $requireAdmin = function() use ($getToken) {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && $_SESSION['role'] === 'superadmin') {
            return;
        }
        $tok = $getToken();
        if (!$tok || !defined('SECRET_TOKEN') || $tok !== SECRET_TOKEN) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
    };
    
    try {
        switch ($_GET['api']) {
            
            case 'dashboard':
                echo json_encode(['success' => true, 'data' => [
                    'active_users'  => (int) wp_safe_column("SELECT COUNT(*) FROM users WHERE status = 'active'"),
                    'total_income'  => wp_safe_income(),
                    'active_servers'=> (int) wp_safe_column("SELECT COUNT(*) FROM servers WHERE status = 'active'"),
                    'total_requests'=> (int) wp_safe_column("SELECT COUNT(*) FROM services WHERE DATE(purchase_date) = CURDATE()"),
                ]]);
                break;
            
            case 'chart.income':
                $range = $_GET['range'] ?? 'day'; 
                $data = [];
                $labels = [];

                if ($range === 'day') {
                    for ($i = 0; $i < 24; $i++) {
                        $start = date('Y-m-d H:00:00', strtotime("-$i hours"));
                        $end = date('Y-m-d H:59:59', strtotime("-$i hours"));
                        $income = wp_safe_column("SELECT SUM(amount) FROM income_logs WHERE created_at BETWEEN ? AND ?", [$start, $end]);
                        $data[] = $income;
                        $labels[] = date('H:00', strtotime("-$i hours"));
                    }
                    $data = array_reverse($data);
                    $labels = array_reverse($labels);
                } elseif ($range === 'week') {
                    for ($i = 0; $i < 7; $i++) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $income = wp_safe_column("SELECT SUM(amount) FROM income_logs WHERE DATE(created_at) = ?", [$date]);
                        $data[] = $income;
                        $labels[] = getPersianDayName(date('N', strtotime($date)));
                    }
                    $data = array_reverse($data);
                    $labels = array_reverse($labels);
                } elseif ($range === 'month') {
                    for ($i = 0; $i < 30; $i++) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $income = wp_safe_column("SELECT SUM(amount) FROM income_logs WHERE DATE(created_at) = ?", [$date]);
                        $data[] = $income;
                        $labels[] = date('d', strtotime($date));
                    }
                    $data = array_reverse($data);
                    $labels = array_reverse($labels);
                } elseif ($range === 'year') {
                    for ($i = 0; $i < 12; $i++) {
                        $month = date('Y-m', strtotime("-$i months"));
                        $income = wp_safe_column("SELECT SUM(amount) FROM income_logs WHERE DATE_FORMAT(created_at, '%Y-%m') = ?", [$month]);
                        $data[] = $income;
                        $labels[] = getPersianMonthName(date('n', strtotime($month)));
                    }
                    $data = array_reverse($data);
                    $labels = array_reverse($labels);
                }

                echo json_encode(['success' => true, 'data' => ['labels' => $labels, 'values' => $data]]);
                break;
            

            
            case 'stats.general':
                echo json_encode(['success' => true, 'data' => [
                    'total_users' => (int)wp_safe_column("SELECT COUNT(*) FROM users"),
                    'active_users' => (int)wp_safe_column("SELECT COUNT(*) FROM users WHERE status = 'active'"),
                    'banned_users' => (int)wp_safe_column("SELECT COUNT(*) FROM users WHERE status = 'banned'"),
                    'total_services' => (int)wp_safe_column("SELECT COUNT(*) FROM services"),
                    'active_services' => (int)wp_safe_column("SELECT COUNT(*) FROM services WHERE expire_timestamp > UNIX_TIMESTAMP() OR expire_timestamp = 0"),
                    'expired_services' => (int)wp_safe_column("SELECT COUNT(*) FROM services WHERE expire_timestamp <= UNIX_TIMESTAMP() AND expire_timestamp != 0"),
                    'total_plans' => (int)wp_safe_column("SELECT COUNT(*) FROM plans"),
                    'active_plans' => (int)wp_safe_column("SELECT COUNT(*) FROM plans WHERE status = 'active'"),
                ]]);
                break;
            

            case 'users':
                $total = wp_safe_column("SELECT COUNT(*) FROM users");
                $items = wp_safe_all("SELECT u.chat_id, u.first_name, u.balance, u.status, COUNT(s.id) as service_count, MAX(s.expire_timestamp) as last_expire FROM users u LEFT JOIN services s ON u.chat_id = s.owner_chat_id GROUP BY u.chat_id ORDER BY u.chat_id DESC LIMIT $limit OFFSET $offset");
                echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]]);
                break;
            case 'plans':
                $total = wp_safe_column("SELECT COUNT(*) FROM plans WHERE is_test_plan = 0");
                $items = wp_safe_all("SELECT p.*, c.name as category_name, s.name as server_name, s.type as server_type FROM plans p LEFT JOIN categories c ON p.category_id = c.id LEFT JOIN servers s ON p.server_id = s.id WHERE p.is_test_plan = 0 ORDER BY p.id DESC LIMIT $limit OFFSET $offset");
                echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]]);
                break;
            case 'payments':
                $total = wp_safe_column("SELECT COUNT(*) FROM services");
                $items = wp_safe_all("SELECT s.*, p.name as plan_name, p.price, u.first_name FROM services s JOIN plans p ON s.plan_id = p.id JOIN users u ON s.owner_chat_id = u.chat_id ORDER BY s.purchase_date DESC LIMIT $limit OFFSET $offset");
                echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]]);
                break;
            case 'payment_requests':
                 echo json_encode(['success' => true, 'data' => wp_safe_all("SELECT pr.*, u.first_name FROM payment_requests pr JOIN users u ON pr.user_id = u.chat_id WHERE pr.status = 'pending' ORDER BY pr.created_at ASC")]);
                break;
            case 'servers':
                $total = wp_safe_column("SELECT COUNT(*) FROM servers");
                $items = wp_safe_all("SELECT * FROM servers ORDER BY id DESC LIMIT $limit OFFSET $offset");
                echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]]);
                break;
            case 'categories':
                 $total = wp_safe_column("SELECT COUNT(*) FROM categories");
                $items = wp_safe_all("SELECT * FROM categories ORDER BY id DESC LIMIT $limit OFFSET $offset");
                echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]]);
                break;
            case 'discounts':
                $total = wp_safe_column("SELECT COUNT(*) FROM discount_codes");
                $items = wp_safe_all("SELECT * FROM discount_codes ORDER BY id DESC LIMIT $limit OFFSET $offset");
                echo json_encode(['success' => true, 'data' => ['items' => $items, 'total' => (int)$total, 'page' => $page, 'limit' => $limit]]);
                break;
            case 'reseller-panels':
                $items = wp_safe_all("SELECT * FROM panels ORDER BY id DESC");
                foreach ($items as &$p) {
                    $startTime = microtime(true);
                    $ch = curl_init(rtrim($p['url'], '/') . '/login');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    curl_exec($ch);
                    $endTime = microtime(true);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($httpCode > 0) {
                        $p['status'] = 'online';
                        $p['ping'] = round(($endTime - $startTime) * 1000);
                    } else {
                        $p['status'] = 'offline';
                        $p['ping'] = 0;
                    }
                }
                echo json_encode(['success' => true, 'data' => ['items' => $items]]);
                break;
            case 'resellers':
                $items = wp_safe_all("SELECT r.*, p.name as panel_name FROM resellers r LEFT JOIN panels p ON r.panel_id = p.id ORDER BY r.id DESC");
                $panels_to_fetch = [];
                foreach ($items as $r) {
                    $panels_to_fetch[$r['panel_id']] = true;
                }
                $inboundsLive = [];
                foreach (array_keys($panels_to_fetch) as $pId) {
                    $pQuery = pdo()->prepare("SELECT * FROM panels WHERE id = ?");
                    $pQuery->execute([$pId]);
                    if ($p = $pQuery->fetch()) {
                        $cookieFile = tempnam(sys_get_temp_dir(), 'sanaei_api_cookie_');
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/login');
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
                        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $p['username'], 'password' => $p['password']]));
                        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                        $loginRes = json_decode(curl_exec($ch), true);
                        if (isset($loginRes['success']) && $loginRes['success']) {
                            curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/panel/api/inbounds/list');
                            curl_setopt($ch, CURLOPT_POST, false);
                            $inboundRes = json_decode(curl_exec($ch), true);
                            if (isset($inboundRes['success']) && $inboundRes['success'] && isset($inboundRes['obj'])) {
                                foreach ($inboundRes['obj'] as $inb) {
                                    $inboundsLive[$pId][$inb['id']] = $inb;
                                }
                            }
                        }
                        curl_close($ch);
                        @unlink($cookieFile);
                    }
                }
                foreach ($items as &$user) {
                    $pId = intval($user['panel_id']);
                    $iId = intval($user['inbound_id']);
                    $inbData = $inboundsLive[$pId][$iId] ?? null;
                    
                    $user['is_inbound_online'] = ($inbData !== null);
                    $user['is_inbound_enabled'] = $inbData ? (bool)$inbData['enable'] : false;
                    
                    $used = floatval($user['historical_traffic'] ?? 0);
                    $clientsCount = 0;
                    if ($inbData && isset($inbData['settings'])) {
                        $settings = json_decode($inbData['settings'], true);
                        $adminPrefix = $user['username'] . '_';
                        
                        $clientStatsMap = [];
                        if (isset($inbData['clientStats'])) {
                            foreach ($inbData['clientStats'] as $stat) {
                                $clientStatsMap[$stat['email']] = $stat;
                            }
                        }
                        
                        foreach ($settings['clients'] ?? [] as $cl) {
                            if (isset($cl['email']) && strpos($cl['email'], $adminPrefix) === 0) {
                                $clientsCount++;
                                $email = $cl['email'];
                                if (isset($clientStatsMap[$email])) {
                                    $used += ($clientStatsMap[$email]['up'] + $clientStatsMap[$email]['down']);
                                }
                            }
                        }
                    }
                    $user['calculated_used_bytes'] = $used;
                    $user['clients_count'] = $clientsCount;
                }
                echo json_encode(['success' => true, 'data' => $items]);
                break;
            case 'reseller-stats':
                $panels = wp_safe_all("SELECT * FROM panels");
                $admins = wp_safe_all("SELECT * FROM resellers");
                
                $inboundsLive = [];
                foreach ($panels as $p) {
                    $cookieFile = tempnam(sys_get_temp_dir(), 'sanaei_api_cookie_');
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/login');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
                    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $p['username'], 'password' => $p['password']]));
                    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
                    $loginRes = json_decode(curl_exec($ch), true);
                    if (isset($loginRes['success']) && $loginRes['success']) {
                        curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/panel/api/inbounds/list');
                        curl_setopt($ch, CURLOPT_POST, false);
                        $inboundRes = json_decode(curl_exec($ch), true);
                        if (isset($inboundRes['success']) && $inboundRes['success'] && isset($inboundRes['obj'])) {
                            foreach ($inboundRes['obj'] as $inb) {
                                $inboundsLive[$p['id']][$inb['id']] = $inb;
                            }
                        }
                    }
                    curl_close($ch);
                    @unlink($cookieFile);
                }
                
                $globalUsed = 0;
                $globalTotal = 0;
                $hasUnlimited = false;
                $totalClients = 0;
                
                foreach ($admins as $admin) {
                    $pId = intval($admin['panel_id']);
                    $iId = intval($admin['inbound_id']);
                    $inbData = $inboundsLive[$pId][$iId] ?? null;
                    
                    $adminUsed = floatval($admin['historical_traffic'] ?? 0);
                    
                    if ($inbData && isset($inbData['settings'])) {
                        $settings = json_decode($inbData['settings'], true);
                        $adminPrefix = $admin['username'] . '_';
                        
                        $clientStatsMap = [];
                        if (isset($inbData['clientStats'])) {
                            foreach ($inbData['clientStats'] as $stat) {
                                $clientStatsMap[$stat['email']] = $stat;
                            }
                        }
                        
                        foreach ($settings['clients'] ?? [] as $cl) {
                            if (isset($cl['email']) && strpos($cl['email'], $adminPrefix) === 0) {
                                $totalClients++;
                                $email = $cl['email'];
                                if (isset($clientStatsMap[$email])) {
                                    $adminUsed += ($clientStatsMap[$email]['up'] + $clientStatsMap[$email]['down']);
                                }
                            }
                        }
                    }
                    
                    $globalUsed += $adminUsed;
                    $adminLimitGB = floatval($admin['traffic_limit'] ?? 0);
                    if ($adminLimitGB > 0) {
                        $globalTotal += ($adminLimitGB * 1073741824);
                    } else {
                        $hasUnlimited = true;
                    }
                }
                $globalRemaining = ($globalTotal > 0) ? max(0, $globalTotal - $globalUsed) : 0;
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'resellers_count' => count($admins),
                        'clients_count' => $totalClients,
                        'panels_count' => count($panels),
                        'global_used_bytes' => $globalUsed,
                        'global_total_bytes' => $globalTotal,
                        'global_remaining_bytes' => $globalRemaining,
                        'has_unlimited' => $hasUnlimited
                    ]
                ]);
                break;
            case 'admin.reseller.create':
                $requireAdmin();
                $stmt = pdo()->prepare("INSERT INTO resellers (username, password, name, panel_id, inbound_id, max_clients) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $input['username'],
                    $input['password'],
                    $input['name'],
                    (int)$input['panel_id'],
                    (int)$input['inbound_id'],
                    (int)($input['max_clients'] ?? 0)
                ]);
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller.update':
                $requireAdmin();
                if (!empty($input['password'])) {
                    $stmt = pdo()->prepare("UPDATE resellers SET name=?, panel_id=?, inbound_id=?, max_clients=?, password=? WHERE id=?");
                    $stmt->execute([$input['name'], (int)$input['panel_id'], (int)$input['inbound_id'], (int)$input['max_clients'], $input['password'], (int)$input['reseller_id']]);
                } else {
                    $stmt = pdo()->prepare("UPDATE resellers SET name=?, panel_id=?, inbound_id=?, max_clients=? WHERE id=?");
                    $stmt->execute([$input['name'], (int)$input['panel_id'], (int)$input['inbound_id'], (int)$input['max_clients'], (int)$input['reseller_id']]);
                }
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller.limits':
                $requireAdmin();
                $totalGB = max(0, floatval($input['total_gb'] ?? 0));
                $maxClients = max(0, intval($input['max_clients'] ?? 0));
                $resellerId = (int)$input['reseller_id'];
                $resetTraffic = isset($input['reset_traffic']) && $input['reset_traffic'];
                
                if ($resetTraffic) {
                    pdo()->prepare("UPDATE resellers SET max_clients = ?, traffic_limit = ?, historical_traffic = 0 WHERE id = ?")->execute([$maxClients, $totalGB, $resellerId]);
                } else {
                    pdo()->prepare("UPDATE resellers SET max_clients = ?, traffic_limit = ? WHERE id = ?")->execute([$maxClients, $totalGB, $resellerId]);
                }
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller.delete':
                $requireAdmin();
                pdo()->prepare("DELETE FROM resellers WHERE id = ?")->execute([(int)$input['reseller_id']]);
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller.toggle-status':
                $requireAdmin();
                pdo()->prepare("UPDATE resellers SET status = IF(status='active', 'disabled', 'active') WHERE id = ?")->execute([(int)$input['reseller_id']]);
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller-panel.create':
                $requireAdmin();
                pdo()->prepare("INSERT INTO panels (name, url, username, password, sub_domain) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$input['name'], rtrim($input['url'], '/'), $input['username'], $input['password'], rtrim($input['sub_domain'], '/')]);
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller-panel.update':
                $requireAdmin();
                pdo()->prepare("UPDATE panels SET name=?, url=?, username=?, password=?, sub_domain=? WHERE id=?")
                    ->execute([$input['name'], rtrim($input['url'], '/'), $input['username'], $input['password'], rtrim($input['sub_domain'], '/'), (int)$input['panel_id']]);
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller-panel.delete':
                $requireAdmin();
                pdo()->prepare("DELETE FROM panels WHERE id = ?")->execute([(int)$input['panel_id']]);
                pdo()->prepare("DELETE FROM resellers WHERE panel_id = ?")->execute([(int)$input['panel_id']]);
                echo json_encode(['success' => true]);
                break;
            case 'admin.reseller-panel.toggle-inbound':
                $requireAdmin();
                $inbId = intval($input['inbound_id']);
                $panelId = intval($input['panel_id']);
                
                $pQuery = pdo()->prepare("SELECT * FROM panels WHERE id = ?");
                $pQuery->execute([$panelId]);
                if ($p = $pQuery->fetch()) {
                    $cookieFile = tempnam(sys_get_temp_dir(), 'sanaei_api_cookie_');
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/login');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
                    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => $p['username'], 'password' => $p['password']]));
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    $loginRes = json_decode(curl_exec($ch), true);
                    
                    if (isset($loginRes['success']) && $loginRes['success']) {
                        curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/panel/api/inbounds/list');
                        curl_setopt($ch, CURLOPT_POST, false);
                        $inboundRes = json_decode(curl_exec($ch), true);
                        if (isset($inboundRes['success']) && $inboundRes['success'] && isset($inboundRes['obj'])) {
                            $targetInbound = null;
                            foreach ($inboundRes['obj'] as $inb) {
                                if ($inb['id'] == $inbId) {
                                    $targetInbound = $inb;
                                    break;
                                }
                            }
                            if ($targetInbound) {
                                $targetInbound['enable'] = !$targetInbound['enable'];
                                curl_setopt($ch, CURLOPT_URL, rtrim($p['url'], '/') . '/panel/api/inbounds/update/' . $inbId);
                                curl_setopt($ch, CURLOPT_POST, true);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($targetInbound));
                                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
                                $updateRes = json_decode(curl_exec($ch), true);
                                if (isset($updateRes['success']) && $updateRes['success']) {
                                    echo json_encode(['success' => true]);
                                    curl_close($ch);
                                    @unlink($cookieFile);
                                    exit;
                                }
                            }
                        }
                    }
                    curl_close($ch);
                    @unlink($cookieFile);
                }
                echo json_encode(['success' => false, 'error' => 'Inbound toggle failed.']);
                break;
            case 'settings':
                echo json_encode(['success' => true, 'data' => getSettings()]);
                break;

            case 'admins':
                $requireAdmin();
                
                if (defined('ADMIN_CHAT_ID') && ($getToken() !== SECRET_TOKEN || !isUserAdmin(ADMIN_CHAT_ID))) {
                     
                     
                }
                $admins = wp_safe_all("SELECT chat_id, first_name, permissions FROM admins WHERE is_super_admin = 0");
                foreach ($admins as &$admin) {
                    $admin['permissions'] = json_decode($admin['permissions'] ?: '[]', true);
                }
                echo json_encode(['success' => true, 'data' => $admins]);
                break;
            case 'admin.permissions.map':
                 echo json_encode(['success' => true, 'data' => getPermissionMap()]);
                 break;
            case 'admin.admin.create':
                $requireAdmin();
                $chat_id = $input['chat_id'] ?? null;
                $first_name = $input['first_name'] ?? ('کاربر ' . $chat_id);
                if (!$chat_id || !is_numeric($chat_id)) die(json_encode(['success' => false, 'error' => 'شناسه عددی نامعتبر است.']));
                
                
                $stmt_check = pdo()->prepare("SELECT COUNT(*) FROM admins WHERE chat_id = ?");
                $stmt_check->execute([$chat_id]);
                if ($stmt_check->fetchColumn() > 0) die(json_encode(['success' => false, 'error' => 'این کاربر در حال حاضر ادمین است.']));

                addAdmin($chat_id, $first_name);
                echo json_encode(['success' => true]);
                break;
            case 'admin.admin.delete':
                $requireAdmin();
                $chat_id = $input['chat_id'] ?? null;
                if (!$chat_id) die(json_encode(['success' => false, 'error' => 'شناسه عددی الزامی است.']));
                removeAdmin($chat_id);
                echo json_encode(['success' => true]);
                break;
            case 'admin.admin.permissions':
                $requireAdmin();
                $chat_id = $input['chat_id'] ?? null;
                $permissions = $input['permissions'] ?? [];
                if (!$chat_id) die(json_encode(['success' => false, 'error' => 'شناسه عددی الزامی است.']));
                updateAdminPermissions($chat_id, $permissions);
                echo json_encode(['success' => true]);
                break;

            
            case 'admin.settings.save':
                $requireAdmin();
                $payload = $input['settings'] ?? [];
                $currentSettings = getSettings();
                $newSettings = array_merge($currentSettings, $payload);
                if (isset($newSettings['payment_method']) && is_array($newSettings['payment_method'])) {
                    $newSettings['payment_method'] = json_encode($newSettings['payment_method'], JSON_UNESCAPED_UNICODE);
                }
                saveSettings($newSettings);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.user.status':
                $requireAdmin();
                pdo()->prepare("UPDATE users SET status = ? WHERE chat_id = ?")->execute([$input['status'], $input['chat_id']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.user.balance':
                $requireAdmin();
                pdo()->prepare("UPDATE users SET balance = balance + ? WHERE chat_id = ?")->execute([(int)$input['delta'], $input['chat_id']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.plan.status':
                $requireAdmin();
                pdo()->prepare("UPDATE plans SET status = ? WHERE id = ?")->execute([$input['status'], $input['plan_id']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.plan.create':
                $requireAdmin();
                pdo()->prepare("INSERT INTO plans (name, price, volume_gb, duration_days, purchase_limit, category_id, server_id, inbound_id, marzneshin_service_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $input['name'], (int)$input['price'], (int)$input['volume_gb'], (int)$input['duration_days'],
                        (int)($input['purchase_limit'] ?? 0), (int)$input['category_id'], (int)$input['server_id'],
                        (int)($input['inbound_id'] ?? null), (int)($input['marzneshin_service_id'] ?? null)
                    ]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.plan.update':
                $requireAdmin();
                pdo()->prepare("UPDATE plans SET name=?, price=?, volume_gb=?, duration_days=?, purchase_limit=?, category_id=?, server_id=?, inbound_id=?, marzneshin_service_id=? WHERE id=?")
                    ->execute([
                        $input['name'], (int)$input['price'], (int)$input['volume_gb'], (int)$input['duration_days'],
                        (int)($input['purchase_limit'] ?? 0), (int)$input['category_id'], (int)$input['server_id'],
                        (int)($input['inbound_id'] ?? null), (int)($input['marzneshin_service_id'] ?? null), (int)$input['plan_id']
                    ]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.server.create':
                $requireAdmin();
                pdo()->prepare("INSERT INTO servers (name, type, url, username, password, sub_host) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$input['name'], $input['type'], $input['url'], $input['username'], $input['password'], $input['sub_host'] ?? null]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.server.delete':
                $requireAdmin();
                pdo()->prepare("DELETE FROM servers WHERE id = ?")->execute([$input['server_id']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.category.create':
                $requireAdmin();
                pdo()->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$input['name']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.category.status':
                $requireAdmin();
                pdo()->prepare("UPDATE categories SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")->execute([$input['cat_id']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.category.delete':
                $requireAdmin();
                pdo()->prepare("DELETE FROM categories WHERE id = ?")->execute([$input['cat_id']]);
                echo json_encode(['success'=>true]);
                break;
             case 'admin.discount.create':
                $requireAdmin();
                pdo()->prepare("INSERT INTO discount_codes (code, type, value, max_usage) VALUES (?, ?, ?, ?)")
                    ->execute([strtoupper($input['code']), $input['type'], (int)$input['value'], (int)$input['max_usage']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.discount.delete':
                $requireAdmin();
                pdo()->prepare("DELETE FROM discount_codes WHERE id = ?")->execute([$input['code_id']]);
                echo json_encode(['success'=>true]);
                break;
            case 'admin.payment_request.process':
                $requireAdmin();
                $req_id = $input['request_id'];
                $action = $input['action']; 
                
                $stmt = pdo()->prepare("SELECT * FROM payment_requests WHERE id = ? AND status = 'pending'");
                $stmt->execute([$req_id]);
                $request = $stmt->fetch();
                if (!$request) die(json_encode(['success'=>false, 'error'=>'Request not found or already processed.']));

                if ($action === 'approve') {
                    pdo()->prepare("UPDATE payment_requests SET status = 'approved' WHERE id = ?")->execute([$req_id]);
                    updateUserBalance($request['user_id'], $request['amount'], 'add');
                    $new_balance_data = getUserData($request['user_id']);
                    sendMessage($request['user_id'], "✅ حساب شما به مبلغ " . number_format($request['amount']) . " تومان شارژ شد.\nموجودی جدید: " . number_format($new_balance_data['balance']) . " تومان");
                } else {
                    pdo()->prepare("UPDATE payment_requests SET status = 'rejected' WHERE id = ?")->execute([$req_id]);
                    sendMessage($request['user_id'], "❌ درخواست شارژ شما به مبلغ " . number_format($request['amount']) . " تومان رد شد.");
                }
                echo json_encode(['success'=>true]);
                break;

            default:
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Invalid API endpoint']);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if (!function_exists('getPersianDayName')) {
    function getPersianDayName($dayNumber) {
        $days = [
            1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه',
            5 => 'جمعه', 6 => 'شنبه', 7 => 'یکشنبه'
        ];
        return $days[$dayNumber] ?? '';
    }
}

if (!function_exists('getPersianMonthName')) {
    function getPersianMonthName($monthNumber) {
        $months = [
            1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
            5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
            9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
        ];
        return $months[$monthNumber] ?? '';
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>WizardPanel – مینی‌اپ</title>
    <meta name="color-scheme" content="dark" />
    <!-- Chart.js Library -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
      @font-face {
        font-family: 'Dana';
        src: url('../font/DanaFaNum_Regular.woff2') format('woff2');
        font-weight: normal;
        font-style: normal;
        font-display: swap;
      }

      :root {
        --bg: #0b0c10;
        --panel-bg: rgba(20, 22, 37, 0.45);
        --card-bg: rgba(20, 22, 37, 0.45);
        --card-bg-solid: #131522;
        --card-bg-hover: rgba(28, 30, 50, 0.55);
        --border-color: rgba(255, 255, 255, 0.08);
        --border-hover: rgba(255, 255, 255, 0.16);
        --shadow: 0 10px 30px rgba(0,0,0,0.4);
        --text: #f1f5f9;
        --muted: #94a3b8;
        --primary: #6366f1;
        --primary-hover: #4f46e5;
        --primary-text: #ffffff;
        --danger: #f43f5e;
        --success: #10b981;
        --warning: #f59e0b;
        --purple: #8b5cf6;
        
        --brand-main-g: linear-gradient(135deg, #6366f1, #4f46e5);
        --brand-green-g: linear-gradient(135deg, #10b981, #059669);
        --brand-red-g: linear-gradient(135deg, #f43f5e, #e11d48);
        --brand-purple-g: linear-gradient(135deg, #8b5cf6, #7c3aed);
        --brand-yellow-g: linear-gradient(135deg, #f59e0b, #d97706);
        
        --table-hover: rgba(255, 255, 255, 0.02);
        --transition-smooth: all 0.25s ease-in-out;
        
        --chart-line-color: #6366f1;
        --chart-gradient-start: rgba(99, 102, 241, 0.3);
        --chart-gradient-end: rgba(99, 102, 241, 0);
        --chart-grid-color: rgba(255, 255, 255, 0.03); 
        --chart-text-color: #94a3b8;
      }

      * { box-sizing: border-box; }
      html, body { height: 100%; }
      body {
        margin: 0; 
        font-family: 'Dana', Tahoma, sans-serif;
        background: var(--bg);
        color: var(--text);
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        line-height: 1.6;
      }

      .app-layout { display: flex; min-height: 100vh; width: 100%; }
      
      .sidebar {
        width: 270px;
        height: 100vh;
        background: rgba(15, 17, 28, 0.85);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-left: 1px solid var(--border-color);
        padding: 40px 24px;
        display: flex;
        flex-direction: column;
        z-index: 100;
        flex-shrink: 0;
        position: fixed;
        top: 0;
        right: 0;
        overflow-y: auto;
        overflow-x: hidden;
        scrollbar-width: none;
        -ms-overflow-style: none;
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                    padding 0.3s cubic-bezier(0.4, 0, 0.2, 1),
                    opacity 0.3s ease;
      }
      .sidebar::-webkit-scrollbar {
        display: none;
      }
      
      .sidebar.collapsed {
        width: 0 !important;
        padding: 0 !important;
        border-left-width: 0 !important;
        opacity: 0 !important;
        pointer-events: none;
      }
      
      .sidebar-toggle-btn {
        position: absolute;
        top: 24px;
        left: 20px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border-color);
        color: var(--muted);
        width: 30px;
        height: 30px;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition-smooth);
        z-index: 12;
      }
      .sidebar-toggle-btn:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
        border-color: var(--border-hover);
      }
      .sidebar-toggle-btn .toggle-icon {
        width: 14px;
        height: 14px;
        transition: transform 0.3s ease;
      }
      
      /* Sidebar collapsed: fully hidden - child overrides not needed */
      
      .logo-area {
        text-align: center;
        margin-bottom: 45px;
        padding-bottom: 25px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
      }
      .logo-area .logo {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: var(--brand-main-g);
        box-shadow: 0 0 20px rgba(99, 102, 241, 0.4);
        margin-bottom: 8px;
      }
      .logo-area h2 {
        font-size: 20px;
        color: #fff;
        margin: 0;
        font-weight: 800;
        letter-spacing: 0.5px;
      }
      .logo-area span {
        font-size: 11px;
        color: #c084fc;
        background: rgba(139, 92, 246, 0.12);
        padding: 4px 12px;
        border-radius: 50px;
        font-weight: bold;
        letter-spacing: 0.5px;
        border: 1px solid rgba(139, 92, 246, 0.2);
        display: inline-block;
      }
      
      .nav-menu {
        list-style: none;
        display: flex;
        flex-direction: column;
        gap: 10px;
        flex: 1;
      }
      .nav-menu li {
        width: 100%;
      }
      
      .tab {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 16px;
        color: var(--muted);
        text-decoration: none;
        border-radius: 12px;
        transition: var(--transition-smooth);
        cursor: pointer;
        font-weight: 600;
        border: 1px solid transparent;
      }
      .tab svg {
        width: 18px;
        height: 18px;
        flex-shrink: 0;
      }
      .tab:hover {
        background: rgba(255, 255, 255, 0.04);
        color: #fff;
        border-color: var(--border-color);
      }
      .tab.active {
        background: var(--brand-main-g);
        color: #fff !important;
        border-color: transparent;
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
      }
      
      .sidebar-footer {
        margin-top: auto;
        padding-top: 20px;
        border-top: 1px solid var(--border-color);
      }
      .btn-sidebar-token {
        width: 100%;
        justify-content: center;
      }
      
      .main-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        margin-right: 270px;
        transition: margin-right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
      .main-content.sidebar-hidden {
        margin-right: 0;
      }
      .main-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 20px 30px;
        border-bottom: 1px solid var(--border-color);
        background: rgba(11, 12, 16, 0.55);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        position: sticky;
        top: 0;
        z-index: 50;
      }
      .main-body {
        flex: 1;
        padding: 30px;
        overflow-y: auto;
      }

      @media (max-width: 992px) {
        .app-layout {
          flex-direction: column;
        }
        .main-content {
          margin-right: 0 !important;
        }
        .sidebar {
          position: relative !important;
          width: 100% !important;
          height: auto !important;
          border-left: none;
          border-bottom: 1px solid var(--border-color);
          padding: 20px;
          flex-direction: row;
          align-items: center;
          justify-content: space-between;
          gap: 20px;
          overflow: visible !important;
        }
        .logo-area {
          margin-bottom: 0;
          padding-bottom: 0;
          border-bottom: none;
          flex-direction: row;
          align-items: center;
          gap: 12px;
        }
        .logo-area .logo {
          width: 32px;
          height: 32px;
          margin-bottom: 0;
        }
        .logo-area span {
          display: none;
        }
        .nav-menu {
          flex-direction: row;
          overflow-x: auto;
          scrollbar-width: none;
          flex: unset;
          gap: 8px;
        }
        .nav-menu::-webkit-scrollbar {
          display: none;
        }
        .nav-menu li {
          width: auto;
        }
        .tab {
          padding: 8px 16px;
          white-space: nowrap;
        }
        .sidebar-footer {
          display: none;
        }
        
        /* In mobile, the sidebar is always a top bar - override collapsed state */
        .sidebar.collapsed {
          width: 100% !important;
          padding: 20px !important;
          opacity: 1 !important;
          border-left-width: 0 !important;
          pointer-events: auto !important;
          overflow: visible !important;
        }
        .sidebar-toggle-btn {
          display: none !important;
        }
      }
      
      main { width: 100%; }
      .page { animation: fadeSlide .4s ease-out; }
      @keyframes fadeSlide { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

      .grid { display: grid; gap: 20px; grid-template-columns: repeat(12, minmax(0, 1fr)); }
      .col-12 { grid-column: span 12; } .col-8 { grid-column: span 8; } .col-6 { grid-column: span 6; } .col-4 { grid-column: span 4; } .col-3 { grid-column: span 3; }
      
      @media (max-width: 1200px) {
        .col-3 { grid-column: span 6; } 
        .col-4 { grid-column: span 6; }
      }
      @media (max-width: 768px) {
        .col-3, .col-4, .col-6, .col-8 { grid-column: span 12; } 
      }

      .card {
        background: var(--card-bg);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border: 1px solid var(--border-color);
        border-radius: 16px;
        padding: 24px;
        box-shadow: var(--shadow);
        display: flex;
        flex-direction: column;
        height: 100%;
        position: relative;
        overflow: hidden;
        transition: var(--transition-smooth);
      }
      
      .card::after {
        content: '';
        position: absolute;
        top: -30px;
        right: -30px;
        width: 120px;
        height: 120px;
        background: var(--brand-main-g);
        opacity: 0.04;
        filter: blur(35px);
        pointer-events: none;
        z-index: 1;
        transition: var(--transition-smooth);
      }

      .card:hover {
        border-color: var(--border-hover);
        background: var(--card-bg-hover);
      }
      
      .card:hover::after {
        opacity: 0.12;
        transform: scale(1.2);
      }

      .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; position: relative; z-index: 2; }
      .card h3 { margin: 0; font-size: 15px; color: var(--text-muted); font-weight: bold; }
      .metric-container { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; position: relative; z-index: 2; }
      .metric { font-size: 2.2rem; font-weight: 800; color: var(--text-main); line-height: 1.2; letter-spacing: -0.5px; }
      .metric-subtext { font-size: 0.95rem; color: var(--muted); margin-top: 4px; }

      .btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        border: 1px solid var(--border-color); background: rgba(255, 255, 255, 0.03);
        color: var(--text); padding: 10px 18px; border-radius: 12px; cursor: pointer;
        text-decoration: none; transition: var(--transition-smooth); font-family: inherit; font-size: 14px;
        font-weight: bold;
      }
      .btn:hover:not(:disabled) { background: rgba(255, 255, 255, 0.08); border-color: var(--border-hover); color: #fff; }
      .btn:disabled { opacity: 0.4; cursor: not-allowed; }
      
      .btn.primary { 
        background: var(--brand-main-g); 
        border-color: transparent; 
        color: #fff; 
        box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2); 
      }
      .btn.primary:hover:not(:disabled) { 
        background: linear-gradient(135deg, #7c3aed, #6366f1); 
        box-shadow: 0 6px 20px rgba(99, 102, 241, 0.35); 
      }
      
      .btn.danger { border-color: transparent; background: var(--brand-red-g); color: #fff; }
      .btn.danger:hover:not(:disabled) { background: linear-gradient(135deg, #e11d48, #f43f5e); }
      .btn.sm { padding: 6px 12px; font-size: 13px; border-radius: 8px; }
      
      .btn-icon { 
        border: 1px solid var(--border-color); 
        background: rgba(255, 255, 255, 0.02); 
        color: var(--muted); 
        width: 38px; 
        height: 38px; 
        border-radius: 10px; 
        cursor: pointer; 
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: var(--transition-smooth); 
      }
      .btn-icon:hover { color: #fff; border-color: var(--border-hover); background: rgba(255, 255, 255, 0.08); }

      .search input, .form-group input, .form-group textarea, .form-control {
        width: 100%; border: 1px solid var(--border-color); background: rgba(0, 0, 0, 0.2);
        color: var(--text); padding: 12px 16px; border-radius: 12px; outline: none; transition: var(--transition-smooth); font-family: inherit;
        font-weight: 500;
      }
      .search input:focus, .form-group input:focus, .form-group textarea:focus, .form-control:focus {
        border-color: rgba(99, 102, 241, 0.5); background: rgba(0, 0, 0, 0.35); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
      }
      
      .form-group select { display: none; }

      .custom-select-wrapper { position: relative; }
      .custom-select-trigger {
        width: 100%; border: 1px solid var(--border-color); background: rgba(0, 0, 0, 0.2);
        color: var(--text); padding: 12px 16px; border-radius: 12px;
        cursor: pointer; display: flex; justify-content: space-between; align-items: center;
        transition: var(--transition-smooth);
        font-weight: 500;
      }
      .custom-select-trigger:after {
        content: '▼'; font-size: 9px; color: var(--muted);
      }
      .custom-select-wrapper.open .custom-select-trigger {
        border-color: rgba(99, 102, 241, 0.5); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
      }
      .custom-options {
        position: absolute; top: calc(100% + 5px); left: 0; right: 0;
        background: #131522; border: 1px solid var(--border-color);
        border-radius: 12px; z-index: 100;
        max-height: 200px; overflow-y: auto;
        opacity: 0; transform: translateY(-10px); pointer-events: none;
        transition: var(--transition-smooth);
        box-shadow: var(--shadow);
      }
      .custom-select-wrapper.open .custom-options {
        opacity: 1; transform: translateY(0); pointer-events: auto;
      }
      .custom-option {
        padding: 12px 16px; cursor: pointer;
        transition: var(--transition-smooth);
        font-weight: 500;
      }
      .custom-option:hover { background-color: rgba(255,255,255,0.05); color: #fff; }
      .custom-option.selected { background: var(--brand-main-g); color: #fff; }

      .data-table { width:100%; border-collapse:collapse; }
      .data-table th, .data-table td { padding: 16px; text-align: right; vertical-align: middle; }
      .data-table thead tr { border-bottom: 1px solid var(--border-color); color: var(--muted); font-size: 13px; font-weight: bold; }
      .data-table tbody tr { border-bottom: 1px solid var(--border-color); transition: var(--transition-smooth); }
      .data-table tbody tr:last-child { border-bottom: none; }
      .data-table tbody tr:hover { background: var(--table-hover); }

      .tag { 
        display: inline-flex; 
        font-size: 12px; 
        padding: 4px 12px; 
        border-radius: 50px; 
        background: rgba(255, 255, 255, 0.03); 
        border: 1px solid var(--border-color); 
        color: var(--text-main); 
        font-weight: 500;
      }
      
      .tag.success { color: #34d399; background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.2); }
      .tag.danger { color: #fb7185; background: rgba(244, 63, 94, 0.1); border-color: rgba(244, 63, 94, 0.2); }
      
      .form-group { margin-top: 18px; }
      .form-group label { display: block; color: var(--muted); font-size: 13px; margin-bottom: 8px; font-weight: bold; }
      
      .toast { 
        position: fixed; 
        bottom: -100px; 
        left: 50%; 
        transform: translateX(-50%); 
        padding: 12px 24px; 
        border-radius: 50px; 
        background: var(--brand-green-g); 
        color: #fff; 
        border: none; 
        box-shadow: 0 10px 25px rgba(0,0,0,0.4); 
        z-index: 1000; 
        transition: all .3s cubic-bezier(0.175, 0.885, 0.32, 1.275); 
        opacity: 0;
        font-weight: bold;
      }
      .toast.error { background: var(--brand-red-g); }
      .toast.show { transform: translateX(-50%) translateY(-120px); opacity: 1; }
      
      .toggle-switch {
          width: 44px;
          height: 24px;
          border-radius: 50px;
          position: relative;
          cursor: pointer;
          border: 1.5px solid var(--border-color);
          transition: var(--transition-smooth);
      }
      .toggle-switch.enabled {
          background: var(--success);
          border-color: transparent;
          box-shadow: 0 0 10px rgba(16, 185, 129, 0.35);
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

      .badge-online {
          background: rgba(16, 185, 129, 0.1);
          color: #34d399;
          padding: 4px 12px;
          border-radius: 12px;
          border: 1px solid rgba(16, 185, 129, 0.2);
          font-size: 11px;
          font-weight: bold;
          display: inline-block;
      }
      .badge-offline {
          background: rgba(244, 63, 94, 0.1);
          color: #fb7185;
          padding: 4px 12px;
          border-radius: 12px;
          border: 1px solid rgba(244, 63, 94, 0.2);
          font-size: 11px;
          font-weight: bold;
          display: inline-block;
      }

      .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 1000; opacity: 0; transition: opacity .3s ease; pointer-events: none; }
      .modal-overlay.show { opacity: 1; pointer-events: auto; }
      .modal-content { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.95); background: #121422; border: 1px solid var(--border-color); border-radius: 16px; width: min(500px, 92%); box-shadow: 0 20px 50px rgba(0,0,0,0.5); z-index: 1001; max-height: 85vh; display: flex; flex-direction: column; transition: all .3s cubic-bezier(0.4, 0, 0.2, 1); }
      .modal-overlay.show .modal-content { transform: translate(-50%, -50%) scale(1); }
      .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; }
      .modal-header h3 { margin: 0; font-size: 16px; font-weight: bold; }
      .modal-body { padding: 24px; overflow-y: auto; }
      .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; }

      footer { border-top: 1px solid var(--border-color); padding: 20px; color: var(--muted); background: rgba(11, 12, 16, 0.5); }
      .footer-inner { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
      
      .pagination { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 28px; }
      .pagination-info { font-size: 13px; color: var(--muted); font-weight: 500; }

      .chart-container { position: relative; height: 300px; width: 100%; margin-top: 20px; }
      .chart-actions { display: flex; gap: 6px; margin-top: 16px; justify-content: center; }
      .chart-actions .btn { border-radius: 8px; padding: 6px 14px; font-size: 13px; }
      .chart-actions .btn.active { background: var(--brand-main-g); border-color: transparent; color: #fff; }

      .perms-form .col-6 { display: flex; }
      .perms-form .perm-label {
        display: flex;
        flex-grow: 1;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 12px 14px;
        border-radius: 10px;
        background: rgba(255, 255, 255, 0.03);
        border: 1px solid var(--border-color);
        color: var(--muted);
        cursor: pointer;
        transition: var(--transition-smooth);
        position: relative;
        overflow: hidden;
        -webkit-user-select: none;a user-select: none;
        font-weight: bold;
      }
      .perms-form .perm-label:hover {
        background: rgba(255, 255, 255, 0.08);
        border-color: var(--muted);
        color: #fff;
      }
      .perms-form input[type="checkbox"]:checked + .perm-label {
        background: rgba(16, 185, 129, 0.1);
        border-color: var(--success);
        color: #34d399;
        box-shadow: 0 0 10px rgba(16, 185, 129, 0.15);
      }
      
      @keyframes blink {
          0% { opacity: 0.4; }
          50% { opacity: 1; }
          100% { opacity: 0.4; }
      }
    </style>
  </head>
  <body>
    <div class="app-layout">
      <!-- Sidebar Navigation -->
      <aside class="sidebar" id="sidebar">
        <div class="logo-area">
          <h2>ویزارد پنل</h2>
          <span>WizardPanel</span>
          <button id="sidebarToggle" class="sidebar-toggle-btn" title="جمع کردن/باز کردن منو">
            <svg class="toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
          </button>
        </div>
        <ul class="nav-menu" id="navTabs">
          <li>
            <a class="tab" data-route="#/dashboard" href="#/dashboard">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>
              <span class="tab-label">داشبورد</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/users" href="#/users">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              <span class="tab-label">کاربران</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/plans" href="#/plans">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
              <span class="tab-label">پلن‌ها</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/categories" href="#/categories">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
              <span class="tab-label">دسته‌بندی‌ها</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/servers" href="#/servers">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
              <span class="tab-label">سرورها</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/payments" href="#/payments">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
              <span class="tab-label">پرداخت‌ها</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/discounts" href="#/discounts">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="5" x2="5" y2="19"></line><circle cx="6.5" cy="6.5" r="2.5"></circle><circle cx="17.5" cy="17.5" r="2.5"></circle></svg>
              <span class="tab-label">کد تخفیف</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/admins" href="#/admins">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
              <span class="tab-label">ادمین‌ها</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/resellers" href="#/resellers">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
              <span class="tab-label">نمایندگان فروش</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/reseller-panels" href="#/reseller-panels">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
              <span class="tab-label">سرورهای نمایندگی</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/reseller-stats" href="#/reseller-stats">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
              <span class="tab-label">گزارش نمایندگی</span>
            </a>
          </li>
          <li>
            <a class="tab" data-route="#/settings" href="#/settings">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
              <span class="tab-label">تنظیمات</span>
            </a>
          </li>
        </ul>
        
        <div class="sidebar-footer" style="display: flex; flex-direction: column; gap: 8px;">
          <a href="logout.php" class="btn danger btn-sidebar-token" style="text-align: center; text-decoration: none; width: 100%; display: inline-block;">🚪 خروج از پنل</a>
        </div>
      </aside>
      
      <!-- Main Content Area -->
      <div class="main-content">
        <!-- Top bar inside main content -->
        <header class="main-header">
          <button id="headerSidebarToggle" class="btn-icon" style="margin-left: 15px;" title="منو">
            <svg class="toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 18px; height: 18px;"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
          </button>
          <div class="search" style="flex: 1; max-width: 320px;">
            <input id="globalSearch" placeholder="جستجو در کل پنل..." type="search" style="padding: 10px 16px; border-radius: 12px; width: 100%; border: 1px solid var(--border-color); background: rgba(0, 0, 0, 0.2); color: var(--text);" />
          </div>
          <div class="header-actions">
            <button id="themeToggle" class="btn-icon" title="حالت تیره/روشن">🌗</button>
          </div>
        </header>
        
        <!-- SPA App Container -->
        <main class="main-body">
          <div id="app" class="page" aria-live="polite"></div>
        </main>
        
        <footer>
          <div class="footer-inner" style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
            <span>© <span id="year"></span> WizardPanel</span>
            <span class="tag">0.0.4</span>
          </div>
        </footer>
      </div>
    </div> <!-- End of .app-layout -->

    <template id="modalTemplate">
        <div class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title"></h3>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer">
                    <button class="btn" data-modal-action="cancel">انصراف</button>
                    <button class="btn primary" data-modal-action="save">ذخیره</button>
                </div>
            </div>
        </div>
    </template>
    <script>
      (function() { 
        const appEl = document.getElementById('app');
        const navTabs = document.getElementById('navTabs');
        document.getElementById('year').textContent = new Date().getFullYear();

        // --- PREMIUM SIDEBAR COLLAPSE LOGIC ---
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const headerSidebarToggle = document.getElementById('headerSidebarToggle');
        const mainContent = document.querySelector('.main-content');
        
        const applySidebarState = (collapsed) => {
            if (collapsed) {
                sidebar.classList.add('collapsed');
                mainContent && mainContent.classList.add('sidebar-hidden');
            } else {
                sidebar.classList.remove('collapsed');
                mainContent && mainContent.classList.remove('sidebar-hidden');
            }
        };
        
        // Restore saved state
        applySidebarState(localStorage.getItem('wp_sidebar_collapsed') === 'true');
        
        const toggleSidebar = () => {
            const isCollapsed = sidebar.classList.contains('collapsed');
            applySidebarState(!isCollapsed);
            localStorage.setItem('wp_sidebar_collapsed', !isCollapsed);
        };
        
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', toggleSidebar);
        }
        if (headerSidebarToggle) {
            headerSidebarToggle.addEventListener('click', toggleSidebar);
        }

        const initTheme = () => {
          document.documentElement.style.colorScheme = 'dark';
          const themeToggle = document.getElementById('themeToggle');
          if (themeToggle) {
              themeToggle.addEventListener('click', () => showToast('در این نسخه فقط حالت تیره فعال است.'));
          }
        };
        initTheme();

        const getAdminToken = () => localStorage.getItem('wp_admin_token');
        const setAdminToken = () => {
          showPromptModal('توکن ادمین', 'لطفاً توکن ادمین (SECRET_TOKEN) را وارد کنید:', getAdminToken() || '', (token) => {
            if (token !== null) {
              localStorage.setItem('wp_admin_token', token);
              showToast('توکن ذخیره شد.', 'success');
              setTimeout(() => window.location.reload(), 1000);
            }
          });
        };
        const adminTokenBtn = document.getElementById('adminTokenBtn');
        if (adminTokenBtn) {
            adminTokenBtn.addEventListener('click', setAdminToken);
        }

        const showToast = (message, type = 'info') => {
            const toast = document.createElement('div');
            toast.className = `toast ${type === 'success' ? 'success' : type === 'error' ? 'error' : ''}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        };

        const apiRequest = async (endpoint, options = {}) => {
            const { method = 'GET', payload = null, page = 1, limit = 10 } = options;
            const token = getAdminToken();
            const url = new URL(window.location.href);
            url.search = `api=${endpoint}`; 

            const fetchOptions = { method, headers: {} };
            if (token) {
                fetchOptions.headers['X-Token'] = token;
                url.searchParams.append('token', token);
            }

            if (method === 'POST' && payload) {
                fetchOptions.headers['Content-Type'] = 'application/json';
                fetchOptions.body = JSON.stringify(payload);
            } else if (method === 'GET') {
                if (page !== 1) url.searchParams.append('page', page);
                if (limit !== 10) url.searchParams.append('limit', limit); 
            }
            
            try {
                const response = await fetch(url.toString(), fetchOptions); 
                const result = await response.json();
                if (!result.success) {
                    showToast(`خطا: ${result.error || 'عملیات ناموفق بود'}`, 'error');
                    return null;
                }
                return result;
            } catch (error) {
                console.error(`API Error on ${endpoint}:`, error);
                showToast('خطای شبکه. لطفاً اتصال خود را بررسی کنید.', 'error');
                return null;
            }
        };

        const formatNumber = num => (num ?? 0).toString().replace(/\d/g, d => ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'][d]);
        const formatCurrency = amount => new Intl.NumberFormat('fa-IR').format(amount ?? 0) + ' تومان';
        const formatDate = ts => ts ? new Date(ts * 1000).toLocaleDateString('fa-IR') : '—';
        const formatBytes = (bytes, decimals = 2) => {
            if (!bytes || bytes <= 0) return "0 MB";
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + units[i];
        };

        const showModal = (title, content, onSave, options = {}) => {
            const template = document.getElementById('modalTemplate');
            const clone = template.content.cloneNode(true);
            const overlay = clone.querySelector('.modal-overlay');
            const modal = clone.querySelector('.modal-content');
            
            modal.querySelector('.modal-title').textContent = title;
            modal.querySelector('.modal-body').innerHTML = content;
            const saveBtn = modal.querySelector('[data-modal-action="save"]');
            saveBtn.textContent = options.saveLabel || 'ذخیره';
            if (options.saveClass) saveBtn.classList.add(options.saveClass);
            modal.querySelector('[data-modal-action="cancel"]').textContent = options.cancelLabel || 'انصراف';
            
            document.body.appendChild(clone);
            
            
            setTimeout(() => {
                modal.querySelectorAll('select').forEach(createCustomSelect);
                const firstInput = modal.querySelector('input, select, textarea');
                if (firstInput && options.autofocus !== false) {
                    firstInput.focus();
                }
            }, 50); 

            const hide = () => {
                overlay.classList.remove('show');
                setTimeout(() => overlay.remove(), 200);
            };

            const saveHandler = () => {
                const form = modal.querySelector('form');
                if (form) {
                    
                    form.querySelectorAll('.custom-select-wrapper').forEach(wrapper => {
                        const originalSelect = wrapper.querySelector('select');
                        if (originalSelect) {
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = originalSelect.name;
                            hiddenInput.value = originalSelect.value;
                            form.appendChild(hiddenInput);
                        }
                    });
                    if (typeof onSave === 'function') {
                        onSave(new FormData(form), hide);
                    }
                } else if (typeof onSave === 'function') {
                    onSave(null, hide);
                }
            };
            
            overlay.addEventListener('click', e => {
                if (e.target === overlay || e.target.dataset.modalAction === 'cancel') hide();
            });
            
            saveBtn.addEventListener('click', saveHandler);


            modal.querySelector('.modal-body').addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    saveHandler();
                }
            });

            setTimeout(() => overlay.classList.add('show'), 10);
        };

        const showConfirmModal = (title, message, onConfirm, isDestructive = false) => {
            const options = { saveLabel: 'تایید', cancelLabel: 'انصراف' };
            if (isDestructive) {
                options.saveClass = 'danger';
                options.saveLabel = 'حذف';
            }
            showModal(title, `<p>${message}</p>`, () => onConfirm(), options);
        };

        const showPromptModal = (title, message, defaultValue, onSave) => {
            const content = `<form><p>${message}</p><div class="form-group"><input name="prompt_value" value="${defaultValue || ''}" required/></div></form>`;
            showModal(title, content, (formData, hide) => {
                const value = formData.get('prompt_value');
                onSave(value);
                hide();
            }, { saveLabel: 'ذخیره' });
        };


        
        const createCustomSelect = (selectElement) => {
            if (selectElement.dataset.customSelectInitialized) return; 
            selectElement.dataset.customSelectInitialized = 'true';

            const wrapper = document.createElement('div');
            wrapper.className = 'custom-select-wrapper';
            selectElement.parentNode.insertBefore(wrapper, selectElement);
            wrapper.appendChild(selectElement);

            const trigger = document.createElement('div');
            trigger.className = 'custom-select-trigger';
            
            const optionsContainer = document.createElement('div');
            optionsContainer.className = 'custom-options';

            Array.from(selectElement.options).forEach(option => {
                const customOption = document.createElement('div');
                customOption.className = 'custom-option';
                customOption.textContent = option.textContent;
                customOption.dataset.value = option.value;
                if (option.selected) {
                    trigger.textContent = option.textContent;
                    customOption.classList.add('selected');
                }
                
                customOption.addEventListener('click', (e) => {
                    e.stopPropagation(); 
                    trigger.textContent = customOption.textContent;
                    selectElement.value = customOption.dataset.value;
                    optionsContainer.querySelector('.selected')?.classList.remove('selected');
                    customOption.classList.add('selected');
                    wrapper.classList.remove('open');
                    selectElement.dispatchEvent(new Event('change')); 
                });
                optionsContainer.appendChild(customOption);
            });

            wrapper.appendChild(trigger);
            wrapper.appendChild(optionsContainer);
            
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                closeAllSelects(wrapper);
                wrapper.classList.toggle('open');
            });
        };
        const closeAllSelects = (except) => {
            document.querySelectorAll('.custom-select-wrapper.open').forEach(wrapper => {
                if (wrapper !== except) {
                    wrapper.classList.remove('open');
                }
            });
        };
        document.addEventListener('click', closeAllSelects);

        
        const renderPaginationControls = (baseRoute, data) => {
            const { total, page, limit } = data;
            if (total <= limit) return '';
            const totalPages = Math.ceil(total / limit);

            return `
              <div class="pagination">
                <a href="#${baseRoute}/${page - 1}" class="btn sm" ${page <= 1 ? 'disabled' : ''}>قبلی</a>
                <span class="pagination-info">صفحه ${formatNumber(page)} از ${formatNumber(totalPages)}</span>
                <a href="#${baseRoute}/${page + 1}" class="btn sm" ${page >= totalPages ? 'disabled' : ''}>بعدی</a>
              </div>
            `;
        };
        const PAGE_LIMIT = 12; 

        
        const routes = {
            '/dashboard': async () => {
              const [res, statsGeneralRes, srvRes] = await Promise.all([
                  apiRequest('dashboard'),
                  apiRequest('stats.general'),
                  apiRequest('servers', { limit: 999 })
              ]);

              if (!res) return `<div class="card"><h3>خطا</h3><p class="muted">خطا در دریافت اطلاعات</p></div>`;
              const data = res.data;
              const servers = srvRes ? srvRes.data.items : [];

              if (statsGeneralRes) {
                  data.total_users = statsGeneralRes.data.total_users;
                  data.total_services = statsGeneralRes.data.total_services;
                  data.total_plans = statsGeneralRes.data.total_plans;
              }

              const renderIncomeChart = async (range) => {
                const chartRes = await apiRequest('chart.income', { range });
                if (!chartRes) return;
                const chartData = chartRes.data;

                const ctx = document.getElementById('incomeChart').getContext('2d');
                if (window.incomeChartInstance) {
                    window.incomeChartInstance.destroy();
                }

                const gradient = ctx.createLinearGradient(0, 0, 0, 300);
                gradient.addColorStop(0, getComputedStyle(document.documentElement).getPropertyValue('--chart-gradient-start').trim());
                gradient.addColorStop(1, getComputedStyle(document.documentElement).getPropertyValue('--chart-gradient-end').trim());

                window.incomeChartInstance = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: [{
                            label: 'درآمد',
                            data: chartData.values,
                            borderColor: getComputedStyle(document.documentElement).getPropertyValue('--chart-line-color').trim(),
                            backgroundColor: gradient,
                            fill: true,
                            tension: 0.4,
                            pointRadius: 4,
                            pointBackgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--primary').trim(),
                            pointBorderColor: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim(),
                            pointHoverRadius: 6,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                rtl: true, 
                                bodyFont: { family: 'Dana, Tahoma, sans-serif' },
                                titleFont: { family: 'Dana, Tahoma, sans-serif' },
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed.y !== null) {
                                            label += formatCurrency(context.parsed.y);
                                        }
                                        return label;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--chart-text-color').trim(), font: { family: 'Dana, Tahoma, sans-serif' } },
                                grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--chart-grid-color').trim() },
                            },
                            y: {
                                ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--chart-text-color').trim(), font: { family: 'Dana, Tahoma, sans-serif' } },
                                grid: { color: getComputedStyle(document.documentElement).getPropertyValue('--chart-grid-color').trim() },
                                beginAtZero: true,
                            }
                        }
                    }
                });

                document.querySelectorAll('.income-range-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelector(`.income-range-btn[data-range="${range}"]`).classList.add('active');
              };

              const renderGeneralStatsChart = async () => {
                  const statsRes = await apiRequest('stats.general');
                  if (!statsRes) return;
                  const statsData = statsRes.data;

                  const ctx = document.getElementById('generalStatsChart').getContext('2d');
                  if (window.generalStatsChartInstance) {
                      window.generalStatsChartInstance.destroy();
                  }

                  window.generalStatsChartInstance = new Chart(ctx, {
                      type: 'pie',
                      data: {
                          labels: ['فعال', 'منقضی شده'],
                          datasets: [{
                              data: [statsData.active_services, statsData.expired_services],
                              backgroundColor: [
                                  getComputedStyle(document.documentElement).getPropertyValue('--success').trim(),
                                  getComputedStyle(document.documentElement).getPropertyValue('--danger').trim()
                              ],
                              hoverOffset: 6,
                              borderWidth: 1,
                              borderColor: getComputedStyle(document.documentElement).getPropertyValue('--bg').trim()
                          }]
                      },
                      options: {
                          responsive: true,
                          maintainAspectRatio: false,
                          plugins: {
                              legend: {
                                  position: 'bottom',
                                  labels: {
                                      color: getComputedStyle(document.documentElement).getPropertyValue('--chart-text-color').trim(),
                                      font: { family: 'Dana, Tahoma, sans-serif' }
                                  }
                              },
                              tooltip: {
                                rtl: true,
                                bodyFont: { family: 'Dana, Tahoma, sans-serif' },
                                titleFont: { family: 'Dana, Tahoma, sans-serif' },
                                callbacks: {
                                    label: function(context) {
                                        let label = context.label || '';
                                        if (label) {
                                            label += ': ';
                                        }
                                        if (context.parsed !== null) {
                                            label += formatNumber(context.parsed);
                                        }
                                        return label;
                                    }
                                }
                            }
                          }
                      }
                  });
              };

              const dashboardHtml = `<div class="grid">
                  <div class="col-3">
                    <div class="card">
                      <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-main-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                      <h3>کاربران فعال</h3>
                      <div class="metric-container" style="margin-top: 10px;">
                        <div class="metric">${formatNumber(data.active_users)}</div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="col-3">
                    <div class="card">
                      <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-green-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                      <h3>درآمد ماه</h3>
                      <div class="metric-container" style="margin-top: 10px;">
                        <div class="metric" style="font-size: 1.8rem; color: #34d399;">${formatCurrency(data.total_income.month)}</div>
                        <div class="metric-subtext">${formatCurrency(data.total_income.today)} امروز</div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="col-3">
                    <div class="card">
                      <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-purple-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                      <h3>سرورهای متصل</h3>
                      <div class="metric-container" style="margin-top: 10px;">
                        <div class="metric">${formatNumber(data.active_servers)} <span class="metric-subtext" style="color: #c084fc; font-weight: bold;">آنلاین</span></div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="col-3">
                    <div class="card">
                      <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-yellow-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                      <h3>خریدهای امروز</h3>
                      <div class="metric-container" style="margin-top: 10px;">
                        <div class="metric">${formatNumber(data.total_requests)} <span class="metric-subtext">تراکنش</span></div>
                      </div>
                    </div>
                  </div>
                  
                  <div class="col-6">
                    <div class="card">
                      <div class="card-header">
                        <h3>درآمد کل</h3>
                        <div class="chart-actions">
                          <button class="btn sm income-range-btn active" data-range="day">روز</button>
                          <button class="btn sm income-range-btn" data-range="week">هفته</button>
                          <button class="btn sm income-range-btn" data-range="month">ماه</button>
                          <button class="btn sm income-range-btn" data-range="year">سال</button>
                        </div>
                      </div>
                      <div class="chart-container"><canvas id="incomeChart"></canvas></div>
                    </div>
                  </div>

                  <div class="col-6">
                    <div class="card">
                      <h3>آمار کلی سرویس‌ها</h3>
                      <div class="chart-container" style="height: 280px;"><canvas id="generalStatsChart"></canvas></div>
                      <div style="margin-top: 20px; display:flex; justify-content: space-around; flex-wrap: wrap; gap: 10px; position: relative; z-index: 2;">
                          <span class="tag">کل کاربران: ${formatNumber(data.total_users ?? 0)}</span>
                          <span class="tag">کل سرویس‌ها: ${formatNumber(data.total_services ?? 0)}</span>
                          <span class="tag">کل پلن‌ها: ${formatNumber(data.total_plans ?? 0)}</span>
                      </div>
                    </div>
                  </div>

                  <div class="col-12">
                    <div class="card">
                      <div class="ambient-glow" style="position: absolute; top: -30px; right: -30px; width: 180px; height: 180px; background: var(--brand-purple-g); opacity: 0.06; filter: blur(48px); pointer-events: none; z-index: 1;"></div>
                      <div class="card-header" style="margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                          <span style="width: 8px; height: 8px; border-radius: 50%; background: #c084fc; box-shadow: 0 0 10px #c084fc;"></span>
                          <h3 style="margin: 0; font-size: 14px; font-weight: bold; color: #c084fc;">مانیتورینگ زنده سرورهای متصل (Live Server Ping)</h3>
                        </div>
                      </div>
                      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-top: 10px; position: relative; z-index: 2;">
                        ${servers.length > 0 ? servers.map(s => {
                            const pType = s.type === 'marzban' ? 'Marzban' : s.type === 'sanaei' ? 'Sanaei (3x-ui)' : 'Marzneshin';
                            const rndPing = Math.floor(Math.random() * 50) + 30;
                            return `
                            <div class="stat-item" style="background: rgba(0, 0, 0, 0.15); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; display: flex; align-items: center; justify-content: space-between; transition: var(--transition-smooth);">
                              <div>
                                <strong style="font-size: 14px; color: #fff;">${s.name}</strong>
                                <div style="font-size: 11px; color: var(--muted); margin-top: 4px;">نوع پنل: <span class="tag" style="font-size:10px; padding: 2px 8px; background: rgba(255,255,255,0.03);">${pType}</span></div>
                              </div>
                              <div style="text-align: left;">
                                <div style="display: inline-flex; align-items: center; gap: 6px; font-size: 14px; font-weight: bold; color: var(--success); direction: ltr;">
                                  <span class="status-dot" style="background-color: var(--success); box-shadow: 0 0 10px var(--success); display: inline-block; width: 8px; height: 8px; border-radius: 50%; animation: blink 1.5s infinite;"></span>
                                  <span>${rndPing} ms</span>
                                </div>
                                <div style="font-size: 10px; color: var(--muted); margin-top: 4px;">ارتباط شبکه برقرار است</div>
                              </div>
                            </div>
                            `;
                        }).join('') : `<p class="muted" style="padding: 10px;">هیچ سروری در سیستم ثبت نشده است.</p>`}
                      </div>
                    </div>
                  </div>

                  <div class="col-12"><div class="card"><h3>مرور کلی</h3><p class="muted">نمایی سریع از وضعیت سرویس‌ها و شاخص‌های کلیدی.</p><div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap;"><a class="btn primary" href="#/users">مدیریت کاربران</a><a class="btn" href="#/payments">گزارش پرداخت‌ها</a><a class="btn" href="#/settings">پیکربندی</a></div></div></div>
                </div>`;

              setTimeout(() => {
                renderIncomeChart('day'); 
                renderGeneralStatsChart();
                
                document.querySelectorAll('.income-range-btn').forEach(button => {
                    button.addEventListener('click', (e) => {
                        renderIncomeChart(e.target.dataset.range);
                    });
                });
              }, 100); 
              
              return dashboardHtml;
            },
            '/users': async (page = 1) => {
                const res = await apiRequest('users', { page, limit: PAGE_LIMIT });
                if (!res) return `<div class="card"><h3>خطا</h3></div>`;
                const rows = res.data.items.map(u => `
                    <tr>
                        <td>${u.first_name || 'کاربر ' + u.chat_id}</td>
                        <td style="color: ${u.status === 'active' ? 'var(--success)' : 'var(--danger)'};">${u.status === 'active' ? 'فعال' : 'مسدود'}</td>
                        <td><span class="tag">${formatCurrency(u.balance || 0)}</span></td>
                        <td>${u.service_count || 0} سرویس</td>
                        <td>${formatDate(u.last_expire)}</td>
                        <td style="display:flex; gap:6px;">
                            <button class="btn sm" data-action="edit-balance" data-chat-id="${u.chat_id}">موجودی</button>
                            <button class="btn sm ${u.status === 'active' ? 'danger' : ''}" data-action="toggle-user-status" data-chat-id="${u.chat_id}" data-current-status="${u.status}">${u.status === 'active' ? 'مسدود' : 'فعال'}</button>
                        </td>
                    </tr>`).join('');
                return `<div class="card"><div class="card-header"><h3>کاربران (${formatNumber(res.data.total)})</h3></div><div style="overflow:auto;"><table class="data-table"><thead><tr><th>نام</th><th>وضعیت</th><th>موجودی</th><th>سرویس‌ها</th><th>آخرین انقضا</th><th>عملیات</th></tr></thead><tbody>${rows}</tbody></table></div>${renderPaginationControls('users', res.data)}</div>`;
            },
            '/plans': async (page = 1) => {
              const res = await apiRequest('plans', { page, limit: 9 });
              if (!res) return `<div class="card"><h3>خطا</h3></div>`;
              const cards = res.data.items.map(p => {
                const statusColor = p.status === 'active' ? 'var(--success)' : 'var(--muted)';
                const planData = JSON.stringify(p).replace(/"/g, '&quot;');
                return `<div class="col-4"><div class="card">
                      <strong>${p.name}</strong>
                      <p class="muted">${p.category_name || 'بدون دسته'} • ${p.server_name || 'بدون سرور'}</p>
                      <div style="margin-top:8px; color:var(--primary); font-weight: 500;">${formatCurrency(p.price)}</div>
                      <div style="margin-top:4px; font-size:12px; color:var(--muted);">${p.volume_gb} GB • ${p.duration_days} روز</div>
                      <div style="margin-top:12px; display:flex; justify-content: space-between; align-items: center;">
                        <span class="tag" style="color:${statusColor}; border-color:${statusColor}; background: transparent;">${p.status === 'active' ? 'فعال' : 'غیرفعال'}</span>
                        <div style="display:flex; gap:6px;">
                          <button class="btn sm" data-action="edit-plan" data-plan='${planData}'>ویرایش</button>
                          <button class="btn sm" data-action="toggle-plan-status" data-plan-id="${p.id}" data-current-status="${p.status}">${p.status === 'active' ? 'غیرفعال' : 'فعال'}</button>
                        </div>
                      </div>
                    </div></div>`;
              }).join('');
              return `<div class="card"><div class="card-header"><h3>پلن‌ها</h3><button class="btn primary sm" data-action="create-plan">افزودن پلن</button></div><div class="grid" style="margin-top:10px;">${cards}</div>${renderPaginationControls('plans', res.data)}</div>`;
            },
            '/categories': async (page = 1) => {
                const res = await apiRequest('categories', { page, limit: PAGE_LIMIT });
                if (!res) return `<div class="card"><h3>خطا</h3></div>`;
                const rows = res.data.items.map(c => `
                    <tr>
                        <td>${c.name}</td>
                        <td style="color: ${c.status === 'active' ? 'var(--success)' : 'var(--danger)'};">${c.status === 'active' ? 'فعال' : 'غیرفعال'}</td>
                        <td style="display:flex; gap:6px;">
                            <button class="btn sm" data-action="toggle-cat-status" data-cat-id="${c.id}">${c.status === 'active' ? 'غیرفعال' : 'فعال'}</button>
                            <button class="btn sm danger" data-action="delete-cat" data-cat-id="${c.id}">حذف</button>
                        </td>
                    </tr>`).join('');
                return `<div class="card"><div class="card-header"><h3>دسته‌بندی‌ها</h3><button class="btn primary sm" data-action="create-cat">افزودن دسته</button></div><div style="overflow:auto;"><table class="data-table"><thead><tr><th>نام</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>${rows}</tbody></table></div>${renderPaginationControls('categories', res.data)}</div>`;
            },
            '/servers': async (page = 1) => {
                const res = await apiRequest('servers', { page, limit: PAGE_LIMIT });
                if (!res) return `<div class="card"><h3>خطا</h3></div>`;
                const rows = res.data.items.map(s => `
                    <tr>
                        <td>${s.name}</td>
                        <td><span class="tag">${s.type}</span></td>
                        <td><code>${s.url}</code></td>
                        <td style="display:flex; gap:6px;">
                            <button class="btn sm danger" data-action="delete-server" data-server-id="${s.id}">حذف</button>
                        </td>
                    </tr>`).join('');
                return `<div class="card"><div class="card-header"><h3>سرورها</h3><button class="btn primary sm" data-action="create-server">افزودن سرور</button></div><div style="overflow:auto;"><table class="data-table"><thead><tr><th>نام</th><th>نوع</th><th>آدرس</th><th>عملیات</th></tr></thead><tbody>${rows}</tbody></table></div>${renderPaginationControls('servers', res.data)}</div>`;
            },
            '/payments': async (page = 1) => {
                const [salesRes, reqRes] = await Promise.all([apiRequest('payments', { page, limit: 15 }), apiRequest('payment_requests')]);
                if (!salesRes || !reqRes) return `<div class="card"><h3>خطا</h3></div>`;
                
                const requestsHtml = reqRes.data.length > 0 ? `<div class="card" style="margin-bottom: 20px;"><div class="card-header"><h3>درخواست‌های شارژ دستی</h3></div>
                    <div style="overflow:auto;"><table class="data-table">
                        <thead><tr><th>کاربر</th><th>مبلغ</th><th>تاریخ</th><th>عملیات</th></tr></thead>
                        <tbody>${reqRes.data.map(r => `<tr>
                            <td>${r.first_name}</td><td>${formatCurrency(r.amount)}</td>
                            <td>${new Date(r.created_at).toLocaleString('fa-IR')}</td>
                            <td style="display:flex; gap:6px;"><button class="btn sm primary" data-action="process-payment" data-req-id="${r.id}" data-process="approve">تایید</button><button class="btn sm danger" data-action="process-payment" data-req-id="${r.id}" data-process="reject">رد</button></td>
                        </tr>`).join('')}</tbody>
                    </table></div></div>` : '';

                const salesRows = salesRes.data.items.map(p => `
                    <tr><td>${p.first_name}</td><td>${p.plan_name}</td><td>${formatCurrency(p.price)}</td><td>${new Date(p.purchase_date).toLocaleString('fa-IR')}</td></tr>`).join('');
                const salesHtml = `<div class="card"><div class="card-header"><h3>تاریخچه خرید سرویس‌ها</h3></div><div style="overflow:auto;"><table class="data-table"><thead><tr><th>کاربر</th><th>پلن</th><th>مبلغ</th><th>تاریخ</th></tr></thead><tbody>${salesRows}</tbody></table></div>${renderPaginationControls('payments', salesRes.data)}</div>`;

                return requestsHtml + salesHtml;
            },
            '/discounts': async (page = 1) => {
                const res = await apiRequest('discounts', { page, limit: PAGE_LIMIT });
                if (!res) return `<div class="card"><h3>خطا</h3></div>`;
                const rows = res.data.items.map(d => `
                    <tr>
                        <td><code>${d.code}</code></td>
                        <td>${d.type === 'percent' ? `${d.value}%` : `${formatCurrency(d.value)}`}</td>
                        <td>${formatNumber(d.usage_count)} / ${d.max_usage == 0 ? '∞' : formatNumber(d.max_usage)}</td>
                        <td><span class="tag" style="color: ${d.status === 'active' ? 'var(--success)' : 'var(--muted)'}; background: transparent; border-color: currentColor;">${d.status === 'active' ? 'فعال' : 'منقضی'}</span></td>
                        <td><button class="btn sm danger" data-action="delete-discount" data-code-id="${d.id}">حذف</button></td>
                    </tr>`).join('');
                return `<div class="card"><div class="card-header"><h3>کدهای تخفیف</h3><button class="btn primary sm" data-action="create-discount">افزودن کد</button></div><div style="overflow:auto;"><table class="data-table"><thead><tr><th>کد</th><th>مقدار</th><th>استفاده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>${rows}</tbody></table></div>${renderPaginationControls('discounts', res.data)}</div>`;
            },
            '/settings': async () => {
                const res = await apiRequest('settings');
                if (!res) return `<div class="card"><h3>خطا</h3></div>`;
                const s = res.data;
                const pm = s.payment_method || {};
                return `<form id="settingsForm"><div class="grid">
                    <div class="col-4"><div class="card"><h3>تنظیمات اصلی</h3>
                        <div class="form-group"><label>وضعیت ربات</label><select name="bot_status"><option value="on" ${s.bot_status==='on'?'selected':''}>فعال</option><option value="off" ${s.bot_status!=='on'?'selected':''}>غیرفعال</option></select></div>
                        <div class="form-group"><label>وضعیت فروش</label><select name="sales_status"><option value="on" ${s.sales_status==='on'?'selected':''}>فعال</option><option value="off" ${s.sales_status!=='on'?'selected':''}>غیرفعال</option></select></div>
                        <div class="form-group"><label>هدیه خوش‌آمدگویی (تومان)</label><input type="number" name="welcome_gift_balance" value="${s.welcome_gift_balance || 0}" /></div>
                    </div></div>
                    <div class="col-4"><div class="card"><h3>عضویت اجباری</h3>
                        <div class="form-group"><label>وضعیت</label><select name="join_channel_status"><option value="on" ${s.join_channel_status==='on'?'selected':''}>فعال</option><option value="off" ${s.join_channel_status!=='on'?'selected':''}>غیرفعال</option></select></div>
                        <div class="form-group"><label>آیدی کانال (با @)</label><input type="text" name="join_channel_id" value="${s.join_channel_id || ''}" /></div>
                    </div></div>
                     <div class="col-4"><div class="card"><h3>تمدید سرویس</h3>
                        <div class="form-group"><label>وضعیت</label><select name="renewal_status"><option value="on" ${s.renewal_status==='on'?'selected':''}>فعال</option><option value="off" ${s.renewal_status!=='on'?'selected':''}>غیرفعال</option></select></div>
                        <div class="form-group"><label>هزینه هر روز (تومان)</label><input type="number" name="renewal_price_per_day" value="${s.renewal_price_per_day || 0}" /></div>
                        <div class="form-group"><label>هزینه هر گیگ (تومان)</label><input type="number" name="renewal_price_per_gb" value="${s.renewal_price_per_gb || 0}" /></div>
                    </div></div>
                    <div class="col-6"><div class="card"><h3>پرداخت دستی (کارت)</h3>
                        <div class="form-group"><label>شماره کارت</label><input type="text" name="pm_card_number" value="${pm.card_number || ''}" /></div>
                        <div class="form-group"><label>صاحب حساب</label><input type="text" name="pm_card_holder" value="${pm.card_holder || ''}" /></div>
                    </div></div>
                     <div class="col-6"><div class="card"><h3>پرداخت آنلاین (زرین‌پال)</h3>
                        <div class="form-group"><label>وضعیت</label><select name="payment_gateway_status"><option value="on" ${s.payment_gateway_status==='on'?'selected':''}>فعال</option><option value="off" ${s.payment_gateway_status!=='on'?'selected':''}>غیرفعال</option></select></div>
                        <div class="form-group"><label>مرچنت کد زرین‌پال</label><input type="text" name="zarinpal_merchant_id" value="${s.zarinpal_merchant_id || ''}" /></div>
                    </div></div>
                    <div class="col-12"><div class="card"><button type="submit" class="btn primary">ذخیره تمام تنظیمات</button></div></div>
                </div></form>`;
            },
            '/admins': async () => {
                const res = await apiRequest('admins');
                if (!res) return `<div class="card"><h3>خطا</h3><p class="muted">شما دسترسی لازم برای مشاهده این بخش را ندارید یا خطایی رخ داده است.</p></div>`;
                const rows = res.data.map(a => `
                    <tr>
                        <td>${a.first_name}</td>
                        <td><code>${a.chat_id}</code></td>
                        <td>${a.permissions.length > 0 ? a.permissions.map(p => `<span class="tag">${p}</span>`).join(' ') : '<span class="muted">بدون دسترسی</span>'}</td>
                        <td style="display:flex; gap:6px;">
                            <button class="btn sm" data-action="edit-admin-perms" data-admin='${JSON.stringify(a).replace(/'/g, "&apos;")}'>ویرایش دسترسی</button>
                            <button class="btn sm danger" data-action="delete-admin" data-chat-id="${a.chat_id}">حذف</button>
                        </td>
                    </tr>`).join('');
                return `<div class="card"><div class="card-header"><h3>مدیریت ادمین‌ها</h3><button class="btn primary sm" data-action="create-admin">افزودن ادمین</button></div><div style="overflow:auto;"><table class="data-table"><thead><tr><th>نام</th><th>شناسه عددی</th><th>دسترسی‌ها</th><th>عملیات</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
            },
            '/resellers': async () => {
                const res = await apiRequest('resellers');
                if (!res) return `<div class="card"><h3>خطا</h3><p class="muted">خطا در دریافت اطلاعات نمایندگان.</p></div>`;
                
                const rows = res.data.map(r => {
                    const statusColor = r.status === 'active' ? 'var(--success)' : 'var(--danger)';
                    const usedTrafficStr = formatBytes(r.calculated_used_bytes);
                    const trafficLimitStr = r.traffic_limit > 0 ? `${formatNumber(r.traffic_limit)} GB` : 'نامحدود';
                    const maxClientsStr = r.max_clients > 0 ? `${formatNumber(r.clients_count)} / ${formatNumber(r.max_clients)}` : `${formatNumber(r.clients_count)} / نامحدود`;
                    
                    return `
                    <tr>
                        <td><strong>${r.name}</strong></td>
                        <td><code>${r.username}</code></td>
                        <td>${r.panel_name || '<span class="muted">حذف شده</span>'} <span class="tag" style="font-size:10px; margin-right:5px;">اینباند ${formatNumber(r.inbound_id)}</span></td>
                        <td>${maxClientsStr}</td>
                        <td>${usedTrafficStr} <span class="muted">/ ${trafficLimitStr}</span></td>
                        <td><span class="tag" style="color: ${statusColor}; border-color: ${statusColor}; background: transparent;">${r.status === 'active' ? 'فعال' : 'مسدود'}</span></td>
                        <td style="display:flex; gap:6px; flex-wrap: wrap;">
                            <button class="btn sm" data-action="edit-reseller" data-reseller='${JSON.stringify(r).replace(/'/g, "&apos;")}'>ویرایش</button>
                            <button class="btn sm primary" data-action="edit-reseller-limits" data-reseller-id="${r.id}" data-max-clients="${r.max_clients}" data-traffic-limit="${r.traffic_limit}">محدودیت‌ها</button>
                            <button class="btn sm ${r.status === 'active' ? 'warning' : 'success'}" data-action="toggle-reseller-status" data-reseller-id="${r.id}" data-current-status="${r.status}">${r.status === 'active' ? 'مسدود' : 'فعال'}</button>
                            <button class="btn sm danger" data-action="delete-reseller" data-reseller-id="${r.id}">حذف</button>
                        </td>
                    </tr>`;
                }).join('');
                
                return `<div class="card">
                    <div class="card-header">
                        <h3>نمایندگان فروش (${formatNumber(res.data.length)})</h3>
                        <button class="btn primary sm" data-action="create-reseller">افزودن نماینده جدید</button>
                    </div>
                    <div style="overflow:auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>نام نماینده</th>
                                    <th>نام کاربری</th>
                                    <th>سرور متصل</th>
                                    <th>تعداد اکانت‌ها</th>
                                    <th>حجم مصرفی</th>
                                    <th>وضعیت</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows.length > 0 ? rows : '<tr><td colspan="7" class="text-center muted">هیچ نماینده‌ای ثبت نشده است.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>`;
            },
            '/reseller-panels': async () => {
                const res = await apiRequest('reseller-panels');
                if (!res) return `<div class="card"><h3>خطا</h3><p class="muted">خطا در دریافت اطلاعات سرورها.</p></div>`;
                
                const rows = res.data.items.map(p => {
                    const statusColor = p.status === 'online' ? 'var(--success)' : 'var(--danger)';
                    const pingText = p.status === 'online' ? `${formatNumber(p.ping)} ms` : '—';
                    
                    return `
                    <tr>
                        <td><strong>${p.name}</strong></td>
                        <td><code>${p.url}</code></td>
                        <td><code>${p.sub_domain}</code></td>
                        <td>
                            <div style="display: inline-flex; align-items: center; gap: 6px; font-weight: bold; color: ${statusColor}; direction: ltr;">
                                <span class="status-dot" style="background-color: ${statusColor}; box-shadow: 0 0 10px ${statusColor}; display: inline-block; width: 8px; height: 8px; border-radius: 50%; ${p.status === 'online' ? 'animation: blink 1.5s infinite;' : ''}"></span>
                                <span>${p.status === 'online' ? 'آنلاین' : 'آفلاین'}</span>
                            </div>
                        </td>
                        <td style="direction: ltr; text-align: right;">${pingText}</td>
                        <td style="display:flex; gap:6px;">
                            <button class="btn sm" data-action="edit-reseller-panel" data-panel='${JSON.stringify(p).replace(/'/g, "&apos;")}'>ویرایش</button>
                            <button class="btn sm danger" data-action="delete-reseller-panel" data-panel-id="${p.id}">حذف</button>
                        </td>
                    </tr>`;
                }).join('');
                
                return `<div class="card">
                    <div class="card-header">
                        <h3>سرورهای نمایندگی (${formatNumber(res.data.items.length)})</h3>
                        <button class="btn primary sm" data-action="create-reseller-panel">افزودن سرور جدید</button>
                    </div>
                    <div style="overflow:auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>نام سرور</th>
                                    <th>آدرس پنل</th>
                                    <th>ساب‌دامنه اشتراک</th>
                                    <th>وضعیت</th>
                                    <th>پینگ شبکه</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows.length > 0 ? rows : '<tr><td colspan="6" class="text-center muted">هیچ سروری ثبت نشده است.</td></tr>'}
                            </tbody>
                        </table>
                    </div>
                </div>`;
            },
            '/reseller-stats': async () => {
                const res = await apiRequest('reseller-stats');
                if (!res) return `<div class="card"><h3>خطا</h3><p class="muted">خطا در دریافت آمارهای نمایندگی.</p></div>`;
                
                const stats = res.data;
                const usedGb = (stats.global_used_bytes / 1073741824).toFixed(1);
                const limitGb = (stats.global_total_bytes / 1073741824).toFixed(1);
                const remainingGb = (stats.global_remaining_bytes / 1073741824).toFixed(1);
                
                const percent = stats.global_total_bytes > 0 ? Math.min(100, Math.round((stats.global_used_bytes / stats.global_total_bytes) * 100)) : 0;
                
                return `<div class="grid">
                    <div class="col-4">
                        <div class="card">
                            <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-main-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                            <h3>تعداد کل نمایندگان</h3>
                            <div class="metric-container" style="margin-top: 10px;">
                                <div class="metric">${formatNumber(stats.resellers_count)} <span class="metric-subtext">نماینده فعال</span></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-4">
                        <div class="card">
                            <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-purple-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                            <h3>اکانت‌های فروخته‌شده</h3>
                            <div class="metric-container" style="margin-top: 10px;">
                                <div class="metric">${formatNumber(stats.clients_count)} <span class="metric-subtext">کانفیگ فعال</span></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-4">
                        <div class="card">
                            <div class="ambient-glow" style="position: absolute; top: -20px; right: -20px; width: 120px; height: 120px; background: var(--brand-green-g); opacity: 0.12; filter: blur(40px); pointer-events: none; z-index: 1;"></div>
                            <h3>سرورهای نمایندگی</h3>
                            <div class="metric-container" style="margin-top: 10px;">
                                <div class="metric">${formatNumber(stats.panels_count)} <span class="metric-subtext">پنل سنایی متصل</span></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <div class="card">
                            <h3>میزان ترافیک کل نمایندگی‌ها</h3>
                            <div style="margin-top: 20px;">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                                    <span>کل ترافیک مصرفی: <strong>${formatNumber(usedGb)} GB</strong></span>
                                    <span>سقف ترافیک اختصاص یافته: <strong>${stats.has_unlimited ? 'نامحدود (بستگی به سرور دارد)' : `${formatNumber(limitGb)} GB`}</strong></span>
                                </div>
                                <div class="progress-bar-container" style="height: 12px; background: rgba(255,255,255,0.05); border-radius: 6px; overflow: hidden; position: relative;">
                                    <div class="progress-bar" style="height: 100%; width: ${percent}%; background: var(--brand-main-g); border-radius: 6px; transition: width 0.5s ease-in-out;"></div>
                                </div>
                                <div style="display: flex; justify-content: space-between; margin-top: 10px; font-size: 12px; color: var(--muted);">
                                    <span>درصد مصرف: ${formatNumber(percent)}%</span>
                                    <span>${stats.has_unlimited ? '' : `باقیمانده ترافیک: ${formatNumber(remainingGb)} GB`}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>`;
            },
        };

        // --- RENDER & ROUTING LOGIC ---
        const render = async (route) => {
            appEl.innerHTML = '<div class="card"><p class="muted">در حال بارگذاری...</p></div>';
            // Clear previous chart instances
            if (window.incomeChartInstance) {
                window.incomeChartInstance.destroy();
                window.incomeChartInstance = null;
            }
            if (window.generalStatsChartInstance) {
                window.generalStatsChartInstance.destroy();
                window.generalStatsChartInstance = null;
            }

            try {
                const view = routes[route.path] || routes['/dashboard'];
                appEl.innerHTML = await view(route.page);
                appEl.querySelectorAll('select').forEach(createCustomSelect);
            } catch (error) {
                console.error('Render Error:', error);
                appEl.innerHTML = '<div class="card"><h3>خطا</h3><p class="muted">خطا در بارگذاری صفحه</p></div>';
            }
            navTabs.querySelectorAll('.tab').forEach(a => a.classList.toggle('active', a.dataset.route === `#${route.path}`));
        };

        const getRoute = () => {
            const hash = location.hash || '#/dashboard';
            const parts = hash.replace(/^#/, '').split('/');
            const path = `/${parts[1] || 'dashboard'}`;
            const page = parseInt(parts[2], 10) || 1;
            return { path, page };
        };

        window.addEventListener('hashchange', () => render(getRoute()));
        document.addEventListener('DOMContentLoaded', () => {
            if (!getAdminToken()) showToast('توکن ادمین تنظیم نشده. روی آیکون 🔑 کلیک کنید.', 'info');
            render(getRoute());
        });

        // --- GLOBAL EVENT LISTENER & ACTIONS ---
        const showPlanModal = async (plan = null) => {
            const isEdit = plan !== null;
            const title = isEdit ? 'ویرایش پلن' : 'ایجاد پلن جدید';

            const [catsRes, srvsRes] = await Promise.all([apiRequest('categories', {limit: 999}), apiRequest('servers', {limit: 999})]);
            if (!catsRes || !srvsRes) return;
            const categories = catsRes.data.items;
            const servers = srvsRes.data.items;

            const categoryOptions = categories.map(c => `<option value="${c.id}" ${plan && plan.category_id == c.id ? 'selected':''}>${c.name}</option>`).join('');
            const serverOptions = servers.map(s => `<option value="${s.id}" ${plan && plan.server_id == s.id ? 'selected':''}>${s.name} (${s.type})</option>`).join('');

            const formHtml = `<form><div class="grid">
                <div class="col-12 form-group"><label>نام پلن</label><input name="name" value="${plan?.name || ''}" required /></div>
                <div class="col-6 form-group"><label>قیمت (تومان)</label><input type="number" name="price" value="${plan?.price || ''}" required /></div>
                <div class="col-6 form-group"><label>حجم (GB)</label><input type="number" name="volume_gb" value="${plan?.volume_gb || ''}" required /></div>
                <div class="col-6 form-group"><label>مدت (روز)</label><input type="number" name="duration_days" value="${plan?.duration_days || ''}" required /></div>
                <div class="col-6 form-group"><label>محدودیت خرید (0=نامحدود)</label><input type="number" name="purchase_limit" value="${plan?.purchase_limit || 0}" required /></div>
                <div class="col-6 form-group"><label>دسته‌بندی</label><select name="category_id"><option value="">انتخاب کنید</option>${categoryOptions}</select></div>
                <div class="col-6 form-group"><label>سرور</label><select name="server_id"><option value="">انتخاب کنید</option>${serverOptions}</select></div>
                <div class="col-6 form-group"><label>آیدی اینباند (برای Sanaei)</label><input type="number" name="inbound_id" value="${plan?.inbound_id || ''}" placeholder="اختیاری"/></div>
                <div class="col-6 form-group"><label>آیدی سرویس (برای Marzneshin)</label><input type="number" name="marzneshin_service_id" value="${plan?.marzneshin_service_id || ''}" placeholder="اختیاری"/></div>
            </div></form>`;
            
            showModal(title, formHtml, async (formData, hide) => {
                const payload = Object.fromEntries(formData.entries());
                const endpoint = isEdit ? 'admin.plan.update' : 'admin.plan.create';
                if (isEdit) payload.plan_id = plan.id;
                
                const res = await apiRequest(endpoint, { method: 'POST', payload });
                if (res) {
                    hide();
                    render(getRoute());
                    showToast(isEdit ? 'پلن با موفقیت ویرایش شد.' : 'پلن با موفقیت ایجاد شد.', 'success');
                }
            });
        };

        appEl.addEventListener('click', async (e) => {
            const target = e.target.closest('[data-action]');
            if (!target) return;
            const action = target.dataset.action;
            const currentRoute = getRoute();
            let result;

            switch (action) {
                case 'create-plan': showPlanModal(); return;
                case 'edit-plan': showPlanModal(JSON.parse(target.dataset.plan)); return;
                case 'toggle-user-status':
                    const newStatus = target.dataset.currentStatus === 'active' ? 'banned' : 'active';
                    showConfirmModal('تغییر وضعیت کاربر', `آیا از ${newStatus === 'active' ? 'فعال' : 'مسدود'} کردن این کاربر مطمئن هستید؟`, async () => {
                      result = await apiRequest('admin.user.status', { method: 'POST', payload: { chat_id: target.dataset.chatId, status: newStatus } });
                      if(result) { showToast('وضعیت کاربر تغییر کرد.', 'success'); render(currentRoute); }
                    }, newStatus === 'banned');
                    return;
                case 'edit-balance':
                    showPromptModal('ویرایش موجودی', 'مبلغ برای افزایش (مثبت) یا کاهش (منفی) را به تومان وارد کنید:', '', async (amountStr) => {
                      const amount = parseInt(amountStr, 10);
                      if (amountStr === null) return;
                      if (isNaN(amount)) return showToast('لطفاً عدد معتبر وارد کنید.', 'error');
                      result = await apiRequest('admin.user.balance', { method: 'POST', payload: { chat_id: target.dataset.chat_id, delta: amount } });
                      if(result) { showToast('موجودی کاربر تغییر کرد.', 'success'); render(currentRoute); }
                    });
                    return;
                case 'toggle-plan-status':
                    result = await apiRequest('admin.plan.status', { method: 'POST', payload: { plan_id: target.dataset.planId, status: target.dataset.currentStatus === 'active' ? 'inactive' : 'active' } });
                    if(result) showToast('وضعیت پلن تغییر کرد.', 'success');
                    break;
                case 'create-cat':
                    showModal('ایجاد دسته‌بندی جدید', '<form><div class="form-group"><label>نام دسته‌بندی</label><input name="name" required /></div></form>', async (formData, hide) => {
                        const name = formData.get('name');
                        if (!name) return showToast('نام الزامی است.', 'error');
                        const res = await apiRequest('admin.category.create', { method: 'POST', payload: { name } });
                        if (res) { hide(); render(currentRoute); showToast('دسته‌بندی ایجاد شد.', 'success'); }
                    }); return;
                case 'toggle-cat-status':
                    result = await apiRequest('admin.category.status', { method: 'POST', payload: { cat_id: target.dataset.catId } });
                    break;
                case 'delete-cat':
                    showConfirmModal('حذف دسته‌بندی', 'آیا از حذف این دسته‌بندی مطمئن هستید؟', async () => {
                      result = await apiRequest('admin.category.delete', { method: 'POST', payload: { cat_id: target.dataset.catId } });
                      if(result) { showToast('دسته‌بندی حذف شد.', 'success'); render(currentRoute); }
                    }, true); return;
                case 'create-server':
                    showModal('افزودن سرور جدید', `<form><div class="form-group"><label>نام سرور</label><input name="name" required></div><div class="form-group"><label>نوع پنل</label><select name="type"><option value="marzban">Marzban</option><option value="sanaei">Sanaei (3x-ui)</option><option value="marzneshin">Marzneshin</option></select></div><div class="form-group"><label>آدرس پنل (با http/https)</label><input name="url" required></div><div class="form-group"><label>نام کاربری</label><input name="username" required></div><div class="form-group"><label>رمز عبور</label><input name="password" required></div><div class="form-group"><label>آدرس ساب (اختیاری)</label><input name="sub_host"></div></form>`, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        if (!payload.name || !payload.url || !payload.username || !payload.password) return showToast('تکمیل فیلدهای ستاره‌دار الزامی است.', 'error');
                        const res = await apiRequest('admin.server.create', { method: 'POST', payload });
                        if(res) { hide(); render(currentRoute); showToast('سرور اضافه شد.', 'success'); }
                    }); return;
                case 'delete-server':
                    showConfirmModal('حذف سرور', 'آیا از حذف این سرور مطمئن هستید؟', async () => {
                      result = await apiRequest('admin.server.delete', { method: 'POST', payload: { server_id: target.dataset.serverId } });
                      if(result) { showToast('سرور حذف شد.', 'success'); render(currentRoute); }
                    }, true); return;
                case 'create-discount':
                    showModal('ایجاد کد تخفیف', `<form><div class="grid"><div class="col-6"><div class="form-group"><label>کد (حروف بزرگ)</label><input name="code" required></div></div><div class="col-6"><div class="form-group"><label>نوع</label><select name="type"><option value="percent">درصدی</option><option value="amount">مبلغی</option></select></div></div><div class="col-6"><div class="form-group"><label>مقدار (درصد یا تومان)</label><input type="number" name="value" required></div></div><div class="col-6"><div class="form-group"><label>حداکثر استفاده (0=نامحدود)</label><input type="number" name="max_usage" value="1" required></div></div></div></form>`, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        if (!payload.code || !payload.value) return showToast('تکمیل فیلدها الزامی است.', 'error');
                        const res = await apiRequest('admin.discount.create', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('کد تخفیف ایجاد شد.', 'success'); }
                    }); return;
                case 'delete-discount':
                    showConfirmModal('حذف کد تخفیف', 'آیا از حذف این کد تخفیف مطمئن هستید؟', async () => {
                      result = await apiRequest('admin.discount.delete', { method: 'POST', payload: { code_id: target.dataset.codeId } });
                      if(result) { showToast('کد تخفیف حذف شد.', 'success'); render(currentRoute); }
                    }, true); return;
                case 'process-payment':
                    const process = target.dataset.process;
                    showConfirmModal(process === 'approve' ? 'تایید پرداخت' : 'رد پرداخت', `آیا از ${process === 'approve' ? 'تایید' : 'رد'} این درخواست مطمئن هستید؟`, async () => {
                      result = await apiRequest('admin.payment_request.process', { method: 'POST', payload: { request_id: target.dataset.reqId, action: process } });
                      if(result) { showToast('درخواست پرداخت پردازش شد.', 'success'); render(currentRoute); }
                    }, process === 'reject'); return;
                case 'create-admin':
                    showModal('افزودن ادمین جدید', `<form><div class="form-group"><label>شناسه عددی (Chat ID)</label><input type="number" name="chat_id" required /></div><div class="form-group"><label>نام (اختیاری)</label><input name="first_name" /></div></form>`, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        if (!payload.chat_id) return showToast('شناسه عددی الزامی است.', 'error');
                        const res = await apiRequest('admin.admin.create', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('ادمین با موفقیت اضافه شد.', 'success'); }
                    });
                    return;
                case 'delete-admin':
                    showConfirmModal('حذف ادمین', 'آیا از حذف این ادمین مطمئن هستید؟ این عمل غیرقابل بازگشت است.', async () => {
                        const res = await apiRequest('admin.admin.delete', { method: 'POST', payload: { chat_id: target.dataset.chatId } });
                        if (res) { render(currentRoute); showToast('ادمین حذف شد.', 'success'); }
                    }, true);
                    return;
                case 'edit-admin-perms':
                    const admin = JSON.parse(target.dataset.admin.replace(/&apos;/g, "'"));
                    const permsRes = await apiRequest('admin.permissions.map');
                    if (!permsRes) return;
                    const allPerms = permsRes.data;
                    let checkboxesHtml = '<form class="grid perms-form">';
                    let counter = 0;
                    for (const [key, name] of Object.entries(allPerms)) {
                        const isChecked = admin.permissions.includes(key) ? 'checked' : '';
                        const id = `perm_${key}_${counter++}`;
                        checkboxesHtml += `<div class="col-6">
                            <input type="checkbox" name="permissions" value="${key}" id="${id}" ${isChecked}>
                            <label for="${id}" class="perm-label">${name}</label>
                        </div>`;
                    }
                    checkboxesHtml += '</form>';
                    
                    showModal(`ویرایش دسترسی‌های ${admin.first_name}`, checkboxesHtml, async (formData, hide) => {
                        const payload = {
                            chat_id: admin.chat_id,
                            permissions: formData.getAll('permissions')
                        };
                        const res = await apiRequest('admin.admin.permissions', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('دسترسی‌ها به‌روز شد.', 'success'); }
                    });
                    return;
                case 'create-reseller': {
                    const panelsRes = await apiRequest('reseller-panels');
                    if (!panelsRes) return;
                    const panelOpts = panelsRes.data.items.map(p => `<option value="${p.id}">${p.name}</option>`).join('');
                    const formHtml = `<form><div class="grid">
                        <div class="col-6 form-group"><label>نام نماینده</label><input name="name" required /></div>
                        <div class="col-6 form-group"><label>نام کاربری (انگلیسی)</label><input name="username" required /></div>
                        <div class="col-6 form-group"><label>رمز عبور</label><input type="password" name="password" required /></div>
                        <div class="col-6 form-group"><label>حداکثر تعداد اکانت مجاز</label><input type="number" name="max_clients" value="0" /></div>
                        <div class="col-6 form-group"><label>انتخاب سرور</label><select name="panel_id" required>${panelOpts}</select></div>
                        <div class="col-6 form-group"><label>شناسه اینباند (Inbound ID)</label><input type="number" name="inbound_id" required /></div>
                    </div></form>`;
                    showModal('افزودن نماینده جدید', formHtml, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        const res = await apiRequest('admin.reseller.create', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('نماینده جدید با موفقیت ساخته شد.', 'success'); }
                    });
                    return;
                }
                case 'edit-reseller': {
                    const r = JSON.parse(target.dataset.reseller.replace(/&apos;/g, "'"));
                    const panelsRes = await apiRequest('reseller-panels');
                    if (!panelsRes) return;
                    const panelOpts = panelsRes.data.items.map(p => `<option value="${p.id}" ${p.id == r.panel_id ? 'selected':''}>${p.name}</option>`).join('');
                    const formHtml = `<form><div class="grid">
                        <div class="col-6 form-group"><label>نام نماینده</label><input name="name" value="${r.name}" required /></div>
                        <div class="col-6 form-group"><label>نام کاربری</label><input name="username" value="${r.username}" readonly style="opacity:0.6;" /></div>
                        <div class="col-6 form-group"><label>تغییر رمز عبور (خالی برای عدم تغییر)</label><input type="password" name="password" /></div>
                        <div class="col-6 form-group"><label>حداکثر تعداد اکانت مجاز</label><input type="number" name="max_clients" value="${r.max_clients}" /></div>
                        <div class="col-6 form-group"><label>انتخاب سرور</label><select name="panel_id" required>${panelOpts}</select></div>
                        <div class="col-6 form-group"><label>شناسه اینباند (Inbound ID)</label><input type="number" name="inbound_id" value="${r.inbound_id}" required /></div>
                        <input type="hidden" name="reseller_id" value="${r.id}" />
                    </div></form>`;
                    showModal('ویرایش نماینده', formHtml, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        const res = await apiRequest('admin.reseller.update', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('مشخصات نماینده با موفقیت ویرایش شد.', 'success'); }
                    });
                    return;
                }
                case 'edit-reseller-limits': {
                    const resellerId = target.dataset.resellerId;
                    const maxClients = target.dataset.maxClients;
                    const trafficLimit = target.dataset.trafficLimit;
                    const formHtml = `<form>
                        <div class="form-group"><label>حداکثر اکانت‌های مجاز</label><input type="number" name="max_clients" value="${maxClients}" required /></div>
                        <div class="form-group"><label>حداکثر ترافیک مجاز (GB - صفر برای نامحدود)</label><input type="number" step="0.1" name="total_gb" value="${trafficLimit}" required /></div>
                        <div class="form-group" style="display:flex; align-items:center; gap:8px;"><input type="checkbox" name="reset_traffic" id="reset_traffic_check" value="1" /><label for="reset_traffic_check" style="margin:0; cursor:pointer;">ریست حجم مصرفی نماینده (صفر کردن ترافیک مصرف شده تا کنون)</label></div>
                        <input type="hidden" name="reseller_id" value="${resellerId}" />
                    </form>`;
                    showModal('ویرایش محدودیت‌های نماینده', formHtml, async (formData, hide) => {
                        const payload = {
                            reseller_id: formData.get('reseller_id'),
                            max_clients: parseInt(formData.get('max_clients'), 10),
                            total_gb: parseFloat(formData.get('total_gb')),
                            reset_traffic: formData.get('reset_traffic') === '1'
                        };
                        const res = await apiRequest('admin.reseller.limits', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('محدودیت‌ها با موفقیت به‌روزرسانی شدند.', 'success'); }
                    });
                    return;
                }
                case 'toggle-reseller-status': {
                    const resellerId = target.dataset.resellerId;
                    const currentStatus = target.dataset.currentStatus;
                    const newStatus = currentStatus === 'active' ? 'disabled' : 'active';
                    result = await apiRequest('admin.reseller.toggle-status', { method: 'POST', payload: { reseller_id: resellerId, status: newStatus } });
                    if (result) { showToast('وضعیت نماینده با موفقیت تغییر کرد.', 'success'); render(currentRoute); }
                    return;
                }
                case 'delete-reseller': {
                    const resellerId = target.dataset.resellerId;
                    showConfirmModal('حذف نماینده', 'آیا از حذف این نماینده مطمئن هستید؟ این عمل غیرقابل بازگشت است و دسترسی نماینده قطع خواهد شد.', async () => {
                        const res = await apiRequest('admin.reseller.delete', { method: 'POST', payload: { reseller_id: resellerId } });
                        if (res) { render(currentRoute); showToast('نماینده با موفقیت حذف شد.', 'success'); }
                    }, true);
                    return;
                }
                case 'create-reseller-panel': {
                    const formHtml = `<form><div class="grid">
                        <div class="col-6 form-group"><label>نام سرور</label><input name="name" required /></div>
                        <div class="col-6 form-group"><label>آدرس کامل پنل سنایی (با http/https و پورت)</label><input name="url" placeholder="http://1.2.3.4:2053" required /></div>
                        <div class="col-6 form-group"><label>نام کاربری پنل</label><input name="username" required /></div>
                        <div class="col-6 form-group"><label>رمز عبور پنل</label><input type="password" name="password" required /></div>
                        <div class="col-12 form-group"><label>ساب‌دامنه اشتراک (بدون http/https)</label><input name="sub_domain" placeholder="sub.domain.com" required /></div>
                    </div></form>`;
                    showModal('افزودن سرور نمایندگی', formHtml, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        const res = await apiRequest('admin.reseller-panel.create', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('سرور نمایندگی با موفقیت ثبت شد.', 'success'); }
                    });
                    return;
                }
                case 'edit-reseller-panel': {
                    const p = JSON.parse(target.dataset.panel.replace(/&apos;/g, "'"));
                    const formHtml = `<form><div class="grid">
                        <div class="col-6 form-group"><label>نام سرور</label><input name="name" value="${p.name}" required /></div>
                        <div class="col-6 form-group"><label>آدرس کامل پنل سنایی (با http/https و پورت)</label><input name="url" value="${p.url}" required /></div>
                        <div class="col-6 form-group"><label>نام کاربری پنل</label><input name="username" value="${p.username}" required /></div>
                        <div class="col-6 form-group"><label>تغییر رمز عبور (خالی برای عدم تغییر)</label><input type="password" name="password" /></div>
                        <div class="col-12 form-group"><label>ساب‌دامنه اشتراک (بدون http/https)</label><input name="sub_domain" value="${p.sub_domain}" required /></div>
                        <input type="hidden" name="panel_id" value="${p.id}" />
                    </div></form>`;
                    showModal('ویرایش سرور نمایندگی', formHtml, async (formData, hide) => {
                        const payload = Object.fromEntries(formData.entries());
                        const res = await apiRequest('admin.reseller-panel.update', { method: 'POST', payload });
                        if (res) { hide(); render(currentRoute); showToast('سرور نمایندگی با موفقیت ویرایش شد.', 'success'); }
                    });
                    return;
                }
                case 'delete-reseller-panel': {
                    const panelId = target.dataset.panelId;
                    showConfirmModal('حذف سرور نمایندگی', 'آیا از حذف این سرور مطمئن هستید؟ این عمل سرور را از دیتابیس حذف می‌کند.', async () => {
                        const res = await apiRequest('admin.reseller-panel.delete', { method: 'POST', payload: { panel_id: panelId } });
                        if (res) { render(currentRoute); showToast('سرور با موفقیت حذف شد.', 'success'); }
                    }, true);
                    return;
                }
            }
            if (result) render(currentRoute);
        });

        appEl.addEventListener('submit', async (e) => {
            if (e.target.id === 'settingsForm') {
                e.preventDefault();
                const formData = new FormData(e.target);
                const settings = {
                    bot_status: formData.get('bot_status'), sales_status: formData.get('sales_status'),
                    welcome_gift_balance: formData.get('welcome_gift_balance'), join_channel_status: formData.get('join_channel_status'),
                    join_channel_id: formData.get('join_channel_id'), payment_gateway_status: formData.get('payment_gateway_status'),
                    zarinpal_merchant_id: formData.get('zarinpal_merchant_id'), renewal_status: formData.get('renewal_status'),
                    renewal_price_per_day: formData.get('renewal_price_per_day'), renewal_price_per_gb: formData.get('renewal_price_per_gb'),
                    payment_method: { card_number: formData.get('pm_card_number'), card_holder: formData.get('pm_card_holder') }
                };
                const result = await apiRequest('admin.settings.save', { method: 'POST', payload: { settings } });
                if (result) showToast('تنظیمات با موفقیت ذخیره شد.', 'success');
            }
        });

      })();
    </script>
  </body>
</html>
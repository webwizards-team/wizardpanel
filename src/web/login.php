<?php
ini_set('session.cookie_lifetime', 86400 * 30);
ini_set('session.gc_maxlifetime', 86400 * 30);
session_start();

if (!file_exists('reseller_config.php')) {
    die('فایل پیکربندی یافت نشد. ابتدا فایل‌ها را کامل آپلود کنید.');
}

require_once 'reseller_config.php';

// بررسی وجود جداول دیتابیس؛ در غیر این صورت انتقال به صفحه نصب
try {
    $pdo->query("SELECT 1 FROM resellers LIMIT 1");
} catch (PDOException $e) {
    header('Location: ../install.php');
    exit;
}

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'superadmin') {
        header('Location: index.php');
    } else {
        header('Location: reseller.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    // 1. بررسی مدیر کل (Super Admin)
    if ($user === $superAdminUser && $pass === $superAdminPass) {
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['role'] = 'superadmin';
        header('Location: index.php');
        exit;
    }

    // 2. بررسی نمایندگان از جدول resellers
    $stmt = $pdo->prepare("SELECT r.*, p.url as panel_url, p.username as panel_user, p.password as panel_pass, p.sub_domain FROM resellers r INNER JOIN panels p ON r.panel_id = p.id WHERE r.username = ? LIMIT 1");
    $stmt->execute([$user]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($adminData && $adminData['password'] === $pass) {
        if ($adminData['status'] === 'disabled') {
            $error = 'دسترسی حساب شما توسط مدیر کل مسدود شده است.';
        } else {
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            $_SESSION['role'] = 'admin';
            $_SESSION['admin_user'] = $user;
            $_SESSION['admin_data'] = $adminData;
            header('Location: reseller.php');
            exit;
        }
    } else {
        $error = 'نام کاربری یا رمز عبور اشتباه است.';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به پنل نمایندگی و مدیریت</title>
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
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --border-color: rgba(255, 255, 255, 0.08);
            --brand-main: #6366f1;
            --brand-main-g: linear-gradient(135deg, #6366f1, #4f46e5);
            --brand-red-g: linear-gradient(135deg, #f43f5e, #e11d48);
            --glass-blur: backdrop-filter: blur(12px);
            --transition-smooth: all 0.25s ease-in-out;
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
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            padding: 15px;
            box-sizing: border-box;
            line-height: 1.6;
        }

        .login-box {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 45px 40px;
            border-radius: 20px;
            width: 100%;
            max-width: 400px;
            text-align: center;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            animation: boxFadeIn 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Ambient subtle glow */
        .login-box::after {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 140px;
            height: 140px;
            background: var(--brand-main-g);
            opacity: 0.15;
            filter: blur(48px);
            pointer-events: none;
            z-index: 1;
        }

        @keyframes boxFadeIn {
            from { opacity: 0; transform: scale(0.97) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        h2 {
            margin: 0 0 10px 0;
            font-weight: 800;
            font-size: 24px;
            color: #fff;
            position: relative;
            z-index: 2;
        }

        p.subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 30px;
            position: relative;
            z-index: 2;
            font-weight: 500;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: right;
            position: relative;
            z-index: 2;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 14px 18px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: rgba(0, 0, 0, 0.25);
            color: #fff;
            outline: none;
            box-sizing: border-box;
            transition: var(--transition-smooth);
            font-weight: 500;
            text-align: center;
        }

        input:focus {
            border-color: rgba(99, 102, 241, 0.5);
            background: rgba(0, 0, 0, 0.35);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }

        button {
            width: 100%;
            padding: 15px;
            background: var(--brand-main-g);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: bold;
            font-size: 15px;
            margin-top: 15px;
            transition: var(--transition-smooth);
            position: relative;
            z-index: 2;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.2);
        }

        button:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.35);
        }

        .error {
            color: #fb7185;
            margin-bottom: 25px;
            font-size: 13px;
            font-weight: 600;
            background: rgba(244, 63, 94, 0.08);
            border: 1px solid rgba(244, 63, 94, 0.15);
            padding: 12px;
            border-radius: 12px;
            position: relative;
            z-index: 2;
            text-align: right;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>ورود به پنل نمایندگی</h2>
        <p class="subtitle">سیستم مدیریت و فروش هوشمند اکانت</p>
        
        <?php if($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>نام کاربری</label>
                <input type="text" name="username" placeholder="نام کاربری خود را وارد کنید" required autocomplete="off" dir="ltr">
            </div>
            <div class="form-group">
                <label>رمز عبور</label>
                <input type="password" name="password" placeholder="رمز عبور خود را وارد کنید" required dir="ltr">
            </div>
            <button type="submit">ورود امن به پنل</button>
        </form>
    </div>
</body>
</html>

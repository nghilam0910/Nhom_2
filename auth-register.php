<?php
/**
 * User Registration Page
 */

session_start();
require_once 'db_connect.php';

// --- 1. BIẾN CHO HEADER ---
$isLoggedIn = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = 'auth-register.php';

if (!function_exists('nav_active')) {
    function nav_active($page, $current)
    {
        return $page === $current ? 'nav-active' : '';
    }
}

$error_message = '';
$success_message = '';

// --- 2. LOGIC HIỂN THỊ THÔNG BÁO ---
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// Chuyển hướng nếu đã đăng nhập
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin') {
        header('Location: admin-index.php');
    } else {
        header('Location: account-index.php');
    }
    exit;
}

function generateUserID($pdo)
{
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(UserID, 2) AS UNSIGNED)) as max_id FROM User_Account");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'U' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// Xử lý Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullname = trim($_POST['fullname'] ?? ''); // Mới
    $email = trim($_POST['email'] ?? '');    // Mới
    $phone = trim($_POST['phone'] ?? '');    // Mới
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation
    if (empty($username) || empty($fullname) || empty($email) || empty($phone) || empty($password)) {
        $error_message = 'Vui lòng điền đầy đủ tất cả các thông tin.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Định dạng email không hợp lệ.';
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $error_message = 'Số điện thoại phải có đúng 10 chữ số và không chứa ký tự lạ.';
    } elseif (strlen($username) < 6) {
        $error_message = 'Tên đăng nhập phải ít nhất 6 ký tự.';
    } elseif (strlen($password) < 6) {
        $error_message = 'Mật khẩu phải ít nhất 6 ký tự.';
    } elseif ($password !== $confirm_password) {
        $error_message = 'Mật khẩu xác nhận không khớp.';
    } else {
        // Kiểm tra Username hoặc Email đã tồn tại chưa
        $stmt = $pdo->prepare("SELECT UserID FROM User_Account WHERE Username = ? OR Email = ?");
        $stmt->execute([$username, $email]);

        if ($stmt->rowCount() > 0) {
            $error_message = 'Tên đăng nhập hoặc Email này đã được sử dụng.';
        } else {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $user_id = generateUserID($pdo);

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO User_Account 
                    (UserID, Username, FullName, Email, Phone, Password, Role, Status, CreatedDate, Points) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), 0)
                ");

                $stmt->execute([
                    $user_id,
                    $username,
                    $fullname,
                    $email,
                    $phone,
                    $hashed_password,
                    'Customer'
                ]);

                $_SESSION['flash_success'] = 'Đăng ký thành công! Vui lòng đăng nhập.';
                header('Location: auth-register.php');
                exit;

            } catch (Exception $e) {
                $error_message = 'Lỗi đăng ký: ' . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng Ký - Moonlit Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">
</head>

<body class="auth-body">
    <!-- ===================== HEADER ===================== -->
    <header class="account-header site-header">
        <div class="container header-inner">
            <div class="header-left">
                <a href="index.php" class="logo-link header-logo">
                    <img src="img/image.png?v=2" alt="Moonlit logo" class="logo-img">


                </a>

                <nav class="header-menu">
                    <a href="index.php" class="header-menu-link <?php echo nav_active('index.php', $currentPage); ?>">
                        Trang chủ
                    </a>
                    <a href="shop.php" class="header-menu-link <?php echo nav_active('shop.php', $currentPage); ?>">
                        Cửa hàng
                    </a>
                    <a href="forum.php" class="header-menu-link <?php echo nav_active('forum.php', $currentPage); ?>">
                        Moonlit Forum
                    </a>
                    <a href="aboutus.php"
                        class="header-menu-link <?php echo nav_active('aboutus.php', $currentPage); ?>">
                        Về chúng tôi
                    </a>
                   
                </nav>
            </div>

            <div class="header-right">
                <form method="GET" action="shop.php" class="header-search-form">
                    <input type="text" name="q" class="account-input header-search-input" placeholder="Tìm sách...">
                    <button type="submit" class="account-btn-save header-search-btn">Tìm</button>
                </form>

                <a href="cart.php" class="account-btn-secondary header-cart-btn">Giỏ hàng</a>

                <?php if ($isLoggedIn): ?>
                    <div class="header-account">
                        <div class="header-account-actions">
                            <a href="account-index.php" class="account-btn-secondary header-account-btn">Tài khoản</a>
                            <a href="logout.php" class="account-btn-secondary header-account-btn">Đăng xuất</a>
                        </div>

                        <span class="account-username">
                            Xin chào, <strong><?php echo htmlspecialchars($currentUsername); ?></strong>
                        </span>
                    </div>
                <?php else: ?>
                    <a href="auth-login.php" class="account-btn-secondary header-account-btn">Tài khoản</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="auth-main">
        <div class="auth-card">
            <h2 class="auth-title">Đăng Ký</h2>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger auth-alert"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success auth-alert">
                    <?php echo htmlspecialchars($success_message); ?><br>
                    <a href="auth-login.php" class="auth-link mt-2 d-inline-block">Về trang đăng nhập →</a>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="mb-3">
                    <label class="form-label auth-label">Họ và tên <span class="text-danger">*</span></label>
                    <input type="text" class="form-control auth-input" name="fullname"
                        value="<?php echo htmlspecialchars($_POST['fullname'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label auth-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control auth-input" name="email"
                        value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label auth-label">Số điện thoại <span class="text-danger">*</span></label>
                    <input type="text" class="form-control auth-input" name="phone"
                        value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label auth-label">Tên đăng nhập <span class="text-danger">*</span></label>
                    <input type="text" class="form-control auth-input" name="username"
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label auth-label">Mật khẩu <span class="text-danger">*</span></label>
                    <input type="password" class="form-control auth-input" name="password" required>
                </div>

                <div class="mb-3">
                    <label class="form-label auth-label">Xác nhận mật khẩu <span class="text-danger">*</span></label>
                    <input type="password" class="form-control auth-input" name="confirm_password" required>
                </div>

                <button type="submit" class="btn auth-btn-submit w-100">Đăng Ký</button>
            </form>

            <div class="auth-footer">
                <p>Bạn đã có tài khoản? <a href="auth-login.php" class="auth-link">Đăng nhập tại đây</a></p>
            </div>
        </div>
    </main>
<footer class="site-footer">
        © 2025 Moonlit — All rights reserved.
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>



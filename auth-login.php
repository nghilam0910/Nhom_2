<?php
/**
 * User Login Page
 * Handles user authentication and session creation
 */

session_start();
require_once 'db_connect.php';

// --- 1. BỔ SUNG CÁC BIẾN CẦN THIẾT CHO HEADER ---
$isLoggedIn = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = 'auth-login.php'; // Đặt tên trang hiện tại để Active menu nếu cần

// --- 2. BỔ SUNG HÀM HỖ TRỢ CHO HEADER ---
if (!function_exists('nav_active')) {
    function nav_active($page, $current)
    {
        return $page === $current ? 'nav-active' : '';
    }
}

$error_message = '';

// --- LOGIC 1: CHUYỂN HƯỚNG NẾU ĐÃ ĐĂNG NHẬP ---
// Nếu người dùng cố vào trang login mà đã đăng nhập rồi, 
// hệ thống sẽ tự kiểm tra quyền và đưa về trang tương ứng.
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'Customer'; // Mặc định là Customer nếu không tìm thấy
    if ($role === 'Admin') {
        header('Location: admin-dashboard.php');
    } else {
        header('Location: account-index.php');
    }
    exit;
}

// --- LOGIC 2: XỬ LÝ FORM ĐĂNG NHẬP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error_message = 'Vui lòng nhập tên đăng nhập và mật khẩu.';
    } else {
        // Lấy thêm cột Role từ database
        $stmt = $pdo->prepare("SELECT UserID, Username, Password, Role FROM User_Account WHERE Username = ?");
        $stmt->execute([$username]);

        if ($stmt->rowCount() === 0) {
            $error_message = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
        } else {
            $user = $stmt->fetch();

            if (password_verify($password, $user['Password'])) {
                // Đăng nhập thành công -> Lưu Session
                $_SESSION['user_id'] = $user['UserID'];
                $_SESSION['username'] = $user['Username'];
                $_SESSION['role'] = $user['Role']; // Lưu quyền hạn vào session

                // --- LOGIC 3: CHUYỂN HƯỚNG THEO ROLE ---
                if ($user['Role'] === 'Admin') {
                    header('Location: admin-dashboard.php');
                } else {
                    // Mặc định Customer hoặc các role khác
                    header('Location: account-index.php');
                }
                exit;
            } else {
                $error_message = 'Tên đăng nhập hoặc mật khẩu không chính xác.';
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
    <title>Đăng Nhập - Moonlit Store</title>
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
            <h2 class="auth-title">Đăng Nhập</h2>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger auth-alert" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="mb-3">
                    <label for="username" class="form-label auth-label">Tên đăng nhập</label>
                    <input type="text" class="form-control auth-input" id="username" name="username"
                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label auth-label">Mật khẩu</label>
                    <input type="password" class="form-control auth-input" id="password" name="password" required>
                </div>

                <button type="submit" class="btn auth-btn-submit w-100">Đăng Nhập</button>
            </form>

            <div class="auth-footer">
                <p>Bạn chưa có tài khoản? <a href="auth-register.php" class="auth-link">Đăng ký ngay</a></p>
            </div>
        </div>
    </main>
<footer class="site-footer">
        © 2025 Moonlit — All rights reserved.
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>



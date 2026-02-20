<?php
/**
 * User Account Dashboard
 * Main dashboard with sidebar navigation and dynamic content
 */

session_start();
require_once 'db_connect.php';

// --- 1. BỔ SUNG CÁC BIẾN CẦN THIẾT CHO HEADER ---
$isLoggedIn = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = 'account-index.php'; // Đặt tên trang hiện tại để Active menu nếu cần

// --- 2. BỔ SUNG HÀM HỖ TRỢ CHO HEADER ---
if (!function_exists('nav_active')) {
    function nav_active($page, $current)
    {
        return $page === $current ? 'nav-active' : '';
    }
}
// Redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_section = $_GET['section'] ?? 'profile';

// Fetch user basic info
$stmt = $pdo->prepare("SELECT UserID, Username, FullName, Role, Phone, Email, City, District, Ward, Street, HouseNumber FROM User_Account WHERE UserID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Logic phân quyền: Nếu là Admin thì chuyển sang trang Admin Dashboard
if ($user && $user['Role'] === 'Admin') {
    header('Location: admin-dashboard.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tài Khoản - Moonlit Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" type="text/css" href="moonlit-style.css">
</head>

<body class="account-body">
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

    <div class="container-fluid account-main">
        <div class="row h-100">
            <!-- Left Sidebar -->
            <div class="col-lg-3 account-sidebar">
                <nav class="account-menu">
                    <a href="?section=profile"
                        class="account-menu-item <?php echo $current_section === 'profile' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> Thông tin cá nhân
                    </a>
                    <a href="?section=voucher"
                        class="account-menu-item <?php echo $current_section === 'voucher' ? 'active' : ''; ?>">
                        <i class="fas fa-coins"></i> Voucher & Đổi Điểm
                    </a>
                    <a href="?section=orders"
                        class="account-menu-item <?php echo $current_section === 'orders' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> Lịch sử đặt hàng
                    </a>
                    <a href="?section=tracking"
                        class="account-menu-item <?php echo $current_section === 'tracking' ? 'active' : ''; ?>">
                        <i class="fas fa-truck"></i> Theo dõi đơn hàng
                    </a>
                    <hr class="account-menu-divider">
                    <a href="logout.php" class="account-menu-item account-menu-logout">
                        <i class="fas fa-sign-out-alt"></i> Đăng xuất
                    </a>
                </nav>
            </div>

            <!-- Right Content -->
            <div class="col-lg-9 account-content">
                <?php
                // Load appropriate section based on query parameter
                if ($current_section === 'profile') {
                    include 'account-profile.php';
                } elseif ($current_section === 'voucher') {
                    include 'account-voucher.php';
                } elseif ($current_section === 'orders') {
                    include 'account-orders.php';
                } elseif ($current_section === 'tracking') {
                    include 'account-tracking.php';
                } else {
                    include 'account-profile.php';
                }
                ?>
            </div>
        </div>
    </div>

    <footer class="site-footer">
        <div class="container footer-grid">

            <!-- COL 1 -->
            <div class="footer-col">
                <h4>Moonlit</h4>
                <p class="footer-desc">
                    Hiệu sách trực tuyến dành cho những tâm hồn yêu đọc.
                    Chúng tôi tin mỗi cuốn sách đều có ánh trăng riêng 🌙
                </p>
            </div>

            <!-- COL 2 -->
            <div class="footer-col">
                <h4>Liên kết</h4>
                <ul>
                    <li><a href="index.php">Trang chủ</a></li>
                    <li><a href="shop.php">Cửa hàng</a></li>
                    <li><a href="forum.php">Moonlit Forum</a></li>
                    <li><a href="aboutus.php">Về chúng tôi</a></li>
                </ul>
            </div>

            <!-- COL 3 -->
            <div class="footer-col">
                <h4>Blog & Nội dung</h4>
                <ul>
                    <li><a href="blogs.php">Blog Moonlit</a></li>
                    <li><a href="blogs.php">Review sách</a></li>
                    <li><a href="blogs.php">Góc đọc chậm</a></li>
                </ul>
            </div>

            <!-- COL 4 -->
            <div class="footer-col">
                <h4>Chính sách</h4>
                <ul>
                    <li><a href="policy.php">Chính sách mua hàng</a></li>
                    <li><a href="policy.php">Bảo mật thông tin</a></li>
                    <li><a href="policy.php">Điều khoản sử dụng</a></li>
                    <li><a href="contact_us.php">Liên hệ</a></li>
                </ul>
            </div>

        </div>

        <div class="footer-bottom">
            © 2025 Moonlit — All rights reserved.
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>

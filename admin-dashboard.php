<?php
/**
 * MOONLIT STORE - ADMIN DASHBOARD
 */

session_start();
require_once 'db_connect.php';
ob_start();
if (isset($_SESSION['redirect_tab'])) {
    $tab = $_SESSION['redirect_tab'];
    unset($_SESSION['redirect_tab']);
}
if (isset($_SESSION['flash_success'])) {
    $success_message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}


$currentUsername = $_SESSION['username'] ?? 'Admin';

// Tab hiện tại
$tab = $_POST['tab'] ?? ($_GET['tab'] ?? 'overview');

$error_message = '';
$success_message = '';
$isAjax = isset($_GET['ajax']) || isset($_POST['ajax']);
/**
 * Helper: escape
 */
function h($str)
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}
ob_end_flush();



?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Moonlit Store</title>

    <!-- CSS Moonlit -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">


</head>

<body class="account-body admin-page">
    <?php if (!$isAjax): ?>
        <!-- ===================== HEADER (giống vibe index/cart) ===================== -->
        <header class="account-header site-header">
            <div class="container header-inner">
                <div class="header-left">
                    <a href="shop.php" class="logo-link">
                        <span class="account-logo">Moonlit</span>
                    </a>

                </div>

                <div class="header-right">
                    <span class="account-username d-none d-sm-inline">
                        Xin chào, <strong><?php echo h($currentUsername); ?></strong>
                    </span>
                    <a href="index.php" class="account-btn-secondary header-account-btn">
                        Xem cửa hàng
                    </a>
                    <a href="logout.php" class="account-btn-secondary header-account-btn">
                        Đăng xuất
                    </a>
                </div>
            </div>
        </header>



        <!-- ===================== MAIN ===================== -->
        <main class="account-main">
            <div class="container">

                <h1 class="account-section-title mb-3">
                    Bảng điều khiển Admin
                </h1>

                <?php if ($success_message): ?>
                    <div class="alert alert-success account-alert">
                        <?php echo h($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-danger account-alert">
                        <?php echo h($error_message); ?>
                    </div>
                <?php endif; ?>

                <!-- NAV TAB -->
                <nav class="admin-nav mb-4">
                    <ul class="admin-nav-list">

                        <li>
                            <a class="<?= $tab === 'overview' ? 'active' : '' ?>" href="?tab=overview">Tổng quan</a>
                        </li>

                        <li>
                            <a class="<?= $tab === 'orders' ? 'active' : '' ?>" href="?tab=orders">Đơn hàng</a>
                        </li>

                        <li>
                            <a class="<?= $tab === 'products' ? 'active' : '' ?>" href="?tab=products">Sản phẩm</a>
                        </li>

                        <li>
                            <a class="<?= $tab === 'customers' ? 'active' : '' ?>" href="?tab=customers">Khách hàng</a>
                        </li>

                        <li class="dropdown">
                            <a class="dropdown-toggle <?= in_array($tab, ['voucher', 'posts','blogs']) ? 'active' : '' ?>" href="#"
                                data-bs-toggle="dropdown">
                                Marketing
                            </a>

                            <ul class="admin-dropdown-menu">
                                <li>
                                    <a class="dropdown-item <?= $tab === 'voucher' ? 'active' : '' ?>" href="?tab=voucher">
                                        Voucher
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= $tab === 'blogs' ? 'active' : '' ?>" href="?tab=blogs">
                                        Blog
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= $tab === 'forum' ? 'active' : '' ?>" href="?tab=forum">
                                        Forum
                                    </a>
                                </li>
                            </ul>
                        </li>

                        <li>
                            <a class="<?= $tab === 'employee' ? 'active' : '' ?>" href="?tab=employee">Nhân viên</a>
                        </li>
                        <li class="dropdown">
                            <a class="dropdown-toggle <?= in_array($tab, ['carriers', 'publishers']) ? 'active' : '' ?>" href="#"
                                data-bs-toggle="dropdown">
                                Đối tác
                            </a>

                            <ul class="admin-dropdown-menu">
                                <li>
                                    <a class="dropdown-item <?= $tab === 'carriers' ? 'active' : '' ?>" href="?tab=carriers">
                                        Đơn vị vận chuyển
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item <?= $tab === 'publishers' ? 'active' : '' ?>" href="?tab=publishers">
                                        Nhà xuất bản
                                    </a>
                                </li>
                                
                            </ul>
                        </li>
                        <li>
                            <a class="<?= $tab === 'contact' ? 'active' : '' ?>" href="?tab=contact">Contact</a>
                        </li>
                        <li>
                            <a class="<?= $tab === 'setting' ? 'active' : '' ?>" href="?tab=setting">Cài đặt</a>
                        </li>

                    </ul>
                </nav>

            <?php endif; ?>
            <?php
            // ================= TAB ROUTER =================
            $allowedTabs = [
                'overview',
                'orders',
                'products',
                'posts',
                'customers',
                'voucher',
                'employee',
                'blogs',
                'forum',
                'publishers',
                'carriers',
                'contact',
                'setting'
            ];

            if (!in_array($tab, $allowedTabs)) {
                $tab = 'overview';
            }

            $tabFile = __DIR__ . "/admin-$tab.php";
            
            if (file_exists($tabFile)) {
                require $tabFile;
            } else {
                echo '<div class="alert alert-danger">Tab không tồn tại</div>';
            }
            ?>

        </div>
    </main>
    <?php if (!$isAjax): ?>
        <!-- ===================== FOOTER ===================== -->
        <footer class="site-footer">
            © <?php echo date('Y'); ?> Moonlit — All rights reserved.
        </footer>
    <?php endif; ?>
    <script>
        function toggleMarketingMenu() {
            document.getElementById('marketingSubMenu')
                .classList.toggle('show');
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.admin-nav .dropdown-toggle').forEach(toggle => {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();

                const li = this.closest('.dropdown');
                document.querySelectorAll('.admin-nav .dropdown').forEach(d => {
                    if (d !== li) d.classList.remove('show');
                });

                li.classList.toggle('show');
            });
        });

        // Click ra ngoài thì đóng
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.admin-nav .dropdown')) {
                document.querySelectorAll('.admin-nav .dropdown')
                    .forEach(d => d.classList.remove('show'));
            }
        });
    </script>

</body>

</html>

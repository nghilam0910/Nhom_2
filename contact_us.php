<?php
session_start();
require_once 'db_connect.php';

/* =====================
   AUTH / COMMON
===================== */
$isLoggedIn = isset($_SESSION['user_id']);
$currentUserID = $_SESSION['user_id'] ?? null;
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string
    {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

/* =====================
   HANDLE CONTACT FORM (PRG)
===================== */
$successMessage = $_SESSION['successMessage'] ?? '';
$errorMessage = $_SESSION['errorMessage'] ?? '';
unset($_SESSION['successMessage'], $_SESSION['errorMessage']); // chỉ hiện 1 lần

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $fullName = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($fullName && $email && $subject && $message) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO Contact_Message
                (UserID, FullName, Email, Subject, Message, CreatedDate, Status)
                VALUES (?, ?, ?, ?, ?, NOW(), 'New')
            ");
            $stmt->execute([
                $currentUserID,
                $fullName,
                $email,
                $subject,
                $message
            ]);

            $_SESSION['successMessage'] = 'Cảm ơn bạn đã liên hệ với Moonlit. Chúng tôi sẽ phản hồi sớm nhất.';

        } catch (PDOException $e) {
            $_SESSION['errorMessage'] = 'Có lỗi xảy ra. Vui lòng thử lại sau.';
        }
    } else {
        $_SESSION['errorMessage'] = 'Vui lòng điền đầy đủ thông tin.';
    }

    // Redirect về chính trang → tránh resubmit form khi F5
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Contact Us - Moonlit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="moonlit-style.css">

    <style>
        /* =====================
   CONTACT PAGE STYLE
===================== */
        .contact-hero {
            padding: 30px 25px;
            border-radius: 20px;
            background:
                radial-gradient(800px circle at left top, rgba(30, 74, 140, 0.12), transparent 40%),
                radial-gradient(600px circle at right bottom, rgba(244, 211, 94, 0.18), transparent 40%),
                #ffffff;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.06);
            margin-bottom: 32px;
        }

        .contact-header {
            display: flex;
            align-items: flex-start;
            gap: 24px;
        }

        .contact-header-divider {
            width: 4px;
            min-height: 50px;
            background: var(--color-deep-blue);
            border-radius: 2px;
        }

        .contact-section {
            background: #ffffff;
            border-radius: 18px;
            padding: 25px;
            margin-bottom: 32px;
            box-shadow: 0 10px 26px rgba(0, 0, 0, 0.05);
        }

        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
        }

        .contact-info ul {
            list-style: none;
            padding: 0;
        }

        .contact-info li {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 0.95rem;
            color: #444;
        }

        .contact-info h2 {
            margin-bottom: 12px;
            color: var(--color-muted-brown);
        }


        .contact-info strong {
            color: var(--color-deep-blue);
        }

        .contact-map iframe {
            width: 100%;
            height: 300px;
            border-radius: 16px;
            border: 0;
            transition: all 0.3s ease;
        }

        .contact-map iframe:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }

        .contact-form {
            background: linear-gradient(180deg, #ffffff, #f8faff);
            padding: 28px;
            border-radius: 18px;
            border: 1px solid #e6edff;
            box-shadow: 0 10px 26px rgba(30, 74, 140, 0.06);
        }

        .contact-form input,
        .contact-form textarea {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid #dbe5ff;
            margin-bottom: 14px;
        }

        .contact-form input:focus,
        .contact-form textarea:focus {
            outline: none;
            border-color: var(--color-deep-blue);
            box-shadow: 0 0 0 3px rgba(30, 74, 140, 0.12);
        }

        .contact-form button {
            margin-top: 6px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .contact-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(30, 74, 140, 0.25);
        }

        .alert-success {
            background: #eaf8ef;
            color: var(--color-success);
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .alert-error {
            background: #fdeaea;
            color: var(--color-danger);
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
        }

        .faq-item {
            padding: 16px 20px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e6edff;
            margin-bottom: 16px;
            transition: all 0.25s ease;
        }

        .faq-item:hover {
            background: #eef4ff;
            transform: translateY(-2px);
        }

        @media (max-width: 900px) {
            .contact-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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

    <main class="account-main">
        <div class="container">

            <section class="contact-hero">
                <div class="contact-header">
                    <h1 class="account-section-title">Liên hệ với Moonlit</h1>
                    <div class="contact-header-divider"></div>
                    <p class="account-section-subtitle">
                        Chào mừng bạn đến với trang Contact Us - Liên hệ với Moonlit!
                        Tại đây, bạn có thể gửi mọi thắc mắc, góp ý hay yêu cầu hỗ trợ – Moonlit luôn sẵn sàng lắng nghe
                        và tư vấn tận tình.
                        Hãy để Moonlit đồng hành cùng bạn và biến mỗi trải nghiệm đọc sách trở nên thú vị và đáng nhớ
                        hơn.</p>
                </div>
            </section>

            <section class="contact-section">
                <div class="contact-grid">

                    <div class="contact-info">
                        <h2>Thông tin liên hệ</h2>
                        <ul>
                            <li>📧 <strong>Email:</strong> moonlit@support.vn</li>
                            <li>📞 <strong>Điện thoại:</strong> 0123 456 789</li>
                            <li>📍 <strong>Địa chỉ:</strong> 123 Đường Sách, Q1, TP.HCM</li>
                            <li>⏰ <strong>Giờ làm việc:</strong> 08:00 – 20:00</li>
                        </ul>

                        <div class="contact-map">
                            <iframe src="https://www.google.com/maps?q=Ho%20Chi%20Minh&output=embed"></iframe>
                        </div>
                    </div>

                    <div>
                        <h2>Gửi tin nhắn</h2>

                        <?php if ($successMessage): ?>
                            <div class="alert-success"><?= $successMessage ?></div><?php endif; ?>
                        <?php if ($errorMessage): ?>
                            <div class="alert-error"><?= $errorMessage ?></div><?php endif; ?>

                        <form method="POST" class="contact-form">
                            <input type="text" name="name" placeholder="Họ và tên" required>
                            <input type="email" name="email" placeholder="Email" required>
                            <input type="text" name="subject" placeholder="Chủ đề" required>
                            <textarea name="message" rows="5" placeholder="Nội dung lời nhắn" required></textarea>

                            <button class="account-btn-save">Gửi tin nhắn</button>
                            <p style="font-size:13px;color:#888;margin-top:8px;">
                                Mọi thông tin của bạn sẽ được bảo mật dưới quyền truy cập của Moonlit.
                            </p>
                        </form>
                    </div>

                </div>
            </section>

            <section class="contact-section">
                <h2 style="color:var(--color-deep-blue)">FAQs</h2>
                <br>
                <div class="faq-item">
                    <h4>⏱ Giao hàng ở Moonlit thường mất bao lâu?</h4>
                    <p>
                        Sau khi bạn đặt hàng, Moonlit sẽ xử lý đơn hàng trong vòng 24 - 48 giờ làm việc. Thời gian giao
                        hàng thường từ <strong>2 đến 5 ngày làm việc</strong>, tùy theo khu vực của bạn.
                        Chúng tôi sẽ gửi email xác nhận và mã theo dõi để bạn dễ dàng theo dõi trạng thái đơn hàng. Bạn
                        có thể theo dõi chi tiết đơn hàng tại Mục Tài khoản.
                        Nếu có bất kỳ vấn đề gì về vận chuyển, đừng ngần ngại liên hệ với chúng tôi để được hỗ trợ nhanh
                        chóng.
                    </p>
                </div>

                <div class="faq-item">
                    <h4>💳 Các hình thức thanh toán ở Moonlit?</h4>
                    <p>
                        Moonlit cung cấp nhiều phương thức thanh toán tiện lợi và an toàn để bạn lựa chọn. Bạn có thể
                        thanh toán khi nhận hàng (<strong>COD</strong>), <strong>chuyển khoản ngân hàng</strong> hoặc
                        qua các <strong>ví điện tử</strong> phổ biến.
                        Mọi giao dịch đều được mã hóa và bảo mật, giúp bạn yên tâm khi mua sắm.
                        Trong trường hợp gặp sự cố thanh toán, đội ngũ hỗ trợ của chúng tôi luôn sẵn sàng hướng dẫn và
                        xử lý kịp thời.
                    </p>
                </div>

                <div class="faq-item">
                    <h4>🔄 Chính sách đổi trả?</h4>
                    <p>
                        Moonlit cam kết mang đến trải nghiệm mua sắm thoải mái. Nếu sản phẩm bạn nhận được bị lỗi, hỏng
                        hoặc không đúng mô tả, bạn có thể yêu cầu <strong>đổi trả trong vòng 7 ngày</strong> kể từ ngày
                        nhận hàng.
                        Vui lòng giữ nguyên tình trạng sản phẩm, bao bì và liên hệ bộ phận chăm sóc khách hàng để được
                        hướng dẫn các bước đổi trả nhanh chóng.
                        Chúng tôi luôn ưu tiên giải quyết mọi yêu cầu một cách thuận tiện và minh bạch nhất.
                    </p>
                </div>

                <div class="faq-item">
                    <h4>📞 Moonlit hỗ trợ khách hàng như thế nào?</h4>
                    <p>
                        Đội ngũ hỗ trợ Moonlit luôn sẵn sàng trả lời mọi thắc mắc của bạn qua email, hotline hoặc chat
                        trực tiếp.
                        Chúng tôi cam kết phản hồi trong vòng <strong>24 giờ làm việc</strong>.
                        Dù là vấn đề về đơn hàng, sản phẩm hay gợi ý đọc sách, bạn đều có thể yên tâm rằng Moonlit sẽ
                        đồng hành và giải đáp tận tình, đảm bảo sự hài lòng của khách hàng.
                    </p>
                </div>

                <div class="faq-item">
                    <h4>📚 Tôi cần chăm sóc và bảo quản sách ở Moonlit ra sao?</h4>
                    <p>
                        Moonlit không chỉ bán sách mà còn quan tâm đến trải nghiệm đọc lâu dài của khách hàng.
                        Vì vậy, Moonlit đã tạo trang <strong>Blogs</strong> riêng nhằm cung cấp các kiến thức xoay quanh
                        sách. Bạn có thể tìm kiếm thêm hướng dẫn bảo quản sách, mẹo giữ giấy tại trang này.
                        Nếu cần, bạn cũng có thể yêu cầu tư vấn cá nhân bằng việc điền form ở trang Liên hệ với Moonlit
                        phía trên.
                    </p>
                </div>


            </section>

        </div>
    </main>

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
</body>

</html>

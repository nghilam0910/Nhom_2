<?php
/**
 * MOONLIT STORE - ABOUT US PAGE
 * Header/Footer match product-detail.php & cart.php
 */

session_start();
require_once 'db_connect.php';

$isLoggedIn = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = 'aboutus.php';

if (!function_exists('nav_active')) {
  function nav_active(string $page, string $currentPage): string
  {
    return $page === $currentPage ? 'nav-active' : '';
  }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="UTF-8">
  <title>Về chúng tôi - Moonlit Store</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="moonlit-style.css">
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
          <a href="aboutus.php" class="header-menu-link <?php echo nav_active('aboutus.php', $currentPage); ?>">
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

  <!-- ===================== MAIN ===================== -->
  <main class="account-main">
    <div class="container">

      <!-- Breadcrumb nhỏ giống product detail -->
      <div class="mb-3 small">
        <a href="index.php" class="text-decoration-none" style="color: var(--color-deep-blue);">Trang chủ</a>
        <span class="text-secondary"> / </span>
        <span>Về chúng tôi</span>
      </div>

      <!-- Hero -->
      <div class="account-card mb-3" style="overflow:hidden;">
        <div class="p-4 p-md-5">
          <h1 class="account-section-title mb-2" style="margin:0;">
            Moonlit Store
          </h1>
          <p class="account-section-subtitle mb-3" style="margin:0;">
            Một tiệm sách nhỏ dành cho những người thích đọc chậm, chọn kỹ, và yêu cảm giác “mở ra là thấy bình yên” ✨
          </p>

          <div class="row g-3 mt-3">
            <div class="col-12 col-md-4">
              <div class="account-card h-100" style="padding:16px;">
                <h3 class="account-card-title mb-2">Tụi mình là ai?</h3>
                <p class="mb-0" style="font-size:14px;">
                  Moonlit là nơi tụi mình tuyển chọn sách theo chủ đề, gu đọc và mood.
                  Không chỉ bán sách, tụi mình muốn tạo “một góc để ở lại”.
                </p>
              </div>
            </div>

            <div class="col-12 col-md-4">
              <div class="account-card h-100" style="padding:16px;">
                <h3 class="account-card-title mb-2">Tụi mình làm gì?</h3>
                <p class="mb-0" style="font-size:14px;">
                  Bán sách theo biến thể (bìa mềm/bìa cứng), ưu đãi theo đợt,
                  tích điểm – voucher, và trải nghiệm mua sắm gọn gàng.
                </p>
              </div>
            </div>

            <div class="col-12 col-md-4">
              <div class="account-card h-100" style="padding:16px;">
                <h3 class="account-card-title mb-2">Vì sao là Moonlit?</h3>
                <p class="mb-0" style="font-size:14px;">
                  “Moonlit” là ánh trăng — dịu, yên, đủ sáng để bạn tiếp tục đọc thêm vài trang.
                  Tụi mình muốn website cũng mang vibe đó.
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Values / Mission -->
      <div class="account-card mb-3">
        <h2 class="account-card-title mb-3">Giá trị tụi mình theo đuổi</h2>

        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="account-order-card" style="padding:16px;">
              <strong style="font-size:14px;">Chọn lọc & rõ ràng</strong>
              <p class="mb-0" style="font-size:14px;">
                Giá hiển thị theo SKU, có sale thì show giá tốt nhất, không “gài”.
                Mọi thứ minh bạch từ mô tả đến thanh toán.
              </p>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="account-order-card" style="padding:16px;">
              <strong style="font-size:14px;">Trải nghiệm nhẹ nhàng</strong>
              <p class="mb-0" style="font-size:14px;">
                Giao diện tối giản, ít làm phiền, tập trung vào nội dung sách và hành trình chọn sách.
              </p>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="account-order-card" style="padding:16px;">
              <strong style="font-size:14px;">Chăm sóc sau mua</strong>
              <p class="mb-0" style="font-size:14px;">
                Đổi trả – hoàn tiền rõ quy trình, phản hồi nhanh, ghi nhận review để cải thiện.
              </p>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="account-order-card" style="padding:16px;">
              <strong style="font-size:14px;">Cộng đồng đọc sách</strong>
              <p class="mb-0" style="font-size:14px;">
                Blog + forum để chia sẻ cảm nhận, gợi ý sách, và “đọc cùng nhau” theo chủ đề.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- CTA -->
      <div class="account-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3" style="padding:16px;">
          <div>
            <h2 class="account-card-title mb-1">Sẵn sàng chọn sách chưa?</h2>
            <p class="mb-0" style="font-size:14px; color: var(--color-secondary);">
              Ghé Cửa hàng để xem các combo và phiên bản bìa mềm/bìa cứng nha 💛
            </p>
          </div>

          <div class="d-flex gap-2">
            <a href="shop.php" class="account-btn-save text-decoration-none">Đi tới Cửa hàng</a>
            <a href="contact.php" class="account-btn-secondary text-decoration-none">Liên hệ</a>
          </div>
        </div>
      </div>

    </div>
  </main>

  <!-- ===================== FOOTER (MATCH) ===================== -->
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

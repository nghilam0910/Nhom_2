<?php
/**
 * MOONLIT STORE - RETURN POLICY PAGE
 * Header/Footer match product-detail.php & cart.php
 */

session_start();
require_once 'db_connect.php';

$isLoggedIn = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = 'policy.php';

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
  <title>Chính sách - Moonlit Store</title>
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

      <!-- Breadcrumb -->
      <div class="mb-3 small">
        <a href="index.php" class="text-decoration-none" style="color: var(--color-deep-blue);">Trang chủ</a>
        <span class="text-secondary"> / </span>
        <span>Chính sách</span>
      </div>

      <!-- Title -->
      <section class="shop-header mb-3">
        <h1 class="account-section-title">Chính sách Moonlit</h1>
        <p class="account-section-subtitle">
          Tụi mình viết rõ ràng để bạn mua yên tâm – nhận sách vui vẻ ✨
        </p>
      </section>

      <!-- Exchange / Return -->
      <div class="account-card mb-3" id="return-policy">
        <h2 class="account-card-title mb-3">1) Chính sách Đổi/Trả</h2>

        <div class="row g-3">
          <div class="col-12 col-lg-7">
            <div class="account-order-card mb-3" style="padding:16px;">
              <strong style="font-size:14px; color: var(--color-deep-blue);"><i class="fas fa-check-circle me-1"></i>
                Điều kiện áp dụng</strong>
              <ul class="mb-0 mt-2" style="font-size:14px;">
                <li>Sách còn nguyên tem/nhãn, không rách/móp/nước, không ghi chú lên sách.</li>
                <li>Đổi/trả trong vòng <strong>07 ngày</strong> kể từ ngày nhận hàng thành công.</li>
                <li>Lỗi do Moonlit: giao sai SKU, thiếu hàng, lỗi sản xuất hoặc hư hại do vận chuyển.</li>
              </ul>
            </div>

            <div class="row g-2">
              <div class="col-12 col-md-6">
                <div class="account-order-card h-100" style="padding:16px; border-left: 4px solid #28a745;">
                  <strong style="font-size:13px; color: #28a745;">Được hỗ trợ</strong>
                  <ul class="mb-0 mt-1" style="font-size:13px; padding-left: 1.2rem;">
                    <li>Giao sai phiên bản, sai ISBN.</li>
                    <li>Lỗi in ấn: thiếu trang, lem mực.</li>
                    <li>Móp méo nặng do vận chuyển.</li>
                  </ul>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <div class="account-order-card h-100" style="padding:16px; border-left: 4px solid #dc3545;">
                  <strong style="font-size:13px; color: #dc3545;">Không hỗ trợ</strong>
                  <ul class="mb-0 mt-1" style="font-size:13px; padding-left: 1.2rem;">
                    <li>Đã sử dụng/ghi chú lên sách.</li>
                    <li>Quá thời hạn 07 ngày.</li>
                    <li>Không có video unbox bằng chứng.</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
          <div class="col-12 col-lg-5">
            <div class="account-order-card h-100" style="padding:16px;">
              <strong style="font-size:14px; color: var(--color-deep-blue);"><i class="fas fa-info-circle me-1"></i> Quy
                trình Đổi/Trả</strong>
              <ol class="mb-0 mt-2" style="font-size:14px; padding-left: 1.2rem;">
                <li>Mã đơn hàng + Ảnh chụp rõ nét tình trạng sách (hoặc video mở hộp).</li>
                <li>Vào <strong>Lịch sử đơn hàng</strong> > nhấn nút <strong>"Trả hàng"</strong>. Hoặc nhắn tin qua
                  Fanpage.</li>
                <li>Moonlit sẽ phản hồi và hướng dẫn bạn cách đóng gói gửi hàng về.</li>
              </ol>
            </div>
          </div>
        </div>
      </div>

      <!-- Refund -->
      <div class="account-card mb-3" id="refund-policy">
        <h2 class="account-card-title mb-3">2) Chính sách Hoàn tiền</h2>

        <div class="account-order-card" style="padding:16px;">
          <div class="mb-3" style="font-size:14px;">
            <p>Trong trường hợp sản phẩm lỗi mà Moonlit không còn hàng để đổi, hoặc yêu cầu trả hàng được phê duyệt, số
              tiền hoàn trả sẽ được tính toán như sau:</p>
          </div>

          <ul class="mb-0" style="font-size:14px; line-height: 1.6;">
            <li>
              <strong>Cách tính số tiền hoàn:</strong>
              Số tiền hoàn lại cho mỗi sản phẩm = <strong>Giá thực tế của sản phẩm - (Giá trị Voucher đã dùng chia theo
                tỷ lệ)</strong>.
              <br><small class="text-secondary">*Điều này giúp đảm bảo công bằng khi bạn chỉ trả lại một phần của đơn
                hàng có áp dụng mã giảm giá.</small>
            </li>
            <li>
              <strong>Phí vận chuyển:</strong> Rất tiếc, phí vận chuyển ban đầu sẽ <strong>không được hoàn lại</strong>
              (vì đây là phí dịch vụ đã chi trả cho đơn vị vận chuyển).
            </li>
            <li>
              <strong>Phương thức nhận tiền:</strong>
              <ul>
                <li><strong>Đơn hàng trả trước (Bank/Ví):</strong> Tiền được hoàn về đúng tài khoản/thẻ bạn đã dùng để
                  thanh toán.</li>
                <li><strong>Đơn hàng COD:</strong> Moonlit sẽ liên hệ để nhận số tài khoản và chuyển khoản trực tiếp cho
                  bạn.</li>
              </ul>
            </li>
            <li>
              <strong>Thời gian xử lý:</strong> Từ <strong>3–7 ngày làm việc</strong> kể từ khi Moonlit xác nhận đã nhận
              lại hàng (thời gian thực tế phụ thuộc vào tốc độ xử lý của ngân hàng).
            </li>
            <li>
              <strong>Lưu ý:</strong> Số tiền sẽ được hệ thống làm tròn xuống hàng đơn vị (VNĐ) để khớp với giao dịch
              ngân hàng.
            </li>
          </ul>
        </div>
      </div>

      <!-- Shipping -->
      <div class="account-card mb-3">
        <h2 class="account-card-title mb-3">3) Vận chuyển</h2>

        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="account-order-card h-100" style="padding:16px;">
              <strong style="font-size:14px;">Thời gian giao hàng</strong>
              <ul class="mb-0" style="font-size:14px; margin-top:8px;">
                <li>Nội thành: 1–3 ngày</li>
                <li>Liên tỉnh: 3–7 ngày</li>
                <li>Có thể thay đổi theo Carrier và thời điểm cao điểm.</li>
              </ul>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <div class="account-order-card h-100" style="padding:16px;">
              <strong style="font-size:14px;">Phí ship</strong>
              <ul class="mb-0" style="font-size:14px; margin-top:8px;">
                <li>Phí ship hiển thị ở bước checkout (theo đơn vị vận chuyển).</li>
                <li>Một số chương trình có thể hỗ trợ giảm phí ship theo điều kiện.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment -->
      <div class="account-card mb-3">
        <h2 class="account-card-title mb-3">4) Thanh toán</h2>

        <div class="account-order-card" style="padding:16px;">
          <ul class="mb-0" style="font-size:14px;">
            <li><strong>COD</strong>: thanh toán khi nhận hàng.</li>
            <li><strong>Chuyển khoản</strong>: bạn sẽ nhận hướng dẫn ở trang checkout/hoặc xác nhận từ Moonlit.</li>
            <li>Đơn hàng chỉ được giữ tối đa một khoảng thời gian nếu cần xác nhận chuyển khoản (tuỳ chính sách từng
              thời điểm).</li>
          </ul>
        </div>
      </div>

      <div class="account-card mb-3" id="membership-policy">
        <h2 class="account-card-title mb-3">5) Thành viên & Tích điểm Moonlit</h2>

        <div class="account-order-card mb-3" style="padding:16px;">
          <strong style="font-size:14px; color: var(--color-deep-blue);">Hệ thống bậc thành viên</strong>
          <p class="mb-2" style="font-size:13px; color: #666;">Bậc hạng dựa trên tổng chi tiêu tích lũy (sau khi đã trừ
            đơn trả hàng):</p>
          <div class="table-responsive">
            <table class="table table-sm table-borderless mb-0" style="font-size:13px;">
              <thead class="text-secondary">
                <tr>
                  <th>Hạng</th>
                  <th>Chi tiêu tích lũy</th>
                  <th>Đặc quyền</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong>Member</strong></td>
                  <td>Dưới 100k</td>
                  <td>Voucher chung</td>
                </tr>
                <tr>
                  <td><strong style="color: #cd7f32;">Bronze</strong></td>
                  <td>Từ 100k</td>
                  <td>Voucher hạng Đồng</td>
                </tr>
                <tr>
                  <td><strong style="color: #9ea0a2;">Silver</strong></td>
                  <td>Từ 200k</td>
                  <td>Voucher hạng Bạc</td>
                </tr>
                <tr>
                  <td><strong style="color: #d4af37;">Gold</strong></td>
                  <td>Từ 300k</td>
                  <td>Voucher hạng Vàng</td>
                </tr>
                <tr>
                  <td><strong style="color: #555;">Platinum</strong></td>
                  <td>Từ 400k</td>
                  <td>Voucher Bạch Kim</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="account-order-card h-100" style="padding:16px;">
              <strong style="font-size:14px;">Quy tắc tích điểm</strong>
              <ul class="mb-0" style="font-size:13px; margin-top:8px;">
                <li>Mỗi <strong>10.000đ</strong> thanh toán = <strong>1 điểm</strong>.</li>
                <li>Mỗi <strong>lần đánh giá sản phẩm</strong> = <strong>5 điểm</strong>.</li>
                <li>Điểm cộng khi đơn hàng chuyển thành <strong>"Đã nhận"</strong>.</li>
                <li>Đơn <strong>Trả hàng</strong> sẽ bị trừ lại số điểm tương ứng.</li>
              </ul>
            </div>
          </div>

          <div class="col-12 col-md-6">
            <div class="account-order-card h-100" style="padding:16px;">
              <strong style="font-size:14px;">Cách nhận Voucher</strong>
              <ul class="mb-0" style="font-size:13px; margin-top:8px;">
                <li><strong>Tự động:</strong> Voucher hạng tặng khi bạn thăng cấp.</li>
                <li><strong>Đổi điểm:</strong> Dùng điểm tích lũy đổi mã trong trang tài khoản.</li>
                <li>Voucher có điều kiện đơn tối thiểu và mức giảm tối đa.</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="mt-5 pt-4 border-top d-flex flex-wrap gap-3">
          <a href="shop.php" class="account-btn-secondary text-decoration-none py-2">
            <i class="fas fa-shopping-cart"></i>Quay lại Cửa hàng
          </a>
          <a href="cart.php" class="account-btn-secondary text-decoration-none py-2">
            <i class="fas fa-shopping-cart"></i>Xem Giỏ hàng
          </a>
        </div>
      </div>
    </div>
  </main>

  <!-- ===================== FOOTER ===================== -->
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

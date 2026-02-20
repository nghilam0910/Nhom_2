<?php
session_start();
require_once 'db_connect.php';

// Trạng thái đăng nhập
$isLoggedIn = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

if (!function_exists('calculateUserRankForHome')) {
    function calculateUserRankForHome($pdo, $user_id) {
        $stmt = $pdo->prepare("
            SELECT (SUM(o.TotalAmount) - COALESCE(SUM(ro.TotalRefund), 0)) as total_spent
            FROM `Order` o
            LEFT JOIN Returns_Order ro ON o.OrderID = ro.OrderID AND ro.Status = 'Chấp thuận'
            WHERE o.UserID = ? AND o.Status IN ('Đã nhận', 'Trả hàng', 'Đã hoàn tiền')
        ");
        $stmt->execute([$user_id]);
        $total_spent = $stmt->fetch()['total_spent'] ?? 0;

            // Tính rank
            if ($total_spent < 100000) $rank = 'Member';
            elseif ($total_spent < 200000) $rank = 'Bronze';
            elseif ($total_spent < 300000) $rank = 'Silver';
            elseif ($total_spent < 400000) $rank = 'Gold';
            else $rank = 'Platinum';

            // Trả về cả hai (Mảng)
            return ['rank' => $rank, 'money' => $total_spent];
    }
}


$has_new_voucher = false; // Biến cờ để hiển thị thông báo


// Lấy role từ session, nếu chưa có thì để rỗng
$userRole = $_SESSION['role'] ?? ''; 

// THÊM ĐIỀU KIỆN: && $userRole === 'Customer'
// Chỉ chạy logic voucher nếu đã đăng nhập VÀ là Khách hàng
if (isset($_SESSION['user_id']) && $userRole === 'Customer') { 
    $user_id_home = $_SESSION['user_id'];
   
    // 2. Tính Rank hiện tại
    $result = calculateUserRankForHome($pdo, $user_id_home);
    $current_tier = $result['rank']; // Lấy chữ Platinum
    $total_spent  = $result['money']; // Lấy số tiền ra biến global để dùng


    // 3. Kiểm tra xem có voucher nào (Rank hiện tại HOẶC Free) mà user CHƯA CÓ trong User_Voucher không
    // Chỉ đếm những voucher còn hạn, còn lượt sử dụng và đang Active
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*)
        FROM Voucher v
        WHERE (v.RankRequirement = ? OR v.RankRequirement = 'Free')
        AND v.Status = 1
        AND (v.EndDate IS NULL OR v.EndDate > NOW())
        AND v.UsedCount < v.UsageLimit
        AND v.VoucherID NOT IN (
            SELECT uv.VoucherID FROM User_Voucher uv WHERE uv.UserID = ?
        )
    ");
   
    $stmtCheck->execute([$current_tier, $user_id_home]);
    $count_new = $stmtCheck->fetchColumn();
    
    // Check xem có voucher Platinum nào thỏa mãn không (Bỏ qua điều kiện User_Voucher để test)
    $stmtTest = $pdo->prepare("SELECT * FROM Voucher WHERE RankRequirement = ?");
    $stmtTest->execute([$current_tier]);
    $vouchers = $stmtTest->fetchAll();
    
    if ($count_new > 0) {
        $has_new_voucher = true;
    }
}
// Helper nav active
if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string
    {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

// Helper format giá
if (!function_exists('format_price')) {
    function format_price($number): string
    {
        if ($number === null)
            return '';
        return number_format((float) $number, 0, ',', '.') . ' đ';
    }
}

// ============================
// LẤY DỮ LIỆU TỪ DB (schema hiện tại: Product  + Image(BLOB) + Book_Post)
// ============================

// Banner
$banners = [];

try {
    $stmt = $pdo->query("
        SELECT
            BannerID,
            Title,
            ImageUrl,
            (ImageBinary IS NOT NULL AND OCTET_LENGTH(ImageBinary) > 0) AS HasBinary
        FROM Banner
        ORDER BY BannerID DESC
    ");
    $banners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}



// Sách mới nhất (4 cuốn, có xét SALE)
$latestProducts = [];
try {
    $stmt = $pdo->query("
        SELECT
            p.ProductID,
            p.ProductName,
            p.Description,
            p.CreatedDate,

            MIN(
                CASE 
                    WHEN ps.DiscountedPrice IS NOT NULL 
                    THEN ps.DiscountedPrice 
                    ELSE s.SellPrice 
                END
            ) AS MinDisplayPrice,

            MAX(s.SellPrice) AS MaxOriginalPrice,

            MAX(ps.DiscountedPrice IS NOT NULL) AS HasSale,
            p.ImageUrl,
            (p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0) AS HasImage
        FROM Product p
        JOIN SKU s 
            ON s.ProductID = p.ProductID
        LEFT JOIN PRODUCT_SALE ps
            ON ps.SKUID = s.SKUID
            AND ps.StartDate <= NOW()
            AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())
        WHERE
            p.Status = 1
            AND s.Status = 1
        GROUP BY
            p.ProductID,
            p.ProductName,
            p.Description,
            p.CreatedDate,
            p.Image,
            p.ImageUrl
        ORDER BY p.CreatedDate DESC
        LIMIT 4
    ");
    $latestProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}




//Book of the month
$bookOfTheMonth = null;

try {
    $stmt = $pdo->query("
        SELECT
            p.ProductID,
            p.ProductName,
            p.Description,
            SUM(oi.Quantity) AS TotalSold,

            MIN(
                CASE
                    WHEN ps.DiscountedPrice IS NOT NULL
                    THEN ps.DiscountedPrice
                    ELSE s.SellPrice
                END
            ) AS MinDisplayPrice,

            MAX(s.SellPrice) AS MaxOriginalPrice,
            MAX(ps.DiscountedPrice IS NOT NULL) AS HasSale,
            p.ImageUrl,
            (p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0) AS HasImage

        FROM Order_Items oi
        JOIN SKU s ON s.SKUID = oi.SKU_ID
        JOIN Product p ON p.ProductID = s.ProductID
        LEFT JOIN PRODUCT_SALE ps
            ON ps.SKUID = s.SKUID
            AND ps.StartDate <= NOW()
            AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())

        WHERE p.Status = 1 AND s.Status = 1

        GROUP BY
            p.ProductID,
            p.ProductName,
            p.Description,
            p.Image,
            p.ImageUrl
        ORDER BY TotalSold DESC
        LIMIT 4
    ");

    $bookOfTheMonth = $stmt->fetchAll(PDO::FETCH_ASSOC); // Lấy hết cả 3 cuốn

} catch (Exception $e) {
}

// BLOG MỚI NHẤT

$latestBlogs = [];

try {
    $stmt = $pdo->query("
        SELECT
            BlogID,
            Title,
            Content,
            Thumbnail,
            CreatedDate
        FROM Blog
        ORDER BY CreatedDate DESC
        LIMIT 3
    ");
    $latestBlogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}



?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Moonlit - Hiệu sách trực tuyến</title>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

    <!-- ===================== MAIN ===================== -->
    <main class="home-main">
        <?php if ($has_new_voucher): ?>
            <div class="position-fixed bottom-0 end-0 p-4" style="z-index: 1100">
                <div id="voucherToast" class="toast voucher-toast-custom text-white align-items-center" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex p-2 align-items-center">
                        <div class="p-2">
                            <div class="voucher-icon-box">
                                🎁
                            </div>
                        </div>
                       
                        <div class="toast-body ps-1">
                            <h6 class="mb-0 fw-bold">Quà tặng mới!</h6>
                            <small class="text-white-50">Bạn nhận được <?php echo $count_new; ?> voucher.</small> <br>
                            <small class="text-white-50">Vô Voucher & Đổi điểm trong tài khoản để nhận ngay</small>
                        </div>


                        <div class="pe-2">
                            <a href="account-index.php?section=voucher" class="btn-voucher-action shadow-sm">
                                Nhận
                            </a>
                        </div>
                       
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            </div>


            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var voucherToast = document.getElementById('voucherToast');
                    // Thêm animation: true và autohide: false nếu muốn nó hiện mãi đến khi bấm tắt
                    var toast = new bootstrap.Toast(voucherToast, { delay: 10000 });
                    toast.show();
                });
            </script>
        <?php endif; ?>

        <!-- ===== BANNER ===== -->
        <?php if (!empty($banners)): ?>
            <section class="home-intro-banner">
                <div class="container">
                    <div class="intro-banner-grid">

                        <!-- ===== GIỚI THIỆU MOONLIT (BÊN TRÁI) ===== -->
                        <div class="moonlit-intro-card">
                            <p class="intro-eyebrow"><strong>Hiệu sách Moonlit</strong></p>

                            <h2 class="intro-title">
                                Không gian sách dành cho mọi tâm hồn yêu đọc
                            </h2>

                            <p class="intro-desc">
                                Chúng tôi chọn lọc từng đầu sách với mong muốn mang đến
                                trải nghiệm đọc trọn vẹn – nơi mỗi cuốn sách đều có câu chuyện
                                riêng chờ bạn khám phá.
                            </p>

                            <div class="intro-actions">

                                <a href="aboutus.php" class="btn btn-outline-secondary">
                                    Về Moonlit
                                </a>
                            </div>
                        </div>

                        <!-- ===== BANNER CAROUSEL (BÊN PHẢI) ===== -->
                        <div class="banner-right-wrap">
                            <div id="carouselExampleIndicators" class="carousel slide banner-carousel"
                                data-bs-ride="carousel">

                                <!-- Indicators -->
                                <div class="carousel-indicators">
                                    <?php foreach ($banners as $i => $b): ?>
                                        <button type="button" data-bs-target="#carouselExampleIndicators"
                                            data-bs-slide-to="<?php echo $i; ?>"
                                            class="<?php echo $i === 0 ? 'active' : ''; ?>">
                                        </button>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Slides -->
                                <div class="carousel-inner">
                                    <?php foreach ($banners as $i => $b): ?>
                                        <div class="carousel-item <?php echo $i === 0 ? 'active' : ''; ?>">
                                            <?php if (!empty($b['HasBinary'])): ?>
                                                <img src="banner-image.php?id=<?php echo $b['BannerID']; ?>"
                                                    class="d-block w-100 banner-img"
                                                    alt="<?php echo htmlspecialchars($b['Title']); ?>">
                                            <?php else: ?>
                                                <img src="<?php echo htmlspecialchars($b['ImageUrl']); ?>"
                                                    class="d-block w-100 banner-img"
                                                    alt="<?php echo htmlspecialchars($b['Title']); ?>">
                                            <?php endif; ?>

                                            <?php if (!empty($b['Title'])): ?>
                                                <div class="carousel-caption">
                                                    <h5><?php echo htmlspecialchars($b['Title']); ?></h5>
                                                    <a href="shop.php" class="btn btn-warning btn-sm mt-2">
                                                        Khám phá cửa hàng
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Controls -->
                                <button class="carousel-control-prev" type="button"
                                    data-bs-target="#carouselExampleIndicators" data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon"></span>
                                </button>

                                <button class="carousel-control-next" type="button"
                                    data-bs-target="#carouselExampleIndicators" data-bs-slide="next">
                                    <span class="carousel-control-next-icon"></span>
                                </button>

                            </div>
                        </div>

                    </div>
                </div>
            </section>
        <?php endif; ?>
        <!-- ===== BOOK OF THE MONTH ===== -->
            <section class="home-section home-section-featured">
                <div class="container">
                    <div class="home-section-header">
                        <h2 class="home-section-title">📚 Book of the Month</h2>
                    </div>

                    <?php if (!empty($bookOfTheMonth)): ?>
                        <div class="home-grid-4"> 
                            
                            <?php foreach ($bookOfTheMonth as $book): ?> <article class="product-card">

                                    <a href="product-detail.php?id=<?php echo $book['ProductID']; ?>" class="product-card-link">

                                        <div class="product-card-image">
                                            <?php
                                                $imgSrc = '';
                                                if (!empty($book['ImageUrl'])) {
                                                    $imgSrc = $book['ImageUrl'];
                                                } elseif (!empty($book['HasImage'])) {
                                                    $imgSrc = "product-image.php?id=" . urlencode($book['ProductID']);
                                                }
                                            ?>

                                            <?php if ($imgSrc !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($book['ProductName']); ?>">
                                            <?php else: ?>
                                                <div class="product-image-placeholder">Moonlit</div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="product-card-body">
                                            <h3 class="product-title">
                                                <?php echo htmlspecialchars($book['ProductName']); ?>
                                            </h3>

                                            <p class="product-desc">
                                                <?php echo htmlspecialchars(mb_strimwidth($book['Description'] ?? '', 0, 80, '...')); ?>
                                            </p>

                                            <div class="product-price-row">
                                                <?php if ($book['HasSale']): ?>
                                                    <span class="product-price text-danger">
                                                        <?php echo format_price($book['MinDisplayPrice']); ?>
                                                    </span>
                                                    <span class="product-old-price">
                                                        <?php echo format_price($book['MaxOriginalPrice']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="product-price">
                                                        <?php echo format_price($book['MinDisplayPrice']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <p class="featured-sold">
                                                🔥 Đã bán: <?php echo (int) $book['TotalSold']; ?> cuốn
                                            </p>
                                        </div>
                                    </a>

                                </article>
                            <?php endforeach; ?> </div>
                    <?php else: ?>
                        <p class="home-empty-text">Hiện chưa có sản phẩm nổi bật.</p>
                    <?php endif; ?>
                </div>
            </section>
        <!-- ===== SÁCH MỚI NHẤT ===== -->
        <section class="home-section">
            <div class="container">
                <div class="home-section-header">
                    <h2 class="home-section-title">Sách mới nhất</h2>
                    <a href="shop.php" class="home-section-link">Xem tất cả</a>
                </div>

                <div class="home-grid-4">
                    <?php if (!empty($latestProducts)): ?>
                        <?php foreach ($latestProducts as $p): ?>
                            <article class="product-card">
                                <a href="product-detail.php?id=<?php echo $p['ProductID']; ?>" class="product-card-link">
                                    <div class="product-card-image">
                                        <?php
                                            $imgSrc = '';
                                            if (!empty($p['ImageUrl'])) {
                                                $imgSrc = $p['ImageUrl'];
                                            } elseif (!empty($p['HasImage'])) {
                                                $imgSrc = "product-image.php?id=" . urlencode($p['ProductID']);
                                            }
                                        ?>

                                        <?php if ($imgSrc !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                                alt="<?php echo htmlspecialchars($p['ProductName']); ?>">
                                        <?php else: ?>
                                            <div class="product-image-placeholder">Moonlit</div>
                                        <?php endif; ?>
                                    </div>


                                    <div class="product-card-body">
                                        <h3 class="product-title"><?php echo htmlspecialchars($p['ProductName']); ?>
                                        </h3>
                                        <p class="product-desc">
                                            <?php echo htmlspecialchars(mb_strimwidth($p['Description'] ?? '', 0, 80, '...')); ?>
                                        </p>

                                        <div class="product-price-row">
                                            <?php if ($p['HasSale']): ?>
                                                <span class="product-price text-danger">
                                                    <?php echo format_price($p['MinDisplayPrice']); ?>
                                                </span>

                                                <span class="product-old-price">
                                                    <?php echo format_price($p['MaxOriginalPrice']); ?>
                                                </span>

                                            <?php else: ?>
                                                <?php if ($p['MinDisplayPrice'] == $p['MaxOriginalPrice']): ?>
                                                    <span class="product-price">
                                                        <?php echo format_price($p['MinDisplayPrice']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="product-price">
                                                        <?php echo format_price($p['MinDisplayPrice']); ?>
                                                        -
                                                        <?php echo format_price($p['MaxOriginalPrice']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>

                                    </div>
                                </a>

                                <div class="product-card-footer">
                                    <a href="product-detail.php?id=<?php echo $p['ProductID']; ?>"
                                        class="account-btn-secondary product-btn">
                                        Xem chi tiết
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="home-empty-text">Chưa có sản phẩm nào.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <!-- ===== BLOG MỚI NHẤT ===== -->
        <section class="home-section home-section-blog">
            <div class="container">
                <div class="home-section-header">
                    <h2 class="home-section-title">📖 Blog Moonlit</h2>
                    <a href="blogs.php" class="home-section-link">Xem tất cả</a>
                </div>

                <div class="home-grid-3">
                    <?php if (!empty($latestBlogs)): ?>
                        <?php foreach ($latestBlogs as $b): ?>
                            <article class="blog-card">
                                <a href="blogs.php?blog_id=<?php echo $b['BlogID']; ?>" class="blog-card-link">

                                    <?php if (!empty($b['Thumbnail'])): ?>
                                        <div class="blog-card-image">
                                            <img src="<?php echo htmlspecialchars($b['Thumbnail']); ?>"
                                                alt="<?php echo htmlspecialchars($b['Title']); ?>">
                                        </div>
                                    <?php endif; ?>

                                    <div class="blog-card-body">
                                        <h3 class="blog-title">
                                            <?php echo htmlspecialchars($b['Title']); ?>
                                        </h3>

                                        <p class="blog-excerpt">
                                            <?php
                                            echo htmlspecialchars(
                                                mb_strimwidth(strip_tags($b['Content']), 0, 120, '...')
                                            );
                                            ?>
                                        </p>

                                        <span class="blog-date">
                                            <?php echo date('d/m/Y', strtotime($b['CreatedDate'])); ?>
                                        </span>
                                    </div>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="home-empty-text">Chưa có bài blog nào.</p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <!-- ===== CONTACT US ===== -->
        <section class="home-section home-section-contact">
            <div class="container">
                <div class="contact-cta-box">
                    <h2>Bạn cần hỗ trợ hoặc muốn hợp tác?</h2>
                    <p>
                        Moonlit luôn sẵn sàng lắng nghe mọi câu hỏi, góp ý
                        hoặc đề xuất từ bạn ✨
                    </p>

                    <a href="contact_us.php" class="account-btn-save">
                        Liên hệ với chúng tôi
                    </a>
                </div>
            </div>
        </section>

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




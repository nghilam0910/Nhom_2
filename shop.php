<?php
/**
 * MOONLIT STORE - SHOP PAGE
 * - Dùng bảng Product, Categories, Product_Categories, Publisher, Review
 * - Giá hiển thị = giá thấp nhất theo SKU đang active + SALE đang hiệu lực (PRODUCT_SALE)
 */

session_start();
require_once 'db_connect.php';

// Trạng thái đăng nhập
$isLoggedIn      = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';

// Lọc từ query string
$search     = trim($_GET['q'] ?? '');
$categoryId = $_GET['category'] ?? '';
$publisherId = trim($_GET['publisher'] ?? '');

// ============================
// Helper nav_active
// ============================
$currentPage = 'shop.php';
if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

// ============================
// Lấy danh sách danh mục
// ============================
$categories = [];
try {
    $cateStmt   = $pdo->query("SELECT CategoryID, CategoryName FROM Categories ORDER BY CategoryName");
    $categories = $cateStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ============================
// Lấy danh sách nhà xuất bản
// ============================
$publishers = [];
try {
    $pubStmt = $pdo->query("SELECT PublisherID, PublisherName FROM Publisher ORDER BY PublisherName");
    $publishers = $pubStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ============================
// Lấy danh sách sản phẩm
// ============================
$error_message = '';
$products      = [];

try {
    $params = [];

    // NOTE:
    // - SKU không nằm trong Product (schema mới)
    // - Sale nằm ở PRODUCT_SALE theo SKUID (có thể nhiều dòng), nên phải gom MIN(DiscountedPrice) theo SKUID trong khoảng thời gian hiệu lực
    $sql = "
SELECT
  p.ProductID,
  p.ProductName,
  p.PublisherID,
    p.CreatedDate,
  p.ImageUrl,
  CASE WHEN p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0 THEN 1 ELSE 0 END AS HasImage,

  CASE WHEN p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0 THEN 1 ELSE 0 END AS HasImage,

  x.MinFinalPrice,
  x.MinOriginalPrice,
  x.CheapestSKUID,
  CASE WHEN x.MinFinalPrice < x.MinOriginalPrice THEN 1 ELSE 0 END AS IsOnSale

FROM Product p

JOIN (
  SELECT
    s.ProductID,

    -- giá hiển thị nhỏ nhất (sale đang chạy thì lấy sale, không thì lấy SellPrice)
    MIN(
      CASE
        WHEN psa.DiscountedPrice IS NOT NULL THEN psa.DiscountedPrice
        ELSE s.SellPrice
      END
    ) AS MinFinalPrice,

    -- SKUID rẻ nhất (theo FinalPrice)
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        s.SKUID ORDER BY
          CASE
            WHEN psa.DiscountedPrice IS NOT NULL THEN psa.DiscountedPrice
            ELSE s.SellPrice
          END ASC,
          s.SKUID ASC
        SEPARATOR ','
      ),
      ',', 1
    ) AS CheapestSKUID,

    -- Giá gốc tương ứng với SKUID rẻ nhất (SellPrice)
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        s.SellPrice ORDER BY
          CASE
            WHEN psa.DiscountedPrice IS NOT NULL THEN psa.DiscountedPrice
            ELSE s.SellPrice
          END ASC,
          s.SKUID ASC
        SEPARATOR ','
      ),
      ',', 1
    ) AS MinOriginalPrice

  FROM SKU s
  LEFT JOIN (
    SELECT
      ps.SKUID,
      MIN(ps.DiscountedPrice) AS DiscountedPrice
    FROM PRODUCT_SALE ps
    WHERE ps.StartDate <= NOW()
      AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())
    GROUP BY ps.SKUID
  ) psa ON psa.SKUID = s.SKUID
  WHERE s.Status = 1
  GROUP BY s.ProductID
) x ON x.ProductID = p.ProductID

WHERE 1=1
  AND (p.Status = 1 OR p.Status IS NULL)
";

    // Lọc nhà xuất bản
    if ($publisherId !== '') {
        $sql .= " AND p.PublisherID = :publisher";
        $params[':publisher'] = $publisherId;
    }

    // Tìm theo tên sách
    if ($search !== '') {
        $sql .= " AND p.ProductName LIKE :search";
        $params[':search'] = '%' . $search . '%';
    }

    // Lọc theo danh mục
    if ($categoryId !== '') {
        $sql .= "
            AND EXISTS (
                SELECT 1
                FROM Product_Categories pc
                WHERE pc.ProductID = p.ProductID
                  AND pc.CategoryID = :categoryId
            )
        ";
        $params[':categoryId'] = $categoryId;
    }

    // Lọc giá (theo giá hiển thị = MinFinalPrice)
    if (!empty($_GET['min_price'])) {
        $sql .= " AND x.MinFinalPrice >= :min_price";
        $params[':min_price'] = (float)$_GET['min_price'];
    }
    if (!empty($_GET['max_price'])) {
        $sql .= " AND x.MinFinalPrice <= :max_price";
        $params[':max_price'] = (float)$_GET['max_price'];
    }

    // Lọc khuyến mãi (dựa vào SKU rẻ nhất)
    if (isset($_GET['sale']) && $_GET['sale'] !== '') {
        if ($_GET['sale'] == '1') $sql .= " AND x.MinFinalPrice < x.MinOriginalPrice";
        if ($_GET['sale'] == '0') $sql .= " AND x.MinFinalPrice >= x.MinOriginalPrice";
    }

    // Lọc theo rating
    if (!empty($_GET['rating'])) {
        $sql .= "
            AND (
                SELECT IFNULL(AVG(r.Rating), 0)
                FROM Review r
                WHERE r.ProductID = p.ProductID
            ) >= :rating
        ";
        $params[':rating'] = (int)$_GET['rating'];
    }

    $sql .= " ORDER BY p.CreatedDate DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error_message = 'Không thể tải danh sách sản phẩm: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cửa hàng - Moonlit Store</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
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

<main class="account-main shop-main">
    <div class="container">

        <section class="shop-header">
            <h1 class="account-section-title">Cửa hàng Moonlit</h1>
            <p class="account-section-subtitle">
                Chọn một cuốn sách, pha tách trà, phần còn lại để Moonlit lo ✨
            </p>
        </section>

        <section class="shop-filter-section">
            <div class="account-card">
                <form method="GET" class="shop-filter-form">
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">

                    <div class="shop-filter-bar">

                        <div class="shop-filter-item">
                            <label class="account-label">Danh mục</label>
                            <select name="category" class="account-input">
                                <option value="">Tất cả</option>
                                <?php foreach ($categories as $cate): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($cate['CategoryID']); ?>"
                                        <?php echo ($categoryId == $cate['CategoryID']) ? 'selected' : ''; ?>
                                    >
                                        <?php echo htmlspecialchars($cate['CategoryName']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        

                        <div class="shop-filter-item">
                            <label class="account-label">Giá từ</label>
                            <input type="number" name="min_price" class="account-input"
                                value="<?php echo htmlspecialchars($_GET['min_price'] ?? ''); ?>">
                        </div>

                        <div class="shop-filter-item">
                            <label class="account-label">Giá đến</label>
                            <input type="number" name="max_price" class="account-input"
                                value="<?php echo htmlspecialchars($_GET['max_price'] ?? ''); ?>">
                        </div>

                        <div class="shop-filter-item">
                            <label class="account-label">Khuyến mãi</label>
                            <select name="sale" class="account-input">
                                <option value="">Tất cả</option>
                                <option value="1" <?php echo (($_GET['sale'] ?? '') === '1') ? 'selected' : ''; ?>>Có</option>
                                <option value="0" <?php echo (($_GET['sale'] ?? '') === '0') ? 'selected' : ''; ?>>Không</option>
                            </select>
                        </div>

                        <div class="shop-filter-item">
                            <label class="account-label">Đánh giá</label>
                            <select name="rating" class="account-input">
                                <option value="">Tất cả</option>
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <option value="<?php echo $i; ?>"
                                        <?php echo (isset($_GET['rating']) && $_GET['rating'] == $i) ? 'selected' : ''; ?>>
                                        <?php echo str_repeat('★', $i) . str_repeat('☆', 5 - $i); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="shop-filter-item shop-filter-actions">
                            <button type="submit" class="account-btn-save shop-filter-btn">Lọc</button>
                            <a href="shop.php" class="account-btn-secondary shop-filter-btn">Xóa lọc</a>
                        </div>

                    </div>
                </form>
            </div>
        </section>

        <?php if (!empty($error_message)): ?>
            <div class="account-alert account-alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <section class="shop-products">
            <div class="shop-grid">

                <?php if (empty($products)): ?>
                    <div class="shop-grid-empty">
                        <div class="account-empty-state">
                            <p class="account-empty-text">
                                Hiện chưa có sản phẩm nào phù hợp với bộ lọc. Thử điều chỉnh lại nha!
                            </p>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <article class="shop-grid-item">
                            <div class="shop-product-card">

                                <div class="shop-product-image">
                                    <?php
                                        $imgSrc = '';

                                        // 1) Ưu tiên link ảnh
                                        if (!empty($product['ImageUrl'])) {
                                            $imgSrc = $product['ImageUrl'];
                                        }
                                        // 2) Fallback: ảnh BLOB cũ
                                        elseif (!empty($product['HasImage'])) {
                                            $imgSrc = "product-image.php?id=" . urlencode($product['ProductID']);
                                        }
                                    ?>

                                    <?php if ($imgSrc !== ''): ?>
                                        <img
                                            src="<?php echo htmlspecialchars($imgSrc); ?>"
                                            alt="<?php echo htmlspecialchars($product['ProductName']); ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <span class="shop-product-image-placeholder">Moonlit</span>
                                    <?php endif; ?>
                                </div>


                                <h2 class="shop-product-title" title="<?php echo htmlspecialchars($product['ProductName']); ?>">
                                    <?php echo htmlspecialchars($product['ProductName']); ?>
                                </h2>

                                <div class="shop-product-price-row">
                                    <?php if (!empty($product['IsOnSale'])): ?>
                                        <span class="shop-product-price-current">
                                            <?php echo number_format((float)$product['MinFinalPrice'], 0, ',', '.'); ?> đ
                                        </span>
                                        <span class="shop-product-price-old">
                                            <?php echo number_format((float)$product['MinOriginalPrice'], 0, ',', '.'); ?> đ
                                        </span>
                                    <?php else: ?>
                                        <span class="shop-product-price-current">
                                            <?php echo number_format((float)$product['MinFinalPrice'], 0, ',', '.'); ?> đ
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="shop-product-actions">
                                    <a href="product-detail.php?id=<?php echo urlencode($product['ProductID']); ?>"
                                       class="account-btn-secondary shop-btn">
                                        Chi tiết
                                    </a>

                                    <a href="product-detail.php?id=<?php echo urlencode($product['ProductID']); ?>#variants"
                                       class="account-btn-save shop-btn">
                                        Chọn phiên bản
                                    </a>
                                </div>

                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>

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

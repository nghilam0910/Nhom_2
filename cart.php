<?php
/**
 * MOONLIT STORE - CART PAGE (DB NEW - SALE FROM PRODUCT_SALE)
 */

session_start();
require_once 'db_connect.php';

/* ================= AUTH ================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$isLoggedIn = true;
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = 'cart.php';

/* ================= NAV ================= */
if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string
    {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

/* ================= REMOVE ITEM ================= */
if (isset($_GET['remove'])) {
    $pdo->prepare("
        DELETE ci FROM Cart_Items ci
        JOIN Cart c ON ci.CartID = c.CartID
        WHERE ci.CartItemID = :cid AND c.UserID = :uid
    ")->execute([
                ':cid' => $_GET['remove'],
                ':uid' => $userId
            ]);
    header('Location: cart.php');
    exit;
}

/* ================= CLEAR CART ================= */
if (isset($_GET['clear']) && $_GET['clear'] == 1) {
    $pdo->prepare("
        DELETE ci FROM Cart_Items ci
        JOIN Cart c ON ci.CartID = c.CartID
        WHERE c.UserID = :uid
    ")->execute([':uid' => $userId]);
    header('Location: cart.php');
    exit;
}

/* ================= UPDATE QTY ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qty'])) {
    foreach ($_POST['qty'] as $cartItemId => $qty) {
        $qty = max(1, (int) $qty);

        // Lấy giá hiện tại của SKU
        $priceStmt = $pdo->prepare("
            SELECT
                s.SellPrice AS UnitPrice,
                CASE
                    WHEN ps.DiscountedPrice IS NOT NULL
                     AND ps.StartDate <= NOW()
                     AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())
                    THEN ps.DiscountedPrice
                    ELSE s.SellPrice
                END AS FinalPrice
            FROM Cart_Items ci
            JOIN SKU s ON ci.SKU_ID = s.SKUID
            LEFT JOIN PRODUCT_SALE ps ON ps.SKUID = s.SKUID
            WHERE ci.CartItemID = :cid
            ORDER BY ps.DiscountedPrice ASC
            LIMIT 1
        ");
        $priceStmt->execute([':cid' => $cartItemId]);
        $price = $priceStmt->fetch(PDO::FETCH_ASSOC);

        if ($price) {
                $unit  = (float)$price['UnitPrice'];
                $final = (float)$price['FinalPrice'];
                $total = $qty * $final;

                $pdo->prepare("
                    UPDATE Cart_Items
                    SET Quantity = :q,
                        UnitPrice = :unit,
                        DiscountedPrice = :final,
                        TotalPrice = :total
                    WHERE CartItemID = :cid
                ")->execute([
                    ':q'     => $qty,
                    ':unit'  => $unit,
                    ':final' => $final,
                    ':total' => $total,
                    ':cid'   => $cartItemId
                ]);
            }

    }
    header('Location: cart.php');
    exit;
}

/* ================= LOAD CART ================= */
$sql = "
    SELECT
        ci.CartItemID,
        ci.Quantity,
        ci.UnitPrice,
        ci.DiscountedPrice,
        ci.TotalPrice,

        s.Format,

        p.ProductID,
        p.ProductName,
        p.ImageUrl,
        (p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0) AS HasImage
    FROM Cart c
    JOIN Cart_Items ci ON c.CartID = ci.CartID
    JOIN SKU s ON ci.SKU_ID = s.SKUID
    JOIN Product p ON s.ProductID = p.ProductID
    WHERE c.UserID = :uid
    ORDER BY ci.CartItemID DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':uid' => $userId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= TOTAL ================= */
$cartTotal = 0;
$totalItems = 0;
foreach ($items as $i) {
    $cartTotal += (float) $i['TotalPrice'];
    $totalItems += (int) $i['Quantity'];
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Giỏ hàng - Moonlit Store</title>
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

    <!-- ================= MAIN ================= -->
    <main class="account-main cart-main">
        <div class="container">

            <section class="shop-header cart-header">
                <h1 class="account-section-title">Giỏ hàng Moonlit</h1>
                <p class="account-section-subtitle">
                    Kiểm tra lại món bạn chọn rồi “chốt đơn” nha ✨
                </p>
            </section>

            <?php if (empty($items)): ?>
                <div class="account-empty-state">
                    <p> Giỏ hàng của bạn đang trống</p>
                    <a href="shop.php" class="account-btn-save">Tiếp tục mua sắm</a>
                </div>
            <?php else: ?>

                <form method="POST">
                    <div class="cart-layout">

                        <section class="cart-items">
                            <div class="account-card cart-items-card">

                                <div class="cart-table-header">
                                    <div>Sản phẩm</div>
                                    <div>Đơn giá</div>
                                    <div>Số lượng</div>
                                    <div>Thành tiền</div>
                                    <div></div>
                                </div>

                                <div class="cart-list">
                                    <?php foreach ($items as $item): ?>
                                        <div class="cart-item">
                                            <div class="cart-item-info">
                                                <div class="cart-item-image">
                                                    <?php
                                                        $imgSrc = '';
                                                        if (!empty($item['ImageUrl'])) {
                                                            $imgSrc = $item['ImageUrl'];
                                                        } elseif (!empty($item['HasImage'])) {
                                                            $imgSrc = "product-image.php?id=" . urlencode($item['ProductID']);
                                                        }
                                                    ?>

                                                    <?php if ($imgSrc !== ''): ?>
                                                        <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="">
                                                    <?php else: ?>
                                                        <span class="shop-product-image-placeholder">Moonlit</span>
                                                    <?php endif; ?>
                                                </div>


                                                <div>
                                                    <div class="cart-item-title">
                                                        <?php echo htmlspecialchars($item['ProductName']); ?>
                                                    </div>
                                                    <div class="cart-item-sku">
                                                        <?php echo htmlspecialchars($item['Format']); ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="cart-item-price">
                                                <?php if ((float) $item['DiscountedPrice'] < (float) $item['UnitPrice']): ?>
                                                    <span class="cart-price-current">
                                                        <?php echo number_format($item['DiscountedPrice'], 0, ',', '.'); ?> đ
                                                    </span>
                                                    <span class="cart-price-old">
                                                        <?php echo number_format($item['UnitPrice'], 0, ',', '.'); ?> đ
                                                    </span>
                                                <?php else: ?>
                                                    <span class="cart-price-current">
                                                        <?php echo number_format($item['UnitPrice'], 0, ',', '.'); ?> đ
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="cart-item-qty">
                                                <input type="number" name="qty[<?php echo $item['CartItemID']; ?>]"
                                                    value="<?php echo $item['Quantity']; ?>" min="1"
                                                    class="account-input cart-qty-input">
                                            </div>

                                            <div class="cart-item-total">
                                                <?php echo number_format($item['TotalPrice'], 0, ',', '.'); ?> đ
                                            </div>

                                            <div class="cart-item-remove">
                                                <a href="cart.php?remove=<?php echo $item['CartItemID']; ?>"
                                                    onclick="return confirm('Xóa sản phẩm này khỏi giỏ hàng nha?');">
                                                    Xóa
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="cart-actions-row">
                                    <button type="submit" class="account-btn-secondary">Cập nhật giỏ hàng</button>
                                    <a href="cart.php?clear=1" onclick="return confirm('Xóa toàn bộ giỏ hàng luôn hả?');">
                                        Xóa hết
                                    </a>
                                </div>
                            </div>
                        </section>

                        <aside class="cart-summary">
                            <div class="account-card cart-summary-card">
                                <h3 class="cart-summary-title">Tóm tắt</h3>

                                <div class="cart-summary-row">
                                    <span>Số lượng</span>
                                    <span><?php echo $totalItems; ?></span>
                                </div>

                                <div class="cart-summary-row cart-summary-total">
                                    <span>Tổng</span>
                                    <span><?php echo number_format($cartTotal, 0, ',', '.'); ?> đ</span>
                                </div>

                                <div class="cart-summary-actions">
                                    <a href="checkout.php" class="account-btn-save cart-summary-btn">Thanh toán</a>
                                    <a href="shop.php" class="account-btn-secondary cart-summary-btn">Tiếp tục mua sắm
                                    </a>
                                </div>

                            </div>
                        </aside>

                    </div>
                </form>

            <?php endif; ?>
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

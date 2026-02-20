<?php

session_start();
require_once 'db_connect.php';

/* ===================== AUTH STATE ===================== */
$isLoggedIn      = isset($_SESSION['user_id']);
$currentUsername = $_SESSION['username'] ?? '';
$currentUserId   = $_SESSION['user_id'] ?? null;

$currentPage = 'product-detail.php';
if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

/* ===================== INPUT ===================== */
$productKey = trim($_GET['id'] ?? '');
$skuKey     = trim($_GET['sku'] ?? '');

if ($productKey === '' && $skuKey === '') {
    header('Location: shop.php');
    exit;
}

/* ===================== LOAD PRODUCT (resolve ProductID) ===================== */
$product = null;
$error_message = '';

try {
    $params = [];

    if ($productKey !== '') {
        $where = "p.ProductID = :pid";
        $params[':pid'] = $productKey;
    } else {
        $where = "s.SKUID = :skuid";
        $params[':skuid'] = $skuKey;
    }

        $sqlProduct = "
        SELECT
            p.ProductID,
            p.ProductName,
            p.Description,
            p.CreatedDate,
            p.ImageUrl,
            (p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0) AS HasImage,
            pub.PublisherName,
            GROUP_CONCAT(DISTINCT c.CategoryName SEPARATOR ', ') AS Categories
        FROM Product p
        LEFT JOIN Publisher pub ON p.PublisherID = pub.PublisherID
        LEFT JOIN Product_Categories pc ON p.ProductID = pc.ProductID
        LEFT JOIN Categories c ON pc.CategoryID = c.CategoryID
        LEFT JOIN SKU s ON s.ProductID = p.ProductID
        WHERE $where
        GROUP BY p.ProductID
        LIMIT 1
    ";


    $stmt = $pdo->prepare($sqlProduct);
    $stmt->execute($params);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        $error_message = 'Sản phẩm không tồn tại hoặc đã bị ẩn.';
    }
} catch (Exception $e) {
    $error_message = 'Không thể tải thông tin sản phẩm: ' . $e->getMessage();
}

/* ===================== LOAD VARIANTS ===================== */
$variants = [];
$selectedSku = null;

if ($product && empty($error_message)) {
    try {
        $skuSql = "
            SELECT
                s.SKUID,
                s.Format,
                s.ISBN,
                s.Stock,
                s.SellPrice AS OriginalPrice,
                CASE
                    WHEN psa.DiscountedPrice IS NOT NULL THEN psa.DiscountedPrice
                    ELSE s.SellPrice
                END AS FinalPrice
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
            WHERE s.ProductID = :pid
              AND s.Status = 1
            ORDER BY FinalPrice ASC, s.SKUID ASC
        ";

        $skuStmt = $pdo->prepare($skuSql);
        $skuStmt->execute([':pid' => $product['ProductID']]);
        $variants = $skuStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($variants)) {
            if ($skuKey !== '') {
                foreach ($variants as $v) {
                    if ($v['SKUID'] === $skuKey) { $selectedSku = $v; break; }
                }
            }
            if (!$selectedSku) $selectedSku = $variants[0];
        }
    } catch (Exception $e) {
        $error_message = 'Không thể tải phiên bản (SKU): ' . $e->getMessage();
    }
}

/* ===================== CAN REVIEW? (only when order status allowed) ===================== */
$canReview = false;

if ($isLoggedIn && $product) {
    try {
        $allowedStatuses = ["Đã nhận", "Trả hàng", "Đã hoàn tiền"];

        // Tìm xem user có đơn nào thuộc các trạng thái trên + có chứa SKU của product này không
        $canReviewSql = "
            SELECT 1
            FROM `Order` o
            JOIN Order_Items oi ON oi.OrderID = o.OrderID
            JOIN SKU s ON s.SKUID = oi.SKU_ID
            WHERE o.UserID = :uid
              AND s.ProductID = :pid
              AND o.Status IN ('Đã nhận', 'Trả hàng', 'Đã hoàn tiền')
            LIMIT 1
        ";

        $st = $pdo->prepare($canReviewSql);
        $st->execute([
            ':uid' => $currentUserId,
            ':pid' => $product['ProductID'],
        ]);

        $canReview = (bool)$st->fetchColumn();
    } catch (Exception $e) {
        $canReview = false;
    }
}


/* ===================== HANDLE REVIEW SUBMIT (after product loaded) ===================== */
$review_error   = '';
$review_success = '';

if (isset($_GET['review']) && $_GET['review'] === 'success') {
    $review_success = 'Cảm ơn bạn đã chia sẻ cảm nhận 💛';
}

if ($product && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_review') {

    if (!$isLoggedIn) {
        $review_error = 'Bạn cần đăng nhập để gửi đánh giá.';
    } else {

        // 1) Check quyền review: đã mua + status hợp lệ
        $allowedStatuses = ['Đã nhận', 'Trả hàng', 'Đã hoàn tiền'];

        try {
            $placeholders = implode(',', array_fill(0, count($allowedStatuses), '?'));

            $sqlCanReview = "
                SELECT COUNT(*) 
                FROM `Order` o
                JOIN Order_Items oi ON oi.OrderID = o.OrderID
                JOIN SKU s ON s.SKUID = oi.SKU_ID
                WHERE o.UserID = ?
                  AND s.ProductID = ?
                  AND o.Status IN ($placeholders)
                LIMIT 1
            ";

            $bind = array_merge([$currentUserId, $product['ProductID']], $allowedStatuses);

            $stmtCan = $pdo->prepare($sqlCanReview);
            $stmtCan->execute($bind);
            $canReview = (int)$stmtCan->fetchColumn() > 0;

            if (!$canReview) {
                $review_error = 'Bạn chỉ có thể đánh giá khi đơn hàng của bạn ở trạng thái: Đã nhận / Trả hàng / Đã hoàn tiền.';
            }
        } catch (Exception $e) {
            $review_error = 'Không thể kiểm tra quyền đánh giá. Thử lại sau nha.';
        }

        // 2) Nếu đủ điều kiện thì validate + insert
        if ($review_error === '') {
            $rating  = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
            $comment = trim($_POST['comment'] ?? '');

            if ($rating < 1 || $rating > 5) {
                $review_error = 'Vui lòng chọn số sao từ 1 đến 5.';
            } elseif ($comment === '') {
                $review_error = 'Bạn hãy viết vài dòng cảm nhận nha.';
            } else {
                try {
                    // (khuyến nghị) chặn 1 user review 1 product nhiều lần
                    $stmtDup = $pdo->prepare("SELECT 1 FROM Review WHERE ProductID = ? AND UserID = ? LIMIT 1");
                    $stmtDup->execute([$product['ProductID'], $currentUserId]);
                    if ($stmtDup->fetchColumn()) {
                        $review_error = 'Bạn đã đánh giá sản phẩm này rồi.';
                    } else {
                        $sqlInsert = "
                            INSERT INTO Review (ProductID, UserID, Rating, Comment, CreatedDate)
                            VALUES (:productId, :userId, :rating, :comment, :createdDate)
                        ";
                        $stmtIns = $pdo->prepare($sqlInsert);
                        $stmtIns->execute([
                            ':productId'   => $product['ProductID'],
                            ':userId'      => $currentUserId,
                            ':rating'      => $rating,
                            ':comment'     => $comment,
                            ':createdDate' => date('Y-m-d H:i:s'),
                        ]);

                        /* --- BẮT ĐẦU PHẦN CỘNG ĐIỂM THƯỞNG --- */
                        $pointsToGive = 5; // Số điểm tặng cho mỗi đánh giá
                        $reason = "Thưởng đánh giá sản phẩm: " . $product['ProductName'];

                        // Cập nhật tổng điểm trong bảng User_Account
                        $updatePointsSql = "UPDATE User_Account SET Points = Points + :points WHERE UserID = :userId";
                        $pdo->prepare($updatePointsSql)->execute([
                            ':points' => $pointsToGive,
                            ':userId' => $currentUserId
                        ]);

                        // Lưu lịch sử cộng điểm vào bảng Point_History
                        $historySql = "INSERT INTO Point_History (UserID, PointChange, Reason, CreatedDate) 
                                    VALUES (:userId, :points, :reason, NOW())";
                        $pdo->prepare($historySql)->execute([
                            ':userId' => $currentUserId,
                            ':points' => $pointsToGive,
                            ':reason' => $reason
                        ]);
                        /* --- KẾT THÚC PHẦN CỘNG ĐIỂM THƯỞNG --- */

                        header('Location: product-detail.php?id=' . urlencode($product['ProductID']) . '&review=success');
                        exit;
                    }
                } catch (Exception $e) {
                    $review_error = 'Không thể lưu đánh giá. Thử lại sau nha.';
                }
            }
        }
    }
}


/* ===================== LOAD REVIEWS ===================== */
$reviews = [];
if ($product) {
    try {
        $reviewSql = "
            SELECT
                r.Rating,
                r.Comment,
                r.CreatedDate,
                COALESCE(u.Username, u.Email, 'Khách hàng') AS DisplayName
            FROM Review r
            LEFT JOIN User_Account u ON r.UserID = u.UserID
            WHERE r.ProductID = :productId
            ORDER BY r.CreatedDate DESC
            LIMIT 20
        ";
        $reviewStmt = $pdo->prepare($reviewSql);
        $reviewStmt->execute([':productId' => $product['ProductID']]);
        $reviews = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

/* ===================== RELATED PRODUCTS (min final price like shop) ===================== */
$relatedProducts = [];
if ($product) {
    try {
        $firstCategorySql = "
            SELECT pc.CategoryID
            FROM Product_Categories pc
            WHERE pc.ProductID = :id
            LIMIT 1
        ";
        $cateStmt = $pdo->prepare($firstCategorySql);
        $cateStmt->execute([':id' => $product['ProductID']]);
        $cateRow = $cateStmt->fetch(PDO::FETCH_ASSOC);

        if ($cateRow) {
            $cateId = $cateRow['CategoryID'];

            $relatedSql = "
                SELECT
                    p.ProductID,
                    p.ProductName,
                    p.ImageUrl,
                    CASE WHEN p.Image IS NOT NULL AND OCTET_LENGTH(p.Image) > 0 THEN 1 ELSE 0 END AS HasImage,

                    x.MinFinalPrice,
                    x.MinOriginalPrice,
                    CASE WHEN x.MinFinalPrice < x.MinOriginalPrice THEN 1 ELSE 0 END AS IsOnSale

                FROM Product p
                JOIN Product_Categories pc ON p.ProductID = pc.ProductID

                JOIN (
                  SELECT
                    s.ProductID,
                    MIN(
                      CASE
                        WHEN ps.DiscountedPrice IS NOT NULL
                         AND ps.StartDate <= NOW()
                         AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())
                        THEN ps.DiscountedPrice
                        ELSE s.SellPrice
                      END
                    ) AS MinFinalPrice,

                    SUBSTRING_INDEX(
                      GROUP_CONCAT(
                        s.SellPrice ORDER BY
                          CASE
                            WHEN ps.DiscountedPrice IS NOT NULL
                             AND ps.StartDate <= NOW()
                             AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())
                            THEN ps.DiscountedPrice
                            ELSE s.SellPrice
                          END ASC,
                          s.SKUID ASC
                        SEPARATOR ','
                      ),
                      ',', 1
                    ) AS MinOriginalPrice

                  FROM SKU s
                  LEFT JOIN PRODUCT_SALE ps ON ps.SKUID = s.SKUID
                  WHERE s.Status = 1
                  GROUP BY s.ProductID
                ) x ON x.ProductID = p.ProductID

                WHERE pc.CategoryID = :cateId
                  AND p.ProductID <> :productId
                ORDER BY p.CreatedDate DESC
                LIMIT 4
            ";

            $relStmt = $pdo->prepare($relatedSql);
            $relStmt->execute([
                ':cateId'    => $cateId,
                ':productId' => $product['ProductID']
            ]);
            $relatedProducts = $relStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $product ? htmlspecialchars($product['ProductName']) . ' - Moonlit Store' : 'Sản phẩm - Moonlit Store'; ?></title>

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

<main class="account-main">
    <div class="container">

        <div class="mb-3 small">
            <a href="shop.php" class="text-decoration-none" style="color: var(--color-deep-blue);">Cửa hàng</a>
            <span class="text-secondary"> / </span>
            <span><?php echo $product ? htmlspecialchars($product['ProductName']) : 'Sản phẩm'; ?></span>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger account-alert">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php else: ?>

            <div class="account-card mb-3">
                <div class="product-detail-layout">

                    <!-- IMAGE -->
                    <div class="product-gallery">
                        <div class="product-gallery-main">
                            <div class="shop-product-image">
                                <?php
                                    $imgSrc = '';

                                    // ưu tiên link ảnh
                                    if (!empty($product['ImageUrl'])) {
                                        $imgSrc = $product['ImageUrl'];
                                    }
                                    // fallback BLOB cũ
                                    elseif (!empty($product['HasImage'])) {
                                        $imgSrc = "product-image.php?id=" . urlencode($product['ProductID']);
                                    }
                                ?>

                                <?php if ($imgSrc !== ''): ?>
                                    <img
                                        id="product-main-image"
                                        src="<?php echo htmlspecialchars($imgSrc); ?>"
                                        alt="<?php echo htmlspecialchars($product['ProductName']); ?>"
                                    >
                                <?php else: ?>
                                    <span class="shop-product-image-placeholder">Moonlit</span>
                                <?php endif; ?>
                            </div>

                        </div>
                    </div>

                    <!-- INFO -->
                    <div class="product-detail-info">
                        <h1 class="account-section-title mb-2">
                            <?php echo htmlspecialchars($product['ProductName']); ?>
                        </h1>

                        <?php if (!empty($product['Categories'])): ?>
                            <p class="mb-1 small text-secondary">
                                Thể loại: <?php echo htmlspecialchars($product['Categories']); ?>
                            </p>
                        <?php endif; ?>

                        <?php if (!empty($product['PublisherName'])): ?>
                            <p class="mb-3 small text-secondary">
                                Nhà xuất bản: <?php echo htmlspecialchars($product['PublisherName']); ?>
                            </p>
                        <?php endif; ?>

                        <!-- PRICE (with old price strikethrough) -->
                        <div class="mb-3">
                            <?php if ($selectedSku): ?>
                                <?php
                                    $final = (float)$selectedSku['FinalPrice'];
                                    $orig  = (float)$selectedSku['OriginalPrice'];
                                ?>
                                <?php if ($final < $orig): ?>
                                    <span class="cart-price-current" style="font-size: 22px;">
                                        <?php echo number_format($final, 0, ',', '.'); ?> đ
                                    </span>
                                    <span class="cart-price-old" style="font-size: 16px; margin-left: 8px; text-decoration: line-through; opacity: .65;">
                                        <?php echo number_format($orig, 0, ',', '.'); ?> đ
                                    </span>
                                <?php else: ?>
                                    <span class="cart-price-current" style="font-size: 22px;">
                                        <?php echo number_format($final, 0, ',', '.'); ?> đ
                                    </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cart-price-current" style="font-size: 14px; color: var(--color-secondary);">
                                    Sản phẩm chưa có phiên bản (SKU).
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="product-description-box">
                            <?php if (!empty($product['Description'])): ?>
                                <p class="mb-0" style="font-size: 14px;">
                                    <?php echo nl2br(htmlspecialchars($product['Description'])); ?>
                                </p>
                            <?php else: ?>
                                <p class="mb-0" style="font-size: 14px;">Cuốn sách đang chờ Moonlit viết mô tả ✨</p>
                            <?php endif; ?>
                        </div>

                        <!-- VARIANTS -->
                        <?php if (!empty($variants)): ?>
                            <div id="variants" class="mb-2">
                                <label class="account-label mb-1">Chọn phiên bản</label>
                                <select class="account-input" onchange="location.href='product-detail.php?id=<?php echo urlencode($product['ProductID']); ?>&sku=' + encodeURIComponent(this.value)">
                                    <?php foreach ($variants as $v): ?>
                                        <?php
                                            $vFinal = (float)$v['FinalPrice'];
                                            $vOrig  = (float)$v['OriginalPrice'];
                                            $label  = ($v['Format'] ?: 'Phiên bản') . ' - ' . number_format($vFinal, 0, ',', '.') . 'đ';
                                            if ($vFinal < $vOrig) $label .= ' (sale)';
                                        ?>
                                        <option value="<?php echo htmlspecialchars($v['SKUID']); ?>"
                                            <?php echo ($selectedSku && $selectedSku['SKUID'] === $v['SKUID']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <?php if ($selectedSku && (int)$selectedSku['Stock'] <= 0): ?>
                                    <div class="small text-danger mt-2">Hết hàng phiên bản này rồi 🥲</div>
                                <?php else: ?>
                                    <div class="small text-secondary mt-2">
                                        Tồn kho: <?php echo (int)($selectedSku['Stock'] ?? 0); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- ADD TO CART -->
                        <form action="cart-add.php" method="GET" class="d-flex flex-wrap align-items-end gap-3 mt-3">
                            <input type="hidden" name="skuid" value="<?php echo htmlspecialchars($selectedSku['SKUID'] ?? ''); ?>">

                            <div>
                                <label for="qty" class="account-label mb-1">Số lượng</label>
                                <input type="number" id="qty" name="qty" min="1" value="1"
                                       class="account-input" style="max-width: 90px;"
                                       <?php echo ($selectedSku && (int)$selectedSku['Stock'] <= 0) ? 'disabled' : ''; ?>>
                            </div>

                            <div class="d-flex gap-2 mt-2">
                                <button type="submit" name="action" value="add_to_cart" class="account-btn-save"
                                    <?php echo ($selectedSku && (int)$selectedSku['Stock'] <= 0) ? 'disabled' : ''; ?>>
                                    Thêm vào giỏ
                                </button>
                                <button type="submit" name="action" value="buy_now" class="account-btn-secondary"
                                    <?php echo ($selectedSku && (int)$selectedSku['Stock'] <= 0) ? 'disabled' : ''; ?>>
                                    Mua ngay
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>

            <!-- REVIEWS -->
            <div class="account-card mb-3">
                <h2 class="account-card-title mb-3">Đánh giá từ khách hàng</h2>

                <?php if (!empty($review_error)): ?>
                    <div class="alert alert-danger account-alert"><?php echo htmlspecialchars($review_error); ?></div>
                <?php endif; ?>

                <?php if (!empty($review_success)): ?>
                    <div class="alert alert-success account-alert"><?php echo htmlspecialchars($review_success); ?></div>
                <?php endif; ?>

                <?php if ($isLoggedIn): ?>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="add_review">

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="account-label">Đánh giá của bạn</label>
                                <select name="rating" class="account-input" required>
                                    <option value="">Chọn số sao</option>
                                    <option value="5">★★★★★ - Tuyệt vời</option>
                                    <option value="4">★★★★☆ - Rất tốt</option>
                                    <option value="3">★★★☆☆ - Bình thường</option>
                                    <option value="2">★★☆☆☆ - Tạm ổn</option>
                                    <option value="1">★☆☆☆☆ - Chưa ưng</option>
                                </select>
                            </div>
                            <div class="col-md-9">
                                <label class="account-label">Nhận xét</label>
                                <textarea name="comment" rows="3" class="account-input"
                                          placeholder="Chia sẻ cảm nhận..." required></textarea>
                            </div>
                        </div>

                        <div class="mt-3 flex-end">
                            <button type="submit" class="account-btn-save">Gửi đánh giá</button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="mb-4" style="font-size: 14px;">
                        Bạn cần <a href="auth-login.php" style="color: var(--color-deep-blue);">đăng nhập</a> để viết đánh giá.
                    </p>
                <?php endif; ?>

                <?php if (empty($reviews)): ?>
                    <p class="mb-0" style="font-size: 14px; color: var(--color-secondary);">
                        Chưa có đánh giá nào. Viết cái đầu tiên đi ✨
                    </p>
                <?php else: ?>
                    <div class="account-orders-list">
                        <?php foreach ($reviews as $rev): ?>
                            <div class="account-order-card" style="padding: 16px;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong style="font-size: 14px;">
                                        <?php echo htmlspecialchars($rev['DisplayName'] ?? 'Khách hàng'); ?>
                                    </strong>
                                    <span class="small text-secondary">
                                        <?php if (!empty($rev['CreatedDate'])) echo date('d/m/Y', strtotime($rev['CreatedDate'])); ?>
                                    </span>
                                </div>

                                <div class="mb-1" style="color: #FFC107; font-size: 14px;">
                                    <?php
                                        $stars = (int)($rev['Rating'] ?? 0);
                                        for ($i = 1; $i <= 5; $i++) echo $i <= $stars ? '★' : '☆';
                                    ?>
                                </div>

                                <?php if (!empty($rev['Comment'])): ?>
                                    <p class="mb-0" style="font-size: 14px;">
                                        <?php echo nl2br(htmlspecialchars($rev['Comment'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RELATED -->
            <?php if (!empty($relatedProducts)): ?>
                <div class="account-card">
                    <h2 class="account-card-title mb-3">Có thể bà cũng thích</h2>

                    <div class="row g-3">
                        <?php foreach ($relatedProducts as $rel): ?>
                            <div class="col-6 col-md-3">
                                <div class="account-card h-100 d-flex flex-column" style="padding: 16px;">

                                    <div class="shop-product-image mb-2">
                                        <?php
                                            $relImg = '';
                                            if (!empty($rel['ImageUrl'])) {
                                                $relImg = $rel['ImageUrl'];
                                            } elseif (!empty($rel['HasImage'])) {
                                                $relImg = "product-image.php?id=" . urlencode($rel['ProductID']);
                                            }
                                        ?>

                                        <?php if ($relImg !== ''): ?>
                                            <img
                                                src="<?php echo htmlspecialchars($relImg); ?>"
                                                alt="<?php echo htmlspecialchars($rel['ProductName']); ?>"
                                            >
                                        <?php else: ?>
                                            <span class="shop-product-image-placeholder">Moonlit</span>
                                        <?php endif; ?>
                                    </div>


                                    <h3 class="account-order-item-name text-truncate mb-1"
                                        title="<?php echo htmlspecialchars($rel['ProductName']); ?>">
                                        <?php echo htmlspecialchars($rel['ProductName']); ?>
                                    </h3>

                                    <div class="shop-product-price-row mb-2">
                                        <?php if (!empty($rel['IsOnSale'])): ?>
                                            <span class="shop-product-price-current">
                                                <?php echo number_format((float)$rel['MinFinalPrice'], 0, ',', '.'); ?> đ
                                            </span>
                                            <span class="shop-product-price-old" style="text-decoration: line-through; opacity:.65; margin-left:6px;">
                                                <?php echo number_format((float)$rel['MinOriginalPrice'], 0, ',', '.'); ?> đ
                                            </span>
                                        <?php else: ?>
                                            <span class="shop-product-price-current">
                                                <?php echo number_format((float)$rel['MinFinalPrice'], 0, ',', '.'); ?> đ
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="mt-auto d-grid gap-1">
                                        <a href="product-detail.php?id=<?php echo urlencode($rel['ProductID']); ?>"
                                           class="account-btn-secondary text-center text-decoration-none">
                                            Xem chi tiết
                                        </a>
                                        <a href="product-detail.php?id=<?php echo urlencode($rel['ProductID']); ?>#variants"
                                           class="account-btn-save text-center text-decoration-none">
                                            Chọn phiên bản
                                        </a>
                                    </div>

                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


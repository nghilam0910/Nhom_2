<?php
session_start();
require_once 'db_connect.php';

/* ================= AUTH ================= */
if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$currentUsername = $_SESSION['username'] ?? null; // nếu m có lưu username

/* ================= INPUT ================= */
$skuid  = trim($_GET['skuid'] ?? '');
$qty    = max(1, (int)($_GET['qty'] ?? 1));
$action = $_GET['action'] ?? 'add_to_cart';

if ($skuid === '') {
    header('Location: shop.php');
    exit;
}

/* ================= ENSURE USERID MATCH FK (User_Account.UserID) ================= */
/**
 * Cart.UserID FK -> User_Account.UserID (thường dạng U00001)
 * Nếu session đang lưu "1" hoặc dạng khác => phải đổi về đúng UserID.
 */
function resolve_userid(PDO $pdo, $sessionUserId, ?string $sessionUsername = null): ?string
{
    // 1) Nếu session đã là đúng UserID (Uxxxxx) và tồn tại
    $uid = (string)$sessionUserId;

    $check = $pdo->prepare("SELECT UserID FROM User_Account WHERE UserID = :uid LIMIT 1");
    $check->execute([':uid' => $uid]);
    $found = $check->fetchColumn();
    if ($found) return $found;

    // 2) Nếu sessionUserId là số (AutoID) => thử map (nếu bảng có cột AutoID)
    if (ctype_digit($uid)) {
        try {
            $stmt = $pdo->prepare("SELECT UserID FROM User_Account WHERE AutoID = :aid LIMIT 1");
            $stmt->execute([':aid' => (int)$uid]);
            $found = $stmt->fetchColumn();
            if ($found) return $found;
        } catch (Exception $e) {
            // nếu bảng không có AutoID thì bỏ qua
        }
    }

    // 3) Thử map bằng username/fullname (nếu m lưu session username)
    if ($sessionUsername) {
        $stmt = $pdo->prepare("SELECT UserID FROM User_Account WHERE FullName = :name LIMIT 1");
        $stmt->execute([':name' => $sessionUsername]);
        $found = $stmt->fetchColumn();
        if ($found) return $found;
    }

    return null;
}

$resolvedUserId = resolve_userid($pdo, $userId, $currentUsername);
if (!$resolvedUserId) {
    // session sai / user không tồn tại => logout cho khỏi lỗi FK
    session_destroy();
    header('Location: auth-login.php?err=session_invalid');
    exit;
}
$userId = $resolvedUserId;

/* ================= CHECK SKU ================= */
$skuStmt = $pdo->prepare("
    SELECT
        s.SKUID,
        s.ProductID,
        s.SellPrice
    FROM SKU s
    WHERE s.SKUID = :skuid
      AND s.Status = 1
    LIMIT 1
");
$skuStmt->execute([':skuid' => $skuid]);
$sku = $skuStmt->fetch(PDO::FETCH_ASSOC);

if (!$sku) {
    header('Location: shop.php?err=sku_not_found');
    exit;
}

/* ================= GET FINAL PRICE (SALE) ================= */
$priceStmt = $pdo->prepare("
    SELECT
        s.SellPrice AS UnitPrice,
        MIN(ps.DiscountedPrice) AS SalePrice
    FROM SKU s
    LEFT JOIN PRODUCT_SALE ps
        ON ps.SKUID = s.SKUID
       AND ps.StartDate <= NOW()
       AND (ps.EndDate IS NULL OR ps.EndDate >= NOW())
    WHERE s.SKUID = :skuid
    GROUP BY s.SellPrice
");
$priceStmt->execute([':skuid' => $skuid]);
$priceRow = $priceStmt->fetch(PDO::FETCH_ASSOC);

$unitPrice  = (float)($priceRow['UnitPrice'] ?? 0);
$finalPrice = ($priceRow && $priceRow['SalePrice'] !== null)
    ? (float)$priceRow['SalePrice']
    : $unitPrice;

/* ================= GET / CREATE CART ================= */
$cartStmt = $pdo->prepare("
    SELECT CartID
    FROM Cart
    WHERE UserID = :uid
    LIMIT 1
");
$cartStmt->execute([':uid' => $userId]);
$cartId = $cartStmt->fetchColumn();

if (!$cartId) {
    // tạo CartID kiểu random 6 ký tự, nếu trùng thì tạo lại
    do {
        $cartId = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $exists = $pdo->prepare("SELECT 1 FROM Cart WHERE CartID = :cid LIMIT 1");
        $exists->execute([':cid' => $cartId]);
        $isDup = (bool)$exists->fetchColumn();
    } while ($isDup);

    $pdo->prepare("
        INSERT INTO Cart (CartID, UserID)
        VALUES (:cid, :uid)
    ")->execute([
        ':cid' => $cartId,
        ':uid' => $userId
    ]);
}

/* ================= CHECK ITEM EXIST ================= */
$checkStmt = $pdo->prepare("
    SELECT CartItemID, Quantity
    FROM Cart_Items
    WHERE CartID = :cid AND SKU_ID = :skuid
    LIMIT 1
");
$checkStmt->execute([
    ':cid'   => $cartId,
    ':skuid' => $skuid
]);
$item = $checkStmt->fetch(PDO::FETCH_ASSOC);

/* ================= INSERT / UPDATE ================= */
if ($item) {
    $newQty   = (int)$item['Quantity'] + $qty;
    $newTotal = $finalPrice * $newQty;

    $pdo->prepare("
        UPDATE Cart_Items
        SET
            Quantity = :q,
            UnitPrice = :u,
            DiscountedPrice = :d,
            TotalPrice = :t
        WHERE CartItemID = :id
    ")->execute([
        ':q'  => $newQty,
        ':u'  => $unitPrice,
        ':d'  => $finalPrice,
        ':t'  => $newTotal,
        ':id' => $item['CartItemID']
    ]);
} else {
    $total = $finalPrice * $qty;

    $pdo->prepare("
        INSERT INTO Cart_Items
            (CartID, SKU_ID, Quantity, UnitPrice, DiscountedPrice, TotalPrice)
        VALUES
            (:cid, :skuid, :q, :u, :d, :t)
    ")->execute([
        ':cid'   => $cartId,
        ':skuid' => $skuid,
        ':q'     => $qty,
        ':u'     => $unitPrice,
        ':d'     => $finalPrice,
        ':t'     => $total
    ]);
}

/* ================= REDIRECT ================= */
if ($action === 'buy_now') {
    header('Location: cart.php?buy_now=1');
    exit;
}

header('Location: cart.php?added=1');
exit;

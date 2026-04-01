<?php
/**
 * MOONLIT STORE - CHECKOUT PAGE (DB CART + VOUCHER + CARRIER)
 */

session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
require_once 'db_connect.php';

/* =========================
   AUTH / USER
========================= */
$userId = $_SESSION['user_id'] ?? null;
$isLoggedIn = $userId !== null;

if (!$userId) {
    header('Location: auth-login.php');
    exit;
}

$currentUsername = $_SESSION['username'] ?? '';
$currentPage     = 'checkout.php';

if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

/* =========================
   LOAD USER PROFILE (prefill address)
========================= */
$userProfile = [
  'FullName' => $currentUsername,
  'Email' => '',
  'Phone' => '',
  'City' => '',
  'District' => '',
  'Ward' => '',
  'Street' => '',
  'HouseNumber' => '',
  'Points' => 0,
];


try {
    
    $uStmt = $pdo->prepare("
    SELECT
        FullName, Email, Phone,
        City, District, Ward, Street, HouseNumber,
        Points
    FROM User_Account
    WHERE UserID = :uid
    LIMIT 1
    ");

    $uStmt->execute([':uid' => $userId]);
    $row = $uStmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $userProfile = array_merge($userProfile, array_filter($row, fn($v) => $v !== null));
    }
} catch (Exception $e) {
    
}

/* =========================
   LOAD CART ITEMS FROM DB
========================= */
$products   = [];
$error_msg  = '';
$subTotal   = 0;
$totalItems = 0;

try {
    $sql = "
        SELECT
            ci.CartItemID,
            ci.Quantity,
            ci.UnitPrice,
            ci.DiscountedPrice,
            ci.TotalPrice,

            s.SKUID,
            s.Format,

            p.ProductID,
            p.ProductName,
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
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as $p) {
        $subTotal   += (float)$p['TotalPrice'];
        $totalItems += (int)$p['Quantity'];
    }
} catch (Exception $e) {
    $error_msg = 'Không thể tải dữ liệu giỏ hàng: ' . $e->getMessage();
}

/* =========================
   LOAD CARRIERS
========================= */
$carriers = [];
try {
    $cStmt = $pdo->query("
        SELECT CarrierID, CarrierName, ShippingPrice
        FROM Carrier
        ORDER BY ShippingPrice ASC, CarrierName ASC
    ");
    $carriers = $cStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $carriers = [];
}

/* =========================
   HELPERS
========================= */
function now_in_range(?string $start, ?string $end): bool {
    $now = time();
    if ($start) {
        $s = strtotime($start);
        if ($s !== false && $now < $s) return false;
    }
    if ($end) {
        $e = strtotime($end);
        if ($e !== false && $now > $e) return false;
    }
    return true;
}

function gen_id6(): string {
    return strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function rank_from_points(int $points): string {
    if ($points >= 2000) return 'Bạch kim';
    if ($points >= 1000) return 'Vàng';
    if ($points >= 500)  return 'Bạc';
    if ($points >= 200)  return 'Đồng';
    return 'Chung';
}
$userPoints = (int)($userProfile['Points'] ?? 0);
$customerRank = rank_from_points($userPoints);

function rank_level(string $rank): int {
    $rank = trim(mb_strtolower($rank));
    return match ($rank) {
        'bạch kim' => 5,
        'vang', 'vàng' => 4,
        'bac', 'bạc' => 3,
        'dong', 'đồng' => 2,
        default => 1, // Chung
    };
}

function user_can_use_rank(string $userRank, ?string $requireRank): bool {
    // Voucher RankRequirement NULL hoặc rỗng => coi như Chung
    $req = trim((string)$requireRank);
    if ($req === '') $req = 'Chung';
    return rank_level($userRank) >= rank_level($req);
}

function gen_user_voucher_id(PDO $pdo): string {
    // Lấy số lớn nhất đang có dạng V00001...
    // SUBSTRING(ID,2) lấy phần số sau chữ V
    $sql = "
        SELECT MAX(CAST(SUBSTRING(ID, 2) AS UNSIGNED)) AS max_num
        FROM User_Voucher
        WHERE ID LIKE 'V%'
    ";
    $max = (int)($pdo->query($sql)->fetchColumn() ?? 0);
    $next = ($max <= 0) ? 10 : ($max + 1);


    return 'V' . str_pad((string)$next, 5, '0', STR_PAD_LEFT);
}


/* =========================
   READ USER INPUT 
========================= */
$form_errors = [];
$success_msg = '';

$selectedCarrierId = $_POST['carrier_id'] ?? ($_GET['carrier_id'] ?? '');
$voucherCodeInput  = trim($_POST['voucher_code'] ?? ($_GET['voucher_code'] ?? ''));

$shippingFee = 0.0;
$selectedCarrier = null;

if ($selectedCarrierId !== '' && !empty($carriers)) {
    foreach ($carriers as $c) {
        if ($c['CarrierID'] === $selectedCarrierId) {
            $selectedCarrier = $c;
            $shippingFee = (float)$c['ShippingPrice'];
            break;
        }
    }
}
if (!$selectedCarrier && !empty($carriers)) {
    $selectedCarrier = $carriers[0];
    $selectedCarrierId = $carriers[0]['CarrierID'];
    $shippingFee = (float)$carriers[0]['ShippingPrice'];
}

/* =========================
   APPLY VOUCHER
========================= */
$voucher = null;
$userVoucherId = null; // thêm dòng này
$voucherError = '';
$discountAmount = 0.0;

if ($voucherCodeInput !== '' && $subTotal > 0) {
    try {
        // LẤY voucher theo User_Voucher (thuộc user & chưa dùng)
        $vSql = "
            SELECT
                uv.ID AS UserVoucherID,
                v.VoucherID, v.VoucherName, v.Code, v.Description,
                v.DiscountType, v.DiscountValue, v.MinOrder, v.MaxDiscount,
                v.StartDate, v.EndDate, v.UsageLimit, v.UsedCount, v.Status, v.RankRequirement
            FROM User_Voucher uv
            JOIN Voucher v ON v.VoucherID = uv.VoucherID
            WHERE uv.UserID = :uid
              AND uv.OrderID IS NULL
              AND v.Code = :code
            LIMIT 1
        ";
        $vStmt = $pdo->prepare($vSql);
        $vStmt->execute([
            ':uid'  => $userId,
            ':code' => $voucherCodeInput
        ]);
        $voucher = $vStmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            $voucherError = 'Voucher này không thuộc tài khoản của bạn hoặc đã được sử dụng.';
        } else {
            $userVoucherId = $voucher['UserVoucherID']; // ưu lại để khi đặt hàng update

            // giữ nguyên các check còn lại
            if ((int)$voucher['Status'] !== 1) {
                $voucherError = 'Voucher đang bị tắt.';
            } else if (!now_in_range($voucher['StartDate'] ?? null, $voucher['EndDate'] ?? null)) {
                $voucherError = 'Voucher đã hết hạn hoặc chưa tới thời gian áp dụng.';
            } else if ($voucher['UsageLimit'] !== null && $voucher['UsedCount'] !== null
                       && (int)$voucher['UsedCount'] >= (int)$voucher['UsageLimit']) {
                $voucherError = 'Voucher đã hết lượt sử dụng.';
            } else if ($voucher['MinOrder'] !== null && (float)$subTotal < (float)$voucher['MinOrder']) {
                $voucherError = 'Đơn hàng chưa đạt giá trị tối thiểu để dùng voucher.';
            } else if (!user_can_use_rank($customerRank, $voucher['RankRequirement'] ?? 'Chung')) {
                $voucherError = 'Voucher không áp dụng cho hạng khách hàng của bạn.';
            } else {
                $type  = strtolower(trim($voucher['DiscountType'] ?? ''));
                $value = (float)($voucher['DiscountValue'] ?? 0);

                if ($type === 'percent' || $type === 'percentage') {
                    $discountAmount = $subTotal * ($value / 100.0);
                } else {
                    $discountAmount = $value;
                }

                if ($voucher['MaxDiscount'] !== null && (float)$voucher['MaxDiscount'] > 0) {
                    $discountAmount = min($discountAmount, (float)$voucher['MaxDiscount']);
                }

                $discountAmount = max(0, min($discountAmount, $subTotal));
            }
        }
    } catch (Exception $e) {
        $voucherError = 'Không thể áp voucher: ' . $e->getMessage();
    }
}


$totalAfterVoucher = max(0, $subTotal - $discountAmount);
$grandTotal = $totalAfterVoucher + $shippingFee;

$availableVouchers = [];

if ($subTotal > 0) {
    try {
        $vListSql = "
            SELECT
                uv.ID AS UserVoucherID,
                v.VoucherID, v.VoucherName, v.Code, v.Description,
                v.DiscountType, v.DiscountValue, v.MinOrder, v.MaxDiscount,
                v.StartDate, v.EndDate, v.UsageLimit, v.UsedCount, v.Status, v.RankRequirement
            FROM User_Voucher uv
            JOIN Voucher v ON v.VoucherID = uv.VoucherID
            WHERE uv.UserID = :uid
              AND uv.OrderID IS NULL
              AND v.Status = 1
            ORDER BY uv.DateReceived DESC, v.StartDate DESC
        ";

        $vListStmt = $pdo->prepare($vListSql);
        $vListStmt->execute([':uid' => $userId]);
        $all = $vListStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all as $v) {
            if (!now_in_range($v['StartDate'] ?? null, $v['EndDate'] ?? null)) continue;

            if ($v['UsageLimit'] !== null && $v['UsedCount'] !== null
                && (int)$v['UsedCount'] >= (int)$v['UsageLimit']) continue;

            if ($v['MinOrder'] !== null && (float)$subTotal < (float)$v['MinOrder']) continue;

            if (!user_can_use_rank($customerRank, $v['RankRequirement'] ?? 'Chung')) continue;

            $availableVouchers[] = $v;
        }
    } catch (Exception $e) {
        $availableVouchers = [];
    }
}




/* =========================
   SUBMIT ORDER (SAVE REAL)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_order'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $note      = trim($_POST['note'] ?? '');
    $payment = strtoupper(trim($_POST['payment_method'] ?? 'COD'));

if (!in_array($payment, ['COD', 'BANK_ATM', 'BANK_QR', 'BANK_VISA'])) {
    $payment = 'COD';
}

    $shippingCity     = trim($_POST['shipping_city'] ?? '');
    $shippingDistrict = trim($_POST['shipping_district'] ?? '');
    $shippingWard     = trim($_POST['shipping_ward'] ?? '');
    $shippingStreet   = trim($_POST['shipping_street'] ?? '');
    $shippingNumber   = trim($_POST['shipping_number'] ?? '');

    if ($full_name === '') $form_errors[] = 'Vui lòng nhập họ và tên.';
    if ($phone === '')     $form_errors[] = 'Vui lòng nhập số điện thoại.';

    if ($shippingCity === '')     $form_errors[] = 'Vui lòng chọn Tỉnh/Thành phố.';
    if ($shippingDistrict === '') $form_errors[] = 'Vui lòng chọn Quận/Huyện.';
    if ($shippingWard === '')     $form_errors[] = 'Vui lòng chọn Phường/Xã.';
    if ($shippingStreet === '')   $form_errors[] = 'Vui lòng nhập Tên đường.';
    if ($shippingNumber === '')   $form_errors[] = 'Vui lòng nhập Số nhà.';

    if (empty($products))       $form_errors[] = 'Giỏ hàng trống, không thể đặt hàng.';
    if (!$selectedCarrierId)    $form_errors[] = 'Vui lòng chọn đơn vị vận chuyển.';

    if ($voucherCodeInput !== '' && $voucherError !== '') {
        $form_errors[] = $voucherError;
    }

    if (empty($form_errors)) {
        try {
            $pdo->beginTransaction();

            // 0) re-check cart still exists
            $cartCheck = $pdo->prepare("
                SELECT COUNT(*)
                FROM Cart c
                JOIN Cart_Items ci ON c.CartID = ci.CartID
                WHERE c.UserID = :uid
            ");
            $cartCheck->execute([':uid' => $userId]);
            $cartCount = (int)$cartCheck->fetchColumn();
            if ($cartCount <= 0) {
                throw new Exception('Giỏ hàng đã trống (có thể bạn vừa đặt ở tab khác).');
            }

            // 1) LOCK + CHECK STOCK (chặn bán âm / đặt 2 tab)
            $lockSku = $pdo->prepare("
                SELECT Stock, Status
                FROM SKU
                WHERE SKUID = :skuid
                FOR UPDATE
            ");

            foreach ($products as $p) {
                $lockSku->execute([':skuid' => $p['SKUID']]);
                $rowSku = $lockSku->fetch(PDO::FETCH_ASSOC);

                if (!$rowSku) {
                    throw new Exception("SKU {$p['SKUID']} không tồn tại.");
                }

                if ((int)$rowSku['Status'] !== 1) {
                    throw new Exception("SKU {$p['SKUID']} đang bị ẩn/tắt bán.");
                }

                $stock = (int)$rowSku['Stock'];
                $need  = (int)$p['Quantity'];

                if ($stock < $need) {
                    throw new Exception("SKU {$p['SKUID']} không đủ tồn kho. Tồn: {$stock}, cần: {$need}");
                }
            }

            // 2) insert Order
            $orderId = gen_id6();

            $orderStatus   = ($payment === 'COD') ? 'Chờ xác nhận' : 'Chờ thanh toán';
            $paymentStatus = ($payment === 'COD') ? 'Unpaid' : 'Pending';

$insOrder = $pdo->prepare("
    INSERT INTO `Order` (
        OrderID, UserID,
        TotalAmount, TotalAmountAfterVoucher,
        Status, PaymentMethod, PaymentStatus,
        ShippingCity, ShippingDistrict, ShippingWard, ShippingStreet, ShippingNumber,
        CreatedDate, DateReceived, Note
    ) VALUES (
        :oid, :uid,
        :total, :afterVoucher,
        :status, :pay, :paymentStatus,
        :city, :district, :ward, :street, :num,
        NOW(), NULL, :note
    )
");

            $insOrder->execute([
    ':oid'           => $orderId,
    ':uid'           => $userId,
    ':total'         => (float)$subTotal,
    ':afterVoucher'  => (float)$grandTotal,
    ':status'        => $orderStatus,
    ':pay'           => $payment,
    ':paymentStatus' => $paymentStatus,
    ':city'          => $shippingCity,
    ':district'      => $shippingDistrict,
    ':ward'          => $shippingWard,
    ':street'        => $shippingStreet,
    ':num'           => $shippingNumber,
    ':note'          => $note,
]);

            // 3) insert Order_Items
            $insItem = $pdo->prepare("
                INSERT INTO Order_Items (OrderID, SKU_ID, Quantity, UnitPrice, DiscountedPrice, TotalPrice)
                VALUES (:oid, :skuid, :qty, :u, :d, :t)
            ");

            foreach ($products as $p) {
                $insItem->execute([
                    ':oid'   => $orderId,
                    ':skuid' => $p['SKUID'],
                    ':qty'   => (int)$p['Quantity'],
                    ':u'     => (float)$p['UnitPrice'],
                    ':d'     => (float)$p['DiscountedPrice'],
                    ':t'     => (float)$p['TotalPrice'],
                ]);
            }

            if ($payment === 'COD') {
    // trừ kho
    $decStock = $pdo->prepare("
        UPDATE SKU
        SET Stock = Stock - :qty_dec
        WHERE SKUID = :skuid
          AND Status = 1
          AND Stock >= :qty_chk
    ");

    $incSold = $pdo->prepare("
        UPDATE Product p
        JOIN SKU s ON s.ProductID = p.ProductID
        SET p.SoldQuantity = COALESCE(p.SoldQuantity, 0) + :qty
        WHERE s.SKUID = :skuid
    ");

                foreach ($products as $p) {
                    $qty = (int) $p['Quantity'];
                    $sk = $p['SKUID'];

                    $decStock->execute([
                        ':qty_dec' => $qty,
                        ':qty_chk' => $qty,
                        ':skuid' => $sk
                    ]);

                    $incSold->execute([
                        ':qty' => $qty,
                        ':skuid' => $sk
                    ]);
                }

                
            }

            // 5) insert Shipping_Order
            $shippingId = gen_id6();
            $insShip = $pdo->prepare("
                INSERT INTO Shipping_Order (
                    ShippingID, OrderID, ReturnID, CarrierID,
                    TrackingNumber, Status, ShippedDate, DeliveredDate
                ) VALUES (
                    :sid, :oid, NULL, :cid,
                    NULL, :status, NULL, NULL
                )
            ");
            $insShip->execute([
                ':sid'    => $shippingId,
                ':oid'    => $orderId,
                ':cid'    => $selectedCarrierId,
                ':status' => 'Pending'
            ]);

            // 6) voucher
            if ($voucher && $voucherError === '' && !empty($voucher['VoucherID'])) {

                if (empty($userVoucherId)) {
                    throw new Exception('Không xác định được User_Voucher để gắn vào đơn.');
                }

                $upd = $pdo->prepare("
                    UPDATE User_Voucher
                    SET OrderID = :oid
                    WHERE ID = :uvId
                      AND UserID = :uid
                      AND OrderID IS NULL
                ");
                $upd->execute([
                    ':oid'  => $orderId,
                    ':uvId' => $userVoucherId,
                    ':uid'  => $userId
                ]);

                if ($upd->rowCount() <= 0) {
                    throw new Exception('Voucher đã được sử dụng hoặc không thuộc tài khoản của bạn.');
                }

                $pdo->prepare("
                    UPDATE Voucher
                    SET UsedCount = IFNULL(UsedCount, 0) + 1
                    WHERE VoucherID = :vid
                ")->execute([':vid' => $voucher['VoucherID']]);
            }

            //7. clear cart
                $pdo->prepare("
                    DELETE ci FROM Cart_Items ci
                    JOIN Cart c ON ci.CartID = c.CartID
                    WHERE c.UserID = :uid
                ")->execute([':uid' => $userId]);
            

            $pdo->commit();


if (in_array($payment, ['BANK_ATM', 'BANK_QR', 'BANK_VISA'])) {

    if ($payment === 'BANK_QR') {
        $requestType = 'captureWallet';
    } elseif ($payment === 'BANK_ATM') {
        $requestType = 'payWithATM';
    } else { // BANK_VISA
        $requestType = 'payWithCC';
    }
    ?>
    <form id="momoRedirectForm" action="fh_amt_momo.php" method="post">
        <input type="hidden" name="soTien" value="<?php echo (float)$grandTotal; ?>">
        <input type="hidden" name="orderId" value="<?php echo htmlspecialchars($orderId); ?>">
        <input type="hidden" name="orderInfo" value="<?php echo htmlspecialchars('Thanh toán đơn hàng ' . $orderId); ?>">
        <input type="hidden" name="requestType" value="<?php echo htmlspecialchars($requestType); ?>">
        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
    </form>
    <script>
        document.getElementById('momoRedirectForm').submit();
    </script>
    <?php
    exit;
}

$success_msg = "Đặt hàng thành công! Mã đơn: <strong>" . htmlspecialchars($orderId) . "</strong> ✨";

            // reset view
            $products = [];
            $subTotal = 0;
            $totalItems = 0;
            $discountAmount = 0;
            $shippingFee = 0;
            $totalAfterVoucher = 0;
            $grandTotal = 0;
            $voucherCodeInput = '';
            $selectedCarrierId = '';

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $form_errors[] = 'Có lỗi khi xử lý đặt hàng: ' . $e->getMessage();
        }
    }
}


$prefill_city     = $_POST['shipping_city'] ?? ($userProfile['City'] ?? '');
$prefill_district = $_POST['shipping_district'] ?? ($userProfile['District'] ?? '');
$prefill_ward     = $_POST['shipping_ward'] ?? ($userProfile['Ward'] ?? '');
$prefill_street   = $_POST['shipping_street'] ?? ($userProfile['Street'] ?? '');
$prefill_number   = $_POST['shipping_number'] ?? ($userProfile['HouseNumber'] ?? '');

$prefill_phone = $_POST['phone'] ?? ($userProfile['Phone'] ?? '');
$prefill_email = $_POST['email'] ?? ($userProfile['Email'] ?? '');
$prefill_name  = $_POST['full_name'] ?? ($userProfile['FullName'] ?? $currentUsername);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thanh toán - Moonlit Store</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">
</head>

<body class="account-body">

<header class="account-header site-header">
    <div class="container header-inner">
        <div class="header-left">
            <a href="index.php" class="logo-link header-logo">
                <img src="img/image.png?v=2" alt="Moonlit logo" class="logo-img">
            </a>

            <nav class="header-menu">
                <a href="index.php" class="header-menu-link <?php echo nav_active('index.php', $currentPage); ?>">Trang chủ</a>
                <a href="shop.php" class="header-menu-link <?php echo nav_active('shop.php', $currentPage); ?>">Cửa hàng</a>
                <a href="forum.php" class="header-menu-link <?php echo nav_active('forum.php', $currentPage); ?>">Moonlit Forum</a>
                <a href="aboutus.php" class="header-menu-link <?php echo nav_active('aboutus.php', $currentPage); ?>">Về chúng tôi</a>
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

<main class="account-main checkout-main">
    <div class="container">

        <section class="checkout-header">
            <h1 class="account-section-title">Thanh toán</h1>
            <p class="account-section-subtitle">Điền thông tin nhận hàng, chọn vận chuyển, áp voucher và kiểm tra lại đơn sách nha ✨</p>
        </section>

        <?php if (!empty($error_msg)): ?>
            <div class="account-alert account-alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if (!empty($form_errors)): ?>
            <div class="account-alert account-alert-error">
                <?php foreach ($form_errors as $err): ?>
                    <div><?php echo htmlspecialchars($err); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="account-alert account-alert-success"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
            <div class="account-card cart-empty-card">
                <p class="account-empty-text">
                    Giỏ hàng của bạn đang trống hoặc đơn đã được đặt.
                    Hãy quay lại <a href="shop.php" class="auth-link">Cửa hàng Moonlit</a> để chọn thêm sách nhé!
                </p>
            </div>
        <?php else: ?>

            <div class="checkout-layout">

                <section class="checkout-form-section">
                    <div class="account-card checkout-form-card">
                        <h2 class="checkout-section-title">Thông tin nhận hàng</h2>

                        <form method="POST" class="checkout-form" id="checkoutForm">
                            <div class="checkout-form-grid">

                                <div class="checkout-field">
                                    <label class="account-label">Họ và tên *</label>
                                    <input type="text" name="full_name" class="account-input"
                                           value="<?php echo htmlspecialchars($prefill_name); ?>" required>
                                </div>

                                <div class="checkout-field">
                                    <label class="account-label">Email</label>
                                    <input type="email" name="email" class="account-input"
                                           value="<?php echo htmlspecialchars($prefill_email); ?>">
                                </div>

                                <div class="checkout-field">
                                    <label class="account-label">Số điện thoại *</label>
                                    <input type="text" name="phone" class="account-input"
                                           value="<?php echo htmlspecialchars($prefill_phone); ?>" required>
                                </div>

                                <!-- DROPDOWN ADDRESS -->
                                <div class="checkout-field">
                                    <label class="account-label">Tỉnh / Thành phố *</label>
                                    <select name="shipping_city" id="citySelect" class="account-input" required>
                                        <option value="">Chọn Tỉnh/Thành phố</option>
                                    </select>
                                </div>

                                <div class="checkout-field">
                                    <label class="account-label">Quận / Huyện *</label>
                                    <select name="shipping_district" id="districtSelect" class="account-input" required disabled>
                                        <option value="">Chọn Quận/Huyện</option>
                                    </select>
                                </div>

                                <div class="checkout-field">
                                    <label class="account-label">Phường / Xã *</label>
                                    <select name="shipping_ward" id="wardSelect" class="account-input" required disabled>
                                        <option value="">Chọn Phường/Xã</option>
                                    </select>
                                </div>

                                <div class="checkout-field checkout-field-full">
                                    <label class="account-label">Tên đường *</label>
                                    <input type="text" name="shipping_street" class="account-input"
                                           value="<?php echo htmlspecialchars($prefill_street); ?>" required>
                                </div>

                                <div class="checkout-field checkout-field-full">
                                    <label class="account-label">Số nhà *</label>
                                    <input type="text" name="shipping_number" class="account-input"
                                           value="<?php echo htmlspecialchars($prefill_number); ?>" required>
                                </div>

                                <div class="checkout-field checkout-field-full">
                                    <label class="account-label">Ghi chú cho đơn hàng</label>
                                    <textarea name="note" class="account-input checkout-textarea" rows="3"><?php
                                        echo htmlspecialchars($_POST['note'] ?? '');
                                    ?></textarea>
                                </div>

                                <!-- SHIPPING -->
                                <div class="checkout-field checkout-field-full">
                                    <label class="account-label mb-1">Đơn vị vận chuyển</label>
                                    <?php if (empty($carriers)): ?>
                                        <div class="small text-secondary">Chưa có dữ liệu Carrier.</div>
                                        <input type="hidden" name="carrier_id" value="">
                                    <?php else: ?>
                                        <select name="carrier_id" id="carrierSelect" class="account-input" required>
                                            <?php foreach ($carriers as $c): ?>
                                                <option value="<?php echo htmlspecialchars($c['CarrierID']); ?>"
                                                    <?php echo ($selectedCarrierId === $c['CarrierID']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['CarrierName']) . ' - ' . number_format((float)$c['ShippingPrice'], 0, ',', '.') . ' đ'; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>

                                <!-- VOUCHER -->
                                <div class="checkout-field checkout-field-full">
                                    <label class="account-label mb-1">Voucher</label>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <select name="voucher_code" class="account-input" style="flex: 1; min-width: 220px;">
                                        <option value="">-- Chọn voucher (có thể áp dụng) --</option>

                                        <?php foreach ($availableVouchers as $v): ?>
                                            <?php
                                            $type = strtolower(trim($v['DiscountType'] ?? ''));
                                            if ($type === 'percent' || $type === 'percentage') {
                                                $desc = 'Giảm ' . rtrim(rtrim((string)$v['DiscountValue'], '0'), '.') . '%';
                                            } else {
                                                $desc = 'Giảm ' . number_format((float)$v['DiscountValue'], 0, ',', '.') . 'đ';
                                            }

                                            if (!empty($v['MaxDiscount']) && (float)$v['MaxDiscount'] > 0) {
                                                $desc .= ' (tối đa ' . number_format((float)$v['MaxDiscount'], 0, ',', '.') . 'đ)';
                                            }

                                            if (!empty($v['MinOrder']) && (float)$v['MinOrder'] > 0) {
                                                $desc .= ' | Đơn từ ' . number_format((float)$v['MinOrder'], 0, ',', '.') . 'đ';
                                            }

                                            $label = ($v['VoucherName'] ?? $v['Code']) . ' (' . $v['Code'] . ') - ' . $desc;
                                            ?>
                                            <option value="<?php echo htmlspecialchars($v['Code']); ?>"
                                            <?php echo ($voucherCodeInput === $v['Code']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        </select>

                                        <button type="submit" name="apply_voucher" value="1" class="account-btn-secondary">
                                        Áp dụng
                                        </button>
                                    </div>

                                    <?php if ($voucherCodeInput !== ''): ?>
                                        <?php if ($voucherError !== ''): ?>
                                        <div class="small text-danger mt-1"><?php echo htmlspecialchars($voucherError); ?></div>
                                        <?php else: ?>
                                        <div class="small text-success mt-1">
                                            Đã áp voucher: <strong><?php echo htmlspecialchars($voucher['VoucherName'] ?? $voucherCodeInput); ?></strong>
                                        </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>


                                <!-- PAYMENT -->
                                <div class="checkout-payment-options">
                                    <label class="checkout-radio-option">
                                        <input type="radio" name="payment_method" value="COD"
                                            <?php echo (($_POST['payment_method'] ?? 'COD') === 'COD') ? 'checked' : ''; ?>>
                                        <span>Thanh toán khi nhận hàng (COD)</span>
                                    </label>

                                    <label class="checkout-radio-option">
                                        <input type="radio" name="payment_method" value="BANK_ATM"
                                            <?php echo (($_POST['payment_method'] ?? '') === 'BANK_ATM') ? 'checked' : ''; ?>>
                                        <span>Thanh toán ATM nội địa</span>
                                    </label>

                                    <label class="checkout-radio-option">
                                        <input type="radio" name="payment_method" value="BANK_VISA"
                                            <?php echo (($_POST['payment_method'] ?? '') === 'BANK_VISA') ? 'checked' : ''; ?>>
                                        <span>Thanh toán thẻ Visa/Mastercard/JCB</span>
                                    </label>

                                    <label class="checkout-radio-option">
                                        <input type="radio" name="payment_method" value="BANK_QR"
                                            <?php echo (($_POST['payment_method'] ?? '') === 'BANK_QR') ? 'checked' : ''; ?>>
                                        <span>Quét mã QR</span>
                                    </label>
                                </div>

                            </div>

                            <button type="submit" name="place_order" value="1" class="account-btn-save checkout-submit-btn">
                                Đặt hàng
                            </button>
                        </form>
                    </div>
                </section>

                <aside class="checkout-summary">
                    <div class="account-card cart-summary-card">
                        <h2 class="cart-summary-title">Đơn hàng của bạn</h2>

                        <div class="checkout-summary-list">
                            <?php foreach ($products as $product): ?>
                                <div class="checkout-summary-item">
                                    <div class="checkout-summary-info">
                                        <p class="checkout-summary-name"><?php echo htmlspecialchars($product['ProductName']); ?></p>
                                        <p class="checkout-summary-qty">
                                            SL: <?php echo (int)$product['Quantity']; ?>
                                            <?php if (!empty($product['Format'])): ?>
                                                (<?php echo htmlspecialchars($product['Format']); ?>)
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="checkout-summary-line-total">
                                        <?php echo number_format((float)$product['TotalPrice'], 0, ',', '.'); ?> đ
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="cart-summary-row">
                            <span>Tạm tính</span>
                            <span><?php echo number_format((float)$subTotal, 0, ',', '.'); ?> đ</span>
                        </div>

                        <div class="cart-summary-row">
                            <span>Giảm giá</span>
                            <span>-<?php echo number_format((float)$discountAmount, 0, ',', '.'); ?> đ</span>
                        </div>

                        <div class="cart-summary-row">
                            <span>Phí vận chuyển</span>
                            <span><?php echo number_format((float)$shippingFee, 0, ',', '.'); ?> đ</span>
                        </div>

                        <div class="cart-summary-row cart-summary-total">
                            <span>Tổng thanh toán</span>
                            <span><?php echo number_format((float)$grandTotal, 0, ',', '.'); ?> đ</span>
                        </div>

                    </div>
                </aside>

            </div>

        <?php endif; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-col">
            <h4>Moonlit</h4>
            <p class="footer-desc">
                Hiệu sách trực tuyến dành cho những tâm hồn yêu đọc.
                Chúng tôi tin mỗi cuốn sách đều có ánh trăng riêng 🌙
            </p>
        </div>
        <div class="footer-col">
            <h4>Liên kết</h4>
            <ul>
                <li><a href="index.php">Trang chủ</a></li>
                <li><a href="shop.php">Cửa hàng</a></li>
                <li><a href="forum.php">Moonlit Forum</a></li>
                <li><a href="aboutus.php">Về chúng tôi</a></li>
            </ul>
        </div>
        <div class="footer-col">
            <h4>Blog & Nội dung</h4>
            <ul>
                <li><a href="blogs.php">Blog Moonlit</a></li>
                <li><a href="blogs.php">Review sách</a></li>
                <li><a href="blogs.php">Góc đọc chậm</a></li>
            </ul>
        </div>
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

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
  function toggleBankBox() {
  const cardRadio = document.querySelector('input[name="payment_method"][value="BANK_CARD"]');
  const qrRadio   = document.querySelector('input[name="payment_method"][value="BANK_QR"]');
  const bankBox   = document.getElementById('bankTransferBox');
  if (!bankBox) return;

  const isOnline = (cardRadio && cardRadio.checked) || (qrRadio && qrRadio.checked);
  bankBox.style.display = isOnline ? 'block' : 'none';
}

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[name="payment_method"]').forEach(r => {
      r.addEventListener('change', toggleBankBox);
    });
    toggleBankBox();
  });
</script>


<!-- Prefill values from PHP -->
<script>
  const PREFILL = {
    city: <?php echo json_encode($prefill_city); ?>,
    district: <?php echo json_encode($prefill_district); ?>,
    ward: <?php echo json_encode($prefill_ward); ?>
  };
</script>

<!-- Address dropdown loader -->
<script>
  const citySelect = document.getElementById('citySelect');
  const districtSelect = document.getElementById('districtSelect');
  const wardSelect = document.getElementById('wardSelect');

  async function fetchJSON(url) {
    const res = await fetch(url);
    if (!res.ok) throw new Error('Fetch failed: ' + url);
    return await res.json();
  }

  function resetSelect(sel, placeholder) {
    sel.innerHTML = `<option value="">${placeholder}</option>`;
  }

  function setEnabled(sel, enabled) {
    sel.disabled = !enabled;
  }

  // Load provinces
  async function loadCities() {
    resetSelect(citySelect, 'Chọn Tỉnh/Thành phố');
    resetSelect(districtSelect, 'Chọn Quận/Huyện');
    resetSelect(wardSelect, 'Chọn Phường/Xã');
    setEnabled(districtSelect, false);
    setEnabled(wardSelect, false);

    const cities = await fetchJSON('https://provinces.open-api.vn/api/p/');
    cities.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.name;
      opt.dataset.code = c.code;
      opt.textContent = c.name;
      citySelect.appendChild(opt);
    });

    // prefill city
    if (PREFILL.city) {
      const opt = Array.from(citySelect.options).find(o => o.value === PREFILL.city);
      if (opt) {
        citySelect.value = PREFILL.city;
        await loadDistricts(opt.dataset.code, true);
      }
    }
  }

  async function loadDistricts(cityCode, isPrefill = false) {
    resetSelect(districtSelect, 'Chọn Quận/Huyện');
    resetSelect(wardSelect, 'Chọn Phường/Xã');
    setEnabled(districtSelect, true);
    setEnabled(wardSelect, false);

    const city = await fetchJSON(`https://provinces.open-api.vn/api/p/${cityCode}?depth=2`);
    (city.districts || []).forEach(d => {
      const opt = document.createElement('option');
      opt.value = d.name;
      opt.dataset.code = d.code;
      opt.textContent = d.name;
      districtSelect.appendChild(opt);
    });

    if (isPrefill && PREFILL.district) {
      const opt = Array.from(districtSelect.options).find(o => o.value === PREFILL.district);
      if (opt) {
        districtSelect.value = PREFILL.district;
        await loadWards(opt.dataset.code, true);
      }
    }
  }

  async function loadWards(districtCode, isPrefill = false) {
    resetSelect(wardSelect, 'Chọn Phường/Xã');
    setEnabled(wardSelect, true);

    const district = await fetchJSON(`https://provinces.open-api.vn/api/d/${districtCode}?depth=2`);
    (district.wards || []).forEach(w => {
      const opt = document.createElement('option');
      opt.value = w.name;
      opt.textContent = w.name;
      wardSelect.appendChild(opt);
    });

    if (isPrefill && PREFILL.ward) {
      const opt = Array.from(wardSelect.options).find(o => o.value === PREFILL.ward);
      if (opt) wardSelect.value = PREFILL.ward;
    }
  }

  citySelect?.addEventListener('change', async () => {
    const selected = citySelect.options[citySelect.selectedIndex];
    const code = selected?.dataset?.code;
    resetSelect(districtSelect, 'Chọn Quận/Huyện');
    resetSelect(wardSelect, 'Chọn Phường/Xã');
    setEnabled(districtSelect, false);
    setEnabled(wardSelect, false);
    if (code) await loadDistricts(code, false);
  });

  districtSelect?.addEventListener('change', async () => {
    const selected = districtSelect.options[districtSelect.selectedIndex];
    const code = selected?.dataset?.code;
    resetSelect(wardSelect, 'Chọn Phường/Xã');
    setEnabled(wardSelect, false);
    if (code) await loadWards(code, false);
  });

  document.addEventListener('DOMContentLoaded', loadCities);
</script>

<!-- Auto refresh shipping fee when change carrier -->
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const carrierSelect = document.getElementById('carrierSelect');
    const form = document.getElementById('checkoutForm');
    if (!carrierSelect || !form) return;

    carrierSelect.addEventListener('change', () => {
      form.submit();
    });
  });
</script>

</body>
</html>


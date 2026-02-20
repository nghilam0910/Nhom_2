<?php
/**
 * Order History Section with Return Management
 * Display completed and cancelled orders with return workflow
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// ============================================================================
// FUNCTION: Generate unique ID
// ============================================================================
function generateReturnId($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ReturnID, 2) AS UNSIGNED)) as max_id FROM Returns_Order");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'R' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

function generateImageId($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ImageID, 2) AS UNSIGNED)) as max_id FROM Return_Images");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'I' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}

// ============================================================================
// FUNCTION: Check return window (7 days)
// ============================================================================
function isReturnWindowOpen($date_received) {
    if (empty($date_received)) return false;
    
    try {
        $received = new DateTime($date_received);
        $now = new DateTime(); // Lấy giờ hiện tại của server PHP
        
        // Tính khoảng cách
        $interval = $now->diff($received);
        
        // Logic mới: Chỉ cần tổng số ngày chênh lệch <= 7
        // Bỏ điều kiện invert == 1 để chấp nhận trường hợp lệch múi giờ 
        // (Ví dụ: DB lưu giờ VN nhưng PHP lấy giờ UTC -> DB sẽ nằm ở 'tương lai' so với PHP)
        return $interval->days <= 7;
        
    } catch (Exception $e) {
        return false;
    }
}

// ============================================================================
// POST HANDLER: Request Return
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_return') {
    $order_id = trim($_POST['order_id'] ?? '');
    $selected_items = isset($_POST['return_items']) ? $_POST['return_items'] : [];
    if (!is_array($selected_items)) $selected_items = [$selected_items];

    $quantities = $_POST['return_quantities'] ?? [];
    $reasons = $_POST['return_reasons'] ?? [];

    if (empty($order_id) || empty($selected_items)) {
        $message = 'Vui lòng chọn ít nhất một sản phẩm để trả hàng.';
        $message_type = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            // [BƯỚC 1] Lấy thông tin Đơn hàng & Voucher
            // Join thêm bảng User_Voucher và Voucher để lấy DiscountType, DiscountValue, MaxDiscount
            $stmtOrder = $pdo->prepare("
                SELECT 
                    o.TotalAmount,
                    v.DiscountType,
                    v.DiscountValue,
                    v.MaxDiscount
                FROM `Order` o
                LEFT JOIN User_Voucher uv ON o.OrderID = uv.OrderID
                LEFT JOIN Voucher v ON uv.VoucherID = v.VoucherID
                WHERE o.OrderID = ?
            ");
            $stmtOrder->execute([$order_id]);
            $orderInfo = $stmtOrder->fetch();

            if (!$orderInfo) throw new Exception('Không tìm thấy thông tin đơn hàng.');

            // --- XÁC ĐỊNH CÁC THAM SỐ TÍNH TOÁN ---

            // 1. Tổng tiền hàng (Mẫu số): Lấy trực tiếp TotalAmount (theo yêu cầu mới)
            $total_goods_value = (float)$orderInfo['TotalAmount'];

            // 2. Tính số tiền Voucher thực tế (Tử số phần trừ)
            $actual_voucher_money = 0;
            
            if (!empty($orderInfo['DiscountValue'])) {
                $type = strtoupper($orderInfo['DiscountType'] ?? ''); // PERCENT hoặc AMOUNT
                $val  = (float)$orderInfo['DiscountValue'];
                $max  = (float)($orderInfo['MaxDiscount'] ?? 0);

                if ($type === 'PERCENT' || $type === '%') {
                    // Logic Voucher %:
                    // a. Tính số tiền giảm lý thuyết: Tổng tiền hàng * %
                    $calculated_discount = $total_goods_value * ($val / 100);

                    // b. Kiểm tra MaxDiscount (Trần giảm giá)
                    if ($max > 0 && $calculated_discount > $max) {
                        $actual_voucher_money = $max; // Nếu vượt trần -> lấy trần
                    } else {
                        $actual_voucher_money = $calculated_discount; // Nếu không -> lấy số tính được
                    }
                } else {
                    // Logic Voucher tiền mặt (AMOUNT): Lấy trực tiếp giá trị
                    $actual_voucher_money = $val;
                }
            }

            // Tạo Return ID
            $return_id = generateReturnId($pdo);
            $stmt = $pdo->prepare("INSERT INTO Returns_Order (ReturnID, OrderID, Status, TotalRefund, CreatedDate) VALUES (?, ?, 'Chờ xác nhận', 0, NOW())");
            $stmt->execute([$return_id, $order_id]);

            $total_refund = 0;

            // [BƯỚC 3] Xử lý từng item & Tính toán hoàn tiền
            // Công thức: Giá Item - ( (Giá Item / Tổng tiền hàng) * Tiền Voucher thực tế )
            foreach ($selected_items as $order_item_id) {
                $qty = isset($quantities[$order_item_id]) ? (int)$quantities[$order_item_id] : 0;
                $reason = isset($reasons[$order_item_id]) ? trim($reasons[$order_item_id]) : '';

                if ($qty <= 0) throw new Exception('Số lượng trả phải lớn hơn 0.');
                if (empty($reason)) throw new Exception('Vui lòng chọn lý do trả hàng.');

                // Lấy thông tin sản phẩm
                $stmt = $pdo->prepare("SELECT UnitPrice, DiscountedPrice, Quantity FROM Order_Items WHERE OrderItemID = ?");
                $stmt->execute([$order_item_id]);
                $item = $stmt->fetch();

                if (!$item || $qty > $item['Quantity']) {
                    throw new Exception('Dữ liệu sản phẩm không hợp lệ.');
                }

                // Xác định giá gốc của sản phẩm (x)
                // Ưu tiên lấy DiscountedPrice nếu có, ngược lại lấy UnitPrice
                $x = (!empty($item['DiscountedPrice']) && $item['DiscountedPrice'] > 0 && $item['DiscountedPrice'] < $item['UnitPrice']) 
                     ? $item['DiscountedPrice'] 
                     : $item['UnitPrice'];

                // --- LOGIC TÍNH TOÁN ---
                $unit_refund = $x; 

                // Chỉ tính phân bổ voucher nếu có tiền hàng và có voucher
                if ($total_goods_value > 0 && $actual_voucher_money > 0) {
                    // Tỷ lệ đóng góp của sản phẩm này vào đơn hàng
                    $ratio = $x / $total_goods_value;

                    // Số tiền voucher được phân bổ cho 1 đơn vị sản phẩm này
                    $deduction = $ratio * $actual_voucher_money;

                    // Giá hoàn tiền cuối cùng = Giá gốc - Phần voucher gánh
                    $unit_refund = $x - $deduction;
                }

                // Làm tròn xuống (floor) để an toàn về số tiền
                $unit_refund = floor($unit_refund);

                // Tổng hoàn cho dòng sản phẩm này
                $refund_amount = $qty * $unit_refund;
                $total_refund += $refund_amount;

                // Lưu vào DB
                $stmt = $pdo->prepare("INSERT INTO Return_Items (ReturnID, OrderItemID, Quantity, RefundAmount, Reason) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$return_id, $order_item_id, $qty, $refund_amount, $reason]);
                $return_item_id = $pdo->lastInsertId();

                // Upload ảnh (Code giữ nguyên)
                if (!isset($_FILES['return_images']['name'][$order_item_id]) || empty($_FILES['return_images']['name'][$order_item_id][0])) {
                    throw new Exception('Vui lòng tải lên hình ảnh minh chứng cho sản phẩm.');
                }
                $files = $_FILES['return_images'];
                $file_count = count($files['name'][$order_item_id]);
                for ($i = 0; $i < $file_count; $i++) {
                    if ($files['error'][$order_item_id][$i] === UPLOAD_ERR_OK) {
                        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        if (!in_array($files['type'][$order_item_id][$i], $allowed_types)) throw new Exception('Chỉ hỗ trợ file ảnh.');
                        
                        $upload_dir = 'uploads/returns/';
                        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                        $extension = pathinfo($files['name'][$order_item_id][$i], PATHINFO_EXTENSION);
                        $filename = $return_id . '_' . uniqid() . '.' . $extension;
                        $filepath = $upload_dir . $filename;

                        if (move_uploaded_file($files['tmp_name'][$order_item_id][$i], $filepath)) {
                            $image_id = generateImageId($pdo);
                            $stmt = $pdo->prepare("INSERT INTO Return_Images (ImageID, ReturnItemID, ImageURL) VALUES (?, ?, ?)");
                            $stmt->execute([$image_id, $return_item_id, $filepath]);
                        }
                    }
                }
            }

            // Cập nhật tổng tiền hoàn vào bảng Returns_Order
            $stmt = $pdo->prepare("UPDATE Returns_Order SET TotalRefund = ? WHERE ReturnID = ?");
            $stmt->execute([$total_refund, $return_id]);

            // Cập nhật trạng thái đơn hàng
            $stmt = $pdo->prepare("UPDATE `Order` SET Status = 'Trả hàng' WHERE OrderID = ?");
            $stmt->execute([$order_id]);

            $pdo->commit();
            $message = 'Yêu cầu trả hàng đã được gửi thành công!';
            $message_type = 'success';
            echo "<script>window.location.href = window.location.href;</script>";

        } catch (Exception $e) {
            $pdo->rollBack();
            $message = 'Lỗi: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// ============================================================================
// POST HANDLER: Cancel Return (Hoàn tác)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_return') {
    $return_id = trim($_POST['return_id'] ?? '');

    try {
        $pdo->beginTransaction();
        
        // Kiểm tra trạng thái và LẤY OrderID để restore
        $stmt = $pdo->prepare("SELECT Status, OrderID FROM Returns_Order WHERE ReturnID = ?");
        $stmt->execute([$return_id]);
        $curr = $stmt->fetch();

        if (!$curr || $curr['Status'] !== 'Chờ xác nhận') {
            throw new Exception('Chỉ có thể hủy yêu cầu khi đang chờ xác nhận.');
        }

        $order_id_to_restore = $curr['OrderID'];

        // Xóa ảnh vật lý và DB
        $stmt = $pdo->prepare("
            SELECT ri.ImageURL, ri.ImageID 
            FROM Return_Images ri
            JOIN Return_Items rit ON ri.ReturnItemID = rit.ReturnItemID
            WHERE rit.ReturnID = ?
        ");
        $stmt->execute([$return_id]);
        $images = $stmt->fetchAll();

        foreach ($images as $img) {
            if (file_exists($img['ImageURL'])) unlink($img['ImageURL']);
        }

        // Xóa dữ liệu theo thứ tự khóa ngoại
        // 1. Return_Images
        $stmt = $pdo->prepare("DELETE FROM Return_Images WHERE ReturnItemID IN (SELECT ReturnItemID FROM Return_Items WHERE ReturnID = ?)");
        $stmt->execute([$return_id]);

        // 2. Return_Items
        $stmt = $pdo->prepare("DELETE FROM Return_Items WHERE ReturnID = ?");
        $stmt->execute([$return_id]);

        // 3. Returns_Order
        $stmt = $pdo->prepare("DELETE FROM Returns_Order WHERE ReturnID = ?");
        $stmt->execute([$return_id]);

        // [MỚI] 4. Khôi phục trạng thái Order gốc về 'Đã nhận'
        $stmt = $pdo->prepare("UPDATE `Order` SET Status = 'Đã nhận' WHERE OrderID = ?");
        $stmt->execute([$order_id_to_restore]);

        $pdo->commit();
        $message = 'Đã hủy yêu cầu trả hàng.';
        $message_type = 'success';

    } catch (Exception $e) {
        $pdo->rollBack();
        $message = 'Lỗi hủy: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// ============================================================================
// GET ORDERS DATA (Đã thêm Phân Trang)
// ============================================================================

// [PHÂN TRANG 1] Cấu hình
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 5; // Số đơn hàng muốn hiện mỗi trang
$offset = ($page - 1) * $limit;

// [PHÂN TRANG 2] Xây dựng điều kiện lọc (WHERE clause)
$filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$where_conditions = ["o.UserID = ?"];
$params_filter = [$user_id]; 

switch ($filter) {
    case 'received':
        $where_conditions[] = "o.Status = 'Đã nhận'";
        break;
    case 'returned':
        $where_conditions[] = "o.Status = 'Trả hàng'";
        break;
    case 'refunded': 
        $where_conditions[] = "o.Status = 'Đã hoàn tiền'";
        break;
    case 'cancelled':
        $where_conditions[] = "o.Status = 'Bị hủy'";
        break;
    default:
        $where_conditions[] = "o.Status IN ('Đã nhận', 'Bị hủy', 'Trả hàng', 'Đã hoàn tiền')";
        break;
}
$where_sql = implode(' AND ', $where_conditions);

// [PHÂN TRANG 3] Đếm tổng số đơn hàng để chia trang
$sql_count = "SELECT COUNT(DISTINCT o.OrderID) FROM `Order` o WHERE $where_sql";
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params_filter);
$total_orders = $stmt->fetchColumn();
$total_pages = ceil($total_orders / $limit);

// [PHÂN TRANG 4] Lấy danh sách OrderID thuộc trang hiện tại
// Lý do: Phải lấy ID trước rồi mới JOIN để tránh lỗi mất sản phẩm khi dùng LIMIT
$sql_ids = "SELECT o.OrderID FROM `Order` o WHERE $where_sql ORDER BY o.CreatedDate DESC, o.OrderID DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql_ids);
$stmt->execute($params_filter);
$page_order_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

// [PHÂN TRANG 5] Truy vấn chi tiết (Giữ nguyên cấu trúc SELECT/JOIN cũ của bạn)
$grouped_orders = [];

if (!empty($page_order_ids)) {
    // Tạo chuỗi placeholder (?,?,?) tương ứng số lượng ID lấy được
    $placeholders = implode(',', array_fill(0, count($page_order_ids), '?'));

    $sql = "
        SELECT
            o.OrderID, o.TotalAmount, o.TotalAmountAfterVoucher, o.Status, o.PaymentMethod,
            o.ShippingCity, o.ShippingDistrict, o.ShippingWard, o.ShippingStreet, o.ShippingNumber,
            o.CreatedDate, o.DateReceived,
            oi.OrderItemID, oi.Quantity, oi.UnitPrice, oi.DiscountedPrice,
            p.ProductName, p.Image, p.ImageUrl, s.Format, s.ISBN, p.ProductID, s.SKUID,
            c.CarrierName, c.ShippingPrice,
            v.DiscountValue, v.MaxDiscount, v.DiscountType  
        FROM `Order` o
        LEFT JOIN Order_Items oi ON o.OrderID = oi.OrderID
        LEFT JOIN SKU s ON oi.SKU_ID = s.SKUID
        LEFT JOIN Product p ON s.ProductID = p.ProductID
        LEFT JOIN Shipping_Order so ON o.OrderID = so.OrderID
        LEFT JOIN Carrier c ON so.CarrierID = c.CarrierID
        LEFT JOIN User_Voucher uv ON o.OrderID = uv.OrderID
        LEFT JOIN Voucher v ON uv.VoucherID = v.VoucherID
        WHERE o.OrderID IN ($placeholders)
        ORDER BY o.CreatedDate DESC
    ";

    // Truyền danh sách ID vào câu query chính
    $stmt = $pdo->prepare($sql);
    $stmt->execute($page_order_ids);
    $orders = $stmt->fetchAll();

    // Group logic cũ của bạn giữ nguyên 100%
    foreach ($orders as $order) {
        $order_id = $order['OrderID'];
        if (!isset($grouped_orders[$order_id])) {
            $address_parts = array_filter([
                $order['ShippingNumber'] ?? '', $order['ShippingStreet'] ?? '', 
                $order['ShippingWard'] ?? '', $order['ShippingDistrict'] ?? '', $order['ShippingCity'] ?? ''
            ], function($value) { return !empty(trim($value)); });
            
            $grouped_orders[$order_id] = [
                'OrderID' => $order['OrderID'],
                'TotalAmount' => $order['TotalAmount'],
                'TotalAmountAfterVoucher' => $order['TotalAmountAfterVoucher'],
                'Status' => $order['Status'],
                'PaymentMethod' => $order['PaymentMethod'],
                'ShippingAddress' => implode(', ', $address_parts),
                'CreatedDate' => $order['CreatedDate'],
                'DateReceived' => $order['DateReceived'],
                'CarrierName' => $order['CarrierName'], 
                'ShippingPrice' => $order['ShippingPrice'],
                'VoucherValue' => $order['DiscountValue'], 
                'VoucherType' => $order['DiscountType'],
                'MaxDiscount' => $order['MaxDiscount'],
                'Items' => []
            ];
        }
        if (!empty($order['ProductName'])) {
            $grouped_orders[$order_id]['Items'][] = [
                'OrderItemID' => $order['OrderItemID'],
                'ProductName' => $order['ProductName'],
                'Image' => $order['Image'],
                'ImageUrl' => $order['ImageUrl'],
                'Format' => $order['Format'],
                'ISBN' => $order['ISBN'],
                'Quantity' => $order['Quantity'],
                'UnitPrice' => $order['UnitPrice'],
                'DiscountedPrice' => $order['DiscountedPrice'],
                'ProductID' => $order['ProductID'], // Thêm dòng này
                'SKUID' => $order['SKUID']
            ];
        }
    }
}

// Logic lấy trạng thái trả hàng (Tối ưu lại bằng cách dùng $page_order_ids)
$return_map = [];
if (!empty($page_order_ids)) {
    $placeholders = implode(',', array_fill(0, count($page_order_ids), '?'));
    $stmt = $pdo->prepare("SELECT ReturnID, OrderID, Status FROM Returns_Order WHERE OrderID IN ($placeholders)");
    $stmt->execute($page_order_ids);
    $returns = $stmt->fetchAll();

    foreach ($returns as $return) {
        $return_map[$return['OrderID']] = $return;
    }
}
?>

<div class="account-section" data-page-id="orders-page">
    
    <h2 class="account-section-title mb-3">Lịch sử đặt hàng</h2>

    <div class="mb-3 d-inline-flex align-items-center" style="color: var(--color-deep-blue); font-size: 14px; background: #f0f4f8; padding: 12px 15px; border-radius: 8px; width: 100%;">
        <i class="fas fa-info-circle me-2"></i>
        <span>
            Hiểu rõ hơn về 
            <a href="policy.php#return-policy" class="fw-bold text-decoration-none" style="color: var(--color-deep-blue);">chính sách đổi/trả</a> 
            và 
            <a href="policy.php#refund-policy" class="fw-bold text-decoration-none" style="color: var(--color-deep-blue);">hoàn tiền</a> 
            của Moonlit.
        </span>
        <i class="fas fa-chevron-right ms-auto" style="font-size: 10px;"></i>
    </div>

    <div class="account-section-header d-flex justify-content-end mb-4">
        <div class="account-filters">
            <form method="GET" action="">
                <?php
                // Giữ nguyên logic xử lý hidden input của bạn
                foreach ($_GET as $key => $value) {
                    if ($key !== 'status' && $key !== 'page') {
                        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                    }
                }
                ?>
                <select name="status" class="account-filter-select" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter ?? 'all') === 'all' ? 'selected' : ''; ?>>Tất cả đơn hàng</option>
                    <option value="received" <?php echo ($filter ?? '') === 'received' ? 'selected' : ''; ?>>Đã nhận</option>
                    <option value="returned" <?php echo ($filter ?? '') === 'returned' ? 'selected' : ''; ?>>Trả hàng</option>
                    <option value="refunded" <?php echo ($filter ?? '') === 'refunded' ? 'selected' : ''; ?>>Đã hoàn tiền</option>
                    <option value="cancelled" <?php echo ($filter ?? '') === 'cancelled' ? 'selected' : ''; ?>>Bị hủy</option>
                </select>
            </form>
        </div>
    </div>

</div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> account-alert" role="alert">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($grouped_orders)): ?>
        <div class="account-empty-state">
            <i class="fas fa-box-open"></i>
            <p class="account-empty-text">Bạn chưa có đơn hàng nào.</p>
        </div>
    <?php else: ?>
        <div class="account-orders-list">
            <?php foreach ($grouped_orders as $order): ?>
                <div class="account-order-card">
                    <div class="account-order-header">
                        <div class="account-order-info">
                            <span class="account-order-id">Đơn hàng: <?php echo htmlspecialchars($order['OrderID']); ?></span>
                            <span class="account-order-date">
                                Ngày đặt hàng: <?php echo date('d/m/Y H:i', strtotime($order['CreatedDate'])); ?>
                                <?php if (!empty($order['DateReceived'])): ?>
                                    - Ngày nhận hàng: <?php echo date('d/m/Y H:i', strtotime($order['DateReceived'])); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php
                        $status_class = 'status-cancelled';
                        $status_text = mb_strtolower($order['Status'], 'UTF-8');

                        if ($status_text === 'đã nhận') {
                            $status_class = 'status-success';
                        } elseif ($status_text === 'trả hàng') {
                            $status_class = 'status-returned';
                        } elseif ($status_text === 'đã hoàn tiền') {
                            $status_class = 'status-refunded';
                        }
                        ?>
                        <span class="account-order-status <?php echo $status_class; ?>">
                            <?php echo htmlspecialchars($order['Status']); ?>
                        </span>
                    </div>

                    <div class="account-order-items">
                        <?php foreach ($order['Items'] as $item): ?>
                            <div class="account-order-item" 
                                 data-order-item-id="<?php echo $item['OrderItemID']; ?>"
                                 data-max-qty="<?php echo $item['Quantity']; ?>">

                                <?php if (!empty($item['Image'])): ?>
                                    <div class="account-order-item-image">
                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($item['Image']); ?>" alt="<?php echo htmlspecialchars($item['ProductName']); ?>">
                                    </div>
                                <?php elseif (!empty($item['ImageUrl'])): ?>
                                    <div class="account-order-item-image">
                                        <img src="<?php echo htmlspecialchars($item['ImageUrl']); ?>" alt="<?php echo htmlspecialchars($item['ProductName']); ?>">
                                    </div>
                                <?php else: ?>
                                    <div class="account-order-item-image account-order-item-image-empty">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>

                                <div class="account-order-item-details">
                                    <h4 class="account-order-item-name"><?php echo htmlspecialchars($item['ProductName']); ?></h4>
                                    
                                    <p class="account-order-item-sku">
                                        <?php if (!empty($item['Format'])): ?>
                                            Định dạng: <?php echo htmlspecialchars($item['Format']); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($item['ISBN'])): ?>
                                            | ISBN: <?php echo htmlspecialchars($item['ISBN']); ?>
                                        <?php endif; ?>
                                    </p>
                                    <p class="account-order-item-qty">
                                        SL: <?php echo htmlspecialchars($item['Quantity']); ?> x 
                                        <?php 
                                        // [LOGIC MỚI BẮT ĐẦU TỪ ĐÂY]
                                        // Kiểm tra: Có DiscountedPrice và nó nhỏ hơn UnitPrice
                                        if (!empty($item['DiscountedPrice']) && $item['DiscountedPrice'] < $item['UnitPrice']) {
                                            
                                            // 1. Hiển thị giá giảm (Giá thực tế - in đậm)
                                            echo '<strong>' . number_format($item['DiscountedPrice'], 0, ',', '.') . ' đ</strong>';
                                            
                                            // 2. Hiển thị giá gốc (Bị gạch ngang - thêm style gạch ngang và màu xám)
                                            echo '<del class="account-order-item-old-price">' . number_format($item['UnitPrice'], 0, ',', '.') . ' đ</del>';
                                            
                                        } else {
                                            // Trường hợp không có giảm giá thì hiện giá gốc bình thường
                                            echo number_format($item['UnitPrice'], 0, ',', '.') . ' đ';
                                        }
                                        // [LOGIC MỚI KẾT THÚC]
                                        ?>
                                        <div class="mt-2">
                                            <a href="product-detail.php?id=<?php echo urlencode($item['ProductID']); ?>&sku=<?php echo urlencode($item['SKUID']); ?>#review-section" 
                                            class="btn account-btn-secondary account-btn-rate">
                                                <i class="fas fa-star me-1"></i> Đánh giá sản phẩm này
                                            </a>
                                        </div>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="account-tracking-details">
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Thanh toán:</span>
                            <span class="account-tracking-detail-value"><?php echo htmlspecialchars($order['PaymentMethod'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Địa chỉ:</span>
                            <span class="account-tracking-detail-value"><?php echo htmlspecialchars($order['ShippingAddress'] ?? 'N/A'); ?></span>
                        </div>
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Đơn vị vận chuyển:</span>
                            <span class="account-tracking-detail-value">
                                <?php 
                                    echo !empty($order['CarrierName']) ? htmlspecialchars($order['CarrierName']) : '-'; 
                                ?>
                            </span>
                        </div>
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Voucher:</span>
                            <span class="account-tracking-detail-value">
                                <?php 
                                if (!empty($order['VoucherValue'])) {
                                    // Kiểm tra loại giảm giá (Percentage/Percent/Phần trăm/%)
                                    if (strcasecmp($order['VoucherType'], 'PERCENT') == 0 || $order['VoucherType'] == '%') {
                                        
                                        // 1. Hiển thị % giảm
                                        echo '-' . number_format($order['VoucherValue'], 0) . '%';
                                        
                                        // 2. Hiển thị Max Discount (Nếu có và > 0)
                                        if (!empty($order['MaxDiscount']) && $order['MaxDiscount'] > 0) {
                                            echo ' (Tối đa ' . number_format($order['MaxDiscount'], 0, ',', '.') . 'đ)</span>';
                                        }
                                        
                                    } else {
                                        // Loại giảm tiền mặt trực tiếp
                                        echo '-' . number_format($order['VoucherValue'], 0, ',', '.') . ' đ';
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </span>
                        </div>
                        <div class="account-tracking-detail-item">
                            <span class="account-tracking-detail-label">Phí vận chuyển:</span>
                            <span class="account-tracking-detail-value">
                                <?php 
                                if (isset($order['ShippingPrice'])) {
                                    echo number_format($order['ShippingPrice'], 0, ',', '.') . ' đ';
                                } else {
                                    echo '0 đ';
                                }
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="account-order-footer">
                        <div class="account-order-total">
                            Tổng tiền: 
                            <?php 
                            // [LOGIC ĐÃ SỬA] 
                            
                            // 1. Lấy tiền ship (nếu null thì bằng 0)
                            $shipping_price = isset($order['ShippingPrice']) ? $order['ShippingPrice'] : 0;
                            
                            // 2. Tính Tổng tiền gốc (Tiền hàng + Ship)
                            // Lưu ý: Đảm bảo $order['TotalAmount'] là tổng tiền hàng chưa bao gồm ship theo logic DB của bạn
                            $original_total = $order['TotalAmount'] + $shipping_price;

                            // 3. Lấy Giá thực tế phải trả (Sau khi áp Voucher)
                            // Nếu dữ liệu null hoặc = 0 thì lấy tổng gốc
                            $final_total = (!empty($order['TotalAmountAfterVoucher']) && $order['TotalAmountAfterVoucher'] > 0) 
                                        ? $order['TotalAmountAfterVoucher'] 
                                        : $original_total;

                            // 4. So sánh và hiển thị
                            if ($final_total < $original_total) {
                                
                                // TRƯỜNG HỢP 1: Có áp dụng Voucher (Giá cuối < Tổng gốc)
                                
                                // Hiện giá đã giảm (In đậm)
                                echo '<strong>' . number_format($final_total, 0, ',', '.') . ' đ</strong>';
                                
                                // Hiện giá gốc (Gạch ngang, màu xám)
                                echo ' <del class="account-order-item-old-price">' . number_format($original_total, 0, ',', '.') . ' đ</del>';
                                
                            } else {
                                
                                // TRƯỜNG HỢP 2: Không có voucher hoặc giá không đổi
                                // Chỉ hiện 1 giá duy nhất
                                echo '<strong>' . number_format($final_total, 0, ',', '.') . ' đ</strong>';
                            }
                            ?>
                        </div>
                        <div class="account-order-actions">
                            <a href="shop.php" class="btn account-btn-secondary account-btn-rebuy text-decoration-none">
                                <i class="fas fa-redo"></i> Mua lại
                            </a>

                             <?php
                            // Logic hiển thị nút Trả hàng
                            $has_return = isset($return_map[$order['OrderID']]);
                            
                            // Nếu đã có đơn trả hàng -> Xem chi tiết
                            if ($has_return) {
                                $r_id = $return_map[$order['OrderID']]['ReturnID'];
                                echo '<button class="btn account-btn-secondary" data-bs-toggle="modal" data-bs-target="#viewReturnModal" onclick="setViewReturnData(\''.$r_id.'\')">
                                        <i class="fas fa-eye"></i> Xem trả hàng
                                      </button>';
                            } 
                            // Nếu chưa trả, Status là Đã nhận VÀ trong vòng 7 ngày -> Nút Trả hàng
                            elseif ($order['Status'] === 'Đã nhận' && isReturnWindowOpen($order['DateReceived'])) {
                                echo '<button class="btn account-btn-secondary account-btn-return" data-bs-toggle="modal" data-bs-target="#returnModal" onclick="setReturnOrderData(\''.$order['OrderID'].'\', this.closest(\'.account-order-card\'))">
                                        <i class="fas fa-undo"></i> Trả hàng
                                      </button>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="account-pagination-wrapper account-orders-pagination">
            <nav>
                <ul class="pagination justify-content-center">
                    <?php 
                    // [FIX LỖI CHUYỂN TRANG] 
                    // 1. Lấy toàn bộ tham số hiện tại trên URL (ví dụ: act=history, type=user...)
                    $params = $_GET;
                    
                    // 2. Xóa tham số 'page' cũ đi (để tránh bị trùng lặp khi nối chuỗi)
                    unset($params['page']);

                    // 3. Đảm bảo tham số status luôn đúng với biến $filter hiện tại
                    $params['status'] = $filter;

                    // 4. Tạo lại chuỗi query chuẩn (Ví dụ: ?act=history&status=all&page=)
                    // http_build_query sẽ tự động nối các tham số lại với nhau
                    $url_param = "?" . http_build_query($params) . "&page=";
                    ?>

                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $url_param . ($page - 1); ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $url_param . $i; ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $url_param . ($page + 1); ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header account-modal-header">
                <h5 class="modal-title">Yêu cầu trả hàng</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="returnForm" onsubmit="return validateReturnForm()">
                <div class="modal-body">
                    <input type="hidden" name="action" value="request_return">
                    <input type="hidden" name="order_id" id="return_order_id">
                    
                    <div class="alert alert-info account-orders-return-alert">
                        <i class="fas fa-info-circle"></i> Vui lòng chọn sản phẩm, lý do và tải ảnh minh chứng để được hỗ trợ nhanh nhất.
                    </div>

                    <div id="return_items_container"></div>
                </div>
                <div class="modal-footer account-modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn account-btn-save">Gửi yêu cầu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header account-modal-header">
                <h5 class="modal-title">Theo dõi trả hàng</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="account-tracking-timeline" id="return_timeline_container"></div>

                <div id="return_success_message"class="account-return-success account-orders-hidden">
                    <i class="fas fa-check-circle"></i>
                    <h4>Đã hoàn tất trả hàng</h4>
                    <p id="return_refund_text"></p>
                </div>

                <div id="return_items_info_container" class="account-orders-return-items-info"></div>
            </div>
            <div class="modal-footer account-modal-footer">
                <form method="POST" id="cancelReturnForm">
                    <input type="hidden" name="action" value="cancel_return">
                    <input type="hidden" name="return_id" id="cancel_return_id_input">
                    <button type="submit" id="cancel_return_btn" class="btn btn-danger account-orders-hidden " onclick="return confirm('Bạn chắc chắn muốn hủy yêu cầu này? Mọi dữ liệu trả hàng sẽ bị xóa.');">Hoàn tác</button>
                </form>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script src="moonlit.js"></script>

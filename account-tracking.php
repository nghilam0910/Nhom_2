<?php
/**
 * Order Tracking Section
 * Display active orders
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

if (isset($_GET['payment'])) {
    $paymentType = $_GET['payment'] ?? '';
    $paymentMsg  = $_GET['msg'] ?? '';
    $paymentOrderId = $_GET['orderId'] ?? '';

    $alertClass = 'info';
    if ($paymentType === 'success') $alertClass = 'success';
    elseif ($paymentType === 'cancel') $alertClass = 'warning';
    elseif ($paymentType === 'error') $alertClass = 'danger';

    if ($paymentMsg === '') {
        if ($paymentType === 'success') $paymentMsg = 'Thanh toán thành công.';
        elseif ($paymentType === 'cancel') $paymentMsg = 'Bạn đã hủy giao dịch hoặc thanh toán thất bại.';
        elseif ($paymentType === 'error') $paymentMsg = 'Có lỗi xảy ra khi xử lý thanh toán.';
    }

    $payment_alert_html = '<div class="alert alert-' . htmlspecialchars($alertClass) . ' account-alert" role="alert">'
        . htmlspecialchars($paymentMsg);

    if ($paymentOrderId !== '') {
        $payment_alert_html .= '<br>Mã đơn: <strong>' . htmlspecialchars($paymentOrderId) . '</strong>';
    }

    $payment_alert_html .= '</div>';
}
// --- PHẦN 1: XỬ LÝ FORM (GIỮ NGUYÊN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_received') {
    $order_id_confirm = $_POST['order_id'] ?? 0;
    $stmt_update = $pdo->prepare("
        UPDATE `Order` 
        SET Status = 'Đã nhận',
            DateReceived = NOW() 
        WHERE OrderID = ? AND UserID = ? AND Status = 'Đã giao'
    ");

        if ($stmt_update->execute([$order_id_confirm, $user_id])) {
        
        // ==================================================================
        // BẮT ĐẦU: LOGIC CỘNG ĐIỂM (DÁN ĐOẠN CODE CỦA BẠN VÀO ĐÂY)
        // ==================================================================
        try {
            // 1. Lấy thông tin số tiền của đơn hàng này
            // Lưu ý: Mình dùng $order_id_confirm cho khớp với biến ở trên
            $stmtGetOrder = $pdo->prepare("SELECT TotalAmount, TotalAmountAfterVoucher FROM `Order` WHERE OrderID = ? AND UserID = ?");
            $stmtGetOrder->execute([$order_id_confirm, $user_id]);
            $orderData = $stmtGetOrder->fetch();
        
            if ($orderData) {
                // Tùy chọn: Nên tính điểm dựa trên số tiền THỰC TRẢ (Sau voucher) nếu có
                // Nếu TotalAmountAfterVoucher > 0 thì dùng nó, ngược lại dùng TotalAmount
                $amountToCalc = ($orderData['TotalAmountAfterVoucher'] > 0) ? $orderData['TotalAmountAfterVoucher'] : $orderData['TotalAmount'];
                
                // 2. Tính số điểm (10.000đ = 1 điểm)
                $pointsEarned = floor($amountToCalc / 10000);
                $reasonString = 'Tích điểm đơn hàng ' . $order_id_confirm;
        
                // 3. Kiểm tra xem đơn này đã từng được cộng điểm chưa
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM Point_History WHERE UserID = ? AND Reason = ?");
                $stmtCheck->execute([$user_id, $reasonString]);
        
                if ($stmtCheck->fetchColumn() == 0 && $pointsEarned > 0) {
                    // 4. Cộng điểm và ghi lịch sử
                    $pdo->beginTransaction(); 
                    
                    // Cập nhật điểm tích lũy
                    $updateUser = $pdo->prepare("UPDATE User_Account SET Points = Points + ? WHERE UserID = ?");
                    $updateUser->execute([$pointsEarned, $user_id]);
        
                    // Ghi lịch sử
                    $insertHistory = $pdo->prepare("INSERT INTO Point_History (UserID, PointChange, Reason, CreatedDate) VALUES (?, ?, ?, NOW())");
                    $insertHistory->execute([$user_id, $pointsEarned, $reasonString]);
        
                    $pdo->commit();
                }
            }
        } catch (Exception $e) {
            // Nếu lỗi cộng điểm thì rollback, nhưng KHÔNG dừng luồng chính (khách vẫn nhận được thông báo thành công đơn hàng)
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
        // ==================================================================
        // KẾT THÚC LOGIC CỘNG ĐIỂM
        // ==================================================================

        // Sau khi cộng điểm xong thì mới Alert và Reload
        echo "<script>alert('Đã xác nhận nhận hàng và tích điểm thành công!'); window.location.href = window.location.href;</script>";
        exit;
        }
    }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $order_id_cancel = $_POST['order_id'] ?? 0;
    $cancel_reason = $_POST['cancel_reason'] ?? 'Không có lý do cụ thể';
    $stmt_cancel = $pdo->prepare("
        UPDATE `Order` 
        SET 
            Status = 'Bị hủy',
            Note = CONCAT(IFNULL(Note, ''), '\nLý do hủy đơn: ', ?)
        WHERE OrderID = ? AND UserID = ? AND Status = 'Chờ xác nhận'
    ");
    if ($stmt_cancel->execute([$cancel_reason, $order_id_cancel, $user_id])) {
        echo "<script>alert('Đã hủy đơn hàng thành công!'); window.location.href = window.location.href;</script>";
        exit;
    }
}

// --- PHẦN 2: LẤY DỮ LIỆU ĐƠN HÀNG (CẬP NHẬT) ---
// Thêm LEFT JOIN Shipping_Order và Carrier để lấy tên nhà vận chuyển
$stmt = $pdo->prepare("
    SELECT
        o.OrderID,
        o.TotalAmount,
        o.TotalAmountAfterVoucher,
        o.Status,
        o.PaymentMethod,
        o.PaymentStatus,
        o.ShippingCity,
        o.ShippingDistrict,
        o.ShippingWard,
        o.ShippingStreet,
        o.ShippingNumber,
        o.CreatedDate,
        oi.OrderItemID,
        oi.Quantity,
        oi.UnitPrice,
        p.ProductName,
        p.Image,
        p.ImageUrl,   
        s.Format,
        s.ISBN,
        c.CarrierName,
        c.ShippingPrice,
        oi.DiscountedPrice,
        v.DiscountValue,
        v.MaxDiscount, 
        v.DiscountType 
    FROM `Order` o
    LEFT JOIN Order_Items oi ON o.OrderID = oi.OrderID
    LEFT JOIN SKU s ON oi.SKU_ID = s.SKUID
    LEFT JOIN Product p ON s.ProductID = p.ProductID
    LEFT JOIN Shipping_Order so ON o.OrderID = so.OrderID
    LEFT JOIN Carrier c ON so.CarrierID = c.CarrierID
    LEFT JOIN User_Voucher uv ON o.OrderID = uv.OrderID
    LEFT JOIN Voucher v ON uv.VoucherID = v.VoucherID
    WHERE o.UserID = ? AND o.Status NOT IN ('Đã nhận', 'Bị hủy', 'Trả hàng', 'Đã hoàn tiền')
    ORDER BY o.CreatedDate DESC
");
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

// Group orders by OrderID
$grouped_orders = [];
foreach ($orders as $order) {
    $order_id = $order['OrderID'];
    
    if (!isset($grouped_orders[$order_id])) {
        $address_parts = array_filter([
            $order['ShippingNumber'] ?? '', 
            $order['ShippingStreet'] ?? '', 
            $order['ShippingWard'] ?? '', 
            $order['ShippingDistrict'] ?? '', 
            $order['ShippingCity'] ?? ''
        ], function($value) {
            return !empty(trim($value));
        });
        $full_shipping_address = implode(', ', $address_parts);

        $grouped_orders[$order_id] = [
            'OrderID' => $order['OrderID'],
            'TotalAmount' => $order['TotalAmount'],
            'TotalAmountAfterVoucher' => $order['TotalAmountAfterVoucher'],
            'Status' => $order['Status'],
            'PaymentMethod' => $order['PaymentMethod'],
            'PaymentStatus' => $order['PaymentStatus'],
            'ShippingAddress' => $full_shipping_address,
            'CreatedDate' => $order['CreatedDate'],
            'CarrierName' => $order['CarrierName'], /* <--- LƯU TÊN NHÀ VẬN CHUYỂN */
            'ShippingPrice' => $order['ShippingPrice'],
            'VoucherValue' => $order['DiscountValue'], 
            'VoucherType' => $order['DiscountType'],
            'MaxDiscount' => $order['MaxDiscount'],
            'Items' => []
        ];
    }
    
    if (!empty($order['ProductName'])) {
        $grouped_orders[$order_id]['Items'][] = [
            'ProductName' => $order['ProductName'],
            'Image' => $order['Image'],
            'ImageUrl' => $order['ImageUrl'],
            'Format' => $order['Format'],
            'ISBN' => $order['ISBN'],
            'Quantity' => $order['Quantity'],
            'UnitPrice' => $order['UnitPrice'],
            'DiscountedPrice' => $order['DiscountedPrice']
        ];
    }
}

// Map status settings
$status_config = [
    'Đang xử lý' => ['icon' => 'fa-clock', 'color' => 'warning'],
    'Chờ xác nhận' => ['icon' => 'fa-hourglass-half', 'color' => 'secondary'],
    'Đang giao' => ['icon' => 'fa-truck', 'color' => 'info'], 
    'Đã giao' => ['icon' => 'fa-box-open', 'color' => 'primary'],
    'Đã xác nhận' => ['icon' => 'fa-check-circle', 'color' => 'success'],
];
?>

<div class="account-section" data-page-id="tracking-page">
    <h2 class="account-section-title">Theo dõi đơn hàng</h2>
    <?php if (!empty($payment_alert_html)) echo $payment_alert_html; ?>
    <?php if (empty($grouped_orders)): ?>
        <div class="account-empty-state">
            <i class="fas fa-check-circle"></i>
            <p class="account-empty-text">Bạn không có đơn hàng nào đang giao.</p>
        </div>
    <?php else: ?>
        <div class="account-orders-list">
            <?php foreach ($grouped_orders as $order): ?>
                <?php 
                    $current_status = $order['Status'];
                    $status_info = $status_config[$current_status] ?? ['icon' => 'fa-circle', 'color' => 'secondary'];
                ?>
                <div class="account-order-card account-order-card-tracking">
                    <div class="account-order-header">
                        <div class="account-order-info">
                            <span class="account-order-id">Đơn hàng: #<?php echo htmlspecialchars($order['OrderID']); ?></span>
                            <span class="account-order-date">
                                <?php echo date('d/m/Y H:i', strtotime($order['CreatedDate'])); ?>
                            </span>
                        </div>
                        <span class="account-order-status text-<?php echo $status_info['color']; ?>">
                            <i class="fas <?php echo $status_info['icon']; ?>"></i> <?php echo htmlspecialchars($current_status); ?>
                        </span>
                    </div>
                    <div class="account-tracking-timeline">
                        <?php 
                        // --- LOGIC XÁC ĐỊNH TRẠNG THÁI ACTIVE CHO TỪNG BƯỚC ---
                        
                        // Bước 1: Chờ xác nhận (Luôn luôn active vì là bước khởi đầu)
                        $step1_active = true;

                        // Bước 2: Đã xác nhận (Sáng khi trạng thái là Đang xử lý, Đang giao hoặc Đã giao)
                        // Lưu ý: Trong Database thường là 'Đang xử lý', ta hiển thị là 'Đã xác nhận'
                        $step2_active = in_array($current_status, ['Đã xác nhận', 'Đang giao', 'Đã giao', 'Đã nhận']);

                        // Bước 3: Đang giao (Sáng khi trạng thái là Đang giao hoặc Đã giao)
                        $step3_active = in_array($current_status, ['Đang giao', 'Đã giao', 'Đã nhận']);

                        // Bước 4: Đã giao (Sáng khi trạng thái là Đã giao)
                        $step4_active = in_array($current_status, ['Đã giao', 'Đã nhận']);
                        ?>

                        <div class="account-tracking-step account-tracking-step-active">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Chờ xác nhận</div>
                        </div>

                        <div class="account-tracking-line <?php echo $step2_active ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo $step2_active ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đã xác nhận</div>
                        </div>

                        <div class="account-tracking-line <?php echo $step3_active ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo $step3_active ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đang giao</div>
                        </div>

                        <div class="account-tracking-line <?php echo $step4_active ? 'account-tracking-line-active' : ''; ?>"></div>

                        <div class="account-tracking-step <?php echo $step4_active ? 'account-tracking-step-active' : ''; ?>">
                            <div class="account-tracking-step-marker"></div>
                            <div class="account-tracking-step-label">Đã giao</div>
                        </div>
                    </div>

                    <div class="account-order-items">
                        <?php foreach ($order['Items'] as $item): ?>
                            <div class="account-order-item">
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
                                        // [LOGIC MỚI] Kiểm tra nếu có giá giảm VÀ giá giảm nhỏ hơn giá gốc
                                        if (!empty($item['DiscountedPrice']) && $item['DiscountedPrice'] < $item['UnitPrice']) {
                                            
                                            // 1. Hiển thị giá sau giảm (In đậm)
                                            echo '<strong>' . number_format($item['DiscountedPrice'], 0, ',', '.') . ' đ</strong>';
                                            
                                            // 2. Hiển thị giá gốc (Gạch ngang - màu xám)
                                            echo ' <del class="account-order-item-old-price">' . number_format($item['UnitPrice'], 0, ',', '.') . ' đ</del>';
                                            
                                        } else {
                                            // Trường hợp không có giảm giá thì hiện giá gốc bình thường
                                            echo number_format($item['UnitPrice'], 0, ',', '.') . ' đ';
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="account-tracking-details">
                        <div class="account-tracking-detail-item">
    <span class="account-tracking-detail-label">Thanh toán:</span>
    <span class="account-tracking-detail-value">
        <?php
        $payMethod = strtoupper(trim($order['PaymentMethod'] ?? ''));
if ($payMethod === 'COD') {
    echo 'COD';
} elseif ($payMethod === 'BANK_QR') {
    echo 'MoMo QR';
} elseif ($payMethod === 'BANK_ATM') {
    echo 'ATM nội địa';
} else {
    echo htmlspecialchars($order['PaymentMethod'] ?? 'N/A');
}
?>
    </span>
</div>

<div class="account-tracking-detail-item">
    <span class="account-tracking-detail-label">Trạng thái thanh toán:</span>
    <span class="account-tracking-detail-value">
        <?php echo htmlspecialchars($order['PaymentStatus'] ?? 'N/A'); ?>
    </span>
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
                            // 1. Lấy các giá trị cần thiết
                            $goods_total = $order['TotalAmount'] ?? 0;      // Tiền hàng
                            $shipping_fee = $order['ShippingPrice'] ?? 0;   // Tiền ship
                            $paid_amount = $order['TotalAmountAfterVoucher'] ?? 0; // Tiền khách phải trả cuối cùng (sau voucher)

                            // 2. Tính TỔNG TIỀN GỐC (Hàng + Ship)
                            $real_total_calculated = $goods_total + $shipping_fee;

                            // 3. Logic hiển thị
                            // Kiểm tra: Nếu có "Tiền sau voucher" và nó KHÁC với "Tổng tiền gốc" (nghĩa là có áp dụng giảm giá)
                            if ($paid_amount > 0 && $paid_amount != $real_total_calculated) {
                                
                                // Hiện số tiền thực trả (In đậm) - Đây là TotalAmountAfterVoucher
                                echo '<strong>' . number_format($paid_amount, 0, ',', '.') . ' đ</strong>';
                                
                                // Hiện số tiền gốc tự tính (Gạch ngang) - Đây là (TotalAmount + Ship)
                                echo ' <del class="account-order-item-old-price">' . number_format($real_total_calculated, 0, ',', '.') . ' đ</del>';

                            } else {
                                // Trường hợp không có voucher hoặc giá không đổi
                                // Chỉ hiện Tổng tiền gốc tự tính
                                echo '<strong>' . number_format($real_total_calculated, 0, ',', '.') . ' đ</strong>';
                            }
                            ?>
                        </div>

                        <?php
$payMethod = strtoupper(trim($order['PaymentMethod'] ?? ''));
$payStatus = strtoupper(trim($order['PaymentStatus'] ?? ''));
?>

<?php if ($current_status === 'Đã giao'): ?>
    <form method="POST" onsubmit="return confirm('Bạn xác nhận đã nhận được đầy đủ hàng?');">
        <input type="hidden" name="action" value="confirm_received">
        <input type="hidden" name="order_id" value="<?php echo $order['OrderID']; ?>">
        
        <button type="submit" class="btn-tracking-action btn-green">
            <i class="fas fa-check-circle"></i> Đã nhận hàng
        </button>
    </form>

<?php elseif ($current_status === 'Chờ thanh toán'): ?>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if (in_array($payMethod, ['BANK_QR', 'BANK_ATM']) && $payStatus === 'PENDING'): ?>
            <?php
                $requestType = ($payMethod === 'BANK_QR') ? 'captureWallet' : 'payWithATM';

                $amountToPay = 0;
                if (!empty($order['TotalAmountAfterVoucher']) && $order['TotalAmountAfterVoucher'] > 0) {
                    $amountToPay = $order['TotalAmountAfterVoucher'];
                } else {
                    $amountToPay = ($order['TotalAmount'] ?? 0) + ($order['ShippingPrice'] ?? 0);
                }
            ?>
            <form action="fh_amt_momo.php" method="post" style="display:inline-block;">
                <input type="hidden" name="soTien" value="<?php echo (float)$amountToPay; ?>">
                <input type="hidden" name="orderId" value="<?php echo htmlspecialchars($order['OrderID']); ?>">
                <input type="hidden" name="orderInfo" value="<?php echo htmlspecialchars('Thanh toán đơn hàng ' . $order['OrderID']); ?>">
                <input type="hidden" name="requestType" value="<?php echo htmlspecialchars($requestType); ?>">
                <button type="submit" class="btn-tracking-action btn-green">
                    <i class="fas fa-credit-card"></i> Thanh toán ngay
                </button>
            </form>
        <?php endif; ?>

        <button type="button" class="btn-tracking-action btn-red" onclick="openCancelModal('<?php echo $order['OrderID']; ?>')">
            <i class="fas fa-times-circle"></i> Hủy đơn hàng
        </button>
    </div>
<?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="cancelOrderModal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="closeCancelModal()">&times;</span>
        <div class="modal-header">
            <div class="modal-title">Lý do hủy đơn hàng</div>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" id="modal_order_id" name="order_id" value="">
            
            <ul class="reason-list">
                <li class="reason-item">
                    <input type="radio" id="r1" name="cancel_reason" value="Muốn thay đổi địa chỉ/số điện thoại nhận hàng" required>
                    <label for="r1">Muốn thay đổi địa chỉ/số điện thoại nhận hàng</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r2" name="cancel_reason" value="Muốn thay đổi sản phẩm (size, màu, số lượng...)">
                    <label for="r2">Muốn thay đổi sản phẩm (size, màu, số lượng...)</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r3" name="cancel_reason" value="Thủ tục thanh toán quá rắc rối">
                    <label for="r3">Thủ tục thanh toán quá rắc rối</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r4" name="cancel_reason" value="Tìm thấy giá rẻ hơn ở chỗ khác">
                    <label for="r4">Tìm thấy giá rẻ hơn ở chỗ khác</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r5" name="cancel_reason" value="Đổi ý, không muốn mua nữa">
                    <label for="r5">Đổi ý, không muốn mua nữa</label>
                </li>
                <li class="reason-item">
                    <input type="radio" id="r6" name="cancel_reason" value="Lý do khác">
                    <label for="r6">Lý do khác</label>
                </li>
            </ul>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeCancelModal()">Đóng</button>
                <button type="submit" class="btn-danger-confirm">Xác nhận hủy</button>
            </div>
        </form>
    </div>
</div>
<script src="moonlit.js"></script>

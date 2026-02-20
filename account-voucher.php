<?php
/**
 * Voucher & Point Exchange Page
 * Logic:
 * 1. Auto-Sync Points: Tự động cộng điểm từ đơn hàng "Đã nhận" (100k = 10 điểm).
 * 2. Auto-Claim Voucher: Tự động nhận voucher Rank/Free.
 * 3. Redeem Voucher: Đổi điểm lấy voucher thường.
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// ============================================================================
// FUNCTION: Calculate User Rank (Dựa trên tổng chi tiêu)
// ============================================================================
function calculateUserRank($pdo, $user_id) {
    // SỬA: Join thêm bảng Returns_Order để trừ tiền hoàn lại
    // Chỉ trừ tiền khi đơn trả hàng đã có trạng thái 'Chấp thuận'
    $stmt = $pdo->prepare("
        SELECT (SUM(o.TotalAmount) - COALESCE(SUM(ro.TotalRefund), 0)) as total_spent
        FROM `Order` o
        LEFT JOIN Returns_Order ro ON o.OrderID = ro.OrderID AND ro.Status = 'Chấp thuận'
        WHERE o.UserID = ? AND o.Status IN ('Đã nhận', 'Đã hoàn tiền', 'Trả hàng')
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $total_spent = $result['total_spent'] ?? 0;

    if ($total_spent < 100000) {
        return ['rank' => 'Member', 'tier' => 'Member', 'total' => $total_spent];
    } elseif ($total_spent < 200000) {
        return ['rank' => 'Bronze', 'tier' => 'Bronze', 'total' => $total_spent];
    } elseif ($total_spent < 300000) {
        return ['rank' => 'Silver', 'tier' => 'Silver', 'total' => $total_spent];
    } elseif ($total_spent < 400000) {
        return ['rank' => 'Gold', 'tier' => 'Gold', 'total' => $total_spent];
    } else {
        return ['rank' => 'Platinum', 'tier' => 'Platinum', 'total' => $total_spent];
    }
}

// ============================================================================
// FUNCTION: Generate Voucher ID
// ============================================================================
function generateVoucherId($pdo) {
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ID, 2) AS UNSIGNED)) as max_id FROM User_Voucher");
    $result = $stmt->fetch();
    $next_id = ($result['max_id'] ?? 0) + 1;
    return 'V' . str_pad($next_id, 5, '0', STR_PAD_LEFT);
}


// ============================================================================
// 2. Fetch user data (Sau khi đã Sync điểm xong)
// ============================================================================
$stmt = $pdo->prepare("SELECT UserID, Points FROM User_Account WHERE UserID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$current_points = $user['Points'] ?? 0;
$user_rank = calculateUserRank($pdo, $user_id);

// ============================================================================
// 3. LOGIC AUTO-CLAIM (Tự động nhận Voucher Rank/Free)
// ============================================================================
if (!empty($user_rank['tier'])) {
    $stmt = $pdo->prepare("
        SELECT VoucherID, Code 
        FROM Voucher
        WHERE (RankRequirement = ? OR RankRequirement = 'Free')     
        AND Status = 1                  
        AND (EndDate IS NULL OR EndDate > NOW())
        AND UsedCount < UsageLimit
        AND VoucherID NOT IN (          
            SELECT VoucherID FROM User_Voucher WHERE UserID = ?
        )
    ");
    $stmt->execute([$user_rank['tier'], $user_id]);
    $rank_rewards = $stmt->fetchAll();

    if (!empty($rank_rewards)) {
        foreach ($rank_rewards as $reward) {
            try {
                $new_uv_id = generateVoucherId($pdo);
                $insertStmt = $pdo->prepare("INSERT INTO User_Voucher (ID, UserID, VoucherID, DateReceived) VALUES (?, ?, ?, NOW())");
                $insertStmt->execute([$new_uv_id, $user_id, $reward['VoucherID']]);

                $pdo->prepare(" UPDATE Voucher SET UsedCount = UsedCount + 1, Status = CASE WHEN UsedCount >= UsageLimit THEN 0 ELSE Status END WHERE VoucherID = ?")->execute([$reward['VoucherID']]);
                
                $message .= "🎁 Quà tặng: Bạn nhận được voucher " . ($reward['Code']) . "<br>";
                $message_type = "success";
            } catch (Exception $e) { }
        }
    }
}

// ============================================================================
// 4. Handle Voucher Redemption (Đổi điểm lấy Voucher thường)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'redeem_voucher') {
    $voucher_id = trim($_POST['voucher_id'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM Voucher WHERE VoucherID = ? AND Status = 1 AND RankRequirement = 'None'");
    $stmt->execute([$voucher_id]);

    if ($stmt->rowCount() === 0) {
        $message = 'Voucher không tồn tại.';
        $message_type = 'danger';
    } else {
        $voucher = $stmt->fetch();
        // Validation...
        if ($current_points < $voucher['VoucherPoint']) {
            $message = 'Không đủ điểm.';
            $message_type = 'danger';
        } elseif ($voucher['UsedCount'] >= $voucher['UsageLimit']) {
            $message = 'Hết lượt sử dụng.';
            $message_type = 'danger';
        } else {
                try {
                    $pdo->beginTransaction();

                    // Trừ điểm
                    $new_points = $current_points - $voucher['VoucherPoint'];
                    $pdo->prepare("UPDATE User_Account SET Points = ? WHERE UserID = ?")->execute([$new_points, $user_id]);

                    // Ghi lịch sử (Lý do: Đổi mã voucher [Code])
                    $reason = 'Đổi mã voucher ' . $voucher['Code'];
                    $pdo->prepare("INSERT INTO Point_History (UserID, PointChange, Reason, CreatedDate) VALUES (?, ?, ?, NOW())")
                        ->execute([$user_id, -$voucher['VoucherPoint'], $reason]);

                    // Thêm voucher
                    $user_voucher_id = generateVoucherId($pdo);
                    $pdo->prepare("INSERT INTO User_Voucher (ID, UserID, VoucherID, DateReceived) VALUES (?, ?, ?, NOW())")
                        ->execute([$user_voucher_id, $user_id, $voucher_id]);

                    // Update count
                    $pdo->prepare(" UPDATE Voucher SET UsedCount = UsedCount + 1, Status = CASE WHEN UsedCount >= UsageLimit THEN 0 ELSE Status END WHERE VoucherID = ?")->execute([$voucher_id]);

                    $pdo->commit();
                    $message = 'Đổi thành công!';
                    $message_type = 'success';
                    $current_points = $new_points;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = 'Lỗi: ' . $e->getMessage();
                    $message_type = 'danger';
                }
            }
        }
    }


// ============================================================================
// DATA FETCHING FOR VIEW
// ============================================================================
// --- 1. VÍ VOUCHER CỦA TÔI (Phân trang biến: page_my) ---
$limit_my = 5; // Số lượng hiển thị mỗi trang
$page_my = isset($_GET['page_my']) ? (int)$_GET['page_my'] : 1;
if ($page_my < 1) $page_my = 1;
$offset_my = ($page_my - 1) * $limit_my;

// Đếm tổng số voucher của tôi
$stmtCountMy = $pdo->prepare("
    SELECT COUNT(*) 
    FROM User_Voucher uv
    JOIN Voucher v ON uv.VoucherID = v.VoucherID
    WHERE uv.UserID = ? 
    AND uv.OrderID IS NULL
    AND v.Status = 1  -- Thêm dòng này
");
$stmtCountMy->execute([$user_id]);
$total_my = $stmtCountMy->fetchColumn();
$total_pages_my = ceil($total_my / $limit_my);

// Lấy dữ liệu phân trang
$stmt = $pdo->prepare("
    SELECT uv.ID, uv.DateReceived, v.Code, v.Code AS VoucherName, v.Description, v.EndDate, v.MinOrder, v.MaxDiscount 
    FROM User_Voucher uv JOIN Voucher v ON uv.VoucherID = v.VoucherID 
    WHERE uv.UserID = ? AND uv.OrderID IS NULL  AND v.Status = 1
    ORDER BY uv.DateReceived DESC
    LIMIT $limit_my OFFSET $offset_my
");
$stmt->execute([$user_id]);
$user_vouchers = $stmt->fetchAll();


// --- 2. VOUCHER CÓ THỂ ĐỔI (Phân trang biến: page_redeem) ---
$limit_redeem = 8; // Số lượng hiển thị mỗi trang
$page_redeem = isset($_GET['page_redeem']) ? (int)$_GET['page_redeem'] : 1;
if ($page_redeem < 1) $page_redeem = 1;
$offset_redeem = ($page_redeem - 1) * $limit_redeem;

// Đếm tổng số voucher có thể đổi
$stmtCountRedeem = $pdo->prepare("
    SELECT COUNT(*) FROM Voucher 
    WHERE Status = 1 AND UsedCount < UsageLimit AND RankRequirement = 'None'
    AND (EndDate IS NULL OR EndDate > NOW()) 
    AND VoucherID NOT IN (SELECT VoucherID FROM User_Voucher WHERE UserID = ?)
");
$stmtCountRedeem->execute([$user_id]);
$total_redeem = $stmtCountRedeem->fetchColumn();
$total_pages_redeem = ceil($total_redeem / $limit_redeem);

// Lấy dữ liệu phân trang
$stmt = $pdo->prepare("
    SELECT *, Code AS VoucherName FROM Voucher 
    WHERE Status = 1 AND UsedCount < UsageLimit AND RankRequirement = 'None'
    AND (EndDate IS NULL OR EndDate > NOW()) 
    AND VoucherID NOT IN (SELECT VoucherID FROM User_Voucher WHERE UserID = ?) 
    ORDER BY VoucherPoint ASC
    LIMIT $limit_redeem OFFSET $offset_redeem
");
$stmt->execute([$user_id]);
$available_vouchers = $stmt->fetchAll();

// 3. Lịch sử điểm (Hiển thị 50 dòng mới nhất)
$stmt = $pdo->prepare("
    SELECT PointID, PointChange, Reason, CreatedDate
    FROM Point_History
    WHERE UserID = ?
    ORDER BY CreatedDate DESC
    LIMIT 50
");
$stmt->execute([$user_id]);
$point_history = $stmt->fetchAll();
?>

<div class="account-section">
    <h2 class="account-section-title">Voucher & Đổi Điểm</h2>
    <div class="mb-4">
        <a href="policy.php#membership-policy" class="text-decoration-none d-inline-flex align-items-center" style="color: var(--color-deep-blue); font-size: 14px; background: #f0f4f8; padding: 10px 15px; border-radius: 8px; transition: 0.3s; width: 100%;">
            <i class="fas fa-info-circle me-2"></i>
            <span>Hiểu rõ hơn về <strong>chính sách thành viên & tích điểm</strong> của Moonlit</span>
            <i class="fas fa-chevron-right ms-2" style="font-size: 10px;"></i>
        </a>
    </div>
    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> account-alert" role="alert">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- ========================================================================
         SECTION 1: User Status (Rank & Points)
         ======================================================================== -->
    <div class="account-voucher-status-card">
        <div class="account-voucher-status-content">
            <div class="account-voucher-rank-section">
                <div class="account-voucher-rank-icon">
                    <?php if ($user_rank['tier'] === 'Platinum'): ?>
                        <i class="fas fa-crown"></i>
                    <?php elseif ($user_rank['tier'] === 'Gold'): ?>
                        <i class="fas fa-star"></i>
                    <?php elseif ($user_rank['tier'] === 'Silver'): ?>
                        <i class="fas fa-medal"></i>
                    <?php elseif ($user_rank['tier'] === 'Bronze'): ?>
                        <i class="fas fa-gem"></i>
                    <?php else: ?>
                        <i class="fas fa-user-circle"></i>
                    <?php endif; ?>
                </div>
                <div class="account-voucher-rank-info">
                    <p class="account-voucher-rank-label">Hạng thành viên</p>
                    <p class="account-voucher-rank-value"><?php echo htmlspecialchars($user_rank['rank']); ?></p>
                    <p class="account-voucher-spent">Đã chi: <?php echo number_format($user_rank['total'], 0, ',', '.'); ?> đ</p>
                </div>
            </div>

            <div class="account-voucher-points-section">
                <p class="account-voucher-points-label">Điểm hiện tại</p>
                <p class="account-voucher-points-value"><?php echo $current_points; ?></p>
                <p class="account-voucher-points-help">Có thể dùng để đổi voucher</p>
            </div>
        </div>
    </div>

    <!-- ========================================================================
         SECTION 2: My Vouchers (Ví Voucher)
         ======================================================================== -->
    <div class="account-card account-voucher-section">
        <h3 class="account-card-title">
            <i class="fas fa-ticket-alt"></i> Ví Voucher Của Tôi
        </h3>

        <?php if (empty($user_vouchers)): ?>
            <div class="account-voucher-empty">
                <i class="fas fa-inbox"></i>
                <p>Bạn chưa có voucher nào.</p>
            </div>
        <?php else: ?>
            <div class="account-voucher-list">
                <?php foreach ($user_vouchers as $voucher): ?>
                    <div class="account-voucher-item">
                        <div class="account-voucher-item-badge">
                            <i class="fas fa-gift"></i>
                        </div>
                        <div class="account-voucher-item-content">
                            <h4 class="account-voucher-item-name">
                                <?php echo htmlspecialchars($voucher['VoucherName']); ?>
                            </h4>
                            <div class="account-voucher-item-description">
                                <?php echo htmlspecialchars(substr($voucher['Description'], 0, 80)); ?>
                                <?php if ($voucher['MinOrder'] > 0): ?>
                                    <div>Đơn tối thiểu <strong><?php echo number_format($voucher['MinOrder'], 0, ',', '.'); ?> đ</strong></div>
                                <?php endif; ?>
                                <?php if ($voucher['MaxDiscount'] > 0): ?>
                                    <div>Giảm tối đa: <strong><?php echo number_format($voucher['MaxDiscount'], 0, ',', '.'); ?> đ</strong></div>
                                <?php endif; ?>
                            </div>
                            <div class="account-voucher-item-meta">
                                <span class="account-voucher-item-received">
                                    Nhận: <?php echo date('d/m/Y', strtotime($voucher['DateReceived'])); ?>
                                </span>
                                <?php if (!empty($voucher['EndDate'])): ?>
                                    <span class="account-voucher-item-expiry">
                                        Hết hạn: <?php echo date('d/m/Y', strtotime($voucher['EndDate'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div> 
                <?php endforeach; ?>
                <?php if ($total_pages_my > 1): ?>
                    <div class="d-flex justify-content-center mt-3">
                        <nav>
                            <ul class="pagination pagination-sm">
                                <?php for ($i = 1; $i <= $total_pages_my; $i++): ?>
                                    <li class="page-item <?php echo ($i == $page_my) ? 'active' : ''; ?>">
                                        <a class="page-link" href="?section=voucher&page_my=<?php echo $i; ?>&page_redeem=<?php echo $page_redeem; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========================================================================
         SECTION 3: Available Vouchers (Đổi Điểm)
         ======================================================================== -->
    <div class="account-card account-voucher-section">
        <h3 class="account-card-title">
            <i class="fas fa-coins"></i> Đổi Điểm Lấy Voucher
        </h3>

        <?php if (empty($available_vouchers)): ?>
            <div class="account-voucher-empty">
                <i class="fas fa-search"></i>
                <p>Không có voucher phù hợp cho hạng thành viên của bạn.</p>
            </div>
        <?php else: ?>
            <div class="account-voucher-redeem-list">
                <?php foreach ($available_vouchers as $voucher): ?>
                    <div class="account-voucher-redeem-card">
                        <div class="account-voucher-redeem-header">
                            <h4 class="account-voucher-redeem-name">
                                <?php echo htmlspecialchars($voucher['VoucherName']); ?>
                            </h4>
                        </div>

                        <div class="account-voucher-redeem-description">
                            <?php echo htmlspecialchars(substr($voucher['Description'], 0, 80)); ?>
                                <?php if ($voucher['MinOrder'] > 0): ?>
                                    <div>Đơn tối thiểu <strong><?php echo number_format($voucher['MinOrder'], 0, ',', '.'); ?> đ</strong></div>
                                <?php endif; ?>
                                <?php if ($voucher['MaxDiscount'] > 0): ?>
                                    <div>Giảm tối đa: <strong><?php echo number_format($voucher['MaxDiscount'], 0, ',', '.'); ?> đ</strong></div>
                                <?php endif; ?>
                        </div>

                        <div class="account-voucher-redeem-details">
                            <div class="account-voucher-redeem-detail-item">
                                <span class="account-voucher-redeem-detail-label">Giá:</span>
                                <span class="account-voucher-redeem-detail-value">
                                    <?php echo $voucher['VoucherPoint']; ?> điểm
                                </span>
                            </div>
                            <div class="account-voucher-redeem-detail-item">
                                <span class="account-voucher-redeem-detail-label">Còn lại:</span>
                                <span class="account-voucher-redeem-detail-value">
                                    <?php echo ($voucher['UsageLimit'] - $voucher['UsedCount']); ?> / <?php echo $voucher['UsageLimit']; ?>
                                </span>
                            </div>
                            <?php if (!empty($voucher['EndDate'])): ?>
                                <div class="account-voucher-redeem-detail-item">
                                    <span class="account-voucher-redeem-detail-label">Hết hạn:</span>
                                    <span class="account-voucher-redeem-detail-value">
                                        <?php echo date('d/m/Y', strtotime($voucher['EndDate'])); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form method="POST" class="account-voucher-redeem-form">
                            <input type="hidden" name="action" value="redeem_voucher">
                            <input type="hidden" name="voucher_id" value="<?php echo htmlspecialchars($voucher['VoucherID']); ?>">
                            <button type="submit" class="btn account-voucher-btn-redeem">
                                <i class="fas fa-save"></i> Lưu
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div> <?php if ($total_pages_redeem > 1): ?>
            <div class="d-flex justify-content-center mt-3">
                <nav>
                    <ul class="pagination pagination-sm">
                        <?php for ($i = 1; $i <= $total_pages_redeem; $i++): ?>
                            <li class="page-item <?php echo ($i == $page_redeem) ? 'active' : ''; ?>">
                                <a class="page-link" href="?section=voucher&page_redeem=<?php echo $i; ?>&page_my=<?php echo $page_my; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ========================================================================
         SECTION 4: Point History
         ======================================================================== -->
    <div class="account-card account-voucher-section">
        <h3 class="account-card-title">
            <i class="fas fa-history"></i> Lịch Sử Đổi Điểm
        </h3>

        <?php if (empty($point_history)): ?>
            <div class="account-voucher-empty">
                <i class="fas fa-file-invoice"></i>
                <p>Bạn chưa có lịch sử đổi điểm.</p>
            </div>
        <?php else: ?>
            <div class="account-voucher-history-table">
                <div class="account-voucher-history-header">
                    <div class="account-voucher-history-col-date">Ngày</div>
                    <div class="account-voucher-history-col-reason">Lý do</div>
                    <div class="account-voucher-history-col-change">Thay đổi</div>
                </div>

                <?php foreach ($point_history as $record): ?>
                    <div class="account-voucher-history-row">
                        <div class="account-voucher-history-col-date-data">
                            <?php echo date('d/m/Y H:i', strtotime($record['CreatedDate'])); ?>
                        </div>
                        <div class="account-voucher-history-col-reason-data">
                            <?php echo htmlspecialchars($record['Reason']); ?>
                        </div>
                        <div class="account-voucher-history-col-change">
                            <span class="account-voucher-history-change <?php echo $record['PointChange'] >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo ($record['PointChange'] >= 0 ? '+' : '') . $record['PointChange']; ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

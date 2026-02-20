<?php
$orderStatuses = [
    'Chờ xác nhận' => 'Chờ xác nhận',
    'Đã xác nhận' => 'Đã xác nhận / Chờ lấy hàng',
    'Đang giao' => 'Đang giao',
    'Đã giao' => 'Đã giao',
    'Đã hoàn tiền' => 'Trả hàng / Hoàn tiền',
    'Bị hủy' => 'Đã hủy',
];

$stmt = $pdo->query("
    SELECT
        COALESCE(SUM(o.TotalAmount), 0)
        - COALESCE(SUM(CASE WHEN ro.Status = 'Chấp thuận' THEN ro.TotalRefund ELSE 0 END), 0) AS revenue,
        COUNT(DISTINCT o.OrderID) AS orders
    FROM `Order` o
    LEFT JOIN Returns_Order ro
        ON ro.OrderID = o.OrderID
    WHERE o.Status IN ('Đã xác nhận', 'Đang giao', 'Đã giao', 'Đã nhận', 'Đã hoàn tiền')
");

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$totalRevenue = $row ? (float) $row['revenue'] : 0;
$totalOrders = $row ? (int) $row['orders'] : 0;
$avgOrder = $totalOrders ? $totalRevenue / $totalOrders : 0;

$totalCost = (float) $pdo->query("
    SELECT
        COALESCE(
            SUM(
                (oi.Quantity - COALESCE(r.ReturnedQty, 0)) * s.BuyPrice
            ), 0
        ) AS Cost
    FROM Order_Items oi
    JOIN SKU s ON oi.SKU_ID = s.SKUID
    JOIN `Order` o ON oi.OrderID = o.OrderID
    LEFT JOIN (
        SELECT
            ri.OrderItemID,
            SUM(ri.Quantity) AS ReturnedQty
        FROM Return_Items ri
        JOIN Returns_Order ro ON ri.ReturnID = ro.ReturnID
        WHERE ro.Status = 'Chấp thuận'
        GROUP BY ri.OrderItemID
    ) r ON oi.OrderItemID = r.OrderItemID
    WHERE o.Status IN ('Đã giao', 'Đã nhận', 'Đã hoàn tiền')
")->fetchColumn();

$profit = $totalRevenue - $totalCost;



$orderStatusCounts = [];
$stmt = $pdo->query("
    SELECT Status, COUNT(*) cnt
    FROM `Order`
    GROUP BY Status
");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $orderStatusCounts[$r['Status']] = (int) $r['cnt'];
}

$latestProducts = $pdo->query("
    SELECT ProductID, ProductName, Price, CreatedDate
    FROM Product
    ORDER BY CreatedDate DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$totalCustomers = (int) $pdo->query("
    SELECT COUNT(*) FROM User_Account where Role ='customer'
")->fetchColumn();
?>
<h2 class="account-section-title">Overview</h2>
<div class="row">
    <div class="col-md-3 mb-3">
        <div class="account-card">
            <div class="account-card-title">Doanh thu</div>
            <p class="fw-bold fs-5">
                <?= number_format($totalRevenue, 0, ',', '.') ?> đ
            </p>
        </div>
    </div>

    <div class="col-md-3 mb-3">
        <div class="account-card">
            <div class="account-card-title">Chi phí</div>
            <p class="fw-bold fs-5 text-danger">
                <?= number_format($totalCost, 0, ',', '.') ?> đ
            </p>
        </div>
    </div>

    <div class="col-md-3 mb-3">
        <div class="account-card">
            <div class="account-card-title">Lợi nhuận</div>
            <p class="fw-bold fs-5 text-success">
                <?= number_format($profit, 0, ',', '.') ?> đ
            </p>
        </div>
    </div>

    <div class="col-md-3 mb-3">
        <div class="account-card">
            <div class="account-card-title">Số đơn hàng thành công</div>
            <p class="fw-bold" style="font-size: 20px;">
                <?php echo (int) $totalOrders; ?>
            </p>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="account-card">
            <div class="account-card-title">Giá trị TB/đơn</div>
            <p class="fw-bold" style="font-size: 20px;">
                <?php echo number_format($avgOrder, 0, ',', '.'); ?> đ
            </p>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="account-card">
            <div class="account-card-title">Khách hàng</div>
            <p class="fw-bold" style="font-size: 20px;">
                <?php echo (int) $totalCustomers; ?>
            </p>
        </div>
    </div>
</div>

<!-- Đơn theo trạng thái -->
<div class="account-card mb-3">
    <h2 class="account-card-title">Đơn hàng theo trạng thái</h2>
    <div class="row">
        <?php foreach ($orderStatuses as $code => $label): ?>
            <div class="col-md-4 mb-2">
                <div class="d-flex justify-content-between">
                    <span><?php echo h($label); ?></span>
                    <strong>
                        <?php echo $orderStatusCounts[$code] ?? 0; ?>
                    </strong>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 10 sản phẩm mới -->
<div class="account-card">
    <h2 class="account-card-title">Sản phẩm mới nhất</h2>
    <?php if (empty($latestProducts)): ?>
        <p class="account-empty-text mb-0">
            Chưa có sản phẩm nào.
        </p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tên sách</th>
                        <th>Giá</th>
                        <th>Ngày tạo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latestProducts as $p): ?>
                        <tr>
                            <td><?php echo h($p['ProductID']); ?></td>
                            <td><?php echo h($p['ProductName']); ?></td>
                            <td><?php echo number_format($p['Price'], 0, ',', '.'); ?> đ</td>
                            <td><?php echo h($p['CreatedDate']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php
require_once 'db_connect.php';
$currentUserId = null;
function generateUserVoucherId(PDO $pdo)
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(ID, 2) AS UNSIGNED))
        FROM User_Voucher
        WHERE ID LIKE 'V%'
    ");
    $next = ((int) $stmt->fetchColumn()) + 1;
    return 'V' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

// ================= GÁN VOUCHER =================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['ajax'] ?? '') === 'assign_voucher'
) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length())
        ob_clean();

    try {
        $uid = $_POST['user_id'] ?? '';
        $vid = $_POST['voucher_id'] ?? '';

        if ($uid === '' || $vid === '') {
            throw new Exception('Thiếu user_id hoặc voucher_id');
        }

        // check trùng
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM User_Voucher
            WHERE UserID = ? AND VoucherID = ?
        ");
        $check->execute([$uid, $vid]);

        if ($check->fetchColumn() > 0) {
            throw new Exception('Khách đã có voucher này');
        }

        // INSERT
        $newId = generateUserVoucherId($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO User_Voucher (ID, UserID, VoucherID, DateReceived)
            VALUES (?, ?, ?, NOW())
        ");

        $stmt->execute([$newId, $uid, $vid]);

        // kiểm tra thật sự có insert không
        if ($stmt->rowCount() !== 1) {
            throw new Exception('Insert không thành công (rowCount = 0)');
        }

        // update voucher
        $pdo->prepare("
            UPDATE Voucher
            SET UsedCount = UsedCount + 1
            WHERE VoucherID = ?
        ")->execute([$vid]);

        echo json_encode(['success' => true]);
        exit;

    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// ================= XÓA VOUCHER CỦA USER =================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajax'])
    && $_POST['ajax'] === 'remove_voucher'
) {
    if (ob_get_length())
        ob_clean();
    $uid = $_POST['user_id'] ?? '';
    $vid = $_POST['voucher_id'] ?? '';
    header('Content-Type: application/json; charset=utf-8');
    if ($uid === '' || $vid === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Thiếu dữ liệu']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            DELETE FROM User_Voucher
            WHERE UserID = ? AND VoucherID = ?
        ");
        $stmt->execute([$uid, $vid]);


        echo json_encode(['success' => true]);
        exit;

    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
// ================= LOAD VOUCHER CỦA USER =================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'load_voucher') {

    $userId = $_GET['user_id'] ?? '';
    if ($userId === '') {
        echo '<p class="text-danger">Thiếu UserID</p>';
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
    v.VoucherID,
    v.VoucherName,
    v.Code,
    v.EndDate,
    uv.DateReceived
        FROM User_Voucher uv
        JOIN Voucher v ON uv.VoucherID = v.VoucherID
        WHERE uv.UserID = ?
        ORDER BY uv.DateReceived DESC
    ");
    $stmt->execute([$userId]);
    $vouchers = $stmt->fetchAll();

    if (empty($vouchers)) {
        echo "<p class='text-muted'>Khách chưa có voucher nào.</p>";
        exit;
    }

    echo "<ul class='list-group'>";
    foreach ($vouchers as $v) {

        $voucherName = htmlspecialchars($v['VoucherName']);
        $code = htmlspecialchars($v['Code']);
        $received = date('d/m/Y', strtotime($v['DateReceived']));
        $endDate = $v['EndDate']
            ? date('d/m/Y', strtotime($v['EndDate']))
            : 'Không';

        echo "
    <li class='list-group-item d-flex justify-content-between align-items-start'>
        <div>
            <strong>{$voucherName}</strong>
            <div>Mã: {$code}</div>
            <div>Nhận: {$received}</div>
            <div>Hết hạn: {$endDate}</div>
        </div>

        <button class='btn btn-sm btn-outline-danger'
            onclick=\"removeVoucher('{$userId}', '{$v['VoucherID']}')\">
            ❌
        </button>
    </li>";
    }

    echo "</ul>";
    exit;
}

// Lấy danh sách customer + điểm + rank
$sql = "
SELECT
    u.UserID,
    u.Username,
    u.FullName,
    u.Email,
    u.Phone,
    u.Points,

    COALESCE(SUM(o.TotalAmount - COALESCE(r.refund, 0)), 0) AS TotalSpent,

    CASE
        WHEN COALESCE(SUM(o.TotalAmount - COALESCE(r.refund, 0)), 0) < 100000 THEN 'Member'
        WHEN COALESCE(SUM(o.TotalAmount - COALESCE(r.refund, 0)), 0) < 200000 THEN 'Bronze'
        WHEN COALESCE(SUM(o.TotalAmount - COALESCE(r.refund, 0)), 0) < 300000 THEN 'Silver'
        WHEN COALESCE(SUM(o.TotalAmount - COALESCE(r.refund, 0)), 0) < 400000 THEN 'Gold'
        ELSE 'Platinum'
    END AS RankName

FROM User_Account u
LEFT JOIN `Order` o
       ON u.UserID = o.UserID
      AND o.Status IN ('Đã nhận', 'Đã hoàn tiền')

LEFT JOIN (
    SELECT
        OrderID,
        SUM(TotalRefund) AS refund
    FROM Returns_Order
    WHERE Status = 'Chấp thuận'
    GROUP BY OrderID
) r ON o.OrderID = r.OrderID

WHERE u.Role = 'Customer'

GROUP BY
    u.UserID,
    u.Username,
    u.FullName,
    u.Email,
    u.Phone,
    u.Points

ORDER BY TotalSpent DESC;
";

$stmt = $pdo->query($sql);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Quản lý sản phẩm | Moonlit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">
</head>

<body class="account-body admin-page">
    <div class="account-card">
        <h2 class="account-section-title">Danh sách khách hàng</h2>
        <?php if (empty($customers)): ?>
            <p>Chưa có khách hàng nào.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>UserID</th>
                            <th>Khách hàng</th>
                            <th>Email</th>
                            <th>Điện thoại</th>
                            <th>Điểm</th>
                            <th>Tổng chi</th>
                            <th>Hạng</th>
                            <th>Voucher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td><?= htmlspecialchars($c['UserID']) ?></td>
                                <td><?= htmlspecialchars($c['Username']) ?></td>
                                <td><?= htmlspecialchars($c['Email']) ?></td>
                                <td><?= htmlspecialchars($c['Phone']) ?></td>
                                <td><?= $c['Points'] ?></td>
                                <td><?= number_format($c['TotalSpent'], 0, ',', '.') ?> đ</td>
                                <td>
                                    <span class="badge-rank <?= strtolower($c['RankName']) ?>">
                                        <?= $c['RankName'] ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                        data-bs-target="#voucherModal" data-userid="<?= $c['UserID'] ?>"
                                        data-username="<?= htmlspecialchars($c['Username']) ?>">
                                        🎟 Xem / Gán
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="modal fade" id="voucherModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title">
                        Voucher của <span id="modalUsername"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <!-- danh sách voucher -->
                    <div id="voucherList">
                        <p class="text-muted">Đang tải...</p>
                    </div>

                    <hr>

                    <!-- thêm voucher -->
                    <form id="addVoucherForm">
                        <input type="hidden" name="user_id" id="voucherUserId">

                        <label class="form-label">Chọn voucher để gán</label>
                        <select name="voucher_id" class="form-select" required>
                            <option value="">-- Chọn voucher --</option>
                            <?php
                            $vStmt = $pdo->query("
                    SELECT VoucherID, VoucherName 
                    FROM Voucher 
                    WHERE Status = 1
                    ORDER BY VoucherName
                ");
                            foreach ($vStmt as $v):
                                ?>
                                <option value="<?= $v['VoucherID'] ?>">
                                    <?= htmlspecialchars($v['VoucherName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <button type="submit" class="btn btn-success mt-3">
                            ➕ Gán voucher
                        </button>
                    </form>
                </div>

            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const voucherModal = document.getElementById('voucherModal');

        voucherModal.addEventListener('show.bs.modal', function (event) {

            const button = event.relatedTarget; // nút bấm
            const userId = button.getAttribute('data-userid');
            const username = button.getAttribute('data-username');
            currentUserId = userId;
            // set tên user
            document.getElementById('modalUsername').innerText = username;
            document.getElementById('voucherUserId').value = userId;

            // load voucher
            loadVoucherList(userId);
        });

        // gán voucher
        document.getElementById('addVoucherForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('ajax', 'assign_voucher');

            fetch('admin-dashboard.php?tab=customers', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('Đã gán voucher thành công');
                        loadVoucherList(currentUserId);
                    } else {
                        alert(data.message || 'Gán voucher thất bại');
                    }
                });

        });
        //reload voucher
        function loadVoucherList(userId) {
            fetch('admin-dashboard.php?tab=customers&ajax=load_voucher&user_id=' + userId, {
                cache: 'no-store'
            })
                .then(res => res.text())
                .then(html => {
                    document.getElementById('voucherList').innerHTML = html;
                });
        }
        //xóa voucher
        function removeVoucher(userId, voucherId) {
            if (!confirm('Xóa voucher này khỏi khách hàng?')) return;

            const fd = new FormData();
            fd.append('ajax', 'remove_voucher');
            fd.append('user_id', userId);
            fd.append('voucher_id', voucherId);

            fetch('admin-dashboard.php?tab=customers', {
                method: 'POST',
                body: fd
            })
                .then(async (res) => {
                    const text = await res.text();      // <-- lấy text trước
                    console.log('REMOVE response status:', res.status);
                    console.log('REMOVE response text:', text);

                    // thử parse JSON
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('Server không trả JSON (xem Console log)');
                    }

                    if (!data.success) {
                        alert(data.message || 'Xóa thất bại');
                        return;
                    }

                    // reload list
                    loadVoucherList(userId);
                })
                .catch(err => {
                    console.error(err);
                    alert('Lỗi JS khi xóa voucher (mở F12 -> Console xem response)');
                });
        }

    </script>
</body>


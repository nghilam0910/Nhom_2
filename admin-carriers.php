<?php
require_once 'db_connect.php';

function jsonOut($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length())
        ob_clean();
    echo json_encode($arr);
    exit;
}

function generateCarrierID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(CarrierID, 2) AS UNSIGNED))
        FROM Carrier
    ");
    $next = ((int) $stmt->fetchColumn()) + 1;
    return 'C' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        /* LOAD */
        if ($action === 'ajax_load_carrier') {
            $stmt = $pdo->query("SELECT * FROM Carrier ORDER BY CarrierName");
            jsonOut(['success' => true, 'data' => $stmt->fetchAll()]);
        }

        /* ADD */
        if ($action === 'ajax_add_carrier') {
            $id = generateCarrierID($pdo);

            $pdo->prepare("
                INSERT INTO Carrier
                (CarrierID, CarrierName, ShippingPrice, Phone, Website)
                VALUES (:id, :name, :price, :phone, :web)
            ")->execute([
                        ':id' => $id,
                        ':name' => $_POST['name'],
                        ':price' => $_POST['price'],
                        ':phone' => $_POST['phone'],
                        ':web' => $_POST['website']
                    ]);

            jsonOut(['success' => true]);
        }

        /* UPDATE */
        if ($action === 'ajax_update_carrier') {
            $pdo->prepare("
                UPDATE Carrier
                SET CarrierName = :name,
                    ShippingPrice = :price,
                    Phone = :phone,
                    Website = :web
                WHERE CarrierID = :id
            ")->execute([
                        ':id' => $_POST['id'],
                        ':name' => $_POST['name'],
                        ':price' => $_POST['price'],
                        ':phone' => $_POST['phone'],
                        ':web' => $_POST['website']
                    ]);

            jsonOut(['success' => true]);
        }

        /* DELETE */
        if ($action === 'ajax_delete_carrier') {
            $pdo->prepare("DELETE FROM Carrier WHERE CarrierID = :id")
                ->execute([':id' => $_POST['id']]);

            jsonOut(['success' => true]);
        }

        throw new Exception('Action không hợp lệ');

    } catch (Exception $e) {
        jsonOut(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Quản lý đơn vị vận chuyển | Moonlit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">
</head>

<body class="account-body admin-page">
    <div class="account-card">
        <div class="d-flex justify-content-between mb-3">
            <h2 class="account-section-title">Đơn vị vận chuyển</h2>
            <button class="btn btn-primary" onclick="openAddCarrier()">+ Thêm</button>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tên đơn vị</th>
                    <th>Phí ship</th>
                    <th>Điện thoại</th>
                    <th>Website</th>
                    <th class="text-end">Thao tác</th>
                </tr>
            </thead>
            <tbody id="carrierBody">
                <tr>
                    <td colspan="6" class="text-center text-muted">Chưa có đơn vị vận chuyển</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="modal fade" id="carrierModal">
        <div class="modal-dialog">
            <form id="carrierForm" class="modal-content">
                <input type="hidden" name="action" id="carrier_action">
                <input type="hidden" name="id" id="carrier_id">

                <div class="modal-header">
                    <h5 class="modal-title">Đơn vị vận chuyển</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <label>Tên đơn vị</label>
                    <input class="account-input w-100" name="name" required>

                    <label class="mt-2">Phí ship</label>
                    <input class="account-input w-100" name="price" type="number" required>

                    <label class="mt-2">Điện thoại</label>
                    <input class="account-input w-100" name="phone">

                    <label class="mt-2">Website</label>
                    <input class="account-input w-100" name="website">
                </div>

                <div class="modal-footer">
                    <button class="btn btn-primary">Lưu</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        const carrierForm = document.getElementById('carrierForm');
        const carrierModal = document.getElementById('carrierModal');
        const carrierAction = document.getElementById('carrier_action');
        const carrierId = document.getElementById('carrier_id');
        function loadCarrier() {
            fetch('admin-carriers.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'ajax_load_carrier' })
            })
                .then(r => r.json())
                .then(res => {
                    let html = '';
                    res.data.forEach(c => {
                        html += `
            <tr>
                <td>${c.CarrierID}</td>
                <td>${c.CarrierName}</td>
                <td>${Number(c.ShippingPrice).toLocaleString()} đ</td>
                <td>${c.Phone ?? ''}</td>
                <td>${c.Website ?? ''}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary"
                        onclick='editCarrier(${JSON.stringify(c)})'>Sửa</button>
                    <button class="btn btn-sm btn-outline-danger"
                        onclick="deleteCarrier('${c.CarrierID}')">Xóa</button>
                </td>
            </tr>`;
                    });
                    document.getElementById('carrierBody').innerHTML = html;
                });
        }

        function openAddCarrier() {
            carrierForm.reset();
            carrierAction.value = 'ajax_add_carrier';
            carrierId.value = '';
            new bootstrap.Modal(carrierModal).show();
        }

        function editCarrier(c) {
            carrierAction.value = 'ajax_update_carrier';
            carrierId.value = c.CarrierID;
            carrierForm.name.value = c.CarrierName;
            carrierForm.price.value = c.ShippingPrice;
            carrierForm.phone.value = c.Phone ?? '';
            carrierForm.website.value = c.Website ?? '';
            new bootstrap.Modal(carrierModal).show();
        }


        function deleteCarrier(id) {
            if (!confirm('Xóa đơn vị này?')) return;
            fetch('admin-carriers.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'ajax_delete_carrier', id })
            }).then(() => loadCarrier());
        }

        carrierForm.addEventListener('submit', e => {
            e.preventDefault();

            fetch('admin-carriers.php', {
                method: 'POST',
                body: new FormData(carrierForm)
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        alert(res.message || 'Lỗi thêm carrier');
                        return;
                    }

                    bootstrap.Modal.getInstance(carrierModal).hide();
                    loadCarrier();
                });
        });


        document.addEventListener('DOMContentLoaded', loadCarrier);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
<?php
require_once 'db_connect.php';

function jsonOut($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr);
    exit;
}

function generatePublisherID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(PublisherID, 2) AS UNSIGNED))
        FROM Publisher
    ");
    return 'P' . str_pad(((int) $stmt->fetchColumn()) + 1, 5, '0', STR_PAD_LEFT);
}

/* ================= AJAX HANDLE ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    try {

        if ($action === 'ajax_load_publisher') {
            $stmt = $pdo->query("SELECT * FROM Publisher ORDER BY PublisherName");
            jsonOut(['success' => true, 'data' => $stmt->fetchAll()]);
        }

        if ($action === 'ajax_add_publisher') {
            $pdo->prepare("
                INSERT INTO Publisher (PublisherID, PublisherName)
                VALUES (?, ?)
            ")->execute([
                        generatePublisherID($pdo),
                        trim($_POST['name'])
                    ]);

            jsonOut(['success' => true]);
        }

        if ($action === 'ajax_update_publisher') {
            $pdo->prepare("
                UPDATE Publisher SET PublisherName = ?
                WHERE PublisherID = ?
            ")->execute([
                        trim($_POST['name']),
                        $_POST['id']
                    ]);

            jsonOut(['success' => true]);
        }

        if ($action === 'ajax_delete_publisher') {
            $pdo->prepare("DELETE FROM Publisher WHERE PublisherID = ?")
                ->execute([$_POST['id']]);

            jsonOut(['success' => true]);
        }

        jsonOut(['success' => false, 'message' => 'Action không hợp lệ']);

    } catch (Exception $e) {
        jsonOut(['success' => false, 'message' => $e->getMessage()]);
    }
}

/* ================= END AJAX ================= */
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Quản lý nhà xuất bản | Moonlit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">
</head>

<body class="account-body admin-page">
    <div class="account-card">
        <div class="d-flex justify-content-between mb-3">
            <h2 class="account-section-title">Nhà xuất bản</h2>
            <button class="btn btn-primary" onclick="openAddPublisher()">+ Thêm</button>
        </div>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tên nhà xuất bản</th>
                    <th class="text-end">Thao tác</th>
                </tr>
            </thead>
            <tbody id="publisherBody">
                <tr>
                    <td colspan="3" class="text-center text-muted">
                        Đang tải...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- MODAL -->
    <div class="modal fade" id="publisherModal">
        <div class="modal-dialog">
            <form id="publisherForm" class="modal-content">
                <input type="hidden" name="action" id="publisher_action">
                <input type="hidden" name="id" id="publisher_id">

                <div class="modal-header">
                    <h5 class="modal-title">Nhà xuất bản</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <label>Tên nhà xuất bản</label>
                    <input class="account-input w-100" name="name" required>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-primary">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const publisherForm = document.getElementById('publisherForm');
        const publisherModal = document.getElementById('publisherModal');
        const publisherAction = document.getElementById('publisher_action');
        const publisherId = document.getElementById('publisher_id');

        function loadPublisher() {
            fetch('admin-publishers.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'ajax_load_publisher' })
            })
                .then(r => r.json())
                .then(res => {
                    let html = '';
                    res.data.forEach(p => {
                        html += `
            <tr>
                <td>${p.PublisherID}</td>
                <td>${p.PublisherName}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary"
                        onclick='editPublisher(${JSON.stringify(p)})'>Sửa</button>
                    <button class="btn btn-sm btn-outline-danger"
                        onclick="deletePublisher('${p.PublisherID}')">Xóa</button>
                </td>
            </tr>`;
                    });
                    publisherBody.innerHTML = html || `
            <tr><td colspan="3" class="text-center text-muted">Chưa có nhà xuất bản</td></tr>
        `;
                });
        }

        function openAddPublisher() {
            publisherForm.reset();
            publisherAction.value = 'ajax_add_publisher';
            publisherId.value = '';
            new bootstrap.Modal(publisherModal).show();
        }

        function editPublisher(p) {
            publisherAction.value = 'ajax_update_publisher';
            publisherId.value = p.PublisherID;
            publisherForm.name.value = p.PublisherName;
            new bootstrap.Modal(publisherModal).show();
        }

        function deletePublisher(id) {
            if (!confirm('Xóa nhà xuất bản này?')) return;
            fetch('admin-publishers.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'ajax_delete_publisher',
                    id
                })
            }).then(() => loadPublisher());
        }

        publisherForm.addEventListener('submit', e => {
            e.preventDefault();
            fetch('admin-publishers.php', {
                method: 'POST',
                body: new FormData(publisherForm)
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        alert(res.message || 'Lỗi xử lý');
                        return;
                    }
                    bootstrap.Modal.getInstance(publisherModal).hide();
                    loadPublisher();
                });
        });

        document.addEventListener('DOMContentLoaded', loadPublisher);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
<?php
require_once 'db_connect.php';

/* ================== HELPER ================== */
function jsonOut($arr)
{
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length())
        ob_clean();
    echo json_encode($arr);
    exit;
}

function generateUserID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(UserID, 2) AS UNSIGNED)) 
        FROM User_Account
    ");
    $next = ((int) $stmt->fetchColumn()) + 1;
    return 'U' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

/* ================== AJAX ROUTER ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {

        /* ===== LOAD EMPLOYEE ===== */
        if ($action === 'ajax_load_employee') {

            $stmt = $pdo->query("
                SELECT UserID, FullName, Username, Email, Phone, Status, CreatedDate
                FROM User_Account
                WHERE Role = 'Admin'
                ORDER BY CreatedDate DESC
            ");

            jsonOut([
                'success' => true,
                'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ]);
        }

        /* ===== ADD EMPLOYEE ===== */ else if ($action === 'ajax_add_employee') {

            $uid = generateUserID($pdo);
            if (empty($_POST['password'])) {
                throw new Exception('Mật khẩu không được để trống');
            }
            $pdo->prepare("
                INSERT INTO User_Account
                (UserID, FullName, Username, Email, Phone, Password, Role, Status, CreatedDate)
                VALUES
                (:id, :name, :user, :email, :phone, :pass, 'Admin', 1, NOW())
            ")->execute([
                        ':id' => $uid,
                        ':name' => trim($_POST['full_name']),
                        ':user' => trim($_POST['username']),
                        ':email' => trim($_POST['email']),
                        ':phone' => trim($_POST['phone']),
                        ':pass' => password_hash($_POST['password'], PASSWORD_DEFAULT)
                    ]);

            jsonOut(['success' => true]);
        }

        /* ===== UPDATE EMPLOYEE ===== */ else if ($action === 'ajax_update_employee') {

            $params = [
                ':id' => $_POST['user_id'],
                ':username' => $_POST['username'],
                ':name' => $_POST['full_name'],
                ':email' => $_POST['email'],
                ':phone' => $_POST['phone'],
                ':status' => $_POST['status']
            ];

            $sql = "
                UPDATE User_Account
                SET Username = :username,
                    FullName = :name,
                    Email = :email,
                    Phone = :phone,
                    Status = :status
            ";

            if (!empty($_POST['password'])) {
                $sql .= ", Password = :pass";
                $params[':pass'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }

            $sql .= " WHERE UserID = :id";

            $pdo->prepare($sql)->execute($params);

            jsonOut(['success' => true]);
        }

        /* ===== DELETE EMPLOYEE ===== */ else if ($action === 'ajax_delete_employee') {

            $pdo->prepare("
                DELETE FROM User_Account
                WHERE UserID = :id AND Role = 'Admin'
            ")->execute([
                        ':id' => $_POST['user_id']
                    ]);

            jsonOut(['success' => true]);
        }
        /* ===== TOGGLE STATUS ===== */ else if ($action === 'ajax_toggle_employee_status') {

            if (empty($_POST['user_id'])) {
                throw new Exception('Thiếu UserID');
            }

            $pdo->prepare("
                    UPDATE User_Account
                    SET Status = IF(Status = 1, 0, 1)
                    WHERE UserID = :id AND Role = 'Admin'
                ")->execute([
                        ':id' => $_POST['user_id']
                    ]);

            jsonOut(['success' => true]);
        }



        throw new Exception('Action không hợp lệ');

    } catch (Exception $e) {
        jsonOut([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
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
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="account-section-title mb-0">Danh sách nhân viên</h2>

            <button class="btn btn-primary" onclick="openAddEmployee()">
                + Thêm nhân viên
            </button>
        </div>
        <div class="table-responsive">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>UserID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Điện thoại</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody id="employeeTableBody">
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            Đang tải danh sách nhân viên...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="modal fade" id="employeeModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="employeeForm" class="modal-content">
                <input type="hidden" name="action" id="emp_action" value="ajax_update_employee">
                <input type="hidden" name="user_id" id="emp_user_id">

                <div class="modal-header">
                    <h5 class="modal-title">Sửa nhân viên</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="account-label">Username</label>
                        <input type="text" name="username" id="emp_username" class="account-input w-100">
                    </div>
                    <div class="col-md-6">
                        <label class="account-label">Họ tên</label>
                        <input type="text" name="full_name" id="emp_fullname" class="account-input w-100" required>
                    </div>

                    <div class="col-md-6">
                        <label class="account-label">Email</label>
                        <input type="email" name="email" id="emp_email" class="account-input w-100">
                    </div>

                    <div class="col-md-6">
                        <label class="account-label">Điện thoại</label>
                        <input type="text" name="phone" id="emp_phone" class="account-input w-100" inputmode="numeric"
                            pattern="[0-9]*" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>

                    <div class="col-md-6">
                        <label class="account-label">Trạng thái</label>

                        <div class="d-flex gap-2">
                            <select name="status" id="emp_status" class="account-input w-100">
                                <option value="1">Hoạt động</option>
                                <option value="0">Khóa</option>
                            </select>


                        </div>
                    </div>


                    <div class="col-12">
                        <label class="account-label">
                            Mật khẩu mới <small class="text-muted">(để trống nếu không đổi)</small>
                        </label>
                        <input type="password" name="password" class="account-input w-100">
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-primary">Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function loadEmployee() {
            fetch('admin-employee.php', {
                method: 'POST',
                body: new URLSearchParams({ action: 'ajax_load_employee' })
            })
                .then(r => r.json())
                .then(res => {
                    const tbody = document.getElementById('employeeTableBody');

                    if (!res.success || res.data.length === 0) {
                        tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        Chưa có nhân viên
                    </td>
                </tr>`;
                        return;
                    }

                    let html = '';
                    res.data.forEach(e => {
                        html += `
            <tr>
                <td>${e.UserID}</td>
                <td>${e.Username}</td>
                <td>${e.Email ?? ''}</td>
                <td>${e.Phone ?? ''}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        ${e.Status == 1
                                ? `<span class="badge bg-success">Hoạt động</span>`
                                : `<span class="badge bg-secondary">Khóa</span>`
                            }

                        <button class="btn btn-sm"
                            onclick="toggleEmployeeStatus('${e.UserID}')"
                                title="Đổi trạng thái">
                                🔄
                            </button>
                        </div>
                </td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-primary"
                        data-id="${e.UserID}"
                        data-username="${e.Username}"
                        data-name="${e.FullName}"
                        data-email="${e.Email ?? ''}"
                        data-phone="${e.Phone ?? ''}"
                        data-status="${e.Status}"
                        onclick="openEditEmployee(this)">
                        Sửa
                    </button>

                    <button class="btn btn-sm btn-outline-danger"
                        onclick="deleteEmployee('${e.UserID}')">
                    Xóa
                    </button>
                </td>

            </tr>`;
                    });

                    tbody.innerHTML = html;
                });
        }

        function deleteEmployee(id) {
            if (!confirm('Xóa nhân viên này?')) return;

            fetch('admin-employee.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'ajax_delete_employee',
                    user_id: id
                })
            })
                .then(() => loadEmployee());
        }
        function openEditEmployee(btn) {
            document.getElementById('emp_action').value = 'ajax_update_employee';

            document.getElementById('emp_user_id').value = btn.dataset.id;
            document.getElementById('emp_username').value = btn.dataset.username;
            document.getElementById('emp_fullname').value = btn.dataset.name;
            document.getElementById('emp_email').value = btn.dataset.email;
            document.getElementById('emp_phone').value = btn.dataset.phone;
            document.getElementById('emp_status').value = btn.dataset.status;
            document.querySelector('#employeeModal .modal-title')
                .innerText = 'Sửa nhân viên';

            const modal = new bootstrap.Modal(
                document.getElementById('employeeModal')
            );
            modal.show();
        }
        function toggleEmployeeStatus(userID) {
            if (!confirm('Đổi trạng thái nhân viên này?')) return;

            fetch('admin-employee.php', {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'ajax_toggle_employee_status',
                    user_id: userID
                })
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        alert(res.message || 'Không đổi được trạng thái');
                        return;
                    }
                    loadEmployee(); // reload lại bảng
                });
        }

        function openAddEmployee() {
            document.getElementById('employeeForm').reset();

            document.getElementById('emp_action').value = 'ajax_add_employee';
            document.getElementById('emp_user_id').value = '';

            document.querySelector('#employeeModal .modal-title')
                .innerText = 'Thêm nhân viên';

            const modal = new bootstrap.Modal(
                document.getElementById('employeeModal')
            );
            modal.show();
        }
        document.getElementById('employeeForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const action = document.getElementById('emp_action').value;
            const passInput = this.querySelector('input[name="password"]');

            // THÊM NHÂN VIÊN → BẮT BUỘC PASSWORD
            if (action === 'ajax_add_employee' && passInput.value.trim() === '') {
                alert('Vui lòng nhập mật khẩu cho nhân viên mới');
                passInput.focus();
                return;
            }

            const fd = new FormData(this);

            fetch('admin-employee.php', {
                method: 'POST',
                body: fd
            })
                .then(r => r.json())
                .then(res => {
                    if (!res.success) {
                        alert(res.message || 'Thao tác thất bại');
                        return;
                    }

                    bootstrap.Modal.getInstance(
                        document.getElementById('employeeModal')
                    ).hide();

                    loadEmployee();
                });
        });

        document.addEventListener('DOMContentLoaded', loadEmployee);
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>

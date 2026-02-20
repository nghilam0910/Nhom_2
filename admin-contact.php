<?php
// ===== admin-contact.php =====

if (session_status() == PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';

// Kiểm tra quyền admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    // header("Location: auth_login.php");
    // exit;
}

$success_message = '';
$error_message = '';

// ================= AJAX =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();

    try {
        $action = $_POST['action'] ?? '';

        // Load all messages
        if ($action === 'ajax_load_messages') {
            $stmt = $pdo->query("
                SELECT cm.*, ua.Username 
                FROM Contact_Message cm
                LEFT JOIN User_Account ua ON cm.UserID = ua.UserID
                ORDER BY CreatedDate DESC
            ");
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'data'=>$messages]);
            exit;
        }

        // Delete message
        elseif ($action === 'ajax_delete') {
            $id = $_POST['id'] ?? '';
            if (!$id) throw new Exception('Thiếu ID');
            $stmt = $pdo->prepare("DELETE FROM Contact_Message WHERE MessageID=:id");
            $stmt->execute([':id'=>$id]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // Update status
        elseif ($action === 'ajax_update_status') {
            $id = $_POST['id'] ?? '';
            $status = $_POST['status'] ?? '';
            if (!$id || !$status) throw new Exception('Thiếu dữ liệu');
            $stmt = $pdo->prepare("UPDATE Contact_Message SET Status=:status WHERE MessageID=:id");
            $stmt->execute([':status'=>$status, ':id'=>$id]);
            echo json_encode(['success'=>true]);
            exit;
        }

    } catch(Exception $e){
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        exit;
    }
}

// ================= LOAD DATA =================
// initial load (optional, table can load via AJAX too)
$messages = $pdo->query("
    SELECT cm.*, ua.Username 
    FROM Contact_Message cm
    LEFT JOIN User_Account ua ON cm.UserID = ua.UserID
    ORDER BY CreatedDate DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Contact | Moonlit</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="moonlit-style.css">
</head>
<body class="account-body admin-page">

<h2 class="account-section-title">Quản lý Contact Messages</h2>
<main class="account-main">

<div id="alert-area"></div>

<div class="account-card">
<h2 class="account-card-title">Danh sách tin nhắn</h2>
<div class="table-responsive">
<table class="admin-table" id="contactTable">
<thead>
<tr>
<th>ID</th>
<th>Người gửi</th>
<th>Email</th>
<th>Chủ đề</th>
<th>Ngày gửi</th>
<th>Trạng thái</th>
<th class="text-end">Thao tác</th>
</tr>
</thead>
<tbody>
<?php foreach($messages as $m): ?>
<tr data-id="<?=$m['MessageID']?>">
<td><?=$m['MessageID']?></td>
<td><?=htmlspecialchars($m['FullName'] ?? $m['Username'] ?? 'Guest')?></td>
<td><?=htmlspecialchars($m['Email'])?></td>
<td><?=htmlspecialchars($m['Subject'])?></td>
<td><?=$m['CreatedDate']?></td>
<td><?=$m['Status']?></td>
<td class="text-end">
<button class="btn btn-sm btn-outline-primary" onclick="toggleDetail(<?=$m['MessageID']?>)">Xem</button>
<button class="btn btn-sm btn-outline-success" onclick="changeStatus(<?=$m['MessageID']?>)">Đánh dấu đã đọc</button>
<button class="btn btn-sm btn-outline-danger" onclick="deleteMessage(<?=$m['MessageID']?>, this)">Xóa</button>
</td>
</tr>
<tr class="collapse" id="detail_<?=$m['MessageID']?>">
<td colspan="7">
<div class="p-3 border bg-light"><?=nl2br(htmlspecialchars($m['Message']))?></div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Show alert
function showAlert(msg,type='success'){
    const area = document.getElementById('alert-area');
    area.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    setTimeout(()=>area.innerHTML='',3000);
}

// Toggle detail collapse
function toggleDetail(id){
    const row = document.getElementById('detail_'+id);
    row.classList.toggle('collapse');
}

// Delete message
function deleteMessage(id, elem){
    if(!confirm('Bạn có chắc muốn xóa tin nhắn này?')) return;
    const fd = new FormData();
    fd.append('action','ajax_delete');
    fd.append('id',id);
    fetch('admin-contact.php',{method:'POST',body:fd})
        .then(res=>res.json())
        .then(res=>{
            if(res.success){
                const tr = elem.closest('tr');
                const detail = document.getElementById('detail_'+id);
                tr.remove();
                if(detail) detail.remove();
                showAlert('Đã xóa tin nhắn');
            } else showAlert(res.message,'danger');
        });
}

// Change status to Read
function changeStatus(id){
    const fd = new FormData();
    fd.append('action','ajax_update_status');
    fd.append('id',id);
    fd.append('status','Read');
    fetch('admin-contact.php',{method:'POST',body:fd})
        .then(res=>res.json())
        .then(res=>{
            if(res.success){
                const row = document.querySelector('tr[data-id="'+id+'"]');
                if(row) row.cells[5].innerText = 'Read';
                showAlert('Đã đánh dấu đã đọc');
            } else showAlert(res.message,'danger');
        });
}
</script>

</body>
</html>

<?php
// ===== admin-forum.php =====

// Chỉ start session nếu chưa có
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connect.php';

// Kiểm tra quyền admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    // header("Location: auth_login.php");
    // exit;
}

$currentUsername = $_SESSION['username'] ?? 'Admin';
$success_message = '';
$error_message = '';

// ===== Hàm sinh ID dạng T00001 / P00001 =====
function generateID(PDO $pdo, $table, $prefix, $col = 'ID') {
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING($col, 2) AS UNSIGNED)) AS max_id 
        FROM $table
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = ($row['max_id'] ?? 0) + 1;
    return $prefix . str_pad($nextId, 5, '0', STR_PAD_LEFT);
}

// ================= AJAX HANDLER =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json; charset=utf-8');
    ob_clean();
    $action = $_POST['action'] ?? '';

    try {
        // ===== AJAX LOAD POSTS + COMMENTS =====
        if ($action === 'ajax_load_posts') {
            $topicId = trim($_POST['topic_id'] ?? '');
            if (!$topicId) throw new Exception('Thiếu TopicID');

            $stmt = $pdo->prepare("
                SELECT fp.*, ua.Username 
                FROM Forum_Post fp
                JOIN User_Account ua ON fp.UserID = ua.UserID
                WHERE TopicID = :tid
                ORDER BY CreatedDate
            ");
            $stmt->execute([':tid'=>$topicId]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($posts as &$p) {
                $stmtc = $pdo->prepare("
                    SELECT fc.*, ua.Username 
                    FROM Forum_Comment fc
                    JOIN User_Account ua ON fc.UserID = ua.UserID
                    WHERE PostID = :pid
                    ORDER BY CreatedDate
                ");
                $stmtc->execute([':pid'=>$p['PostID']]);
                $p['comments'] = $stmtc->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success'=>true,'data'=>$posts]);
            exit;
        }

        // ===== AJAX DELETE =====
        elseif ($action === 'ajax_delete') {
            $type = $_POST['type'] ?? '';
            $id = $_POST['id'] ?? '';
            if (!$type || !$id) throw new Exception('Thiếu dữ liệu');

            $pdo->beginTransaction();
            if ($type === 'topic') {
                $pdo->prepare("DELETE fc FROM Forum_Comment fc JOIN Forum_Post fp ON fc.PostID=fp.PostID WHERE fp.TopicID=:id")->execute([':id'=>$id]);
                $pdo->prepare("DELETE FROM Forum_Post WHERE TopicID=:id")->execute([':id'=>$id]);
                $pdo->prepare("DELETE FROM Forum_Topic WHERE TopicID=:id")->execute([':id'=>$id]);
            } elseif ($type==='post') {
                $pdo->prepare("DELETE FROM Forum_Comment WHERE PostID=:id")->execute([':id'=>$id]);
                $pdo->prepare("DELETE FROM Forum_Post WHERE PostID=:id")->execute([':id'=>$id]);
            } elseif ($type==='comment') {
                $pdo->prepare("DELETE FROM Forum_Comment WHERE CommentID=:id")->execute([':id'=>$id]);
            }
            $pdo->commit();
            echo json_encode(['success'=>true]);
            exit;
        }

        // ===== AJAX ADD COMMENT =====
        elseif ($action === 'add_comment') {
            $postId = $_POST['post_id'] ?? '';
            $content = trim($_POST['content'] ?? '');
            if (!$postId || !$content) throw new Exception('Thiếu dữ liệu');

            // Kiểm tra topic có bị khóa không
            $stmtCheck = $pdo->prepare("
                SELECT ft.IsLocked 
                FROM Forum_Post fp 
                JOIN Forum_Topic ft ON fp.TopicID = ft.TopicID
                WHERE fp.PostID=:pid
            ");
            $stmtCheck->execute([':pid'=>$postId]);
            if ($stmtCheck->fetchColumn()) throw new Exception('Topic đang bị khóa, không thể thêm bình luận');

            $stmt = $pdo->prepare("
                INSERT INTO Forum_Comment
                (PostID, UserID, Content, CreatedDate)
                VALUES (:pid,:uid,:content,NOW())
            ");
            $stmt->execute([
                ':pid'=>$postId,
                ':uid'=>$_SESSION['user_id'],
                ':content'=>$content
            ]);
            $commentId = $pdo->lastInsertId();
            echo json_encode(['success'=>true,'commentId'=>$commentId,'username'=>$_SESSION['username']]);
            exit;
        }

        // ===== ADD TOPIC =====
        elseif ($action==='add_topic'){
            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            if (!$title) throw new Exception('Tiêu đề không được để trống');
            $topicId = generateID($pdo,'Forum_Topic','T','TopicID');
            $stmt = $pdo->prepare("
                INSERT INTO Forum_Topic
                (TopicID, UserID, Title, Description, CreatedBy, CreatedDate, IsLocked)
                VALUES (:id, :uid, :title, :desc, :cb, NOW(), 0)
            ");
            $stmt->execute([
                ':id'=>$topicId,
                ':uid'=>$_SESSION['user_id'],
                ':title'=>$title,
                ':desc'=>$desc,
                ':cb'=>$_SESSION['user_id']
            ]);
            echo json_encode(['success'=>true]);
            exit;
        }

        // ===== ADD POST =====
        elseif ($action==='add_post'){
            $topicId = $_POST['topic_id'] ?? '';
            $content = trim($_POST['content'] ?? '');
            if (!$topicId || !$content) throw new Exception('Thiếu dữ liệu');

            // Kiểm tra topic có bị khóa không
            $stmtCheck = $pdo->prepare("SELECT IsLocked FROM Forum_Topic WHERE TopicID=:tid");
            $stmtCheck->execute([':tid'=>$topicId]);
            if($stmtCheck->fetchColumn()) throw new Exception('Topic đang bị khóa, không thể thêm bài viết');

            $postId = generateID($pdo,'Forum_Post','P','PostID');
            $stmt = $pdo->prepare("
                INSERT INTO Forum_Post
                (PostID, TopicID, UserID, Content, CreatedDate)
                VALUES (:pid,:tid,:uid,:content,NOW())
            ");
            $stmt->execute([
                ':pid'=>$postId,
                ':tid'=>$topicId,
                ':uid'=>$_SESSION['user_id'],
                ':content'=>$content
            ]);
            echo json_encode(['success'=>true]);
            exit;
        }

    } catch(Exception $e){
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        exit;
    }
}

// ================= LOAD DATA =================
$topics = $pdo->query("
    SELECT ft.*, ua.Username 
    FROM Forum_Topic ft
    JOIN User_Account ua ON ft.CreatedBy = ua.UserID
    ORDER BY CreatedDate DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Forum | Moonlit</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="moonlit-style.css">
</head>
<body class="account-body admin-page">

<h2 class="account-section-title">Quản lý Forum</h2>
<main class="account-main">

<div id="alert-area"></div>

<!-- Thêm Topic -->
<div class="account-card mb-4">
<h2 class="account-card-title">Thêm chủ đề</h2>
<form id="formAddTopic" class="row g-3">
<input type="hidden" name="action" value="add_topic">
<div class="col-md-8">
<label class="account-label">Tiêu đề *</label>
<input type="text" name="title" class="account-input w-100" required>
</div>
<div class="col-md-4 flex-end">
<button type="submit" class="account-btn-save">Thêm chủ đề</button>
</div>
<div class="col-12">
<label class="account-label">Mô tả</label>
<textarea name="description" rows="3" class="account-input w-100"></textarea>
</div>
</form>
</div>

<!-- Danh sách Topic -->
<div class="account-card">
<h2 class="account-card-title">Danh sách chủ đề</h2>
<?php if(empty($topics)): ?>
<p class="text-muted">Chưa có chủ đề nào.</p>
<?php else: ?>
<div class="table-responsive">
<table class="admin-table">
<thead>
<tr>
<th>ID</th>
<th>Tiêu đề</th>
<th>Người tạo</th>
<th>Ngày tạo</th>
<th>Khóa</th>
<th class="text-end">Thao tác</th>
</tr>
</thead>
<tbody>
<?php foreach($topics as $t): ?>
<tr>
<td><?=htmlspecialchars($t['TopicID'])?></td>
<td><?=htmlspecialchars($t['Title'])?></td>
<td><?=htmlspecialchars($t['Username'])?></td>
<td><?=htmlspecialchars($t['CreatedDate'])?></td>
<td><?=$t['IsLocked']?'Có':'Không'?></td>
<td class="text-end">
<button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#posts_<?=$t['TopicID']?>">Xem bài viết</button>
<button class="btn btn-sm btn-outline-danger" onclick="deleteForum('topic','<?=$t['TopicID']?>')">Xóa</button>
</td>
</tr>
<tr class="collapse" id="posts_<?=$t['TopicID']?>">
<td colspan="6">
<div id="postContent_<?=$t['TopicID']?>"><p class="text-muted">Đang tải...</p></div>
<form class="row g-2 mt-2 formAddPost" data-topic="<?=$t['TopicID']?>">
<input type="hidden" name="action" value="add_post">
<input type="hidden" name="topic_id" value="<?=$t['TopicID']?>">
<div class="col-md-10">
<input type="text" name="content" class="account-input w-100" placeholder="Nội dung bài viết mới" required>
</div>
<div class="col-md-2 flex-end">
<button class="account-btn-save">Thêm bài viết</button>
</div>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== AJAX HELPERS =====
function showAlert(msg,type='success'){
    const area = document.getElementById('alert-area');
    area.innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
    setTimeout(()=>area.innerHTML='',3000);
}

// DELETE topic/post/comment
function deleteForum(type,id,elem=null){
    if(!confirm('Bạn có chắc muốn xóa?')) return;
    const fd = new FormData();
    fd.append('action','ajax_delete');
    fd.append('type',type);
    fd.append('id',id);
    fetch('admin-forum.php',{method:'POST',body:fd})
        .then(res=>res.json())
        .then(res=>{
            if(res.success){
                if(elem) elem.closest('.mb-1, .mb-2').remove();
                else location.reload();
            } else showAlert(res.message||'Lỗi xóa','danger');
        });
}

// ADD COMMENT
function addComment(e,postId){
    e.preventDefault();
    const val = e.target.querySelector('input').value.trim();
    if(!val) return;
    const fd = new FormData();
    fd.append('action','add_comment');
    fd.append('post_id',postId);
    fd.append('content',val);
    fetch('admin-forum.php',{method:'POST',body:fd})
        .then(res=>res.json())
        .then(res=>{
            if(res.success){
                const commentDiv = document.createElement('div');
                commentDiv.className = 'd-flex justify-content-between mb-1';
                commentDiv.innerHTML = `<span><b>${res.username}</b>: ${val}</span>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteForum('comment',${res.commentId},this)">Xóa Comment</button>`;
                e.target.parentNode.insertBefore(commentDiv,e.target);
                e.target.querySelector('input').value='';
            } else showAlert(res.message||'Lỗi thêm comment','danger');
        });
}

// ADD TOPIC AJAX
document.getElementById('formAddTopic').addEventListener('submit',function(e){
    e.preventDefault();
    const fd = new FormData(this);
    fetch('admin-forum.php',{method:'POST',body:fd})
        .then(res=>res.json())
        .then(res=>{
            if(res.success) location.reload();
            else showAlert(res.message||'Lỗi','danger');
        });
});

// ADD POST AJAX
document.querySelectorAll('.formAddPost').forEach(f=>{
    f.addEventListener('submit',function(e){
        e.preventDefault();
        const fd = new FormData(this);
        fetch('admin-forum.php',{method:'POST',body:fd})
            .then(res=>res.json())
            .then(res=>{
                if(res.success){
                    showAlert('Đã thêm bài viết');
                    const topicId = this.dataset.topic;
                    // reload posts
                    loadPosts(topicId);
                    this.querySelector('input[name="content"]').value='';
                } else showAlert(res.message||'Lỗi','danger');
            });
    });
});

// LOAD POSTS + COMMENTS
function loadPosts(topicId){
    const target = document.querySelector('#posts_'+topicId+' #postContent_'+topicId);
    const fd = new FormData();
    fd.append('action','ajax_load_posts');
    fd.append('topic_id',topicId);
    fetch('admin-forum.php',{method:'POST',body:fd})
        .then(res=>res.json())
        .then(res=>{
            if(res.success){
                let html='';
                res.data.forEach(p=>{
                    html+=`<div class="mb-2 border-bottom p-2">
                        <b>${p.Username}</b>: ${p.Content}
                        <button class="btn btn-sm btn-outline-danger float-end ms-1" onclick="deleteForum('post','${p.PostID}',this)">Xóa Post</button>
                        <div class="ms-3 mt-2 comments">`;
                    p.comments.forEach(c=>{
                        html+=`<div class="d-flex justify-content-between mb-1">
                            <span><b>${c.Username}</b>: ${c.Content}</span>
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteForum('comment','${c.CommentID}',this)">Xóa Comment</button>
                        </div>`;
                    });
                    html+=`<form class="d-flex gap-1 mt-1" onsubmit="addComment(event,'${p.PostID}')">
                        <input type="text" class="form-control form-control-sm" placeholder="Thêm bình luận" required>
                        <button class="btn btn-sm btn-primary">Thêm</button>
                    </form>`;
                    html+=`</div></div>`;
                });
                target.innerHTML = html || '<p class="text-muted">Chưa có bài viết</p>';
            }
        });
}

// LOAD POSTS khi mở collapse
document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(btn=>{
    btn.addEventListener('click',e=>{
        const topicId = btn.dataset.bsTarget.replace('#posts_','');
        loadPosts(topicId);
    });
});
</script>
</body>
</html>

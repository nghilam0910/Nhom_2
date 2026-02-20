<?php
// ===== admin-contact.php =====

if (session_status() == PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';

// Kiểm tra quyền admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    // header("Location: auth_login.php");
    // exit;
}


$currentUsername = $_SESSION['username'] ?? 'Admin';
$success_message = '';
$error_message   = '';

$categories = [
    'Góc độc giả nổi bật' => 'reader_corner',
    'Review sách'        => 'book_review',
    'Tác giả Việt Nam'   => 'vietnam_authors',
    'Tin khuyến mãi'     => 'promotions',
    'Xu hướng đọc sách'  => 'reading_trends'
];

// ===== FORM SUBMIT (Thêm/Sửa) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $blogId = $_POST['blog_id'] ?? null;

    // Xử lý upload thumbnail
    $thumbnailPath = '';
    if (!empty($_FILES['thumbnail']['name'])) {
        $uploadDir = 'uploads/blogs/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
        $filename = uniqid('blog_').'.'.$ext;
        $targetFile = $uploadDir.$filename;
        if(move_uploaded_file($_FILES['thumbnail']['tmp_name'], $targetFile)){
            $thumbnailPath = $targetFile;
        } else {
            $error_message = 'Upload thumbnail thất bại';
        }
    }

    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO Blog
                (Title, Content, Thumbnail, CreatedDate, UserID, Section)
                VALUES (:title,:content,:thumbnail,NOW(),:uid,:section)
            ");
            $stmt->execute([
                ':title'=>$title,
                ':content'=>$content,
                ':thumbnail'=>$thumbnailPath,
                ':uid'=>$_SESSION['user_id'],
                ':section'=>$section
            ]);
            $success_message = 'Đã thêm blog mới';
        } elseif ($action === 'edit' && $blogId) {
            // Nếu không upload thumbnail mới thì giữ lại cũ
            if (!$thumbnailPath) {
                $stmt = $pdo->prepare("SELECT Thumbnail FROM Blog WHERE BlogID=:id");
                $stmt->execute([':id'=>$blogId]);
                $thumbnailPath = $stmt->fetchColumn();
            }
            $stmt = $pdo->prepare("UPDATE Blog SET Title=:title, Content=:content, Thumbnail=:thumbnail, Section=:section WHERE BlogID=:id");
            $stmt->execute([
                ':title'=>$title,
                ':content'=>$content,
                ':thumbnail'=>$thumbnailPath,
                ':section'=>$section,
                ':id'=>$blogId
            ]);
            $success_message = 'Đã cập nhật blog';
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// ===== AJAX XÓA BLOG =====
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='ajax_delete'){
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? '';
    if($id){
        try{
            $pdo->prepare("DELETE FROM Blog WHERE BlogID=:id")->execute([':id'=>$id]);
            echo json_encode(['success'=>true]);
        }catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
    } else {
        echo json_encode(['success'=>false,'message'=>'Thiếu ID']);
    }
    exit;
}

// ===== LOAD BLOGS =====
$blogs = $pdo->query("
    SELECT b.*, ua.Username
    FROM Blog b
    JOIN User_Account ua ON b.UserID=ua.UserID
    ORDER BY CreatedDate DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Blogs | Moonlit</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="moonlit-style.css">
<style>
.blog-thumbnail {width:80px;height:60px;object-fit:cover;border-radius:6px;}
</style>
</head>
<body class="account-body admin-page">

<h2 class="account-section-title">Quản lý Blogs</h2>
<main class="account-main container">

<?php if($success_message): ?>
<div class="alert alert-success"><?=htmlspecialchars($success_message)?></div>
<?php endif; ?>
<?php if($error_message): ?>
<div class="alert alert-danger"><?=htmlspecialchars($error_message)?></div>
<?php endif; ?>

<!-- Thêm / Sửa Blog -->
<div class="account-card mb-4">
<h2 class="account-card-title">Thêm / Sửa Blog</h2>
<form method="POST" enctype="multipart/form-data" class="row g-3" id="blogForm">
<input type="hidden" name="action" value="add" id="formAction">
<input type="hidden" name="blog_id" id="blogId">
<div class="col-md-6">
<label class="account-label">Title</label>
<input type="text" name="title" id="title" class="account-input w-100" required>
</div>
<div class="col-md-6">
<label class="account-label">Section</label>
<select name="section" id="section" class="account-input w-100">
    <option value="">Chọn mục</option>
    <?php foreach($categories as $label => $value): ?>
        <option value="<?=htmlspecialchars($value)?>"><?=htmlspecialchars($label)?></option>
    <?php endforeach; ?>
</select>
</div>
<div class="col-12">
<label class="account-label">Content</label>
<textarea name="content" id="content" rows="5" class="account-input w-100" required></textarea>
</div>
<div class="col-md-6">
<label class="account-label">Thumbnail</label>
<input type="file" name="thumbnail" accept="image/*" class="account-input w-100">
</div>
<div class="col-md-6 d-flex align-items-end">
<button type="submit" class="account-btn-save" id="submitBtn">Thêm Blog</button>
<button type="button" class="btn btn-secondary ms-2" onclick="resetForm()">Reset</button>
</div>
</form>
</div>

<!-- Danh sách Blogs -->
<div class="account-card">
<h2 class="account-card-title">Danh sách Blogs</h2>
<?php if(empty($blogs)): ?>
<p class="text-muted">Chưa có blog nào.</p>
<?php else: ?>
<div class="table-responsive">
<table class="admin-table">
<thead>
<tr>
<th>ID</th>
<th>Thumbnail</th>
<th>Title</th>
<th>Section</th>
<th>Người tạo</th>
<th>Ngày tạo</th>
<th class="text-end">Thao tác</th>
</tr>
</thead>
<tbody>
<?php foreach($blogs as $b): ?>
<tr id="blogRow_<?=$b['BlogID']?>">
<td><?=$b['BlogID']?></td>
<td><?php if($b['Thumbnail']): ?><img src="<?=htmlspecialchars($b['Thumbnail'])?>" class="blog-thumbnail"><?php endif;?></td>
<td><?=htmlspecialchars($b['Title'])?></td>
<td><?=htmlspecialchars(array_search($b['Section'],$categories) ?: $b['Section'])?></td>
<td><?=htmlspecialchars($b['Username'])?></td>
<td><?=htmlspecialchars($b['CreatedDate'])?></td>
<td class="text-end">
<button class="btn btn-sm btn-outline-primary" onclick="editBlog(<?=htmlspecialchars(json_encode($b))?>)">Sửa</button>
<button class="btn btn-sm btn-outline-danger" onclick="deleteBlog(<?=$b['BlogID']?>)">Xóa</button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sửa blog
function editBlog(blog){
    document.getElementById('formAction').value = 'edit';
    document.getElementById('blogId').value = blog.BlogID;
    document.getElementById('title').value = blog.Title;
    document.getElementById('content').value = blog.Content;
    document.getElementById('section').value = blog.Section;
    document.getElementById('submitBtn').innerText = 'Cập nhật Blog';
    window.scrollTo({top:0,behavior:'smooth'});
}

// Reset form
function resetForm(){
    document.getElementById('formAction').value = 'add';
    document.getElementById('blogId').value = '';
    document.getElementById('title').value = '';
    document.getElementById('content').value = '';
    document.getElementById('section').value = '';
    document.getElementById('submitBtn').innerText = 'Thêm Blog';
}

// Xóa blog AJAX
function deleteBlog(id){
    if(!confirm('Bạn có chắc muốn xóa blog này?')) return;
    const fd = new FormData();
    fd.append('action','ajax_delete');
    fd.append('id',id);
    fetch('admin-blogs.php',{method:'POST',body:fd})
    .then(res=>res.json())
    .then(res=>{
        if(res.success) document.getElementById('blogRow_'+id).remove();
        else alert(res.message||'Lỗi xóa blog');
    });
}
</script>
</body>
</html>

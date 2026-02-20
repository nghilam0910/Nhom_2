<?php
session_start();
require_once 'db_connect.php';

/* =====================
   AUTH / COMMON
===================== */
$isLoggedIn = isset($_SESSION['user_id']) && $_SESSION['user_id'] !== '';
$currentUserID = $_SESSION['user_id'] ?? null;
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

function nav_active(string $page, string $currentPage): string
{
    return $page === $currentPage ? 'nav-active' : '';
}

$action = $_GET['action'] ?? 'list';

/* =====================
   HANDLE POST
===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$isLoggedIn) {
        header("Location: auth-login.php");
        exit;
    }

    /* ===== CREATE TOPIC + FIRST POST ===== */
    if ($_POST['action'] === 'create_topic') {

        $title = trim($_POST['title']);
        $content = trim($_POST['content']);

        if ($title === '' || $content === '') {
            header("Location: forum.php");
            exit;
        }

        // Tạo Topic
        $stmt = $pdo->prepare("
            INSERT INTO Forum_Topic
            (UserID, Title, Description, CreatedBy, CreatedDate, IsLocked)
            VALUES (?, ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([
            $currentUserID,
            $title,
            $content,
            $currentUserID
        ]);

        $topicID = $pdo->lastInsertId(); // Lấy ID INT

        // Tạo Post đầu tiên
        do {
            $postID = 'P' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
            $check = $pdo->prepare("SELECT 1 FROM Forum_Post WHERE PostID=?");
            $check->execute([$postID]);
        } while ($check->fetch());

        $stmt = $pdo->prepare("
            INSERT INTO Forum_Post
            (PostID, TopicID, UserID, Content, CreatedDate)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $postID,
            $topicID,
            $currentUserID,
            $content
        ]);

        header("Location: forum.php?");
        exit;
    }

    /* ===== CREATE POST (REPLY) ===== */
    if ($_POST['action'] === 'create_post') {

        if ($_POST['action'] === 'create_post') {

            $topicID = $_POST['topic_id'];
            $content = trim($_POST['content']);

            do {
                $postID = 'P' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("SELECT 1 FROM Forum_Post WHERE PostID = ?");
                $stmt->execute([$postID]);
            } while ($stmt->fetch());

            if ($topicID && $content) {
                $stmt = $pdo->prepare("
            INSERT INTO Forum_Post
            (PostID, TopicID, UserID, Content, CreatedDate)
            VALUES (?, ?, ?, ?, NOW())
        ");
                $stmt->execute([
                    $postID,
                    $topicID,
                    $currentUserID,
                    $content
                ]);
            }

            header("Location: forum.php?action=view&id=$topicID");
            exit;
        }
        $postID = uniqid('P');
        $content = trim($_POST['content']);

        do {
            $topicID = 'T' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("SELECT 1 FROM Forum_Topic WHERE TopicID = ?");
            $stmt->execute([$topicID]);
        } while ($stmt->fetch());

        do {
            $postID = 'P' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("SELECT 1 FROM Forum_Post WHERE PostID = ?");
            $stmt->execute([$postID]);
        } while ($stmt->fetch());


        if ($topicID && $content) {
            $stmt = $pdo->prepare("
                INSERT INTO Forum_Post
                (PostID, TopicID, UserID, Content, CreatedDate)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $postID,
                $topicID,
                $currentUserID,
                $content
            ]);
        }

        header("Location: forum.php?action=view&id=$topicID");
        exit;
    }

    /* ===== CREATE COMMENT ===== */
    if ($_POST['action'] === 'create_comment') {

        $postID = $_POST['post_id'];
        $content = trim($_POST['content']);

        if ($postID && $content) {
            $stmt = $pdo->prepare("
                INSERT INTO Forum_Comment
                (PostID, UserID, Content, CreatedDate)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([
                $postID,
                $currentUserID,
                $content
            ]);
        }

        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
}

/* =====================
   LOAD DATA
===================== */

/* ===== VIEW TOPIC ===== */
if ($action === 'view' && isset($_GET['id'])) {

    $topicID = $_GET['id'];

    // topic
    $stmt = $pdo->prepare("
        SELECT t.*, u.Username
        FROM Forum_Topic t
        JOIN User_Account u ON t.UserID = u.UserID
        WHERE t.TopicID = ?
    ");
    $stmt->execute([$topicID]);
    $topic = $stmt->fetch();

    // posts
    $stmt = $pdo->prepare("
        SELECT p.*, u.Username
        FROM Forum_Post p
        JOIN User_Account u ON p.UserID = u.UserID
        WHERE p.TopicID = ?
        ORDER BY p.CreatedDate ASC
    ");
    $stmt->execute([$topicID]);
    $posts = $stmt->fetchAll();
}

/* ===== LIST TOPICS ===== */
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            t.TopicID,
            t.Title,
            t.CreatedDate,
            u.Username,
            (
                SELECT COUNT(*)
                FROM Forum_Comment c
                JOIN Forum_Post p ON c.PostID = p.PostID
                WHERE p.TopicID = t.TopicID
            ) AS PostCount
        FROM Forum_Topic t
        JOIN User_Account u ON t.UserID = u.UserID
        ORDER BY t.CreatedDate DESC
    ");
    $topics = $stmt->fetchAll();
}

/* ===== LIST TOPICS - PAGINATION ===== */
$limit = 5; // số topic hiển thị trên 1 trang
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Lấy tổng số topic
$stmt = $pdo->query("SELECT COUNT(*) AS total FROM Forum_Topic");
$totalTopics = $stmt->fetch()['total'];
$totalPages = ceil($totalTopics / $limit);

// Lấy topic theo trang
$stmt = $pdo->prepare("
    SELECT t.TopicID, t.Title, t.CreatedDate, u.Username,
           (
               SELECT COUNT(*)
               FROM Forum_Comment c
               JOIN Forum_Post p ON c.PostID = p.PostID
               WHERE p.TopicID = t.TopicID
           ) AS PostCount
    FROM Forum_Topic t
    JOIN User_Account u ON t.UserID = u.UserID
    ORDER BY t.CreatedDate DESC
    LIMIT :limit OFFSET :offset
");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$topics = $stmt->fetchAll();
?>


<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Forum - Moonlit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="moonlit-style.css">
    
    <style>
        /* =====================
   FORUM GENERAL (style được bổ sung thêm)
===================== */
        .forum-section {
            background: #ffffff;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 32px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
        }

        .forum-section h3 {
            font-size: 1.25rem;
            font-weight: 600;
        }

        /* =====================
   TOPIC ITEM
===================== */
        .forum-topic-item {
            padding: 16px 18px;
            border-radius: 12px;
            background: #f8fafc;
            margin-bottom: 12px;
            transition: all 0.25s ease;
        }

        .forum-topic-item:hover {
            background: #eef4ff;
            transform: translateY(-2px);
        }

        .forum-topic-item a {
            font-weight: 600;
            color: var(--color-deep-blue);
            display: block;
            margin-bottom: 6px;
        }

        .forum-topic-meta {
            font-size: 0.85rem;
            color: #777;
        }

        /* =====================
   CREATE TOPIC FORM
===================== */
        .forum-form-desc {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 16px;
        }

        .forum-form {
            background: linear-gradient(180deg, #f9fbff, #ffffff);
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #e6edff;
        }

        .forum-form input,
        .forum-form textarea {
            width: 100%;
            margin-bottom: 14px;
            border-radius: 12px;
            border: 1px solid #dbe5ff;
            padding: 12px 14px;
            font-size: 0.95rem;
            transition: all 0.25s ease;
        }

        .forum-form input:focus,
        .forum-form textarea:focus {
            outline: none;
            border-color: var(--color-deep-blue);
            box-shadow: 0 0 0 3px rgba(40, 90, 200, 0.12);
        }

        .forum-form button {
            padding: 10px 22px;
            border-radius: 999px;
            font-weight: 600;
        }

        /* =====================
   VIEW TOPIC
===================== */
        .forum-topic-desc {
            font-size: 1rem;
            color: #444;
            line-height: 1.6;
            margin-top: 12px;
        }

        .forum-back-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #f3f6fb;
            color: var(--color-deep-blue);
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid #dbe3f0;
            transition: all 0.2s ease;
        }

        .forum-back-btn:hover {
            background: var(--color-deep-blue);
            color: #fff;
        }

        /* =====================
   POST & COMMENT
===================== */
        .forum-post {
            background: #ffffff;
            border-radius: 16px;
            padding: 22px;
            margin-bottom: 24px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.04);
        }

        .forum-post-meta {
            font-size: 0.85rem;
            color: #888;
            margin-bottom: 10px;
        }

        .forum-post-content {
            font-size: 0.95rem;
            line-height: 1.6;
            color: #333;
        }

        .forum-comment {
            background: #f6f8fb;
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 8px;
            font-size: 0.9rem;
        }

        .forum-comment-form {
            margin-top: 14px;
        }

        .forum-comment-form textarea {
            width: 100%;
            border-radius: 10px;
            margin-bottom: 8px;
        }
    </style>
</head>

<body class="account-body">

    <!-- ===================== HEADER ===================== -->
    <header class="account-header site-header">
        <div class="container header-inner">
            <div class="header-left">
                <a href="index.php" class="logo-link header-logo">
                    <img src="img/image.png?v=2" alt="Moonlit logo" class="logo-img">

                </a>

                <nav class="header-menu">
                    <a href="index.php" class="header-menu-link <?php echo nav_active('index.php', $currentPage); ?>">
                        Trang chủ
                    </a>
                    <a href="shop.php" class="header-menu-link <?php echo nav_active('shop.php', $currentPage); ?>">
                        Cửa hàng
                    </a>
                    <a href="forum.php" class="header-menu-link <?php echo nav_active('forum.php', $currentPage); ?>">
                        Moonlit Forum
                    </a>
                    <a href="aboutus.php"
                        class="header-menu-link <?php echo nav_active('aboutus.php', $currentPage); ?>">
                        Về chúng tôi
                    </a>
                    
                </nav>
            </div>

            <div class="header-right">
                <form method="GET" action="shop.php" class="header-search-form">
                    <input type="text" name="q" class="account-input header-search-input" placeholder="Tìm sách...">
                    <button type="submit" class="account-btn-save header-search-btn">Tìm</button>
                </form>

                <a href="cart.php" class="account-btn-secondary header-cart-btn">Giỏ hàng</a>

                <?php if ($isLoggedIn): ?>
                    <div class="header-account">
                        <div class="header-account-actions">
                            <a href="account-index.php" class="account-btn-secondary header-account-btn">Tài khoản</a>
                            <a href="logout.php" class="account-btn-secondary header-account-btn">Đăng xuất</a>
                        </div>

                        <span class="account-username">
                            Xin chào, <strong><?php echo htmlspecialchars($currentUsername); ?></strong>
                        </span>
                    </div>
                <?php else: ?>
                    <a href="auth-login.php" class="account-btn-secondary header-account-btn">Tài khoản</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ===================== MAIN ===================== -->
    <main class="account-main forum-main">
        <div class="container">

            <!-- ===== FORUM HEADER ===== -->
            <section class="forum-header">
                <div style="display:flex;align-items:flex-start;gap:24px;margin-bottom:32px;">
                    <h1 class="account-section-title" style="margin:0;white-space:nowrap;">
                        Diễn đàn Moonlit
                    </h1>

                    <div style="width:4px;min-height:60px;background:var(--color-deep-blue);border-radius:2px;"></div>

                    <p class="account-section-subtitle" style="margin:0;max-width:900px;">
                        Trang <strong style="color:var(--color-deep-blue);">Forum</strong> là một không gian trực tuyến
                        dành riêng cho cộng đồng yêu sách của Moonlit. Tại diễn đàn Moonlit, người đọc sách có thể thảo
                        luận,
                        chia sẻ cảm nhận của bản thân nhằm lan tỏa cảm hứng đọc. Ngoài ra, khách hàng từng mua sách
                        tại Moonlit còn có thể tạo chủ đề mới để review và feedback về sản phẩm hoặc dịch vụ tại
                        Moonlit.
                    </p>
                </div>
            </section>

            <?php if ($action === 'list'): ?>

                <!-- ===================== LỊCH SỬ THẢO LUẬN ===================== -->
                <section class="forum-section">
                    <h2 style="color:var(--color-deep-blue);margin-bottom:8px;">
                        Lịch sử thảo luận
                    </h2>
                    <p style="color:#666;margin-bottom:16px;">
                        Nơi người đọc trao đổi cảm nhận, đánh giá và thảo luận về các tác phẩm văn học. Ngoài ra, khách hàng
                        của Moonlit cũng có thể đăng tải các bài review về sản phẩm và dịch vụ tại Moonlit.
                    </p>

                    <?php if (!empty($topics)): ?>
                        <?php foreach ($topics as $t): ?>
                            <div class="forum-topic-item">
                                <a href="forum.php?action=view&id=<?= $t['TopicID'] ?>">
                                    <?= htmlspecialchars($t['Title']) ?>
                                </a>
                                <div class="forum-topic-meta">
                                    Bởi <?= htmlspecialchars($t['Username']) ?> •
                                    <?= date('d/m/Y', strtotime($t['CreatedDate'])) ?> •
                                    <?= $t['PostCount'] ?> phản hồi
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <!-- ===================== PHÂN TRANG ===================== -->
                        <?php if ($totalPages > 1): ?>
                            <div style="margin-top:16px;">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <a href="forum.php?page=<?= $i ?>"
                                        style="margin-right:6px; padding:6px 10px; border-radius:6px; background:<?= $i == $page ? '#1E4A8C' : '#f0f0f0' ?>; color:<?= $i == $page ? '#fff' : '#333' ?>; text-decoration:none;">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <p style="color:#999;">Chưa có chủ đề thảo luận nào.</p>
                    <?php endif; ?>
                </section>


                <!-- ===================== FORM TẠO CHỦ ĐỀ MỚI ===================== -->
                <?php if ($isLoggedIn): ?>
                    <section class="forum-section">
                        <h2 style="color:var(--color-deep-blue);">
                            Tạo chủ đề thảo luận mới
                        </h2>
                        <p class="forum-form-desc">
                            Biểu mẫu dưới đây cho phép người đọc trở thành người đóng góp nội dung
                            bằng cách chia sẻ suy nghĩ, đánh giá hoặc phản hồi về sách và dịch vụ Moonlit.
                        </p>

                        <form method="POST" class="forum-form">
                            <input type="hidden" name="action" value="create_topic">

                            <input type="text" name="title" class="account-input" placeholder="Tiêu đề thảo luận" required>

                            <textarea name="content" class="account-input" placeholder="Nội dung thảo luận" rows="5"
                                required></textarea>

                            <button type="submit" class="account-btn-save">
                                Đăng bài
                            </button>
                        </form>
                    </section>
                <?php endif; ?>

            <?php elseif ($action === 'view'): ?>
                <section style="margin-bottom:20px;">
                    <a href="forum.php" class="forum-back-btn">
                        ← Quay lại diễn đàn
                    </a>
                </section>
                <!-- ===================== VIEW TOPIC ===================== -->
                <section class="forum-section">
                    <h2><?= htmlspecialchars($topic['Title']) ?></h2>
                    <p class="forum-topic-desc">
                        <?= nl2br(htmlspecialchars($topic['Description'])) ?>
                    </p>
                </section>



                <?php foreach ($posts as $p): ?>
                    <section class="forum-post">
                        <div class="forum-post-meta">
                            <?= htmlspecialchars($p['Username']) ?> •
                            <?= date('d/m/Y H:i', strtotime($p['CreatedDate'])) ?>
                        </div>

                        <div class="forum-post-content">
                            <?= nl2br(htmlspecialchars($p['Content'])) ?>
                        </div>

                        <?php
                        $stmt = $pdo->prepare("
                        SELECT c.*, u.Username
                        FROM Forum_Comment c
                        JOIN User_Account u ON c.UserID = u.UserID
                        WHERE c.PostID = ?
                        ORDER BY c.CreatedDate ASC
                    ");
                        $stmt->execute([$p['PostID']]);
                        $comments = $stmt->fetchAll();
                        ?>

                        <h4 style="
                        color: var(--color-deep-blue);
                        margin: 24px 0 12px;
                        font-weight: 600;
                    ">
                            💬 Bình luận
                        </h4>

                        <?php foreach ($comments as $c): ?>
                            <div class="forum-comment" style="
                        background: #f4f7fb;
                        border-left: 4px solid var(--color-deep-blue);
                        padding: 14px 16px;
                        margin-bottom: 14px;
                        border-radius: 8px;
                    ">
                                <div class="forum-comment-meta" style="
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            font-size: 14px;
                            margin-bottom: 6px;
                        ">
                                    <strong style="color: var(--color-deep-blue);">
                                        <?= htmlspecialchars($c['Username']) ?>
                                    </strong>

                                    <span style="color:#999;">
                                        • <?= date('d/m/Y H:i', strtotime($c['CreatedDate'])) ?>
                                    </span>
                                </div>

                                <div class="forum-comment-content" style="
                            color: #333;
                            line-height: 1.6;
                            font-size: 15px;
                        ">
                                    <?= nl2br(htmlspecialchars($c['Content'])) ?>
                                </div>
                            </div>

                        <?php endforeach; ?>

                        <?php if ($isLoggedIn): ?>
                            <form method="POST" class="forum-comment-form">
                                <input type="hidden" name="action" value="create_comment">
                                <input type="hidden" name="post_id" value="<?= $p['PostID'] ?>">
                                <textarea name="content" class="account-input" placeholder="Viết bình luận..." required></textarea>
                                <button class="account-btn-secondary">Gửi</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>
    </main>

    <footer class="site-footer">
        <div class="container footer-grid">

            <!-- COL 1 -->
            <div class="footer-col">
                <h4>Moonlit</h4>
                <p class="footer-desc">
                    Hiệu sách trực tuyến dành cho những tâm hồn yêu đọc.
                    Chúng tôi tin mỗi cuốn sách đều có ánh trăng riêng 🌙
                </p>
            </div>

            <!-- COL 2 -->
            <div class="footer-col">
                <h4>Liên kết</h4>
                <ul>
                    <li><a href="index.php">Trang chủ</a></li>
                    <li><a href="shop.php">Cửa hàng</a></li>
                    <li><a href="forum.php">Moonlit Forum</a></li>
                    <li><a href="aboutus.php">Về chúng tôi</a></li>
                </ul>
            </div>

            <!-- COL 3 -->
            <div class="footer-col">
                <h4>Blog & Nội dung</h4>
                <ul>
                    <li><a href="blogs.php">Blog Moonlit</a></li>
                    <li><a href="blogs.php">Review sách</a></li>
                    <li><a href="blogs.php">Góc đọc chậm</a></li>
                </ul>
            </div>

            <!-- COL 4 -->
            <div class="footer-col">
                <h4>Chính sách</h4>
                <ul>
                    <li><a href="policy.php">Chính sách mua hàng</a></li>
                    <li><a href="policy.php">Bảo mật thông tin</a></li>
                    <li><a href="policy.php">Điều khoản sử dụng</a></li>
                    <li><a href="contact_us.php">Liên hệ</a></li>
                </ul>
            </div>

        </div>

        <div class="footer-bottom">
            © 2025 Moonlit — All rights reserved.
        </div>
    </footer>

</body>

</html>

<?php
session_start();
require_once 'db_connect.php';
if (!function_exists('nav_active')) {
    function nav_active(string $page, string $currentPage): string
    {
        return $page === $currentPage ? 'nav-active' : '';
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$currentUserID = $_SESSION['user_id'] ?? null;
$currentUsername = $_SESSION['username'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

/* =====================
   CHUYÊN MỤC NỔI BẬT
===================== */
$categories = [
    'Góc độc giả nổi bật' => 'reader_corner',
    'Review sách' => 'book_review',
    'Tác giả Việt Nam' => 'vietnam_authors',
    'Tin khuyến mãi' => 'promotions',
    'Xu hướng đọc sách' => 'reading_trends'
];

$selectedCategory = $_GET['category'] ?? '';
$categoryBlogs = [];

if ($selectedCategory && in_array($selectedCategory, $categories)) {

    // 👉 Có category → lọc theo chuyên mục
    $stmt = $pdo->prepare("
        SELECT *
        FROM Blog
        WHERE Section = ?
        ORDER BY CreatedDate DESC
    ");
    $stmt->execute([$selectedCategory]);

} else {

    // 👉 KHÔNG có category → load TẤT CẢ BLOG
    $stmt = $pdo->query("
        SELECT *
        FROM Blog
        ORDER BY CreatedDate DESC
    ");
}

$categoryBlogs = $stmt->fetchAll(PDO::FETCH_ASSOC);


/* =====================
   BLOG CHI TIẾT
===================== */
$selectedBlogID = $_GET['blog_id'] ?? null;
$selectedBlog = null;

if ($selectedBlogID) {
    $stmt = $pdo->prepare("SELECT * FROM Blog WHERE BlogID = ?");
    $stmt->execute([$selectedBlogID]);
    $selectedBlog = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <title>Blogs - Moonlit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="moonlit-style.css">

    <style>
        .blog-hero {
            margin-bottom: 32px;
        }

        .blog-hero h1 {
            color: var(--color-deep-blue);
        }

        .blog-hero-divider {
            width: 4px;
            height: 60px;
            background: var(--color-deep-blue);
            border-radius: 2px;
        }

        .blog-section {
            background: #fff;
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 32px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
        }

        .blog-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
        }

        .blog-card {
            flex: 1 1 30%;
            background: #f8fafc;
            border-radius: 16px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .blog-card:hover {
            background: #eef4ff;
            transform: translateY(-2px);
        }

        .blog-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
        }

        .blog-card-content {
            padding: 16px;
        }

        .blog-card-content h3 {
            color: var(--color-deep-blue);
            margin-bottom: 8px;
        }

        .blog-card-content p {
            color: #555;
        }

        .read-more {
            font-weight: 600;
            color: var(--color-deep-blue);
            text-decoration: none;
        }

        .category-list {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .category-list a {
            padding: 8px 14px;
            border-radius: 12px;
            background: #f0f4ff;
            color: var(--color-deep-blue);
            text-decoration: none;
        }

        .category-list a.active,
        .category-list a:hover {
            background: var(--color-deep-blue);
            color: #fff;
        }

        .blog-detail img {
            width: 100%;
            max-height: 360px;
            object-fit: cover;
            border-radius: 12px;
            margin-bottom: 16px;
        }

        .blog-detail-content {
            line-height: 1.8;
            color: #333;
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

    <main class="account-main">
        <div class="container">

            <section class="blog-hero">
                <div style="display:flex;gap:24px;">
                    <h1>Blogs & News</h1>
                    <div class="blog-hero-divider"></div>
                    <p>
                        Đây là nơi Moonlit chia sẻ những câu chuyện, cảm hứng đọc sách và xu hướng văn hóa đọc
                        dành cho cộng đồng yêu sách. Bạn có thể tìm đọc cái bài blog do Moonlit đăng tải theo các chuyên
                        mục bên dưới.
                    </p>
                </div>
            </section>

            <section class="blog-section">
                <h2>Chuyên mục nổi bật</h2>
                <br>

                <div class="category-list">
                    <?php foreach ($categories as $name => $id): ?>
                        <a href="blogs.php?category=<?= $id ?>" class="<?= $selectedCategory == $id ? 'active' : '' ?>">
                            <?= $name ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($categoryBlogs): ?>
                    <div class="blog-grid">
                        <?php foreach ($categoryBlogs as $blog): ?>
                            <div class="blog-card">
                                <img src="<?= htmlspecialchars($blog['Thumbnail']) ?>"
                                    alt="<?= htmlspecialchars($blog['Title']) ?>">
                                <div class="blog-card-content">
                                    <h3><?= htmlspecialchars($blog['Title']) ?></h3>
                                    <p><?= mb_substr(strip_tags($blog['Content']), 0, 120) ?>...</p>
                                    <a class="read-more"
                                        href="blogs.php?category=<?= $selectedCategory ?>&blog_id=<?= $blog['BlogID'] ?>">
                                        Đọc thêm
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($selectedBlog): ?>
                <section class="blog-section blog-detail">
                    <h2><?= htmlspecialchars($selectedBlog['Title']) ?></h2>
                    <br>
                    <img src="<?= htmlspecialchars($selectedBlog['Thumbnail']) ?>"
                        alt="<?= htmlspecialchars($selectedBlog['Title']) ?>">
                    <div class="blog-detail-content">
                        <?= nl2br($selectedBlog['Content']) ?>
                    </div>
                </section>
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

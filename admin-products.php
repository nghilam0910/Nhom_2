<?php
/**
 * MOONLIT STORE - ADMIN PRODUCT MANAGEMENT
 * - Thêm sách mới
 * - Upload hình (LONGBLOB)
 * - Gán danh mục
 * - Xóa sách
 */


require_once 'db_connect.php';
ob_start();
// ====== Check quyền admin ======
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'Admin')) {
    // header("Location: auth_login.php");
    // exit;
}

$currentUsername = $_SESSION['username'] ?? 'Admin';

$success_message = '';
$error_message = '';

// ====== Hàm sinh ProductID dạng P00001 ======
function generateProductID(PDO $pdo): string
{
    $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(ProductID, 2) AS UNSIGNED)) AS max_id FROM Product");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = ($row['max_id'] ?? 0) + 1;
    return 'P' . str_pad($nextId, 5, '0', STR_PAD_LEFT);
}
// ====== Hàm sinh Category dạng C00001 ======
function generateCategoryID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(CategoryID, 2) AS UNSIGNED)) AS max_id
        FROM Categories
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = ($row['max_id'] ?? 0) + 1;

    return 'C' . str_pad($nextId, 5, '0', STR_PAD_LEFT);
}
// ====== Hàm sinh Publisher dạng N00001 ======
function generatePublisherID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(PublisherID, 2) AS UNSIGNED))
        FROM Publisher
        WHERE PublisherID LIKE 'N%'
    ");

    $next = ((int) $stmt->fetchColumn()) + 1;
    return 'N' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

//====== Hàm sinh SKU dạng SKU001 ======
function generateSKUID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(SKUID, 4) AS UNSIGNED)) AS max_id
        FROM SKU
        WHERE SKUID LIKE 'SKU%'
    ");

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextId = ($row['max_id'] ?? 0) + 1;

    return 'SKU' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
}
//====== Hàm sinh AUTHOR dạng A000001 ======
function generateAuthorID(PDO $pdo): string
{
    $stmt = $pdo->query("
        SELECT MAX(CAST(SUBSTRING(AuthorID, 2) AS UNSIGNED))
        FROM Book_Author
        WHERE AuthorID LIKE 'A%'
    ");
    $next = ((int) $stmt->fetchColumn()) + 1;
    return 'A' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

/* ===== AJAX LOAD SKU ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajax_load_sku') {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();

    try {
        $pid = trim($_POST['product_id'] ?? '');
        if ($pid === '') {
            throw new Exception('Thiếu ProductID');
        }

        $stmt = $pdo->prepare("
            SELECT SKUID, ISBN, Format, BuyPrice, SellPrice, Stock, Status
            FROM SKU
            WHERE ProductID = :pid
            ORDER BY SKUID
        ");
        $stmt->execute([':pid' => $pid]);

        echo json_encode([
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
/* ===== AJAX DELETE SKU ===== */ else if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_delete_sku'
) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $skuid = trim($_POST['skuid'] ?? '');
        if ($skuid === '') {
            throw new Exception('Thiếu SKUID');
        }

        // Lấy ProductID
        $stmt = $pdo->prepare("
            SELECT ProductID FROM SKU WHERE SKUID = :skuid
        ");
        $stmt->execute([':skuid' => $skuid]);
        $productId = $stmt->fetchColumn();

        if (!$productId) {
            throw new Exception('SKU không tồn tại');
        }

        //KIỂM TRA SKU ĐÃ TỪNG BÁN CHƯA
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM order_items
            WHERE SKU_ID = :skuid
        ");
        $stmt->execute([':skuid' => $skuid]);
        $soldCount = (int) $stmt->fetchColumn();

        $pdo->beginTransaction();

        if ($soldCount > 0) {
            // ===== ĐÃ BÁN → KHÓA SKU =====
            $pdo->prepare("
                UPDATE SKU
                SET Status = 0
                WHERE SKUID = :skuid
            ")->execute([':skuid' => $skuid]);

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'mode' => 'locked',
                'message' => 'SKU đã có đơn hàng → chuyển sang Ngừng bán'
            ]);

            exit;
        }

        // ===== CHƯA BÁN → XÓA CỨNG =====

        // Xóa sale nếu có
        $pdo->prepare("
            DELETE FROM PRODUCT_SALE
            WHERE SKUID = :skuid
        ")->execute([':skuid' => $skuid]);

        // Xóa SKU
        $pdo->prepare("
            DELETE FROM SKU
            WHERE SKUID = :skuid
        ")->execute([':skuid' => $skuid]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'mode' => 'deleted'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    echo "<script>
    window.location.href = 'admin-dashboard.php?tab=products&success=add';
    </script>";
    exit;
}
// ===== AJAX update category  =====
else if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_update_sku'
) {
    $pdo->prepare("
    UPDATE SKU
    SET Format = :format,
        ISBN = :isbn,
        BuyPrice = :buy,
        SellPrice = :sell,
        Stock = :stock,
        Status = :status
    WHERE SKUID = :id
  ")->execute([
                ':format' => $_POST['format'],
                ':isbn' => $_POST['isbn'] ?: null,
                ':buy' => $_POST['buy_price'],
                ':sell' => $_POST['sell_price'],
                ':stock' => $_POST['stock'],
                ':status' => $_POST['status'],
                ':id' => $_POST['skuid']
            ]);

    echo json_encode(['success' => true]);
    exit;
}

//Xử lý load trang sau mỗi lần thêm
// ===== AJAX thêm category  =====
else if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_add_category'
) {
    if (ob_get_length())
        ob_clean();

    try {
        $categoryName = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['category_desc'] ?? '');

        if ($categoryName === '') {
            throw new Exception('Tên danh mục không được để trống');
        }

        $categoryId = generateCategoryID($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO Categories (CategoryID, CategoryName, Description)
            VALUES (:id, :name, :desc)
        ");
        $stmt->execute([
            ':id' => $categoryId,
            ':name' => $categoryName,
            ':desc' => $description ?: null
        ]);

        echo json_encode([
            'success' => true,
            'id' => $categoryId,
            'name' => $categoryName
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
// ===== AJAX thêm publisher =====
else if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_add_publisher'
) {
    if (ob_get_length())
        ob_clean();

    try {
        $publisherName = trim($_POST['publisher_name'] ?? '');

        if ($publisherName === '') {
            throw new Exception('Tên NXB không được để trống');
        }

        $publisherId = generatePublisherID($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO publisher (PublisherID, PublisherName)
            VALUES (:id, :name)
        ");
        $stmt->execute([
            ':id' => $publisherId,
            ':name' => $publisherName
        ]);

        echo json_encode([
            'success' => true,
            'id' => $publisherId,
            'name' => $publisherName
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
} else if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_add_author'
) {
    if (ob_get_length())
        ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $authorName = trim($_POST['author_name'] ?? '');
        $summary = trim($_POST['summary'] ?? '');

        if ($authorName === '') {
            throw new Exception('Tên tác giả không được để trống');
        }

        $authorId = generateAuthorID($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO Book_Author (AuthorID, AuthorName, Summary)
            VALUES (:id, :name, :summary)
        ");
        $stmt->execute([
            ':id' => $authorId,
            ':name' => $authorName,
            ':summary' => $summary ?: null
        ]);

        echo json_encode([
            'success' => true,
            'id' => $authorId,
            'name' => $authorName
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
} else if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ajax_update_product'
) {
    header('Content-Type: application/json; charset=utf-8');
    if (ob_get_length()) ob_clean();

    try {
        $pdo->beginTransaction();

        $params = [
            ':id'        => $_POST['product_id'],
            ':name'      => $_POST['name'],
            ':status'    => (int)$_POST['status'],
            ':publisher' => $_POST['publisher_id'] ?: null,
            ':author'    => $_POST['author_id'] ?: null
        ];

        $sql = "
            UPDATE Product
            SET ProductName = :name,
                Status = :status,
                PublisherID = :publisher,
                AuthorID = :author
        ";

        // chỉ update ảnh khi có upload
        if (
            isset($_FILES['image']) &&
            $_FILES['image']['error'] === UPLOAD_ERR_OK &&
            is_uploaded_file($_FILES['image']['tmp_name'])
        ) {
            $sql .= ", Image = :image";
        }

        $sql .= " WHERE ProductID = :id";
        $stmt = $pdo->prepare($sql);

        // bind param thường
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }

        // bind ảnh nếu có
        if (!empty($_FILES['image']['tmp_name'])) {
            $fp = fopen($_FILES['image']['tmp_name'], 'rb');
            $stmt->bindParam(':image', $fp, PDO::PARAM_LOB);
        }

        // ✅ EXECUTE 1 LẦN DUY NHẤT
        $stmt->execute();

        // ===== CATEGORY =====
        if (isset($_POST['category_id'])) {
            $pdo->prepare("
                DELETE FROM Product_Categories
                WHERE ProductID = :id
            ")->execute([':id' => $_POST['product_id']]);

            if ($_POST['category_id'] !== '') {
                $pdo->prepare("
                    INSERT INTO Product_Categories (ProductID, CategoryID)
                    VALUES (:pid, :cid)
                ")->execute([
                    ':pid' => $_POST['product_id'],
                    ':cid' => $_POST['category_id']
                ]);
            }
        }

        $pdo->commit();

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}



// ====== Xử lý submit form ======
else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        try {
            $productId = $_POST['product_id'] ?? '';
            if ($productId === '') {
                throw new Exception('Thiếu ProductID');
            }

            $pdo->beginTransaction();

            // Kiểm tra product đã từng bán chưa
            $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM order_items oi
            JOIN SKU s ON oi.SKU_ID = s.SKUID
            WHERE s.ProductID = :pid
        ");
            $stmt->execute([':pid' => $productId]);
            $soldCount = (int) $stmt->fetchColumn();

            if ($soldCount === 0) {
                // ===== CHƯA BÁN → XÓA CỨNG =====

                // Xóa sale
                $pdo->prepare("
                DELETE ps
                FROM PRODUCT_SALE ps
                JOIN SKU s ON ps.SKUID = s.SKUID
                WHERE s.ProductID = :pid
            ")->execute([':pid' => $productId]);

                // Xóa SKU
                $pdo->prepare("
                DELETE FROM SKU WHERE ProductID = :pid
            ")->execute([':pid' => $productId]);

                // Xóa Product
                $pdo->prepare("
                DELETE FROM Product WHERE ProductID = :pid
            ")->execute([':pid' => $productId]);

                $success_message = 'Đã xóa sản phẩm (chưa từng bán)';
            } else {
                // ===== ĐÃ BÁN → SOFT DELETE =====
                $pdo->prepare("
                UPDATE SKU SET Status = 0 WHERE ProductID = :pid
            ")->execute([':pid' => $productId]);

                $pdo->prepare("
                UPDATE Product SET Status = 0 WHERE ProductID = :pid
            ")->execute([':pid' => $productId]);

                $success_message = 'Sản phẩm đã bán → chuyển sang Ngừng bán';
            }

            $pdo->commit();
            echo "<script>
                window.location.href = 'admin-dashboard.php?tab=products&success=add';
            </script>";
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_message = $e->getMessage();
        }
    } else if ($action === 'add') {

        try {
            $pdo->beginTransaction();
            $productId = generateProductID($pdo);
            $skuId = generateSKUID($pdo);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $buyPrice = $_POST['buy_price'] ?? null;
            $sellPrice = $_POST['sell_price'] ?? null;
            $stock = $_POST['stock'] ?? null;
            if ($stock < 0) {
                throw new Exception('Tồn kho không hợp lệ');
            }
            if ($buyPrice === null || $sellPrice === null) {
                throw new Exception('Thiếu giá mua hoặc giá bán');
            }
            if ($sellPrice < $buyPrice) {
                throw new Exception('Giá bán không được thấp hơn giá mua');
            }
            $salePrice = trim($_POST['sale_price'] ?? '');
            $publisherId = $_POST['publisher_id'] ?? '';
            $categoryId = $_POST['category_id'] ?? '';
            $authorId = $_POST['author_id'] ?? null;
            $isbn = trim($_POST['isbn'] ?? '');
            if ($isbn !== '' && !preg_match('/^[0-9\-]{10,17}$/', $isbn)) {
                throw new Exception('ISBN không hợp lệ');
            }

            // xử lý image
            $imageData = null;
            if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
                $imageData = file_get_contents($_FILES['image']['tmp_name']);
            }

            /* Product */
            $stmt = $pdo->prepare("
                INSERT INTO Product
                (ProductID, ProductName, Description, Price, Image, PublisherID, AuthorID, CreatedDate, Status)
                VALUES (:id, :name, :desc, :price, :image, :publisher, :author, NOW(), 1)
            ");
            $stmt->execute([
                ':id' => $productId,
                ':name' => $name,
                ':desc' => $description,
                ':price' => $sellPrice,
                ':image' => $imageData,
                ':publisher' => $publisherId ?: null,
                ':author' => $authorId ?: null
            ]);


            /* Category */
            if ($categoryId) {
                $pdo->prepare("
                                INSERT INTO Product_Categories (ProductID, CategoryID)
                                VALUES (:pid, :cid)
                            ")->execute([
                            ':pid' => $productId,
                            ':cid' => $categoryId
                        ]);
            }

            /* SKU */
            $format = trim($_POST['sku_format'] ?? '');
            if ($format === '') {
                throw new Exception('Thiếu đặc tính SKU');
            }
            if ($isbn !== '') {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM SKU
                    WHERE ProductID = :pid AND ISBN = :isbn
                ");
                $stmt->execute([
                    ':pid' => $productId,
                    ':isbn' => $isbn
                ]);

                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('ISBN đã tồn tại cho sản phẩm này');
                }
            }

            $pdo->prepare("
                INSERT INTO SKU
                (SKUID, ProductID, ISBN, Format, BuyPrice, SellPrice, Stock, Status)
                VALUES (:skuid, :pid, :isbn, :format, :buy, :sell, :stock, 1)
            ")->execute([
                        ':skuid' => $skuId,
                        ':pid' => $productId,
                        ':isbn' => $isbn ?: null,
                        ':format' => $format,
                        ':buy' => $buyPrice,
                        ':sell' => $sellPrice,
                        ':stock' => $stock
                    ]);



            /* Sale */
            if ($salePrice) {
                $pdo->prepare("
                INSERT INTO PRODUCT_SALE
                (ProductSaleID, SKUID, DiscountedPrice, StartDate, EndDate)
                VALUES (:id, :skuid, :price, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
            ")->execute([
                            ':id' => 'PS' . substr(uniqid(), -4),
                            ':skuid' => $skuId,
                            ':price' => $salePrice
                        ]);
            }

            $pdo->commit();
            if (isset($_GET['success'])) {
                $success_message = 'Đã thêm sản phẩm';
            }
            echo "<script>
                window.location.href = 'admin-dashboard.php?tab=products&success=add';
            </script>";
            exit;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }

    } else if ($action === 'add_sku') {
        try {
            $skuId = generateSKUID($pdo);
            $buyPrice = $_POST['buy_price'] ?? null;
            $sellPrice = $_POST['sell_price'] ?? null;
            $stock = $_POST['stock'] ?? 0;
            $format = trim($_POST['format'] ?? '');
            $isbn = trim($_POST['isbn'] ?? '');
            if ($format === '' || $buyPrice === null || $sellPrice === null) {
                throw new Exception('Thiếu thông tin SKU');
            }
            $pdo->prepare("
             INSERT INTO SKU
            (SKUID, ProductID, ISBN, Format, BuyPrice, SellPrice, Stock, Status)
            VALUES (:id, :pid,:isbn, :format, :buy, :sell, :stock, 1)
        ")->execute([
                        ':id' => $skuId,
                        ':pid' => $_POST['product_id'],
                        ':isbn' => $isbn ?: null,
                        ':format' => $format,
                        ':buy' => $buyPrice,
                        ':sell' => $sellPrice,
                        ':stock' => $stock
                    ]);

            $success_message = 'Đã thêm SKU mới';

            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error_message = $e->getMessage();
        }
    }
}


$pubStmt = $pdo->query("
    SELECT PublisherID, PublisherName
    FROM publisher
    ORDER BY PublisherName
");
$publishers = $pubStmt->fetchAll(PDO::FETCH_ASSOC);

$cateStmt = $pdo->query("
    SELECT CategoryID, CategoryName 
    FROM categories 
    ORDER BY CategoryName
");
$categories = $cateStmt->fetchAll(PDO::FETCH_ASSOC);

$authorStmt = $pdo->query("
    SELECT AuthorID, AuthorName
    FROM Book_Author
    ORDER BY AuthorName
");
$authors = $authorStmt->fetchAll(PDO::FETCH_ASSOC);


// ====== Lấy danh sách sản phẩm để hiển thị ======
$products = [];
try {
    $sql = "
SELECT
    p.ProductID,
    p.ProductName,
    p.Price,
    p.CreatedDate,
    p.Status AS ProductStatus,

    p.PublisherID,
    p.AuthorID,
    pc.CategoryID,

    pub.PublisherName,
    ba.AuthorName,
    GROUP_CONCAT(DISTINCT c.CategoryName) AS Categories,

    (
        SELECT MIN(s.SellPrice)
        FROM SKU s
        WHERE s.ProductID = p.ProductID
          AND s.Status = 1
    ) AS SellPrice,

    (
        SELECT ps.DiscountedPrice
        FROM PRODUCT_SALE ps
        JOIN SKU s2 ON ps.SKUID = s2.SKUID
        WHERE s2.ProductID = p.ProductID
          AND NOW() BETWEEN ps.StartDate AND ps.EndDate
        ORDER BY ps.DiscountedPrice ASC
        LIMIT 1
    ) AS DiscountedPrice

FROM Product p
LEFT JOIN Publisher pub ON p.PublisherID = pub.PublisherID
LEFT JOIN Book_Author ba ON p.AuthorID = ba.AuthorID
LEFT JOIN Product_Categories pc ON p.ProductID = pc.ProductID
LEFT JOIN Categories c ON pc.CategoryID = c.CategoryID
GROUP BY p.ProductID
ORDER BY p.CreatedDate DESC
";


    $stmt = $pdo->query($sql);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);


} catch (Exception $e) {
    $error_message = 'Không thể tải danh sách sản phẩm: ' . $e->getMessage();
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
    <h2 class="account-section-title">Danh sách sản phẩm</h2>
    <main class="account-main">
        <?php if ($success_message): ?>
            <div class="alert alert-success account-alert">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger account-alert">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        <!-- FORM THÊM NHÀ XUẤT BẢN-->
        <div class="account-card mb-4">
            <h2 class="account-card-title">Thêm nhà xuất bản</h2>

            <form id="publisherForm" class="row g-3">
                <input type="hidden" name="action" value="ajax_add_publisher">

                <div class="col-md-8">
                    <label class="account-label">Tên NXB *</label>
                    <input type="text" name="publisher_name" class="account-input w-100"
                        placeholder="VD: Kim Đồng, Nhã Nam, Trẻ..." required>
                </div>

                <div class="col-md-4 flex-end">
                    <button type="submit" class="account-btn-save">
                        Thêm NXB
                    </button>
                </div>
            </form>
        </div>

        <!--FORM THÊM DANH MỤC-->
        <div class="account-card mb-4">
            <h2 class="account-card-title">Thêm danh mục</h2>

            <form id="categoryForm" class="row g-3">
                <input type="hidden" name="action" value="ajax_add_category">

                <div class="col-md-6">
                    <label class="account-label">Tên danh mục *</label>
                    <input type="text" name="category_name" class="account-input w-100" required
                        placeholder="VD: Văn học, Kinh tế, Thiếu nhi">
                </div>

                <div class="col-md-6">
                    <label class="account-label">Mô tả</label>
                    <input type="text" name="category_desc" class="account-input w-100"
                        placeholder="Mô tả ngắn cho danh mục">
                </div>

                <div class="col-12 flex-end">
                    <button type="submit" class="account-btn-save">
                        Thêm danh mục
                    </button>
                </div>
            </form>
        </div>
        <!-- FORM THÊM TÁC GIẢ -->
        <div class="account-card mb-4">
            <h2 class="account-card-title">Thêm tác giả</h2>

            <form id="authorForm" class="row g-3">
                <input type="hidden" name="action" value="ajax_add_author">

                <div class="col-md-6">
                    <label class="account-label">Tên tác giả *</label>
                    <input type="text" name="author_name" class="account-input w-100" required>
                </div>

                <div class="col-md-6">
                    <label class="account-label">Giới thiệu ngắn</label>
                    <input type="text" name="summary" class="account-input w-100">
                </div>

                <div class="col-12 flex-end">
                    <button class="account-btn-save">Thêm tác giả</button>
                </div>
            </form>
        </div>

        <!-- FORM THÊM SẢN PHẨM -->
        <div class="account-card mb-4">
            <h2 class="account-card-title">Thêm sách mới</h2>

            <form method="POST" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="action" value="add">

                <div class="col-md-6">
                    <label class="account-label" for="name">Tên sách *</label>
                    <input type="text" class="account-input w-100" id="name" name="name" required>
                </div>

                <div class="col-md-3">
                    <label class="account-label">Giá mua *</label>
                    <input type="number" name="buy_price" class="account-input w-100" min="0" step="1000" required>
                </div>

                <div class="col-md-3">
                    <label class="account-label">Giá bán *</label>
                    <input type="number" name="sell_price" class="account-input w-100" min="0" step="1000" required>
                </div>
                <div class="col-md-3">
                    <label class="account-label">Tồn kho ban đầu *</label>
                    <input type="number" name="stock" class="account-input w-100" min="0" required>
                </div>

                <div class="col-md-3">
                    <label class="account-label" for="sale_price">Giá khuyến mãi (nếu có)</label>
                    <input type="number" id="sale_price" name="sale_price" class="account-input w-100" step="1000"
                        min="0" placeholder="VD: 80000">
                </div>

                <div class="col-md-3">
                    <label class="account-label" for="publisher_id">Nhà xuất bản</label>
                    <select id="publisher_id" name="publisher_id" class="account-input w-100">
                        <option value="">-- Chọn NXB --</option>
                        <?php foreach ($publishers as $pub): ?>
                            <option value="<?php echo htmlspecialchars($pub['PublisherID']); ?>">
                                <?php echo htmlspecialchars($pub['PublisherName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="account-label">Tác giả</label>
                    <select name="author_id" class="account-input w-100">
                        <option value="">-- Chọn tác giả --</option>
                        <?php foreach ($authors as $a): ?>
                            <option value="<?= $a['AuthorID'] ?>">
                                <?= htmlspecialchars($a['AuthorName']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="account-label" for="category_id">Danh mục chính</label>
                    <select id="category_id" name="category_id" class="account-input w-100">
                        <option value="">-- Chọn danh mục --</option>
                        <?php foreach ($categories as $cate): ?>
                            <option value="<?php echo htmlspecialchars($cate['CategoryID']); ?>">
                                <?php echo htmlspecialchars($cate['CategoryName']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="account-label">ISBN</label>
                    <input type="text" name="isbn" class="account-input w-100" placeholder="VD: 9786043654789">
                </div>

                <div class="col-md-6">
                    <label class="account-label">Đặc tính / Format *</label>
                    <input type="text" name="sku_format" class="account-input w-100"
                        placeholder="VD: Hardcover / Paperback / Bản đặc biệt" required>
                </div>
                <div class="col-md-4">
                    <label class="account-label" for="image">Ảnh bìa (JPEG/PNG)</label>
                    <input type="file" class="account-input w-100" id="image" name="image" accept="image/*">
                </div>

                <div class="col-12">
                    <label class="account-label" for="description">Mô tả</label>
                    <textarea id="description" name="description" rows="4" class="account-input w-100"></textarea>
                </div>

                <div class="col-12 flex-end">
                    <button type="submit" class="account-btn-save" value="add">
                        Thêm sản phẩm
                    </button>
                </div>
            </form>
        </div>

        <!-- DANH SÁCH SẢN PHẨM -->
        <div class="account-card">
            <h2 class="account-card-title">Danh sách sách hiện có</h2>

            <?php if (empty($products)): ?>
                <div class="account-empty-state">
                    <p class="account-empty-text">
                        Chưa có sản phẩm nào. Thêm sách mới ở form phía trên nha!
                    </p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tên sách</th>
                                <th>Tác giả</th>
                                <th>Danh mục</th>
                                <th>NXB</th>
                                <th>Giá</th>
                                <th>Ngày tạo</th>
                                <th>Trạng thái</th>
                                <th class="text-end">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['ProductID']); ?></td>
                                    <td><?php echo htmlspecialchars($p['ProductName']); ?></td>
                                    <td><?= htmlspecialchars($p['AuthorName'] ?? '') ?></td>
                                    <td><?php echo htmlspecialchars($p['Categories'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($p['PublisherName'] ?? ''); ?></td>
                                    <td>
                                        <?php if (!empty($p['DiscountedPrice'])): ?>
                                            <span class="cart-price-current">
                                                <?php echo number_format($p['DiscountedPrice'], 0, ',', '.'); ?> đ
                                            </span>
                                            <span class="cart-price-old">
                                                <?php echo number_format($p['Price'], 0, ',', '.'); ?> đ
                                            </span>
                                        <?php else: ?>
                                            <?php echo number_format($p['Price'], 0, ',', '.'); ?> đ
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($p['CreatedDate']); ?></td>
                                    <td>
                                        <?php if ($p['ProductStatus'] == 1): ?>
                                            <span class="badge bg-success">Đang bán</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Ngừng bán</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="product_id"
                                                value="<?php echo htmlspecialchars($p['ProductID']); ?>">

                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                data-bs-target="#editProductModal"
                                                data-product='<?= json_encode($p, JSON_HEX_APOS) ?>'>
                                                Sửa
                                            </button>
                                            <button type="submit" class="btn btn-sm btn-outline-danger admin-btn-small"
                                                onclick="return confirm('Xóa sản phẩm này?');">
                                                Xóa
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal"
                                                data-bs-target="#skuModal" data-product="<?= $p['ProductID'] ?>">
                                                Thêm SKU
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                data-bs-toggle="modal" data-bs-target="#skuListModal"
                                                data-product="<?= $p['ProductID'] ?>">
                                                Xem SKU
                                            </button>

                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="modal fade" id="editProductModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <form method="POST" class="modal-content" id="editProductForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="ajax_update_product">
                    <input type="hidden" name="product_id" id="edit_product_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Sửa sản phẩm</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body row g-3">

                        <div class="col-md-6">
                            <label class="account-label">Tên sách *</label>
                            <input type="text" id="edit_product_name" name="name" class="account-input w-100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="account-label">Trạng thái</label>
                            <select id="edit_product_status" name="status" class="account-input w-100">
                                <option value="1">Đang bán</option>
                                <option value="0">Ngừng bán</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="account-label">Nhà xuất bản</label>
                            <select name="publisher_id" id="edit_product_publisher" class="account-input w-100">
                                <option value="">-- Chọn --</option>
                                <?php foreach ($publishers as $pub): ?>
                                    <option value="<?= $pub['PublisherID'] ?>">
                                        <?= htmlspecialchars($pub['PublisherName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="account-label">Tác giả</label>
                            <select name="author_id" id="edit_product_author" class="account-input w-100">
                                <option value="">-- Chọn --</option>
                                <?php foreach ($authors as $a): ?>
                                    <option value="<?= $a['AuthorID'] ?>">
                                        <?= htmlspecialchars($a['AuthorName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="account-label">Danh mục</label>
                            <select name="category_id" id="edit_product_category" class="account-input w-100">
                                <option value="">-- Chọn --</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?= $c['CategoryID'] ?>">
                                        <?= htmlspecialchars($c['CategoryName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="account-label">Ảnh hiện tại</label>
                            <div>
                                <img id="edit_product_preview" src="" alt="Ảnh bìa"
                                    style="max-height:150px; border:1px solid #ddd; padding:4px">
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="account-label">Ảnh bìa (để trống nếu không đổi)</label>
                            <input type="file" name="image" class="account-input w-100" accept="image/*">
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-primary">Lưu thay đổi</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="skuModal" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" class="modal-content">
                    <input type="hidden" name="action" value="add_sku">
                    <input type="hidden" name="product_id" id="sku_product_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Thêm đặc tính (SKU)</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body row g-3">
                        <div class="col-12">
                            <label class="account-label">Đặc tính / Format *</label>
                            <input type="text" name="format" class="account-input w-100" required>
                        </div>
                        <div class="col-12">
                            <label class="account-label">ISBN</label>
                            <input type="text" name="isbn" class="account-input w-100" placeholder="VD: 9786043654789">
                        </div>
                        <div class="col-md-6">
                            <label class="account-label">Giá mua *</label>
                            <input type="number" name="buy_price" class="account-input w-100" min="0" step="1000"
                                required>
                        </div>

                        <div class="col-md-6">
                            <label class="account-label">Giá bán *</label>
                            <input type="number" name="sell_price" class="account-input w-100" min="0" step="1000"
                                required>
                        </div>

                        <div class="col-md-6">
                            <label class="account-label">Tồn kho</label>
                            <input type="number" name="stock" class="account-input w-100" value="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary">Thêm SKU</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="modal fade" id="skuListModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">Danh sách SKU</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div id="skuListContent">
                            <p class="text-muted">Đang tải SKU...</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <div class="modal fade" id="editSkuModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <form method="POST" class="modal-content" id="editSkuForm">
                    <input type="hidden" name="action" value="ajax_update_sku">
                    <input type="hidden" name="skuid" id="edit_sku_id">

                    <div class="modal-header">
                        <h5 class="modal-title">Sửa sách</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body row g-3">

                        <!-- SKU -->
                        <div class="col-md-6">
                            <label class="account-label">Đặc tính / Format</label>
                            <input type="text" id="edit_sku_format" name="format" class="account-input w-100" required>
                        </div>

                        <div class="col-md-6">
                            <label class="account-label">ISBN</label>
                            <input type="text" id="edit_sku_isbn" name="isbn" class="account-input w-100">
                        </div>

                        <div class="col-md-4">
                            <label class="account-label">Giá mua</label>
                            <input type="number" id="edit_sku_buy" name="buy_price" class="account-input w-100"
                                step="1000" required>
                        </div>

                        <div class="col-md-4">
                            <label class="account-label">Giá bán</label>
                            <input type="number" id="edit_sku_sell" name="sell_price" class="account-input w-100"
                                step="1000" required>
                        </div>

                        <div class="col-md-4">
                            <label class="account-label">Tồn kho</label>
                            <input type="number" id="edit_sku_stock" name="stock" class="account-input w-100">
                        </div>

                        <div class="col-md-6">
                            <label class="account-label">Trạng thái</label>
                            <select id="edit_sku_status" name="status" class="account-input w-100">
                                <option value="1">Đang bán</option>
                                <option value="0">Ngừng bán</option>
                            </select>
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-primary">Lưu thay đổi</button>
                    </div>
                </form>
            </div>
        </div>


        <script>
            document.getElementById('categoryForm').addEventListener('submit', function (e) {
                e.preventDefault();

                const formData = new FormData(this);

                fetch('admin-products.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            alert(data.message);
                            return;
                        }

                        // thêm option vào select
                        const select = document.getElementById('category_id');
                        const opt = document.createElement('option');
                        opt.value = data.id;
                        opt.textContent = data.name;
                        opt.selected = true;
                        select.appendChild(opt);

                        this.reset();
                    })
                    .catch(err => {
                        alert('Lỗi khi thêm danh mục');
                        console.error(err);
                    });
            });
            document.getElementById('publisherForm').addEventListener('submit', function (e) {
                e.preventDefault();

                const formData = new FormData(this);

                fetch('admin-products.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            alert(data.message);
                            return;
                        }

                        const select = document.getElementById('publisher_id');
                        const opt = document.createElement('option');
                        opt.value = data.id;
                        opt.textContent = data.name;
                        opt.selected = true;
                        select.appendChild(opt);

                        this.reset();
                    })
                    .catch(err => {
                        alert('Lỗi khi thêm nhà xuất bản');
                        console.error(err);
                    });
            });

            document.getElementById('skuModal')
                .addEventListener('show.bs.modal', e => {
                    document.getElementById('sku_product_id').value =
                        e.relatedTarget.dataset.product;
                });
            const skuListModal = document.getElementById('skuListModal');

            skuListModal.addEventListener('show.bs.modal', function (e) {
                const productId = e.relatedTarget.dataset.product;
                const box = document.getElementById('skuListContent');

                box.innerHTML = '<p class="text-muted">Đang tải...</p>';

                const fd = new FormData();
                fd.append('action', 'ajax_load_sku');
                fd.append('product_id', productId);

                fetch('admin-products.php', {
                    method: 'POST',
                    body: fd
                })
                    .then(res => res.json())
                    .then(res => {
                        if (!res.success || res.data.length === 0) {
                            box.innerHTML = '<p class="text-muted">Sản phẩm chưa có SKU</p>';
                            return;
                        }

                        let html = `
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>SKUID</th>
              <th>Đặc tính</th>
              <th>ISBN</th>
              <th>Giá mua</th>
              <th>Giá bán</th>
              <th>Tồn kho</th>
              <th>Trạng thái</th>
              <th class="text-center">Xóa</th>
            </tr>
          </thead>
          <tbody>
        `;

                        res.data.forEach(sku => {
                            html += `
            <tr>
              <td>${sku.SKUID}</td>
              <td>${sku.Format}</td>
              <td> ${sku.ISBN ?? ''}</td>
              <td>${Number(sku.BuyPrice).toLocaleString()} đ</td>
              <td>${Number(sku.SellPrice).toLocaleString()} đ</td>
              <td>${sku.Stock}</td>
              <td>${sku.Status == 1 ? 'Đang bán' : 'Ẩn'}</td>
              <td class="text-center">
                <button class="btn btn-sm btn-outline-primary me-1"
                    onclick='openEditSKU(${JSON.stringify(sku)})'>
                    Sửa
                </button>

                <button class="btn btn-sm btn-outline-danger"
                    onclick="deleteSKU('${sku.SKUID}', '${productId}')">
                    Xóa
                </button>
                </td>
            </tr>
            `;
                        });

                        html += '</tbody></table>';
                        box.innerHTML = html;
                    })
                    .catch(() => {
                        box.innerHTML = '<p class="text-danger">Lỗi tải SKU</p>';
                    });
            });
            function deleteSKU(skuid, productId) {
                if (!confirm('Xóa SKU này?')) return;

                const fd = new FormData();
                fd.append('action', 'ajax_delete_sku');
                fd.append('skuid', skuid);

                fetch('admin-products.php', {
                    method: 'POST',
                    body: fd
                })
                    .then(res => res.json())
                    .then(res => {
                        if (!res.success) {
                            alert(res.message || 'Xóa SKU thất bại');
                            return;
                        }

                        // reload lại danh sách SKU
                        const box = document.getElementById('skuListContent');
                        box.innerHTML = '<p class="text-muted">Đang tải...</p>';

                        const reloadFd = new FormData();
                        reloadFd.append('action', 'ajax_load_sku');
                        reloadFd.append('product_id', productId);

                        return fetch('admin-products.php', {
                            method: 'POST',
                            body: reloadFd
                        });
                    })
                    .then(res => res ? res.json() : null)
                    .then(res => {
                        if (!res) return;

                        if (!res.success || res.data.length === 0) {
                            document.getElementById('skuListContent').innerHTML =
                                '<p class="text-muted">Sản phẩm chưa có SKU</p>';
                            return;
                        }

                        let html = `
            <table class="table table-bordered">
              <thead>
                <tr>
                  <th>SKUID</th>
                  <th>Đặc tính</th>
                  <th>Giá</th>
                  <th>Tồn kho</th>
                  <th>Trạng thái</th>
                  <th class="text-center">Xóa</th>
                </tr>
              </thead>
              <tbody>
            `;

                        res.data.forEach(sku => {
                            html += `
                <tr>
                  <td>${sku.SKUID}</td>
                  <td>${sku.Format}</td>
                  <td>${Number(sku.SellPrice).toLocaleString()} đ</td>
                  <td>${sku.Stock}</td>
                  <td>${sku.Status == 1 ? 'Đang bán' : 'Ẩn'}</td>
                  <td class="text-center">
                    <button class="btn btn-sm btn-outline-danger"
                        onclick="deleteSKU('${sku.SKUID}', '${productId}')">
                        Xóa
                    </button>
                  </td>
                </tr>
                `;
                        });

                        html += '</tbody></table>';
                        document.getElementById('skuListContent').innerHTML = html;
                    })
                    .catch(() => {
                        alert('Lỗi khi xóa SKU');
                    });
            }
            document.getElementById('authorForm').addEventListener('submit', function (e) {
                e.preventDefault();

                const formData = new FormData(this);

                fetch('admin-products.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            alert(data.message);
                            return;
                        }

                        // thêm option vào select tác giả
                        const select = document.querySelector('select[name="author_id"]');
                        const opt = document.createElement('option');
                        opt.value = data.id;
                        opt.textContent = data.name;
                        opt.selected = true;
                        select.appendChild(opt);

                        this.reset();
                    })
                    .catch(err => {
                        alert('Lỗi khi thêm tác giả');
                        console.error(err);
                    });
            });
            function openEditSKU(sku) {

                // đóng modal danh sách SKU trước
                const skuListModalEl = document.getElementById('skuListModal');
                const skuListModal = bootstrap.Modal.getInstance(skuListModalEl);
                if (skuListModal) {
                    skuListModal.hide();
                }

                // fill data
                document.getElementById('edit_sku_id').value = sku.SKUID;
                document.getElementById('edit_sku_format').value = sku.Format;
                document.getElementById('edit_sku_isbn').value = sku.ISBN ?? '';
                document.getElementById('edit_sku_buy').value = sku.BuyPrice;
                document.getElementById('edit_sku_sell').value = sku.SellPrice;
                document.getElementById('edit_sku_stock').value = sku.Stock;
                document.getElementById('edit_sku_status').value = sku.Status;

                // mở modal sửa SKU SAU KHI modal kia đã đóng
                setTimeout(() => {
                    new bootstrap.Modal(
                        document.getElementById('editSkuModal'),
                        { focus: true }
                    ).show();
                }, 300);
            }

            document.addEventListener('DOMContentLoaded', function () {

                const editForm = document.getElementById('editSkuForm');
                if (!editForm) return;

                editForm.addEventListener('submit', function (e) {
                    e.preventDefault(); // ⛔ CHẶN SUBMIT THƯỜNG

                    const fd = new FormData(this);

                    fetch('admin-products.php', {
                        method: 'POST',
                        body: fd
                    })
                        .then(res => res.json())
                        .then(res => {
                            if (!res.success) {
                                alert('Cập nhật SKU thất bại');
                                return;
                            }

                            // đóng modal edit
                            const editModalEl = document.getElementById('editSkuModal');
                            bootstrap.Modal.getInstance(editModalEl).hide();

                            // CLEANUP BACKDROP + BODY
                            document.body.classList.remove('modal-open');
                            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
                        })

                        .catch(err => {
                            alert('Lỗi khi lưu SKU');
                            console.error(err);
                        });
                });

            });


            document.addEventListener('DOMContentLoaded', function () {

                const form = document.getElementById('editProductForm');
                if (!form) return;

                form.addEventListener('submit', function (e) {
                    e.preventDefault(); // ⛔ chặn submit thường

                    const fd = new FormData(form);

                    fetch('admin-products.php', {
                        method: 'POST',
                        body: fd
                    })
                        .then(res => res.json())
                        .then(res => {
                            if (!res.success) {
                                alert(res.message || 'Cập nhật sản phẩm thất bại');
                                return;
                            }

                            // đóng modal
                            const modalEl = document.getElementById('editProductModal');
                            bootstrap.Modal.getInstance(modalEl).hide();

                            // cleanup backdrop (tránh màn hình đen)
                            document.body.classList.remove('modal-open');
                            document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());

                            // reload trang để thấy dữ liệu mới
                            window.location.reload();
                        })
                        .catch(err => {
                            console.error(err);
                            alert('Lỗi khi lưu sản phẩm');
                        });
                });

            });
            document.getElementById('editProductModal')
                .addEventListener('show.bs.modal', e => {

                    const p = JSON.parse(e.relatedTarget.dataset.product);

                    document.getElementById('edit_product_id').value = p.ProductID;
                    document.getElementById('edit_product_name').value = p.ProductName;
                    document.getElementById('edit_product_status').value = p.ProductStatus;
                    document.getElementById('edit_product_publisher').value = p.PublisherID ?? '';
                    document.getElementById('edit_product_author').value = p.AuthorID ?? '';
                    document.getElementById('edit_product_category').value = p.CategoryID ?? '';

                    // 🔥 LOAD ẢNH
                    document.getElementById('edit_product_preview').src =
                        'admin-product-image.php?id=' + p.ProductID + '&t=' + Date.now();
                });
            document.querySelector('#editProductModal input[type="file"]')
                .addEventListener('change', function () {
                    const file = this.files[0];
                    if (!file) return;

                    const reader = new FileReader();
                    reader.onload = e => {
                        document.getElementById('edit_product_preview').src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                });

        </script>

    </main>


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

</body>

</html>

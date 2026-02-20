<?php
/**
 * MOONLIT STORE - KẾT NỐI CSDL (PDO)
 */

$DB_HOST = 'localhost';
$DB_NAME = 'moonlit'; // nhớ đúng tên DB bà tạo trong file .sql
$DB_USER = 'root';          // XAMPP/MAMP thường mặc định là root
$DB_PASS = '';              // XAMPP mặc định rỗng, nếu bà đặt mật khẩu thì sửa ở đây

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // bật exception
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch dạng mảng kết hợp
    PDO::ATTR_EMULATE_PREPARES   => false,                  // dùng prepared statement thật
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // Tùy bà: lúc làm bài có thể echo lỗi, còn chạy thật thì nên log và báo message chung chung
    die('Lỗi kết nối cơ sở dữ liệu: ' . $e->getMessage());
}

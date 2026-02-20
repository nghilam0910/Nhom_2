<?php
require_once 'db_connect.php';

$id = $_GET['id'] ?? '';
if ($id === '') {
    http_response_code(400);
    exit;
}

$stmt = $pdo->prepare("SELECT Image FROM Product WHERE ProductID = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['Image'])) {
    http_response_code(404);
    exit;
}

$imageData = $row['Image'];

// Detect mime từ BLOB
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->buffer($imageData) ?: 'image/jpeg';

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($imageData));
header('Cache-Control: public, max-age=86400'); // 1 ngày
echo $imageData;
exit;

<?php
require_once 'db_connect.php';

if (!isset($_GET['id'])) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare("SELECT ImageBinary FROM Banner WHERE BannerID = ?");
$stmt->execute([$_GET['id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['ImageBinary'])) {
    http_response_code(404);
    exit;
}

header("Content-Type: image/jpeg");
echo $row['ImageBinary'];

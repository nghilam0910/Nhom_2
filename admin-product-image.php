<?php
require_once 'db_connect.php';

$id = $_GET['id'] ?? '';
if ($id === '') exit;

$stmt = $pdo->prepare("
    SELECT Image
    FROM Product
    WHERE ProductID = :id
");
$stmt->execute([':id' => $id]);
$img = $stmt->fetchColumn();

if ($img) {
    header("Content-Type: image/jpeg");
    echo $img;
}

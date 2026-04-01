<?php
session_start();
require_once 'db_connect.php';

$resultCode = $_GET['resultCode'] ?? '';
$message    = $_GET['message'] ?? '';
$extraData  = $_GET['extraData'] ?? '';

$internalOrderId = '';

if ($extraData !== '') {
    $decoded = json_decode(base64_decode($extraData), true);
    if (is_array($decoded) && !empty($decoded['internalOrderId'])) {
        $internalOrderId = $decoded['internalOrderId'];
    }
}

if ($internalOrderId === '') {
    header('Location: account-index.php?section=tracking&payment=error&msg=' . urlencode('Không xác định được đơn hàng.'));
    exit;
}

try {
    if ($resultCode === '0') {
    $stmt = $pdo->prepare("
        UPDATE `Order`
        SET PaymentStatus = 'Completed',
            Status = 'Chờ xác nhận'
        WHERE OrderID = :oid
    ");
    $stmt->execute([':oid' => $internalOrderId]);

    header(
        'Location: account-index.php?section=tracking'
        . '&payment=success'
        . '&orderId=' . urlencode($internalOrderId)
        . '&msg=' . urlencode('Thanh toán đơn hàng thành công.')
    );
    exit;
} else {
        $stmt = $pdo->prepare("
            UPDATE `Order`
            SET PaymentStatus = 'Cancelled',
                Status = 'Bị hủy'
            WHERE OrderID = :oid
              AND PaymentStatus <> 'Paid'
        ");
        $stmt->execute([':oid' => $internalOrderId]);

        header(
            'Location: account-index.php?section=tracking'
            . '&payment=cancel'
            . '&orderId=' . urlencode($internalOrderId)
            . '&msg=' . urlencode($message !== '' ? $message : 'Bạn đã hủy hoặc thanh toán thất bại.')
        );
        exit;
    }
} catch (Exception $e) {
    header(
        'Location: account-index.php?section=tracking'
        . '&payment=error'
        . '&orderId=' . urlencode($internalOrderId)
        . '&msg=' . urlencode($e->getMessage())
    );
    exit;
}
?>
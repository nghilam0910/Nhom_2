<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
require_once 'db_connect.php';

/*
|--------------------------------------------------------------------------
| MoMo config
|--------------------------------------------------------------------------
*/
$partnerCode = 'MOMO4MUD20240115_TEST';
$accessKey   = 'Ekj9og2VnRfOuIys';
$secretKey   = 'PseUbm2s8QVJEbexsh8H3Jz2qa9tDqoa';

/*
|--------------------------------------------------------------------------
| Read raw JSON from MoMo
|--------------------------------------------------------------------------
*/
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'resultCode' => 1,
        'message'    => 'Invalid JSON'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Get fields
|--------------------------------------------------------------------------
*/
$partnerCodeRes = $data['partnerCode']  ?? '';
$orderId        = $data['orderId']      ?? '';
$requestId      = $data['requestId']    ?? '';
$amount         = $data['amount']       ?? '';
$orderInfo      = $data['orderInfo']    ?? '';
$orderType      = $data['orderType']    ?? '';
$transId        = $data['transId']      ?? '';
$resultCode     = (string)($data['resultCode'] ?? '');
$message        = $data['message']      ?? '';
$payType        = $data['payType']      ?? '';
$responseTime   = $data['responseTime'] ?? '';
$extraData      = $data['extraData']    ?? '';
$signature      = $data['signature']    ?? '';
$decodedExtra = json_decode(base64_decode($extraData), true);
$internalOrderId = '';

if (is_array($decodedExtra) && !empty($decodedExtra['internalOrderId'])) {
    $internalOrderId = $decodedExtra['internalOrderId'];
}

if ($internalOrderId === '') {
    http_response_code(400);
    echo json_encode([
        'resultCode' => 1,
        'message'    => 'Missing internalOrderId in extraData'
    ]);
    exit;
}
if ($orderId === '' || $requestId === '' || $signature === '') {
    http_response_code(400);
    echo json_encode([
        'resultCode' => 1,
        'message'    => 'Missing required fields'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Verify signature
|--------------------------------------------------------------------------
| Công thức này dùng cho response IPN của MoMo v2.
| Nếu bên test trả field hơi khác thì dump rawBody ra file log để chỉnh lại.
|--------------------------------------------------------------------------
*/
$rawHash =
    "accessKey="   . $accessKey .
    "&amount="     . $amount .
    "&extraData="  . $extraData .
    "&message="    . $message .
    "&orderId="    . $orderId .
    "&orderInfo="  . $orderInfo .
    "&orderType="  . $orderType .
    "&partnerCode=". $partnerCodeRes .
    "&payType="    . $payType .
    "&requestId="  . $requestId .
    "&responseTime=". $responseTime .
    "&resultCode=" . $resultCode .
    "&transId="    . $transId;

$calcSignature = hash_hmac('sha256', $rawHash, $secretKey);

if ($calcSignature !== $signature) {
    http_response_code(400);
    echo json_encode([
        'resultCode' => 1,
        'message'    => 'Invalid signature'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Process order
|--------------------------------------------------------------------------
*/
try {
    $pdo->beginTransaction();

    // Khóa đơn hàng để tránh xử lý trùng
    $stmtOrder = $pdo->prepare("
        SELECT OrderID, UserID, PaymentMethod, PaymentStatus, Status
        FROM `Order`
        WHERE OrderID = :oid
        LIMIT 1
        FOR UPDATE
    ");
    $stmtOrder->execute([':oid' => $internalOrderId]);
    $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found: ' . $internalOrderId);
    }

    // Nếu đã xử lý paid rồi thì trả ok luôn, tránh trừ kho 2 lần
    if (($order['PaymentStatus'] ?? '') === 'Paid') {
        $pdo->commit();
        echo json_encode([
            'resultCode' => 0,
            'message'    => 'Order already processed'
        ]);
        exit;
    }

    // Nếu thanh toán thành công
    if ($resultCode === '0') {
        // Lấy danh sách item của order
        $stmtItems = $pdo->prepare("
            SELECT SKU_ID, Quantity
            FROM Order_Items
            WHERE OrderID = :oid
        ");
        $stmtItems->execute([':oid' => $internalOrderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            throw new Exception('Order has no items');
        }

        // Trừ kho + cộng sold
        $decStock = $pdo->prepare("
            UPDATE SKU
            SET Stock = Stock - :qty
            WHERE SKUID = :skuid
              AND Status = 1
              AND Stock >= :qty
        ");

        $incSold = $pdo->prepare("
            UPDATE Product p
            JOIN SKU s ON s.ProductID = p.ProductID
            SET p.SoldQuantity = COALESCE(p.SoldQuantity, 0) + :qty
            WHERE s.SKUID = :skuid
        ");

        foreach ($items as $it) {
            $qty = (int)$it['Quantity'];
            $sk  = $it['SKU_ID'];

            $decStock->execute([
                ':qty'   => $qty,
                ':skuid' => $sk
            ]);

            if ($decStock->rowCount() <= 0) {
                throw new Exception("Không đủ tồn kho cho SKU {$sk}");
            }

            $incSold->execute([
                ':qty'   => $qty,
                ':skuid' => $sk
            ]);
        }

        // Update order thành đã thanh toán
        $updOrder = $pdo->prepare("
            UPDATE `Order`
            SET PaymentStatus = 'Paid',
                Status = 'Chờ xác nhận'
            WHERE OrderID = :oid
        ");
        $updOrder->execute([':oid' => $internalOrderId]);

        // Xóa cart của user sau khi thanh toán thành công
        $clearCart = $pdo->prepare("
            DELETE ci
            FROM Cart_Items ci
            JOIN Cart c ON ci.CartID = c.CartID
            WHERE c.UserID = :uid
        ");
        $clearCart->execute([':uid' => $order['UserID']]);

    } else {
        // Thanh toán thất bại / hủy
        $updOrder = $pdo->prepare("
            UPDATE `Order`
            SET PaymentStatus = 'Cancelled',
                Status = 'Bị hủy'
            WHERE OrderID = :oid
              AND PaymentStatus <> 'Paid'
        ");
        $updOrder->execute([':oid' => $internalOrderId]);
    }

    $pdo->commit();

    echo json_encode([
        'resultCode' => 0,
        'message'    => 'Success'
    ]);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'resultCode' => 1,
        'message'    => $e->getMessage()
    ]);
    exit;
}
?>
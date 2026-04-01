<?php
function execPostRequest($url, $data)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data)
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        die('cURL Error: ' . curl_error($ch));
    }

    curl_close($ch);
    return $result;
}

$endpoint = "https://test-payment.momo.vn/v2/gateway/api/create";

$partnerCode = 'MOMO4MUD20240115_TEST';
$accessKey   = 'Ekj9og2VnRfOuIys';
$secretKey   = 'PseUbm2s8QVJEbexsh8H3Jz2qa9tDqoa';

if (!isset($_POST["soTien"]) || $_POST["soTien"] === "") {
    die("Thiếu số tiền thanh toán");
}

$amount = (string)(int)$_POST["soTien"];
$internalOrderId = !empty($_POST["orderId"]) ? trim($_POST["orderId"]) : time() . "";
$orderInfo = !empty($_POST["orderInfo"]) ? trim($_POST["orderInfo"]) : "Thanh toán qua MoMo";

/*
|--------------------------------------------------------------------------
| TẠO MÃ orderId RIÊNG CHO MOMO, KHÔNG DÙNG LẠI OrderID NỘI BỘ
|--------------------------------------------------------------------------
*/
$momoOrderId = $internalOrderId . "_" . time();
$requestId   = $momoOrderId;

/*
|--------------------------------------------------------------------------
| Lưu order gốc vào extraData để return/ipn đọc lại
|--------------------------------------------------------------------------
*/
$extraDataArr = [
    'internalOrderId' => $internalOrderId
];
$extraData = base64_encode(json_encode($extraDataArr));

$redirectUrl = "http://localhost/web/payment_return.php";
$ipnUrl      = "http://localhost/web/payment_ipn.php";
$requestType = !empty($_POST["requestType"]) ? trim($_POST["requestType"]) : 'captureWallet';
$allowRequestTypes = ['captureWallet', 'payWithATM', 'payWithCC'];
if (!in_array($requestType, $allowRequestTypes, true)) {
    $requestType = 'captureWallet';
}


$rawHash = "accessKey=" . $accessKey .
           "&amount=" . $amount .
           "&extraData=" . $extraData .
           "&ipnUrl=" . $ipnUrl .
           "&orderId=" . $momoOrderId .
           "&orderInfo=" . $orderInfo .
           "&partnerCode=" . $partnerCode .
           "&redirectUrl=" . $redirectUrl .
           "&requestId=" . $requestId .
           "&requestType=" . $requestType;

$signature = hash_hmac("sha256", $rawHash, $secretKey);

$data = array(
    'partnerCode' => $partnerCode,
    'partnerName' => "Test",
    'storeId' => "MomoTestStore",
    'requestId' => $requestId,
    'amount' => $amount,
    'orderId' => $momoOrderId,
    'orderInfo' => $orderInfo,
    'redirectUrl' => $redirectUrl,
    'ipnUrl' => $ipnUrl,
    'lang' => 'vi',
    'extraData' => $extraData,
    'requestType' => $requestType,
    'signature' => $signature
);

if ($requestType === 'payWithCC') {
    $data['userInfo'] = [
        'email' => trim($_POST['email'] ?? '')
    ];
}

$result = execPostRequest($endpoint, json_encode($data));
$jsonResult = json_decode($result, true);

if (isset($jsonResult['payUrl']) && $jsonResult['payUrl'] !== '') {
    header('Location: ' . $jsonResult['payUrl']);
    exit;
} else {
    echo "<pre>";
    print_r($jsonResult);
    echo "</pre>";
}
?>
<?php
/**
 * Personal Information Section
 * Display and edit user profile information with Vietnam Administrative Units
 */

if (!isset($_SESSION['user_id'])) {
    header('Location: auth-login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Fetch current user data (Cập nhật câu SELECT lấy các trường mới)
$stmt = $pdo->prepare("SELECT UserID, Username, FullName, Phone, Email, City, District, Ward, Street, HouseNumber, Password FROM User_Account WHERE UserID = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_info') {
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        // Lấy dữ liệu địa chỉ chi tiết
        $city = trim($_POST['city'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $ward = trim($_POST['ward'] ?? '');
        $street = trim($_POST['street'] ?? '');
        $house_number = trim($_POST['house_number'] ?? '');

        if (empty($email)) {
            $message = 'Email không thể trống.';
            $message_type = 'danger';
        } else {
            try {
                // Cập nhật câu UPDATE
                $stmt = $pdo->prepare("
                    UPDATE User_Account
                    SET FullName = ?, Phone = ?, Email = ?, 
                        City = ?, District = ?, Ward = ?, Street = ?, HouseNumber = ?
                    WHERE UserID = ?
                ");
                $stmt->execute([$full_name, $phone, $email, $city, $district, $ward, $street, $house_number, $user_id]);

                $message = 'Cập nhật thông tin thành công!';
                $message_type = 'success';

                // Refresh user data
                $stmt = $pdo->prepare("SELECT UserID, Username, FullName, Phone, Email, City, District, Ward, Street, HouseNumber FROM User_Account WHERE UserID = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } catch (Exception $e) {
                $message = 'Lỗi cập nhật: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    } elseif ($action === 'change_password') {
        // ... (Giữ nguyên logic đổi mật khẩu như cũ) ...
        $current_password = $_POST['current_password'] ?? '';
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $message = 'Vui lòng điền đầy đủ thông tin.';
            $message_type = 'danger';
        } elseif (!password_verify($current_password, $user['Password'])) {
            $message = 'Mật khẩu hiện tại không chính xác.';
            $message_type = 'danger';
        } elseif (strlen($new_password) < 6) {
            $message = 'Mật khẩu mới phải ít nhất 6 ký tự.';
            $message_type = 'danger';
        } elseif ($new_password !== $confirm_password) {
            $message = 'Mật khẩu xác nhận không khớp.';
            $message_type = 'danger';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE User_Account SET Password = ? WHERE UserID = ?");
                $stmt->execute([$hashed_password, $user_id]);

                $message = 'Đổi mật khẩu thành công!';
                $message_type = 'success';
                
                // Refresh data (quan trọng để lấy lại password hash mới nếu cần kiểm tra lại ngay)
                $stmt = $pdo->prepare("SELECT UserID, Username, FullName, Phone, Email, City, District, Ward, Street, HouseNumber, Password FROM User_Account WHERE UserID = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
            } catch (Exception $e) {
                $message = 'Lỗi đổi mật khẩu: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}
?>

<div class="account-section" data-page-id="profile-page">
    <h2 class="account-section-title">Thông tin cá nhân</h2>

    <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> account-alert" role="alert">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="account-card">
        <h3 class="account-card-title">Thông tin cơ bản</h3>
        <form method="POST" class="account-form">
            <input type="hidden" name="action" value="update_info">

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="username" class="form-label account-label">Tên đăng nhập <span class="text-danger">*</span></label>
                    <input type="text" class="form-control account-input" id="username" value="<?php echo htmlspecialchars($user['Username']); ?>" disabled>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="full_name" class="form-label account-label">Họ và tên <span class="text-danger">*</span></label>
                    <input type="text" class="form-control account-input" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['FullName'] ?? ''); ?>" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="email" class="form-label account-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control account-input" id="email" name="email" value="<?php echo htmlspecialchars($user['Email'] ?? ''); ?>" required>
                </div>

                <div class="col-md-6 mb-3">
                    <label for="phone" class="form-label account-label">Số điện thoại <span class="text-danger">*</span></label>
                    <input type="tel" class="form-control account-input" id="phone" name="phone" value="<?php echo htmlspecialchars($user['Phone'] ?? ''); ?>" required>
                </div>
            </div>

            <hr class="my-4">
            <h3 class="account-card-title">Địa chỉ liên hệ</h3>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="city" class="form-label account-label">Tỉnh / Thành phố <span class="text-danger">*</span></label>
                    <select class="form-select account-input" id="city" name="city">
                        <option value="" selected>Chọn Tỉnh/Thành phố</option>
                    </select>
                    <input type="hidden" id="saved_city" value="<?php echo htmlspecialchars($user['City'] ?? ''); ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label for="district" class="form-label account-label">Quận / Huyện <span class="text-danger">*</span></label>
                    <select class="form-select account-input" id="district" name="district" disabled>
                        <option value="" selected>Chọn Quận/Huyện</option>
                    </select>
                    <input type="hidden" id="saved_district" value="<?php echo htmlspecialchars($user['District'] ?? ''); ?>">
                </div>

                <div class="col-md-4 mb-3">
                    <label for="ward" class="form-label account-label">Phường / Xã <span class="text-danger">*</span></label>
                    <select class="form-select account-input" id="ward" name="ward" disabled>
                        <option value="" selected>Chọn Phường/Xã</option>
                    </select>
                    <input type="hidden" id="saved_ward" value="<?php echo htmlspecialchars($user['Ward'] ?? ''); ?>">
                </div>

                <div class="col-md-8 mb-3">
                    <label for="street" class="form-label account-label">Tên đường <span class="text-danger">*</span></label>
                    <input type="text" class="form-control account-input" id="street" name="street" value="<?php echo htmlspecialchars($user['Street'] ?? ''); ?>" placeholder="Ví dụ: Đường Nguyễn Huệ">
                </div>

                <div class="col-md-4 mb-3">
                    <label for="house_number" class="form-label account-label">Số nhà <span class="text-danger">*</span></label>
                    <input type="text" class="form-control account-input" id="house_number" name="house_number" value="<?php echo htmlspecialchars($user['HouseNumber'] ?? ''); ?>" placeholder="Ví dụ: 123A">
                </div>
            </div>

            <button type="submit" class="btn account-btn-save">
                <i class="fas fa-save"></i> Lưu thay đổi
            </button>
        </form>
    </div>

    <div class="account-card account-card-password">
        <h3 class="account-card-title">Đổi mật khẩu</h3>
        <form method="POST" class="account-form">
            <input type="hidden" name="action" value="change_password">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="current_password" class="form-label account-label">Mật khẩu hiện tại</label>
                    <input type="password" class="form-control account-input" id="current_password" name="current_password" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="new_password" class="form-label account-label">Mật khẩu mới</label>
                    <input type="password" class="form-control account-input" id="new_password" name="new_password" required>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="confirm_password" class="form-label account-label">Xác nhận mật khẩu</label>
                    <input type="password" class="form-control account-input" id="confirm_password" name="confirm_password" required>
                </div>
            </div>
            <button type="submit" class="btn account-btn-save">
                <i class="fas fa-key"></i> Đổi mật khẩu
            </button>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/axios/0.21.1/axios.min.js"></script>
<script src ="moonlit.js"></script>
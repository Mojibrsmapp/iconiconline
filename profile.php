<?php
require_once __DIR__ . '/includes/user_session.php';

if (!isset($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}
$profileCsrf = $_SESSION['profile_csrf'];

// Images selected by the user are stored locally, never forwarded to a third party.
function store_profile_image(array $file): ?string
{
    $temporaryFile = $file['tmp_name'] ?? null;
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($temporaryFile)
        || !is_uploaded_file($temporaryFile) || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return null;
    }

    $image = @getimagesize($temporaryFile);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $extension = $extensions[$image['mime'] ?? ''] ?? null;
    if ($extension === null) {
        return null;
    }

    $directory = __DIR__ . '/uploads/avatars';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return null;
    }

    $path = 'uploads/avatars/' . bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = __DIR__ . '/' . $path;
    if (!move_uploaded_file($temporaryFile, $destination)) {
        return null;
    }
    if (!chmod($destination, 0640)) {
        unlink($destination);
        return null;
    }
    return $path;
}

function profile_upload_response(array $response, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

$update_msg = '';
$update_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ajax = isset($_FILES['ajax_profile_image']);
    $submittedToken = $_POST['csrf_token'] ?? null;
    if (!is_string($submittedToken) || !hash_equals($profileCsrf, $submittedToken)) {
        if ($ajax) {
            profile_upload_response(['status' => 'error', 'message' => 'অনুরোধটি গ্রহণ করা যায়নি।'], 403);
        }
        http_response_code(403);
        exit('অনুরোধটি গ্রহণ করা যায়নি।');
    }

    if ($ajax) {
        $imagePath = store_profile_image($_FILES['ajax_profile_image']);
        if ($imagePath === null) {
            profile_upload_response(['status' => 'error', 'message' => 'সর্বোচ্চ ৫ এমবি JPG, PNG, WebP বা GIF ছবি দিন।'], 422);
        }
        try {
            $stmt = $conn->prepare('UPDATE users SET image = ? WHERE id = ?');
            $stmt->bind_param('si', $imagePath, $id);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            unlink(__DIR__ . '/' . $imagePath);
            profile_upload_response(['status' => 'error', 'message' => 'ছবিটি সংরক্ষণ করা যায়নি।'], 500);
        }
        profile_upload_response(['status' => 'success', 'image_url' => $imagePath]);
    }

    if (isset($_POST['update_profile'])) {
        $newName = $_POST['name'] ?? null;
        $newPhone = $_POST['phone'] ?? null;
        $newAddress = $_POST['address'] ?? '';
        $imagePath = null;
        if (!is_string($newName) || !is_string($newPhone) || !is_string($newAddress)
            || trim($newName) === '' || trim($newPhone) === '') {
            $update_error = 'নাম ও মোবাইল নম্বর সঠিকভাবে দিন।';
        } elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $imagePath = store_profile_image($_FILES['profile_image']);
            if ($imagePath === null) {
                $update_error = 'সর্বোচ্চ ৫ এমবি JPG, PNG, WebP বা GIF ছবি দিন।';
            }
        }
        if ($update_error === '') {
            $newName = trim($newName);
            $newPhone = trim($newPhone);
            $newAddress = trim($newAddress);
            try {
                $stmt = $conn->prepare('UPDATE users SET name = ?, mobile = ?, address = ?, image = COALESCE(?, image) WHERE id = ?');
                $stmt->bind_param('ssssi', $newName, $newPhone, $newAddress, $imagePath, $id);
                $stmt->execute();
                $stmt->close();
                $name = $newName;
                $phone = $newPhone;
                $user_image = $imagePath ?? $user_image;
                $update_msg = 'প্রোফাইল সফলভাবে আপডেট করা হয়েছে!';
            } catch (mysqli_sql_exception $e) {
                if ($imagePath !== null) {
                    unlink(__DIR__ . '/' . $imagePath);
                }
                $update_error = 'প্রোফাইল তথ্য সংরক্ষণ করা যায়নি।';
            }
        }
    }
}

$user_query = $conn->prepare('SELECT address FROM users WHERE id = ?');
$user_query->bind_param('i', $id);
$user_query->execute();
$address = $user_query->get_result()->fetch_assoc()['address'] ?? '';
$user_query->close();
$user_balance = $balance;
$user_avatar = $user_image;

require __DIR__ . '/header.php';
?>

<!-- FontAwesome 6 CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Nikosh&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>

<style>
    * { font-family: 'Nikosh', sans-serif !important; }
    body { background: #0b071a; color: #F8FAFC; min-height: 100vh; padding-bottom: 80px; }
    
    .hub-card {
        background: radial-gradient(130% 130% at 0% 0%, rgba(30, 16, 61, 0.6) 0%, rgba(13, 8, 27, 0.8) 100%);
        border: 1px solid rgba(168, 85, 247, 0.2);
        border-radius: 20px;
        box-shadow: 0 10px 30px -10px rgba(124, 58, 237, 0.2);
    }
    
    .form-control-custom {
        width: 100%;
        border-radius: 12px;
        border: 1.5px solid rgba(168, 85, 247, 0.3);
        padding: 12px 16px;
        font-size: 14px;
        outline: none;
        background: rgba(14, 9, 28, 0.8);
        color: #FFFFFF;
        transition: all 0.2s;
    }
    .form-control-custom:focus {
        border-color: #c084fc;
        box-shadow: 0 0 0 4px rgba(192, 132, 252, 0.2);
    }
    
    .btn-grad {
        background: linear-gradient(135deg, #7c3aed 0%, #c026d3 50%, #e11d48 100%);
        color: #FFF;
        border: none;
        border-radius: 12px;
        padding: 12px 20px;
        font-weight: 700;
        transition: all 0.3s ease;
        box-shadow: 0 8px 20px rgba(124, 58, 237, 0.3);
    }
    .btn-grad:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 25px rgba(225, 29, 72, 0.4);
    }
</style>


<!-- Hidden File Input for Instant Profile Upload -->
<input type="file" id="profileFolderInput" accept="image/*" style="display: none;" onchange="uploadProfilePictureLocally()">

<div class="max-w-5xl mx-auto px-3 sm:px-6 py-6 space-y-5">
    
    <!-- Top Header Title Card -->
    <div class="hub-card p-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-purple-500/20 text-purple-400 border border-purple-500/30 flex items-center justify-center text-lg">
                <i class="fa-solid fa-user-gear"></i>
            </div>
            <div>
                <h1 class="text-base sm:text-lg font-extrabold text-white">আমার প্রোফাইল ও সেটিংস</h1>
                <p class="text-xs text-purple-300/70">আপনার ব্যক্তিগত তথ্য এবং একাউন্ট নিরাপত্তা ম্যানেজ করুন</p>
            </div>
        </div>
        <a href="dashboard.php" class="bg-purple-950/60 hover:bg-purple-900/60 text-purple-200 border border-purple-500/30 text-xs font-bold px-3.5 py-2 rounded-xl transition flex items-center gap-1.5">
            <i class="fa-solid fa-house"></i> ড্যাশবোর্ড
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-12 gap-5">
        
        <!-- Profile Sidebar Card -->
        <div class="md:col-span-4 space-y-4">
            <div class="hub-card p-5 text-center">
                <div class="relative inline-block mb-3">
                    <img id="profileImagePreview" src="<?php echo htmlspecialchars($user_avatar); ?>" 
                         class="rounded-full border-4 border-purple-500/50 shadow-md object-cover cursor-pointer w-28 h-28 mx-auto" 
                         alt="Profile Picture" 
                         title="প্রোফাইল ছবি পরিবর্তন করতে ক্লিক করুন"
                         onclick="document.getElementById('profileFolderInput').click();">
                    <span class="absolute bottom-0 right-0 bg-purple-600 text-white rounded-full p-2 shadow text-xs pointer-events-none border border-purple-400">
                        <i class="fa-solid fa-camera"></i>
                    </span>
                </div>
            
                <h4 class="font-extrabold text-white text-base sm:text-lg"><?php echo htmlspecialchars($name); ?></h4>
                <p class="text-purple-300/70 text-xs mb-4"><?php echo htmlspecialchars($email); ?></p>
                
                <!-- Wallet Widget -->
                <div class="bg-purple-950/40 border border-purple-500/20 rounded-xl p-3 mb-3 flex items-center justify-between">
                    <div class="text-left">
                        <span class="text-[10px] font-bold text-purple-300/60 block uppercase">কারেন্ট ব্যালেন্স</span>
                        <span class="text-sm font-extrabold text-emerald-400">৳<?php echo htmlspecialchars($user_balance); ?></span>
                    </div>
                    <a href="recharge.php" class="bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
                        <i class="fa-solid fa-plus text-[10px]"></i> রিচার্জ
                    </a>
                </div>

                <div class="space-y-2">
                    <button type="button" class="btn-grad w-full text-xs py-2.5 flex items-center justify-center gap-2" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                        <i class="fa-solid fa-key"></i> পাসওয়ার্ড পরিবর্তন করুন
                    </button>
                </div>
            </div>

            <!-- System Security Info Card -->
            <div class="hub-card p-4">
                <h4 class="text-xs font-extrabold uppercase tracking-wider text-purple-300/80 mb-3 pb-2 border-b border-purple-500/20">সিকিউরিটি ও ইনফো</h4>
                <div class="space-y-2 text-xs">
                    <div class="flex justify-between items-center text-slate-300">
                        <span class="font-bold text-purple-300/70">IP Address:</span>
                        <span class="text-sky-400 font-bold"><?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '—'); ?></span>
                    </div>
                    <div class="flex justify-between items-center text-slate-300">
                        <span class="font-bold text-purple-300/70">একাউন্ট আইডি:</span>
                        <span class="font-bold text-white">#<?php echo $id; ?></span>
                    </div>

                </div>
            </div>
        </div>

        <!-- User Info & Edit Card -->
        <div class="md:col-span-8">
            <div class="hub-card p-5 sm:p-6 mb-4">
                <h4 class="text-sm sm:text-base font-extrabold text-white border-b border-purple-500/20 pb-3 mb-4 flex items-center gap-2">
                    <i class="fa-solid fa-user-pen text-purple-400"></i> প্রোফাইল তথ্য এবং আপডেট
                </h4>

                <?php if (!empty($update_msg)): ?>
                    <div class="bg-emerald-500/15 border border-emerald-500/30 text-emerald-300 rounded-xl py-2.5 px-3.5 text-xs font-bold mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-circle-check text-emerald-400"></i> <?php echo $update_msg; ?>
                    </div>
                <?php endif; ?>

                <?php if ($update_error !== ''): ?>
                    <p role="alert" class="text-rose-300 text-xs mb-3"><?= htmlspecialchars($update_error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <form action="" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileCsrf, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-purple-200 mb-1.5">পূর্ণ নাম</label>
                            <input type="text" class="form-control-custom" name="name" value="<?php echo htmlspecialchars($name); ?>" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-purple-200 mb-1.5">ইমেইল ঠিকানা (পরিবর্তনযোগ্য নয়)</label>
                            <input type="email" class="form-control-custom bg-purple-950/40 text-purple-300/50 cursor-not-allowed border-purple-500/10" value="<?php echo htmlspecialchars($email); ?>" disabled>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-purple-200 mb-1.5">মোবাইল / WhatsApp নম্বর</label>
                            <input type="text" class="form-control-custom" name="phone" value="<?php echo htmlspecialchars($phone); ?>" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-purple-200 mb-1.5">প্রোফাইল ছবি আপলোড</label>
                            <input type="file" class="form-control-custom text-xs file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-purple-950 file:text-purple-300 hover:file:bg-purple-900 cursor-pointer" name="profile_image" accept="image/*">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-bold text-purple-200 mb-1.5">বর্তমান ঠিকানা</label>
                            <textarea class="form-control-custom" name="address" rows="2" placeholder="আপনার সম্পূর্ণ ঠিকানা লিখুন..."><?php echo htmlspecialchars($address); ?></textarea>
                        </div>
                        <div class="sm:col-span-2 pt-2">
                            <button type="submit" name="update_profile" class="btn-grad w-full text-sm flex items-center justify-center gap-2">
                                <i class="fa-solid fa-floppy-disk"></i> তথ্য সংরক্ষণ করুন
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<!-- মোবাইল বটম নেভবার (ড্যাশবোর্ডের সাথে মিল রেখে যুক্ত করা হলো) -->
<nav class="md:hidden fixed bottom-0 inset-x-0 bg-[#0d081b]/95 backdrop-blur-md border-t border-purple-500/20 py-2 px-4 flex items-center justify-around z-40 shadow-[0_-4px_15px_rgba(0,0,0,0.04)]">
    <a href="dashboard.php" class="flex flex-col items-center gap-1 text-purple-300/70 hover:text-purple-300 text-[11px] font-semibold">
        <i class="fa-solid fa-house text-lg"></i>
        <span>হোম</span>
    </a>
    <a href="services.php" class="flex flex-col items-center gap-1 text-purple-300/70 hover:text-purple-300 text-[11px] font-semibold">
        <i class="fa-solid fa-table-cells-large text-lg"></i>
        <span>সেবা</span>
    </a>
    <a href="recharge.php" class="flex flex-col items-center gap-1 text-purple-300/70 hover:text-purple-300 text-[11px] font-semibold">
        <i class="fa-solid fa-wallet text-lg"></i>
        <span>ওয়ালেট</span>
    </a>
    <a href="profile.php" class="flex flex-col items-center gap-1 text-purple-400 text-[11px] font-extrabold">
        <i class="fa-solid fa-user text-lg"></i>
        <span>প্রোফাইল</span>
    </a>
</nav>

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-2xl border border-purple-500/30 bg-[#170f2b] text-white shadow-2xl">
            <div class="modal-header border-bottom-0 px-4 py-3">
                <h5 class="modal-title font-bold text-white text-sm" id="changePasswordModalLabel">পাসওয়ার্ড পরিবর্তন করুন</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="changePasswordForm" action="pages/change_password.php" method="POST" class="space-y-3">
                    <div>
                        <label for="currentPassword" class="block text-xs font-bold text-purple-200 mb-1">বর্তমান পাসওয়ার্ড</label>
                        <input type="password" class="form-control-custom" id="currentPassword" name="currentPassword" required>
                    </div>
                    <div>
                        <label for="newPassword" class="block text-xs font-bold text-purple-200 mb-1">নতুন পাসওয়ার্ড</label>
                        <input type="password" class="form-control-custom" id="newPassword" name="newPassword" required>
                    </div>
                    <div>
                        <label for="confirmPassword" class="block text-xs font-bold text-purple-200 mb-1">কনফার্ম নতুন পাসওয়ার্ড</label>
                        <input type="password" class="form-control-custom" id="confirmPassword" name="confirmPassword" required>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="btn-grad w-full text-xs">পাসওয়ার্ড আপডেট করুন</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function uploadProfilePictureLocally() {
    const fileInput = document.getElementById('profileFolderInput');
    const file = fileInput.files[0];
    if (file) {
        Swal.fire({
            title: 'আপলোড হচ্ছে...',
            text: 'অনুগ্রহ করে অপেক্ষা করুন',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        const serverData = new FormData();
        serverData.append('ajax_profile_image', file);
        serverData.append('csrf_token', <?= json_encode($profileCsrf) ?>);

        $.ajax({
            url: 'profile.php',
            type: 'POST',
            data: serverData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response && response.status === 'success') {
                    const updatedSrc = response.image_url + '?t=' + new Date().getTime();
                    $('#profileImagePreview').attr('src', updatedSrc);
                    $('#UserDropdown img').attr('src', updatedSrc);
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'সফল!',
                        text: 'প্রোফাইল ছবি সফলভাবে আপডেট করা হয়েছে।'
                    });
                } else {
                    Swal.fire('ত্রুটি!', response.message || 'ছবি সেভ করা সম্ভব হয়নি।', 'error');
                }
            },
            error: function(request) {
                Swal.fire('ত্রুটি!', request.responseJSON?.message || 'সার্ভারে সমস্যা হয়েছে।', 'error');
            }
        });
    }
}
</script>

<?php include("footer.php");?>
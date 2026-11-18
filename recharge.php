<?php
session_start();
include_once("includes/configuration.php");

// ১. ইউজার অথেন্টিকেশন ও ব্যালেন্স ফেচ
if (!isset($_SESSION['user_token'])) {
    header("Location: index.php");
    exit();
}

$token_user = $_SESSION['user_token'];
$stmt = $conn->prepare("SELECT id, name, email, mobile, balance FROM users WHERE token = ? LIMIT 1");
$stmt->bind_param("s", $token_user);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    $user = $res->fetch_assoc();
    $user_id = $user['id'];
    $user_balance = $user['balance'] ?? 0.00;
    $user_email = $user['email'] ?? '';
} else {
    unset($_SESSION['user_token']);
    header("Location: index.php");
    exit();
}
$stmt->close();

// ২. ডাটাবেজ থেকে পেমেন্ট সেটিংস লোড করা
$payment_numbers = [];
$pay_q = $conn->query("SELECT method_key, number FROM payment_settings");
while ($payment = $pay_q->fetch_assoc()) {
    $method = strtolower((string) $payment['method_key']);
    $number = trim((string) $payment['number']);
    if (in_array($method, ['bkash', 'nagad'], true) && $number !== '') {
        $payment_numbers[$method] = $number;
    }
}

if (!isset($payment_numbers['bkash'], $payment_numbers['nagad'])) {
    http_response_code(503);
    exit('রিচার্জ এখন উপলব্ধ নয়। পেমেন্ট করার আগে সঠিক নম্বরের জন্য অপারেটরের সাথে যোগাযোগ করুন।');
}

// ৩. ডিপোজিট রিকোয়েস্ট সাবমিট হ্যান্ডলার
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_deposit'])) {
    $method = trim($_POST['method'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $trx_id = trim($_POST['trx_id'] ?? '');

    if (!isset($payment_numbers[$method]) || !is_finite($amount) || $amount <= 0 || empty($trx_id)) {
        $alert = ['type' => 'error', 'title' => 'ত্রুটি!', 'text' => 'সবগুলো তথ্য সঠিকভাবে পূরণ করুন।'];
    } else {
        // ডুপ্লিকেট TrxID চেক
        $chk_stmt = $conn->prepare("SELECT id FROM deposit_requests WHERE trx_id = ? LIMIT 1");
        $chk_stmt->bind_param("s", $trx_id);
        $chk_stmt->execute();
        $chk_res = $chk_stmt->get_result();

        if ($chk_res && $chk_res->num_rows > 0) {
            $alert = ['type' => 'error', 'title' => 'ব্যর্থ!', 'text' => 'এই ট্রানজেকশন আইডিটি ইতিমধ্যে ব্যবহৃত হয়েছে।'];
        } else {
            // ডাটাবেজের deposit_requests টেবিলে ইনসার্ট
            $gateway = 'manual';
            $status = 'pending';
            $ins_stmt = $conn->prepare("INSERT INTO deposit_requests (user_id, gateway, method, trx_id, amount, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $ins_stmt->bind_param("isssds", $user_id, $gateway, $method, $trx_id, $amount, $status);

            if ($ins_stmt->execute()) {
                $alert = ['type' => 'success', 'title' => 'আবেদন সফল!', 'text' => 'আপনার রিচার্জ রিকোয়েস্ট জমা হয়েছে। খুব শীঘ্রই ব্যালেন্স যুক্ত হবে।'];
            } else {
                $alert = ['type' => 'error', 'title' => 'ত্রুটি!', 'text' => 'রিকোয়েস্ট জমা করতে সমস্যা হয়েছে।'];
            }
            $ins_stmt->close();
        }
        $chk_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>দ্রুত রিচার্জ - ICONIC ONLINE</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            font-family: 'Hind Siliguri', 'Plus Jakarta Sans', sans-serif;
            background: #F1F5F9;
            color: #1E293B;
            min-height: 100vh;
            padding-bottom: 90px;
        }

        .recharge-header-bg {
            background: linear-gradient(180deg, #1D4ED8 0%, #2563EB 50%, #3B82F6 100%);
            border-bottom-left-radius: 35px;
            border-bottom-right-radius: 35px;
            padding: 16px 20px 70px;
        }

        .balance-badge-top {
            background: #FBBF24;
            color: #1E293B;
            font-weight: 800;
            padding: 6px 14px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.35);
        }

        .premium-card {
            background: #FFFFFF;
            border-radius: 28px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(226, 232, 240, 0.9);
        }

        .quick-amount-btn {
            border: 1.5px solid #E2E8F0;
            border-radius: 14px;
            padding: 8px 12px;
            font-weight: 800;
            font-size: 14px;
            transition: all 0.2s ease;
            cursor: pointer;
            text-align: center;
        }
        .quick-amount-btn.active {
            border-color: #4F46E5;
            background: #EEF2FF;
            transform: scale(1.02);
        }

        .method-select-card {
            border: 2px solid #E2E8F0;
            border-radius: 18px;
            padding: 12px 10px;
            cursor: pointer;
            transition: all 0.25s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #FFFFFF;
        }
        .method-select-card.active {
            border-color: #4F46E5;
            background: #F5F3FF;
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.12);
        }

        .recharge-submit-btn {
            background: linear-gradient(135deg, #2563EB 0%, #4F46E5 50%, #7C3AED 100%);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.35);
            transition: all 0.25s ease;
        }
        .recharge-submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(79, 70, 229, 0.45);
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #FFFFFF;
            box-shadow: 0 -3px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            justify-content: space-around;
            padding: 8px 0;
            z-index: 50;
            border-top: 1px solid #F1F5F9;
        }
        .nav-item-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #64748B;
            text-decoration: none;
            font-size: 11px;
            font-weight: 600;
            gap: 2px;
        }
        .nav-item-btn i { font-size: 18px; }
        .nav-item-btn.active { color: #4F46E5; }

        @media (min-width: 769px) {
            .bottom-nav { display: none !important; }
            body { padding-bottom: 30px; }
        }
    </style>
</head>
<body>

    <!-- টপবার এরিয়া -->
    <div class="recharge-header-bg">
        <div class="max-w-md mx-auto flex items-center justify-between">
            <a href="user Dashboad.php" class="flex items-center gap-2 text-white no-underline">
                <img src="https://iconiconline.xyz/assets/logo_nav.png" alt="Logo" class="h-8 w-auto">
            </a>

            <div class="balance-badge-top">
                <i class="fa-solid fa-wallet text-xs"></i>
                <span class="text-xs"><?php echo number_format($user_balance, 0); ?> tk</span>
            </div>
        </div>
    </div>

    <!-- মূল কনটেন্ট কন্টেইনার -->
    <div class="max-w-md mx-auto px-4 -mt-12 space-y-4">

        <!-- ১. ইনফরমেশন ব্যানার কার্ড -->
        <div class="bg-gradient-to-r from-sky-50 to-indigo-50 border border-sky-200 rounded-3xl p-4 shadow-sm text-center">
            <h5 class="text-xs md:text-sm font-black text-sky-800 flex items-center justify-center gap-1.5 leading-snug">
                <span class="text-amber-500 text-sm">⚡</span> ডিপোজিট রিকোয়েস্ট — পেমেন্ট যাচাইয়ের পর ব্যালেন্স যোগ হবে।
            </h5>
        </div>

        <!-- ২. রিচার্জ বক্স কার্ড -->
        <div class="premium-card p-6 space-y-5">
            
            <div class="text-center">
                <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mx-auto mb-2 text-lg">
                    <i class="fa-solid fa-bolt-lightning"></i>
                </div>
                <h3 class="font-extrabold text-blue-600 text-lg">দ্রুত রিচার্জ করুন</h3>
            </div>

            <form action="" method="POST" id="rechargeForm" class="space-y-4">
                <input type="hidden" name="method" id="selectedMethodInput" value="bkash">

                <!-- টাকার পরিমাণ ইনপুট -->
                <div>
                    <label class="block text-center font-black text-sm text-slate-700 mb-2">টাকার পরিমাণ লিখুন</label>
                    <div class="relative max-w-[240px] mx-auto">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-lg">৳</span>
                        <input type="number" name="amount" id="amountInput" value="100" min="10" placeholder="100" class="w-full bg-white border-2 border-sky-100 focus:border-blue-500 rounded-2xl py-3 pl-9 pr-4 text-center font-black text-slate-800 text-lg outline-none transition shadow-sm" required>
                    </div>
                </div>

                <!-- কুইক অ্যামাউন্ট বাটন -->
                <div>
                    <span class="block text-center text-xs font-bold text-slate-500 mb-2">দ্রুত Amount নির্বাচন করুন</span>
                    <div class="grid grid-cols-3 gap-2.5">
                        <div class="quick-amount-btn active bg-sky-50 text-blue-600" data-val="100">
                            ৳ 100
                        </div>
                        <div class="quick-amount-btn bg-emerald-50 text-emerald-600" data-val="200">
                            ৳ 200
                        </div>
                        <div class="quick-amount-btn bg-amber-50 text-amber-600" data-val="500">
                            ৳ 500
                        </div>
                    </div>
                </div>

                <!-- পেমেন্ট মেথড নির্বাচন -->
                <div>
                    <span class="block text-center text-xs font-bold text-slate-500 mb-2.5">পেমেন্ট মেথড নির্বাচন করুন</span>
                    <div class="grid grid-cols-2 gap-3">
                        
                        <!-- bKash -->
                        <div class="method-select-card active" data-method="bkash" data-number="<?php echo htmlspecialchars($payment_numbers['bkash']); ?>">
                            <div class="h-9 flex items-center justify-center mb-1">
                                <img src="https://1000logos.net/wp-content/uploads/2021/02/Bkash-logo.jpg" alt="bKash" class="h-8 object-contain">
                            </div>
                            <span class="font-bold text-xs text-slate-700">bKash</span>
                        </div>

                        <!-- Nagad -->
                        <div class="method-select-card" data-method="nagad" data-number="<?php echo htmlspecialchars($payment_numbers['nagad']); ?>">
                            <div class="h-9 flex items-center justify-center mb-1">
                                <img src="https://download.logo.wine/logo/Nagad/Nagad-Logo.wine.png" alt="Nagad" class="h-8 object-contain">
                            </div>
                            <span class="font-bold text-xs text-slate-700">Nagad</span>
                        </div>

                    </div>
                </div>

                <!-- পেমেন্ট নাম্বার প্রদর্শন ও কপি বক্স -->
                <div class="bg-indigo-50/60 border border-indigo-100 rounded-2xl p-3 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-bold text-indigo-500 block uppercase" id="paymentMethodLabel">বিকাশ পার্সোনাল নম্বর (Send Money)</span>
                        <span class="text-sm font-black text-slate-800 font-mono" id="paymentNumberText"><?php echo htmlspecialchars($payment_numbers['bkash']); ?></span>
                    </div>
                    <button type="button" onclick="copyPaymentNumber()" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded-xl transition shadow-sm flex items-center gap-1">
                        <i class="fa-regular fa-copy"></i> কপি
                    </button>
                </div>

                <!-- TrxID ইনপুট -->
                <div>
                    <label class="block text-xs font-bold text-slate-600 mb-1">ট্রানজেকশন আইডি (TrxID)</label>
                    <input type="text" name="trx_id" placeholder="উদাহরণ: BL87XZ91Q" class="w-full border border-slate-200 rounded-xl px-3.5 py-2.5 text-xs font-bold text-slate-800 bg-slate-50 outline-none focus:border-indigo-500 focus:bg-white font-mono uppercase transition" required>
                </div>

                <!-- সাবমিট বাটন -->
                <button type="submit" name="submit_deposit" class="w-full recharge-submit-btn text-white font-black text-sm py-3.5 rounded-2xl flex items-center justify-center gap-2">
                    <span class="text-amber-300">⚡</span> এখনই রিচার্জ করুন
                </button>
            </form>

        </div>

        <!-- ৩. সাম্প্রতিক ডিপোজিট হিস্টোরি -->
        <div class="premium-card p-5 space-y-3">
            <h5 class="font-extrabold text-xs uppercase tracking-wider text-slate-400">আপনার সাম্প্রতিক ডিপোজিটসমূহ</h5>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-bold uppercase text-[10px]">
                        <tr>
                            <th class="p-2">তারিখ</th>
                            <th class="p-2">মেথড</th>
                            <th class="p-2">TrxID</th>
                            <th class="p-2 text-right">টাকা</th>
                            <th class="p-2 text-center">স্ট্যাটাস</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700 font-medium">
                        <?php
                        $hist_stmt = $conn->prepare("SELECT method, amount, trx_id, status, created_at FROM deposit_requests WHERE user_id = ? ORDER BY id DESC LIMIT 5");
                        $hist_stmt->bind_param("i", $user_id);
                        $hist_stmt->execute();
                        $hist_res = $hist_stmt->get_result();

                        if ($hist_res && $hist_res->num_rows > 0) {
                            while ($h = $hist_res->fetch_assoc()) {
                                $st = strtolower($h['status']);
                                $badge = match($st) {
                                    'completed', 'success' => '<span class="bg-emerald-50 text-emerald-600 border border-emerald-200 text-[10px] font-bold px-2 py-0.5 rounded-full">সফল</span>',
                                    'failed', 'cancelled' => '<span class="bg-rose-50 text-rose-600 border border-rose-200 text-[10px] font-bold px-2 py-0.5 rounded-full">বাতিল</span>',
                                    default => '<span class="bg-amber-50 text-amber-600 border border-amber-200 text-[10px] font-bold px-2 py-0.5 rounded-full">পেন্ডিং</span>'
                                };
                                echo "<tr>";
                                echo "<td class='p-2 text-slate-400 text-[10.5px]'>" . date('d M, h:i A', strtotime($h['created_at'])) . "</td>";
                                echo "<td class='p-2 font-bold uppercase'>" . htmlspecialchars($h['method']) . "</td>";
                                echo "<td class='p-2 font-mono text-[10.5px]'>" . htmlspecialchars($h['trx_id']) . "</td>";
                                echo "<td class='p-2 text-right font-black text-indigo-600'>৳ " . number_format($h['amount'], 0) . "</td>";
                                echo "<td class='p-2 text-center'>" . $badge . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='5' class='text-center py-4 text-slate-400 text-xs'>কোনো হিস্টোরি পাওয়া যায়নি</td></tr>";
                        }
                        $hist_stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- বটম ন্যাভিগেশন বার -->
    <nav class="bottom-nav">
        <a href="user Dashboad.php" class="nav-item-btn">
            <i class="fa-solid fa-house"></i>
            <span>হোম</span>
        </a>
        <a href="work_history.php" class="nav-item-btn">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>হিস্টোরি</span>
        </a>
        <a href="recharge.php" class="nav-item-btn active">
            <i class="fa-solid fa-wallet"></i>
            <span>রিচার্জ</span>
        </a>
        <a href="https://chat.whatsapp.com/IKEmCVrXgwp5a87Lng1AGX" target="_blank" class="nav-item-btn">
            <i class="fa-solid fa-paper-plane"></i>
            <span>সাপোর্ট</span>
        </a>
        <a href="profile.php" class="nav-item-btn">
            <i class="fa-solid fa-circle-user"></i>
            <span>প্রোফাইল</span>
        </a>
    </nav>

    <script>
        // কুইক অ্যামাউন্ট সিলেক্টর
        $('.quick-amount-btn').click(function() {
            $('.quick-amount-btn').removeClass('active');
            $(this).addClass('active');
            const val = $(this).data('val');
            $('#amountInput').val(val);
        });

        $('#amountInput').on('input', function() {
            const currentVal = parseInt($(this).val());
            $('.quick-amount-btn').removeClass('active');
            $(`.quick-amount-btn[data-val="${currentVal}"]`).addClass('active');
        });

        // পেমেন্ট মেথড টগল
        $('.method-select-card').click(function() {
            $('.method-select-card').removeClass('active');
            $(this).addClass('active');

            const method = $(this).data('method');
            const num = $(this).data('number');

            $('#selectedMethodInput').val(method);
            $('#paymentNumberText').text(num);
            $('#paymentMethodLabel').text((method === 'bkash' ? 'বিকাশ' : 'নগদ') + ' পার্সোনাল নম্বর (Send Money)');
        });

        // নম্বর ক্লিপবোর্ডে কপি
        function copyPaymentNumber() {
            const num = $('#paymentNumberText').text().trim();
            navigator.clipboard.writeText(num).then(() => {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'নম্বর কপি হয়েছে: ' + num,
                    showConfirmButton: false,
                    timer: 1500
                });
            });
        }

        // অ্যালার্ট হ্যান্ডলার
        <?php if (isset($alert)): ?>
            Swal.fire({
                icon: '<?php echo $alert['type']; ?>',
                title: '<?php echo $alert['title']; ?>',
                text: '<?php echo $alert['text']; ?>',
                confirmButtonColor: '#2563EB'
            });
        <?php endif; ?>
    </script>
</body>
</html>
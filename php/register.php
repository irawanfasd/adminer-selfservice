<?php
header('Content-Type: text/html; charset=utf-8');

// === CONFIG ===
$PG_HOST = '127.0.0.1';
$PG_PORT = 5432;
$ADMIN_USER = 'db_creator';
$ADMIN_PASS = 'GANTI_DENGAN_HASIL';       // ← ganti!
$USER_PREFIX = 'u_';
$DB_PREFIX = 'praktikum_';
$MAX_USERS = 100;
$RATE_LIMIT_HOURS = 1;
$RATE_LIMIT_COUNT = 100;
$MIN_PASSWORD_LEN = 8;

// === HELPER: Form ===
function show_form($error = '') {
    global $MIN_PASSWORD_LEN;
    ?>
    <!DOCTYPE html><html lang="id"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Daftar Database Praktikum</title>
    <style>
      body{font-family:system-ui,sans-serif;max-width:500px;margin:40px auto;padding:20px;background:#f9fafb}
      h1{color:#111}
      input{width:100%;padding:12px;margin:8px 0;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;font-size:16px}
      button{width:100%;padding:14px;background:#2563eb;color:white;border:none;border-radius:6px;font-size:16px;cursor:pointer;font-weight:600}
      button:hover{background:#1d4ed8}
      .error{background:#fee2e2;color:#991b1b;padding:12px;border-radius:6px;margin-bottom:20px;border-left:4px solid #dc2626}
      label{font-weight:600;color:#374151;font-size:14px;display:block;margin-top:12px}
      .info{margin-top:30px;padding:12px;background:#eff6ff;border-radius:6px;font-size:14px;color:#1e40af}
    </style></head><body>
    <h1>🗄️ Daftar Database</h1>
    <p>Isi form untuk membuat database PostgreSQL pribadi.</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <label>NIM (6-15 digit)</label>
<input name="nim" pattern="[A-Za-z0-9]{6,15}" required placeholder="Contoh: L0124016">
      <label>Nama Lengkap</label>
      <input name="nama" required maxlength="100" placeholder="Contoh: Budi Santoso">
      <label>Password (min <?= $MIN_PASSWORD_LEN ?> karakter, huruf + angka)</label>
      <input name="password" type="password" required minlength="<?= $MIN_PASSWORD_LEN ?>"
             placeholder="Password untuk database">
      <button type="submit">Buat Database</button>
    </form>
    <div class="info">
      Sudah punya akun? <a href="/adminer/">Login ke Adminer</a>
    </div>
    </body></html>
    <?php
    exit;
}

// === HELPER: Rate limit ===
function rate_limit_check($ip) {
    global $RATE_LIMIT_HOURS, $RATE_LIMIT_COUNT;
    $dir = "/tmp/db_signup";
    @mkdir($dir, 0700, true);
    $file = "$dir/" . md5($ip);
    $now = time();
    $records = [];
    if (file_exists($file)) {
        $records = array_values(array_filter(
            array_map('intval', file($file, FILE_IGNORE_NEW_LINES)),
            fn($t) => ($now - $t) < ($RATE_LIMIT_HOURS * 3600)
        ));
    }
    if (count($records) >= $RATE_LIMIT_COUNT) return false;
    $records[] = $now;
    file_put_contents($file, implode("\n", $records));
    return true;
}

// === MAIN ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') show_form();

$nim = trim($_POST['nim'] ?? '');
$nama = trim($_POST['nama'] ?? '');
$password = $_POST['password'] ?? '';

// Validasi
if (!preg_match('/^[A-Za-z0-9]{6,15}$/', $nim))
    show_form("NIM harus 6-15 karakter (huruf/angka).");
if (strlen($nama) < 2 || strlen($nama) > 100)
    show_form("Nama harus 2-100 karakter.");
if (strlen($password) < $MIN_PASSWORD_LEN)
    show_form("Password minimal $MIN_PASSWORD_LEN karakter.");
if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password))
    show_form("Password harus mengandung huruf dan angka.");

if (!rate_limit_check($_SERVER['REMOTE_ADDR']))
    show_form("Rate limit: maksimal $RATE_LIMIT_COUNT pendaftaran per $RATE_LIMIT_HOURS jam.");

$username = $USER_PREFIX . $nim;
$dbname = $DB_PREFIX . $nim;
function pg_ident($s) {
    return '"' . str_replace('"', '""', $s) . '"';
}
try {
    $pdo = new PDO("pgsql:host=$PG_HOST;port=$PG_PORT;dbname=postgres",
                   $ADMIN_USER, $ADMIN_PASS,
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Cek user sudah ada?
    $stmt = $pdo->prepare("SELECT 1 FROM pg_roles WHERE rolname = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) show_form("NIM sudah terdaftar. Hubungi admin jika lupa password.");

    // Cek cap user
    $stmt = $pdo->query("SELECT COUNT(*) FROM pg_roles WHERE rolname LIKE 'u\\_%'");
    if ((int)$stmt->fetchColumn() >= $MAX_USERS)
        show_form("Pendaftaran penuh (maks $MAX_USERS user). Hubungi admin.");

    // Cek DB conflict
    $stmt = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
    $stmt->execute([$dbname]);
    if ($stmt->fetch()) show_form("Database untuk NIM ini sudah ada.");

    // Buat user + DB
    // Buat user
    $pdo->exec("CREATE USER " . pg_ident($username) .
               " WITH PASSWORD " . $pdo->quote($password));

    // PENTING: db_creator harus member user baru agar bisa CREATE DATABASE ... OWNER
    $pdo->exec("GRANT " . pg_ident($username) . " TO " . pg_ident($ADMIN_USER));

    // Buat database dengan owner user baru
    $pdo->exec("CREATE DATABASE " . pg_ident($dbname) .
               " OWNER " . pg_ident($username));
    // Grant schema public (PG15+ mencabut default)
    $pdo2 = new PDO("pgsql:host=$PG_HOST;port=$PG_PORT;dbname=$dbname",
                    $ADMIN_USER, $ADMIN_PASS,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo2->exec("ALTER SCHEMA public OWNER TO " . pg_ident($username));

    // Resource limits (STB kecil)
    $pdo->exec("ALTER USER " . pg_ident($username) . " CONNECTION LIMIT 5");
    $pdo->exec("ALTER USER " . pg_ident($username) .
               " SET statement_timeout = '30s'");
    $pdo->exec("ALTER USER " . pg_ident($username) .
               " SET idle_in_transaction_session_timeout = '5min'");
    // === SUKSES ===
    ?>
    <!DOCTYPE html><html lang="id"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Berhasil</title>
    <style>
      body{font-family:system-ui,sans-serif;max-width:520px;margin:40px auto;padding:20px;background:#f9fafb}
      .success{background:#dcfce7;padding:16px;border-radius:8px;border-left:4px solid #16a34a;color:#166534;font-weight:600}
      table{width:100%;border-collapse:collapse;margin:20px 0;background:white;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
      th,td{padding:12px;text-align:left;border-bottom:1px solid #e5e7eb}
      th{background:#f3f4f6;width:35%;font-weight:600;color:#374151}
      code{background:#f3f4f6;padding:3px 8px;border-radius:4px;font-family:monospace;color:#1e40af}
      .warning{background:#fef3c7;padding:16px;border-radius:8px;border-left:4px solid #f59e0b;margin-top:20px;color:#92400e}
      a.btn{display:inline-block;padding:14px 28px;background:#2563eb;color:white;text-decoration:none;border-radius:6px;margin-top:16px;font-weight:600}
    </style></head><body>
    <h1>✅ Berhasil!</h1>
    <div class="success">Database kamu sudah siap. Simpan kredensial berikut!</div>
    <table>
      <tr><th>System</th><td>PostgreSQL</td></tr>
      <tr><th>Server</th><td><code>127.0.0.1</code></td></tr>
      <tr><th>Username</th><td><code><?= htmlspecialchars($username) ?></code></td></tr>
      <tr><th>Password</th><td><em>(password yang kamu isi)</em></td></tr>
      <tr><th>Database</th><td><code><?= htmlspecialchars($dbname) ?></code></td></tr>
    </table>
    <div class="warning">
      <strong>⚠️ Penting:</strong>
      <ul>
        <li>Catat username & password di tempat aman</li>
        <li>Password TIDAK bisa dilihat lagi</li>
        <li>Max 5 koneksi bersamaan, query timeout 30 detik</li>
        <li>Akun inactive 30 hari akan dihapus</li>
      </ul>
    </div>
    <a class="btn" href="/adminer/">Login ke Adminer →</a>
    </body></html>
    <?php

    } catch (PDOException $e) {
        // Cleanup jika partial
        try {
            if (isset($pdo)) {
                $pdo->exec("DROP DATABASE IF EXISTS " . pg_ident($dbname));
                $pdo->exec("DROP USER IF EXISTS " . pg_ident($username));
            }
        } catch (Exception $e2) {}
        show_form("Gagal membuat database: " . $e->getMessage());
    }

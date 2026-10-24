<?php
// webgui-access-control - wspolna biblioteka
// Uzytkownicy przechowywani w /boot/config/plugins/webgui-access-control/users.json
// Format: [{"username":"...","hash":"...","role":"admin|user","sections":["Dashboard","Docker",...]}]

if (!defined('WAC_USERS_FILE')) {
    define('WAC_USERS_FILE', '/boot/config/plugins/webgui-access-control/users.json');
    define('WAC_LOG_FILE', '/var/log/webgui-access-control.log');
}

// Sekcje WebGUI (etykieta => lista prefiksow sciezek URL nalezacych do tej sekcji)
function wac_sections(): array {
    return [
        'Dashboard' => ['/Dashboard', '/Main', '/'],
        'Shares'    => ['/Shares'],
        'Users'     => ['/Users'],
        'Docker'    => ['/Docker'],
        'VMs'       => ['/VMs'],
        'Apps'      => ['/Apps'],
        'Tools'     => ['/Tools'],
        'Settings'  => ['/Settings'],
        'Plugins'   => ['/Plugins'],
        'Log'       => ['/Log'],
    ];
}

function wac_load_users(): array {
    if (!file_exists(WAC_USERS_FILE)) return [];
    $json = @file_get_contents(WAC_USERS_FILE);
    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function wac_save_users(array $users): bool {
    @mkdir(dirname(WAC_USERS_FILE), 0700, true);
    $ok = @file_put_contents(WAC_USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod(WAC_USERS_FILE, 0600);
    return $ok !== false;
}

function wac_log(string $msg): void {
    @file_put_contents(WAC_LOG_FILE, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// Wywolywane z wnetrza try{} bloku .login.php (reuse istniejacej obslugi bledow/limitu prob).
// Jesli username nalezy do custom usera: sukces -> ustawia sesje + exit (redirect).
//                                         zle haslo -> throw Exception (jak przy root).
// Jesli username nieznany - nic nie robi (pozwala orginalnemu kodowi obslugiwac root).
function wac_handle_login(string $username, string $password): void {
    if ($username === 'root' || $username === '') return;
    $users = wac_load_users();
    foreach ($users as $u) {
        if (($u['username'] ?? '') === $username) {
            if (empty($u['enabled'])) {
                throw new Exception(_('Invalid username or password'));
            }
            if (!password_verify($password, $u['hash'] ?? '')) {
                wac_log("Login FAILED (zle haslo): $username");
                throw new Exception(_('Invalid username or password'));
            }
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['unraid_login'] = time();
            $_SESSION['unraid_user'] = $username;
            $_SESSION['wac_role'] = $u['role'] ?? 'user';
            $_SESSION['wac_sections'] = $u['sections'] ?? [];
            session_regenerate_id(true);
            session_write_close();
            wac_log("Login OK: $username (rola: " . ($u['role'] ?? 'user') . ")");
            global $start_page;
            header('Location: /' . ($start_page ?? 'Dashboard'));
            exit;
        }
    }
}

// Wywolywane z auth-request.php DLA KAZDEGO zadania po stwierdzeniu ze sesja jest zalogowana.
// root / custom-admin = zawsze dozwolone. custom-user = tylko przypisane sekcje.
function wac_check_permission(string $requestPath): bool {
    if (!isset($_SESSION['wac_role']) || $_SESSION['wac_role'] === 'admin') {
        return true;
    }
    $allowedSections = $_SESSION['wac_sections'] ?? [];
    $sections = wac_sections();
    foreach ($allowedSections as $sec) {
        foreach (($sections[$sec] ?? []) as $prefix) {
            if ($prefix === '/' && $requestPath === '/') return true;
            if ($prefix !== '/' && str_starts_with($requestPath, $prefix)) return true;
        }
    }
    return false;
}

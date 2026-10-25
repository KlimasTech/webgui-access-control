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

// Granularne pod-uprawnienia dla Docker/VM (NIE sa to sciezki URL - to flagi odczytywane
// po stronie klienta przez skrypt wstrzykiwany w DefaultPageLayout.php, bo Docker/VM
// Manager nie ma oddzielnych, latwych do przechwycenia po stronie serwera endpointow per-akcja;
// to ograniczenie na poziomie UI, nie twardej bariery backendowej jak w przypadku sekcji stron).
function wac_subpermissions(): array {
    return [
        'Docker:add'   => 'Docker: dodawanie kontenerow',
        'Docker:edit'  => 'Docker: edycja / usuwanie',
        'Docker:power' => 'Docker: start / stop / restart',
        'VMs:add'      => 'VM: dodawanie maszyn',
        'VMs:edit'     => 'VM: edycja / usuwanie / klonowanie',
        'VMs:power'    => 'VM: start / stop / restart',
    ];
}

// Konta unRAID dodane przez natywny panel Uzytkownicy (udostepnianie SMB/NFS),
// uid >= 1000, z pominieciem kont systemowych (nobody itp).
function wac_native_users(): array {
    $out = [];
    $lines = @file('/etc/passwd', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $parts = explode(':', $line);
        if (count($parts) < 3) continue;
        $username = $parts[0];
        $uid = (int)$parts[2];
        if ($uid < 1000 || $username === 'nobody' || $username === 'root') continue;
        $out[] = $username;
    }
    sort($out);
    return $out;
}

// Weryfikuje haslo konta natywnego unRAID przez /etc/shadow (ten sam mechanizm,
// ktorego uzywa oryginalny .login.php dla roota - password_verify() wspiera
// rowniez hashe crypt() SHA-512 ($6$...) uzywane przez unRAID dla zwyklych kont).
function wac_verify_native_password(string $username, string $password): bool {
    $output = @exec('/usr/bin/getent shadow ' . escapeshellarg($username));
    if (!$output) return false;
    $credentials = explode(':', $output);
    if (empty($credentials[1]) || $credentials[1] === '!' || $credentials[1] === '*') return false;
    return password_verify($password, $credentials[1]);
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
            $type = $u['type'] ?? 'custom';
            $ok = $type === 'native'
                ? wac_verify_native_password($username, $password)
                : password_verify($password, $u['hash'] ?? '');
            if (!$ok) {
                wac_log("Login FAILED (zle haslo): $username ($type)");
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
            wac_log("Login OK: $username (rola: " . ($u['role'] ?? 'user') . ", typ: $type)");
            global $start_page;
            header('Location: /' . ($start_page ?? 'Dashboard'));
            exit;
        }
    }
}

// Wywolywane z auth-request.php DLA KAZDEGO zadania po stwierdzeniu ze sesja jest zalogowana.
// root / custom-admin = zawsze dozwolone. custom-user = tylko przypisane sekcje.
function wac_path_matches(string $path, array $prefixes): bool {
    foreach ($prefixes as $prefix) {
        if ($prefix === '/' && $path === '/') return true;
        if ($prefix !== '/' && str_starts_with($path, $prefix)) return true;
    }
    return false;
}

function wac_check_permission(string $requestPath): bool {
    // Kanaly live-push Nchan (/sub/...) dostarczaja wylacznie ogolne dane kondycji systemu
    // (CPU/RAM/siec/powiadomienia) dla widgetow Dashboardu - nie sa to dane specyficzne dla
    // zadnej chronionej sekcji ani akcje wykonujace cokolwiek. Te polaczenia sa dlugotrwale
    // (long-poll) i powtarzane czesto, wiec zawsze przepuszczamy je dla kazdej zalogowanej
    // sesji (rola nieistotna) - blokowanie ich psuje wyswietlanie danych na Dashboardzie.
    if (str_starts_with($requestPath, '/sub/')) {
        return true;
    }
    if (!isset($_SESSION['wac_role']) || $_SESSION['wac_role'] === 'admin') {
        return true;
    }
    $allowedSections = $_SESSION['wac_sections'] ?? [];
    $sections = wac_sections();
    $prefixes = [];
    foreach ($allowedSections as $sec) {
        foreach (($sections[$sec] ?? []) as $prefix) { $prefixes[] = $prefix; }
    }
    if (wac_path_matches($requestPath, $prefixes)) {
        return true;
    }
    // Fallback: wiele stron (Dashboard, Docker, VMs...) doladowuje dane przez wspoldzielone
    // endpointy AJAX pod /webGui/include/*.php, ktore nie maja wlasnego prefiksu sekcji
    // (np. DockerContainers.php, VMs.php). Zamiast blokowac je zawsze (co psuje wyswietlanie
    // parametrow na dozwolonych stronach), sprawdz naglowek Referer - jesli zadanie przyszlo
    // ze strony nalezacej do dozwolonej sekcji, przepusc je (sama strona i tak jest juz chroniona).
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer !== '') {
        $refPath = parse_url($referer, PHP_URL_PATH) ?: '';
        if (wac_path_matches($refPath, $prefixes)) {
            return true;
        }
    }
    return false;
}

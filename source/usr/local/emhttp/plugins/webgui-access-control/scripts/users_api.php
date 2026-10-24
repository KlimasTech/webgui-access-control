<?php
// webgui-access-control/scripts/users_api.php
// Error-safe JSON endpoint do zarzadzania kontami (lista/zapis/usuniecie).
// Dostepne WYLACZNIE dla zalogowanego prawdziwego roota (nie custom-user) - sprawdzane ponizej.

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'PHP error: ' . $err['message']]);
    }
});

require_once '/usr/local/emhttp/plugins/webgui-access-control/include/AccessControl.php';

session_start();

// Tylko prawdziwy root (brak wac_role w sesji = zalogowany jako root) moze zarzadzac kontami.
if (!empty($_SESSION['wac_role'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Tylko administrator (root) moze zarzadzac kontami.']);
    exit;
}

// CSRF
$varIni = @parse_ini_file('/var/local/emhttp/var.ini');
$csrfToken = is_array($varIni) ? ($varIni['csrf_token'] ?? '') : '';
// CSRF jest juz zwalidowane globalnie przez /usr/local/emhttp/webGui/include/local_prepend.php
// (auto_prepend_file w php.ini) - jesli to wykonanie dotarlo tutaj, token byl poprawny.
// (local_prepend.php usuwa $_POST['csrf_token'] po walidacji, wiec nie trzeba/nie mozna go tu ponownie sprawdzac).
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

$users = wac_load_users();

switch ($action) {
    case 'list':
        // nie zwracaj hashy hasel do przegladarki
        $safe = array_map(function ($u) {
            unset($u['hash']);
            return $u;
        }, $users);
        echo json_encode(['ok' => true, 'users' => array_values($safe), 'sections' => array_keys(wac_sections())]);
        break;

    case 'save':
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $sections = isset($_POST['sections']) ? (array)json_decode((string)$_POST['sections'], true) : [];
        $enabled  = !empty($_POST['enabled']);

        if ($username === '' || !preg_match('/^[a-zA-Z0-9_.-]{2,30}$/', $username)) {
            echo json_encode(['ok' => false, 'error' => 'Nieprawidlowa nazwa uzytkownika (2-30 znakow, litery/cyfry/._-).']);
            exit;
        }
        if ($username === 'root') {
            echo json_encode(['ok' => false, 'error' => '"root" jest zarezerwowane dla konta systemowego.']);
            exit;
        }
        $validSections = array_keys(wac_sections());
        $sections = array_values(array_intersect($sections, $validSections));

        $found = false;
        foreach ($users as &$u) {
            if (($u['username'] ?? '') === $username) {
                $found = true;
                $u['role'] = $role;
                $u['sections'] = $sections;
                $u['enabled'] = $enabled;
                if ($password !== '') {
                    $u['hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                break;
            }
        }
        unset($u);
        if (!$found) {
            if ($password === '') {
                echo json_encode(['ok' => false, 'error' => 'Haslo jest wymagane przy tworzeniu nowego konta.']);
                exit;
            }
            $users[] = [
                'username' => $username,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'sections' => $sections,
                'enabled' => $enabled,
                'created' => date('Y-m-d H:i:s'),
            ];
        }
        if (!wac_save_users($users)) {
            echo json_encode(['ok' => false, 'error' => 'Nie udalo sie zapisac pliku konfiguracji.']);
            exit;
        }
        wac_log(($found ? "Zaktualizowano konto: " : "Utworzono konto: ") . $username);
        echo json_encode(['ok' => true]);
        break;

    case 'delete':
        $username = trim((string)($_POST['username'] ?? ''));
        $before = count($users);
        $users = array_values(array_filter($users, fn($u) => ($u['username'] ?? '') !== $username));
        if (count($users) === $before) {
            echo json_encode(['ok' => false, 'error' => 'Nie znaleziono konta.']);
            exit;
        }
        if (!wac_save_users($users)) {
            echo json_encode(['ok' => false, 'error' => 'Nie udalo sie zapisac pliku konfiguracji.']);
            exit;
        }
        wac_log("Usunieto konto: $username");
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Nieznana akcja.']);
}

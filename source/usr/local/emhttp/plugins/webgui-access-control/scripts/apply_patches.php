<?php
// webgui-access-control - idempotentny patcher plikow rdzenia unRAID.
// Uruchamiany przy kazdym starcie systemu (z /boot/config/go) bo /usr/local/emhttp
// jest za kazdym razem budowane od zera z obrazow na /boot - oryginalne pliki
// WRACAJA do stanu fabrycznego po kazdym restarcie, wiec patch trzeba nakladac na nowo.
// Skrypt NIC nie robi jesli patch juz jest nalozony (bezpieczny do wielokrotnego uruchamiania).

$authRequestFile = '/usr/local/emhttp/auth-request.php';
$loginFile       = '/usr/local/emhttp/plugins/dynamix/include/.login.php';
$layoutFile      = '/usr/local/emhttp/webGui/include/DefaultPageLayout.php';
$marker          = 'webgui-access-control';
$backupDir       = '/boot/config/plugins/webgui-access-control/backup';

// Kopia zapasowa oryginalnych plikow (tylko raz - potrzebna do emergency_revert.sh)
@mkdir($backupDir, 0700, true);
foreach ([
    'auth-request.php' => $authRequestFile,
    '.login.php' => $loginFile,
    'DefaultPageLayout.php' => $layoutFile,
] as $label => $path) {
    $backupPath = "$backupDir/$label.orig";
    if (!file_exists($backupPath) && file_exists($path)
        && strpos(file_get_contents($path), $marker) === false) {
        copy($path, $backupPath);
    }
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

// --- DefaultPageLayout.php: wstaw skrypt ukrywajacy zakladki menu dla kont z ograniczonym dostepem ---
$layoutAnchor = '<?php include "$docroot/webGui/include/DefaultPageLayout/ToastSetup.php"; ?>' . "\n</body>";
$layoutInsert = <<<'PHP'
<?php
// >>> webgui-access-control: ukrywanie zakladek menu niedostepnych dla roli "user"
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (!empty($_SESSION['wac_role']) && $_SESSION['wac_role'] !== 'admin') {
  $wacSectionMap = [
    'Dashboard' => ['/Dashboard', '/Main'],
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
  $wacAllowedPrefixes = [];
  foreach (($_SESSION['wac_sections'] ?? []) as $s) {
    foreach ($wacSectionMap[$s] ?? [] as $p) { $wacAllowedPrefixes[] = $p; }
  }
  ?>
  <script>
  (function () {
    var allowed = <?=json_encode($wacAllowedPrefixes)?>;
    function isAllowed(href) {
      if (!href || href.charAt(0) !== '/') return true; // przyciski narzedziowe (#) zawsze widoczne
      for (var i = 0; i < allowed.length; i++) { if (href.indexOf(allowed[i]) === 0) return true; }
      return false;
    }
    document.querySelectorAll('.nav-item').forEach(function (item) {
      var a = item.querySelector('a[href]');
      if (!a) return;
      if (!isAllowed(a.getAttribute('href'))) { item.style.display = 'none'; }
    });
    // Dashboard (i inne strony z kafelkami) pokazuja dane/parametry normalnie - NIGDY nie
    // ukrywamy tresci kafelka. Ukrywamy tylko ikonki w prawym gornym rogu kafelka (ustawienia,
    // zarzadzanie, a nawet przyciski systemowe typu Zatrzymaj macierz/Restart/Wylacz - te
    // ostatnie sa pomijane bez wzgledu na przyznane sekcje, bo to bezposrednie akcje systemowe
    // omijajace kontrole dostepu per-strona). Zostaje widoczna wylacznie ikonka zwijania kafelka
    // (chevron "openclose") - to tylko lokalny podglad/ukrycie tresci, nie dostep do danych.
    function hideTileIcons() {
      document.querySelectorAll('.tile-header-right').forEach(function (right) {
        right.querySelectorAll('.tile-ctrl').forEach(function (ctrl) { ctrl.style.display = 'none'; });
        right.querySelectorAll('.tile-header-right-controls').forEach(function (controls) {
          var seenCollapse = false;
          Array.prototype.forEach.call(controls.children, function (child) {
            if (seenCollapse) { return; } // elementy PO ikonie zwijania (jesli jakies sa) zostaja widoczne
            if (child.classList.contains('openclose') || child.querySelector('.openclose')) {
              seenCollapse = true;
              return;
            }
            child.style.display = 'none';
          });
        });
      });
    }
    hideTileIcons();
    new MutationObserver(hideTileIcons).observe(document.body, { childList: true, subtree: true });

    // --- Docker / VM: granularne pod-uprawnienia (dodawanie / edycja / start-stop) ---
    var path = location.pathname;
    var onDocker = path.indexOf('/Docker') === 0;
    var onVMs = path.indexOf('/VMs') === 0;
    if (onDocker || onVMs) {
      var prefix = onDocker ? 'Docker' : 'VMs';
      function hasPerm(p) { return allowedSections.indexOf(prefix + ':' + p) !== -1; }
      var allowedSections = <?=json_encode($_SESSION['wac_sections'] ?? [])?>;
      var canAdd = hasPerm('add'), canEdit = hasPerm('edit'), canPower = hasPerm('power');

      // Globalne przyciski akcji na gorze strony (Dodaj kontener/VM, Uruchom/Zatrzymaj wszystkie, itp.)
      var globalButtonBucket = {
        addContainer: 'add', addVm: 'add', addVM: 'add',
        startAll: 'power', stopAll: 'power', pauseAll: 'power', resumeAll: 'power',
        checkAll: 'edit', updateAll: 'edit'
      };
      function allowedBucket(need) {
        return (need === 'add' && canAdd) || (need === 'edit' && canEdit) || (need === 'power' && canPower);
      }
      function hideGlobalButtons() {
        document.querySelectorAll('input[type=button][onclick]').forEach(function (b) {
          var m = b.getAttribute('onclick').match(/^([a-zA-Z_]+)\(/);
          if (!m || !(m[1] in globalButtonBucket)) return;
          if (!allowedBucket(globalButtonBucket[m[1]])) { b.style.display = 'none'; }
        });
      }
      hideGlobalButtons();
      setTimeout(hideGlobalButtons, 1500); // niektore przyciski doladowuja sie przez AJAX

      // Menu kontekstowe pojedynczego kontenera/VM (Uruchom/Stop/Edytuj/Usun...) - rozpoznawane
      // po klasie ikony <i class="fa fa-..."> wewnatrz pozycji menu (jezykowo niezalezne).
      var iconBucket = {
        'fa-play': 'power', 'fa-stop': 'power', 'fa-pause': 'power', 'fa-power-off': 'power',
        'fa-refresh': 'power', 'fa-repeat': 'power', 'fa-undo': 'power',
        'fa-wrench': 'edit', 'fa-trash': 'edit', 'fa-clone': 'edit', 'fa-copy': 'edit', 'fa-pencil': 'edit'
      };
      function filterContextMenu(menu) {
        menu.querySelectorAll('li').forEach(function (li) {
          var icon = li.querySelector('i[class*="fa-"]');
          if (!icon) return;
          for (var cls in iconBucket) {
            if (icon.classList.contains(cls)) {
              if (!allowedBucket(iconBucket[cls])) { li.style.display = 'none'; }
              return;
            }
          }
        });
      }
      new MutationObserver(function (muts) {
        muts.forEach(function (m) {
          m.addedNodes.forEach(function (n) {
            if (n.nodeType !== 1) return;
            if (n.matches && n.matches('ul.dropdown-context, ul[id^="dropdown-"]')) { filterContextMenu(n); }
            if (n.querySelectorAll) { n.querySelectorAll('ul.dropdown-context, ul[id^="dropdown-"]').forEach(filterContextMenu); }
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  })();
  </script>
  <?php
}
// <<< webgui-access-control
?>
PHP;
wac_patch_file($layoutFile, $layoutAnchor, $layoutInsert, $marker);

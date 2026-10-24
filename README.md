# webgui-access-control

Plugin unRAID dodający dodatkowe konta logowania do WebGUI (poza `root`) z
rolami **admin** / **user** i konfigurowalnym dostępem do wybranych sekcji
panelu (Dashboard, Shares, Users, Docker, VMs, Apps, Tools, Settings, Plugins,
Log).

## ⚠️ Ważne — jak to działa i jakie jest ryzyko

unRAID natywnie **nie ma** pojęcia "konto WebGUI z rolą" — loguje tylko
`root`. Ten plugin dodaje tę funkcję przez **patchowanie dwóch plików
systemowych unRAID**:

- `/usr/local/emhttp/auth-request.php` — centralna bramka autoryzacji
  wywoływana przez nginx (`auth_request`) dla **każdego** żądania HTTP.
  Plugin dokłada tu sprawdzenie, czy zalogowany użytkownik ma dostęp do danej
  sekcji.
- `/usr/local/emhttp/plugins/dynamix/include/.login.php` — obsługa
  formularza logowania. Plugin dokłada tu próbę zalogowania jako konto
  niestandardowe (jeśli username ≠ `root`).

Patch jest **minimalny i idempotentny** (kilka linii wstawianych w konkretne,
rozpoznawalne miejsca — nie nadpisuje całych plików). Ponieważ
`/usr/local/emhttp` jest budowane od zera z obrazu na `/boot` przy **każdym**
starcie systemu, patch jest automatycznie nakładany ponownie po każdym
restarcie (wpis w `/boot/config/go`).

### Awaryjne wyłączenie

Jeśli coś pójdzie nie tak (nie można się zalogować), połącz się przez SSH i
uruchom:

```bash
/usr/local/emhttp/plugins/webgui-access-control/scripts/emergency_revert.sh
```

Przywraca to oryginalne pliki unRAID z kopii zapasowej zrobionej automatycznie
przy pierwszej instalacji (`/boot/config/plugins/webgui-access-control/backup/`)
i usuwa wpis z `/boot/config/go`, żeby patch nie wrócił po restarcie.
**Login roota zawsze działa niezależnie od stanu pluginu** — nawet jeśli
plugin się zepsuje, prosty restart serwera przywraca oryginalne pliki
(bo są budowane od zera z `/boot`).

## Funkcje

- Zarządzanie kontami: **Ustawienia → Access Control** (tylko dla `root`)
- Rola **admin** = pełny dostęp (jak root)
- Rola **user** = dostęp tylko do zaznaczonych sekcji WebGUI
- Dwa typy kont:
  - **Istniejący użytkownik unRAID** — wybierany z listy kont utworzonych w natywnym panelu
    **Użytkownicy** (Users). Hasło weryfikowane bezpośrednio przez `/etc/shadow`, plugin go
    nigdzie nie duplikuje.
  - **Konto niestandardowe** — login+hasło zarządzane w całości przez ten plugin (bcrypt hash w
    `/boot/config/plugins/webgui-access-control/users.json`).
- Zakładki menu, do których użytkownik nie ma dostępu, są **ukrywane** w interfejsie (nie tylko
  blokowane 403 po kliknięciu)
- Logowanie prób/zdarzeń w `/var/log/webgui-access-control.log`

## Instalacja

Przez unRAID: **Plugins → Install Plugin**, wklej:

```
https://raw.githubusercontent.com/KlimasTech/webgui-access-control/main/webgui-access-control.plg
```

## Licencja

MIT

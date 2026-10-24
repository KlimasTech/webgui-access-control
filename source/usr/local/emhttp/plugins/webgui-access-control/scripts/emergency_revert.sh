#!/bin/bash
# webgui-access-control - awaryjne cofniecie patchy. Uruchom przez SSH jesli
# cos pojdzie nie tak (np. nie mozna sie zalogowac do webGUI).
# Przywraca oryginalne pliki unRAID z kopii zapasowej zrobionej przed pierwszym patchem.
BACKUP_DIR="/boot/config/plugins/webgui-access-control/backup"

restore_one() {
    local label="$1"
    local target="$2"
    if [ -f "$BACKUP_DIR/$label.orig" ]; then
        cp "$BACKUP_DIR/$label.orig" "$target"
        echo "Przywrocono: $target"
    else
        echo "BRAK kopii zapasowej $label.orig - nic nie przywrocono dla $target"
    fi
}

restore_one "auth-request.php" "/usr/local/emhttp/auth-request.php"
restore_one ".login.php" "/usr/local/emhttp/plugins/dynamix/include/.login.php"
restore_one "DefaultPageLayout.php" "/usr/local/emhttp/webGui/include/DefaultPageLayout.php"

# Usun wpis z /boot/config/go zeby patch NIE wracal po restarcie
sed -i '/webgui-access-control\/scripts\/apply_patches.php/d' /boot/config/go 2>/dev/null || true

echo "Gotowe. Logowanie root dziala teraz dokladnie jak przed instalacja pluginu."
echo "(Uwaga: ten revert dziala tylko do nastepnego restartu - pliki i tak wroca do"
echo " stanu oryginalnego po restarcie, bo /usr/local/emhttp jest budowane od nowa z /boot.)"

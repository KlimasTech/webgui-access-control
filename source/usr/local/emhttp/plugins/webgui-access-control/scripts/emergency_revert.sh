#!/bin/bash
# webgui-access-control - awaryjne cofniecie patchy. Uruchom przez SSH jesli
# cos pojdzie nie tak (np. nie mozna sie zalogowac do webGUI).
# Przywraca oryginalne pliki unRAID z kopii zapasowej zrobionej przed pierwszym patchem.
set -e
BACKUP_DIR="/boot/config/plugins/webgui-access-control/backup"
AUTH_REQUEST="/usr/local/emhttp/auth-request.php"
LOGIN_FILE="/usr/local/emhttp/plugins/dynamix/include/.login.php"

if [ -f "$BACKUP_DIR/auth-request.php.orig" ]; then
    cp "$BACKUP_DIR/auth-request.php.orig" "$AUTH_REQUEST"
    echo "Przywrocono: $AUTH_REQUEST"
else
    echo "BRAK kopii zapasowej auth-request.php.orig - nic nie przywrocono"
fi

if [ -f "$BACKUP_DIR/.login.php.orig" ]; then
    cp "$BACKUP_DIR/.login.php.orig" "$LOGIN_FILE"
    echo "Przywrocono: $LOGIN_FILE"
else
    echo "BRAK kopii zapasowej .login.php.orig - nic nie przywrocono"
fi

# Usun wpis z /boot/config/go zeby patch NIE wracal po restarcie
sed -i '/webgui-access-control\/scripts\/apply_patches.php/d' /boot/config/go 2>/dev/null || true

echo "Gotowe. Logowanie root dziala teraz dokladnie jak przed instalacja pluginu."
echo "(Uwaga: ten revert dziala tylko do nastepnego restartu - pliki i tak wroca do"
echo " stanu oryginalnego po restarcie, bo /usr/local/emhttp jest budowane od nowa z /boot.)"

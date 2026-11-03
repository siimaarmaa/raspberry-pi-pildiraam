#!/usr/bin/env bash
# Kasutamine: sudo bash scripts/install.sh saarmaa
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Käivita: sudo bash scripts/install.sh saarmaa" >&2
    exit 1
fi

APP_USER="${1:-${SUDO_USER:-saarmaa}}"
if ! id "$APP_USER" >/dev/null 2>&1; then
    echo "Sellist kasutajat ei ole: $APP_USER" >&2
    exit 1
fi

for command in php setfacl install; do
    if ! command -v "$command" >/dev/null 2>&1; then
        echo "Puudub '$command'. Paigalda eelnevalt: sudo apt install php-cli php-gd acl" >&2
        exit 1
    fi
done

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEBROOT="/var/www/html/pildiraam"
PHOTOS="$(getent passwd "$APP_USER" | cut -d: -f6)/pildid"
CACHE="/var/cache/pildiraam"

if [[ ! -d /var/www/html ]]; then
    echo "Apache veebikausta /var/www/html ei leitud." >&2
    exit 1
fi

install -d -m 0755 "$WEBROOT" "$WEBROOT/assets"
install -d -m 0755 -o "$APP_USER" -g "$(id -gn "$APP_USER")" "$PHOTOS"
install -d -m 0750 -o www-data -g www-data "$CACHE"

# Säilitame olemasolevate PHP-failide eelmise versiooni väljaspool veebijuurt.
BACKUP="/var/backups/pildiraam/$(date +%Y%m%d-%H%M%S)"
if compgen -G "$WEBROOT/*.php" > /dev/null; then
    install -d -m 0700 "$BACKUP"
    cp -p "$WEBROOT"/*.php "$BACKUP"/
    echo "Eelmiste PHP-failide varukoopia: $BACKUP"
fi

for f in index.php upload.php api.php photo.php lib.php config.php; do
    install -m 0644 "$ROOT/$f" "$WEBROOT/$f"
done
for f in slideshow.css slideshow.js upload.css upload.js; do
    install -m 0644 "$ROOT/assets/$f" "$WEBROOT/assets/$f"
done

# Masinapõhine seadistus ei lähe GitHubi ega kirjutata uuendamisel üle.
if [[ ! -e "$WEBROOT/config.local.php" ]]; then
    cat > "$WEBROOT/config.local.php" <<PHP
<?php
return [
    'photo_dir' => '$PHOTOS',
    'cache_dir' => '$CACHE',
];
PHP
    chmod 0644 "$WEBROOT/config.local.php"
fi

# Kodukataloogi ei muudeta kõigile loetavaks: ainult Apache kasutajale ligipääs.
HOME_DIR="$(getent passwd "$APP_USER" | cut -d: -f6)"
setfacl -m u:www-data:--x "$HOME_DIR"
setfacl -R -m u:www-data:rX "$PHOTOS"
setfacl -m u:www-data:rwx "$PHOTOS"
setfacl -d -m u:www-data:rwX "$PHOTOS"

# Suur failipiir ühe HTTP-päringu kohta. Fotosid saadetakse eraldi päringutena.
for dir in /etc/php/*/apache2/conf.d /etc/php/*/fpm/conf.d; do
    [[ -d "$dir" ]] || continue
    cat > "$dir/99-pildiraam-upload.ini" <<'INI'
file_uploads=On
upload_max_filesize=64M
post_max_size=70M
max_input_time=300
INI
done

for file in "$WEBROOT"/*.php; do
    php -l "$file" >/dev/null
done

systemctl try-restart apache2.service >/dev/null 2>&1 || true
for fpm in /etc/php/*/fpm; do
    [[ -d "$fpm" ]] || continue
    ver="$(basename "$(dirname "$fpm")")"
    systemctl try-restart "php${ver}-fpm.service" >/dev/null 2>&1 || true
done

echo
echo "Paigaldatud!"
echo "  Slaidid: http://RASPBERRY_IP/pildiraam/"
echo "  Lisa pildid: http://RASPBERRY_IP/pildiraam/upload.php"
echo "  Fotokaust: $PHOTOS"
echo "  Vahemälu: $CACHE"
echo "NB! Kontrolli README järgi, et Apache töötleb PHP-koodi."

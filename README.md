# Pildiraam Raspberry Pi jaoks

Väike pildiraam Raspberry Pi jaoks. Ekraan näitab kodus pilte ja uusi fotosid saab telefonist juurde panna, ilma et peaks Pi-sse sisse logima.

See pole suur pildihaldussüsteem. On kaks PHP faili: üks näitab pilte, teine laseb neid juurde laadida. Andmebaasi pole, kontosid ka mitte.

See juhend viib sind **nullist** valmis pildiraamini. Eeldus on ainult värskelt kirjutatud SD-kaart, mitte midagi eelnevalt paigaldatut.

## Testitud keskkond

- Raspberry Pi 3 Model B Rev 1.2
- Raspberry Pi OS Lite (Raspbian GNU/Linux 13 "Trixie"), **ilma töölauata**
- Kernel `6.18.x+rpt-rpi-v7`, arhitektuur `arm` (32-bitine)
- Apache + PHP 8.4, ekraanil Weston ja Cog

Algselt tegin selle Raspberry Pi 2 jaoks (Touch Display 2, DSI). Pi 3 puhul on lihtsaim ekraan HDMI, aga DSI töötab samamoodi; vahe on ainult `weston.ini` failis (vt samm 8).

Westoni ja Cogi pole vaja, kui tahad pilte lihtsalt arvutis brauserist vaadata. Siis piisab sammudest 1-6.

## Mida see teeb

Pildid võetakse kaustast `~/pildid`. Slaidid vahetuvad iga 12 sekundi järel ja üleminek on sujuv. Ekraanil on ka kell, kuupäev, foto nimi ning piltide arv.

Pildikausta kontrollitakse iga 30 sekundi järel. Uued pildid tulevad automaatselt nimekirja, kuid slaidiesitlus käib oma järjekorras edasi, nii et uut pilti ei näe kohe.

Toetatud on JPG, PNG ja WebP. Suuri pilte vähendatakse enne ekraanile saatmist ning väiksem koopia hoitakse vahemälus. Originaalfaili ei muudeta. Ühe üleslaaditava faili piir on 64 MiB ja foto tohib olla kuni 20 megapikslit. Pi 3 mälu on 1 GB, seega väga suure piltide hunniku esmakordne töötlemine võtab aega.

## Failid

```
raspberry-pi-pildiraam/
├── index.php     # slaidiesitlus ja piltide töötlemine
├── upload.php    # fotode lisamine telefonist või arvutist
└── README.md     # see juhend
```

CSS ja JavaScript on PHP failide sees.

## Paigaldamine

Kõik käsud käivad tavakasutajana (mitte `root`), `sudo` on seal, kus vaja. Käsud kasutavad muutujaid `$USER` ja `$HOME`, nii et kasutajanimi võib olla mis tahes.

### 1. SD-kaardi ettevalmistamine

1. Ava [Raspberry Pi Imager](https://www.raspberrypi.com/software/).
2. Vali seade **Raspberry Pi 3**, süsteemiks **Raspberry Pi OS (other) → Raspberry Pi OS Lite** (Trixie). 32-bitine versioon sobib hästi.
3. Enne kirjutamist ava seaded ja määra: hostinimi (nt `pildiraam`), kasutajanimi ja parool, Wi-Fi andmed (kui ei kasuta kaablit), ajavöönd ning **SSH sisse**.
4. Kirjuta kaart, pane Pi-sse ja lülita sisse. Esimene käivitus võtab minuti-paar.

Ühenda arvutist:

```
ssh KASUTAJA@pildiraam.local
```

### 2. Süsteemi uuendamine ja ajavöönd

```
sudo apt update
sudo apt full-upgrade -y
sudo timedatectl set-timezone Europe/Tallinn
sudo reboot
```

Ajavöönd on vajalik, et ekraani kell näitaks õiget aega. Pärast taaskäivitust logi uuesti sisse.

### 3. Vajalike pakettide paigaldus

Veebiserver, PHP ja pildikäsitlus:

```
sudo apt install -y git apache2 php libapache2-mod-php php-gd acl curl fonts-dejavu-core
```

Kontrolli, et GD on olemas:

```
php -v
php -m | grep -i gd
```

### 4. Projekti allalaadimine

```
cd ~
git clone https://github.com/siimaarmaa/raspberry-pi-pildiraam.git
cd raspberry-pi-pildiraam
```

PHP failid on algselt kirjutatud kasutajale `saarmaa`. Vaheta piltide kaust oma kasutaja kausta vastu **mõlemas failis** ja kontrolli tulemust:

```
sed -i "s#/home/saarmaa/pildid#$HOME/pildid#g" index.php upload.php
grep -n 'photoDir' index.php upload.php
```

`grep` peaks näitama sinu kodukausta, mitte `/home/saarmaa`. Kui rida on teistsuguse kujuga, muuda `$photoDir` käsitsi.

### 5. Kaustad ja õigused

Tee piltide kaust, veebikaust ja vahemälu:

```
mkdir -p "$HOME/pildid"
sudo mkdir -p /var/www/html/pildiraam
sudo install -d -o www-data -g www-data -m 0750 /var/cache/pildiraam
```

Apache töötab kasutajana `www-data`. Talle tuleb anda õigus piltide kausta lugeda ja sinna uusi fotosid kirjutada. Tervet kodukausta pole vaja kõigile avatuks teha.

```
sudo setfacl -m u:www-data:--x "$HOME"
sudo setfacl -R -m u:www-data:rX "$HOME/pildid"
sudo setfacl -m u:www-data:rwx "$HOME/pildid"
sudo setfacl -d -m u:www-data:rwX "$HOME/pildid"
```

Kontrolli ja kopeeri failid Apache alla:

```
php -l index.php
php -l upload.php
sudo install -m 0644 common.php index.php upload.php /var/www/html/pildiraam/
```

Kui vanad `index.php` ja `upload.php` on juba olemas, tee neist enne varukoopia. See käsk kirjutab need üle.

### 6. Suuremate fotode üleslaadimine

PHP vaikimisi üleslaadimise piir on liiga väike. Allolev seadistus lubab kuni 64 MiB foto korraga. Kui valid telefonis näiteks 200 pilti, saadetakse need serverisse ükshaaval, mitte ühe suure päringuna.

```
for dir in /etc/php/*/apache2/conf.d /etc/php/*/fpm/conf.d; do
    [ -d "$dir" ] || continue
    printf '%s\n' \
      'file_uploads=On' \
      'upload_max_filesize=64M' \
      'post_max_size=70M' \
      'max_input_time=300' \
      'memory_limit=256M' \
      | sudo tee "$dir/99-pildiraam.ini" >/dev/null
done

sudo systemctl restart apache2
```

### Kas PHP töötab?

Proovi seda enne edasiminekut:

```
printf '%s\n' '<?php echo "PHP_OK"; ?>' | sudo tee /var/www/html/pildiraam/php-test.php >/dev/null
curl -s http://127.0.0.1/pildiraam/php-test.php
sudo rm /var/www/html/pildiraam/php-test.php
```

Vastus peab olema `PHP_OK`. Kui näed `<?php` teksti, pole PHP Apachega korralikult ühendatud. See tuleb enne korda teha. Ära tee sellist serverit internetist kättesaadavaks.

Proovi ka pildiraami ennast:

```
curl -s http://127.0.0.1/pildiraam/ | head -c 100
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

Esimene peaks algama `<!doctype html>`-ga. Teine annab JSON-kujul fotode nimekirja. Tühi kaust annab tühja nimekirja, see on normaalne.

Siin on pildiraam juba brauserist kasutatav (vt "Kuidas pilte lisada"). Kui tahad, et Pi enda ekraanil hakkaks see ise käima, jätka sammudega 7-9.

### 7. Ekraani tarkvara (Weston ja Cog)

Töölauda ei paigalda. Weston on minimaalne Waylandi kuvaserver ja Cog kerge brauser, mis näitab ühte lehte täisekraanil.

```
sudo apt install -y weston cog
```

Kontrolli, et Pi graafikadraiver on sees. Failis `/boot/firmware/config.txt` peab olema rida:

```
dtoverlay=vc4-kms-v3d
```

Raspberry Pi OS paneb selle vaikimisi, nii et tavaliselt pole midagi vaja teha. Kontrolli:

```
grep -n vc4-kms /boot/firmware/config.txt
```

Veendu, et su kasutaja on vajalikes gruppides (vaikimisi on):

```
groups
```

Nimekirjas peaksid olema `video`, `render` ja `input`. Kui mõni puudub:

```
sudo usermod -aG video,render,input "$USER"
```

### 8. Kioski seadistus

Selgita välja, mis nime all su ekraan on:

```
cat /sys/class/drm/*/status
ls /sys/class/drm/
```

Ühendatud ekraani juures on `connected`. Tüüpilised nimed on `HDMI-A-1` (HDMI) ja `DSI-1` (Touch Display 2).

Loo brauseri käivitusskript:

```
sudo tee /usr/local/bin/kiosk-browser >/dev/null <<'EOF'
#!/bin/sh
URL_FILE="$HOME/.config/kiosk-url"
URL="$(cat "$URL_FILE" 2>/dev/null)"
[ -n "$URL" ] || URL="http://127.0.0.1/pildiraam/"
exec cog --platform=wl "$URL"
EOF
sudo chmod 755 /usr/local/bin/kiosk-browser
```

Pane aadress faili (hiljem saad seda siit muuta):

```
mkdir -p ~/.config
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
```

Loo Westoni seadistus.

**HDMI ekraan (Pi 3 tavaline valik):**

```
cat > ~/.config/weston.ini <<'EOF'
[core]
backend=drm
shell=kiosk-shell.so
idle-time=0
require-input=false

[autolaunch]
path=/usr/local/bin/kiosk-browser
watch=false
EOF
```

**Raspberry Pi Touch Display 2 (DSI, 720 × 1280):** lisa kindlasti `[output]` plokk:

```
cat > ~/.config/weston.ini <<'EOF'
[core]
backend=drm
shell=kiosk-shell.so
idle-time=0
require-input=false

[output]
name=DSI-1
transform=normal

[autolaunch]
path=/usr/local/bin/kiosk-browser
watch=false
EOF
```

Kui ekraan on teistpidi paigaldatud, muuda `transform` väärtust (`rotate-90`, `rotate-180`, `rotate-270`). Touch Display 2 paneel on ise juba püstine.

### 9. Automaatne käivitus ilma töölauata

Pi peab käivitama ise ekraani, ilma et keegi sisse logiks. Selleks lülita sisse konsooli automaatne sisselogimine ja käivita Weston `tty1` peal.

```
sudo raspi-config nonint do_boot_behaviour B2
```

Lisa kasutaja `~/.profile` faili lõppu:

```
cat >> ~/.profile <<'EOF'

# Käivita pildiraami ekraan automaatselt esimesel konsoolil
if [ -z "$WAYLAND_DISPLAY" ] && [ "$(tty)" = "/dev/tty1" ]; then
    exec weston --log="$HOME/weston.log"
fi
EOF
```

SSH kaudu sisselogimisel see ei käivitu, sest kontrollitakse `tty1`.

Taaskäivita:

```
sudo reboot
```

Mõne hetke pärast peaks ekraanile ilmuma pildiraam.

## Kuidas pilte lisada

Vaata Pi IP-aadressi:

```
hostname -I
```

Oletame, et see on `192.168.1.100`. Siis on aadressid sellised:

- Pildiraam: `http://192.168.1.100/pildiraam/`
- Fotode lisamine: `http://192.168.1.100/pildiraam/upload.php`
- Pi enda ekraanil: `http://127.0.0.1/pildiraam/`

Ava telefonis üleslaadimise aadress, vali fotod ja lase neil ära laadida. Võid valida suure hulga fotosid korraga. Lehel näed, palju on valmis ja kas mõni fail läks veaga. Telefon peab olema Raspberry Pi-ga samas võrgus ning leht peab üleslaadimise ajal lahti olema.

Sama nimega pilte üle ei kirjutata, üles laaditud failid saavad endale uue nime.

Pilte võid lisada ka otse arvutist, näiteks:

```
scp *.jpg KASUTAJA@pildiraam.local:pildid/
```

Kui kopeerid failid käsitsi, kontrolli, et `www-data` saaks neid lugeda: `sudo setfacl -R -m u:www-data:rX ~/pildid`.

## Kui tahad midagi muuta

**Kioski aadress.** Muuda faili `~/.config/kiosk-url` ja taaskäivita (`sudo reboot`).

**Slaidi kestus.** `index.php` sees on rida:

```
const delay = 12000;
```

See tähendab, et ühte pilti näidatakse 12 sekundit. Kui muudad selle näiteks `20000` peale, muuda samas failis CSS-i `animation: fill 12s` samuti `20s` peale, muidu edenemisriba ja slaidide aeg ei klapi.

**Fotode kontrolli intervall.** JavaScriptis `setInterval(reload, 30000)`.

**Piltide kaust või vahemälu.** `$photoDir` on **mõlemas** PHP-failis, vahemälu kaust `$cacheDir` on `index.php` alguses. Pärast muutmist kopeeri failid uuesti: `sudo install -m 0644 index.php upload.php /var/www/html/pildiraam/`.

Kui kopeerid uue `index.php` Apache alla, märkab ekraan muudatust ligikaudu 30 sekundi jooksul. Lisaks värskendatakse leht iga poole tunni järel.

## Uuendamine

```
cd ~/raspberry-pi-pildiraam
git pull
sed -i "s#/home/saarmaa/pildid#$HOME/pildid#g" index.php upload.php
sudo install -m 0644 index.php upload.php /var/www/html/pildiraam/
```

## Kui midagi ei tööta

**Lehel paistab PHP lähtekood.** Apache ei käivita PHP-d. Kontrolli, et `libapache2-mod-php` on paigaldatud, ja taaskäivita Apache: `sudo systemctl restart apache2`.

**Pildiraam ütleb, et pilte pole.** Kontrolli, kas `~/pildid` sees on pildid ja kas Apache saab neid lugeda:

```
ls -la ~/pildid
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

**Fotot ei saa avada või tuleb HTTP 500.** Vaata, kas GD on olemas ning kas vahemälu on kirjutatav:

```
php -m | grep -i gd
ls -ld /var/cache/pildiraam
sudo tail -n 30 /var/log/apache2/error.log
```

**Üleslaadimisel tuleb õiguste viga.**

```
getfacl ~/pildid
sudo -u www-data test -w ~/pildid && echo OK
```

**Fail on liiga suur.** Kontrolli PHP `upload_max_filesize` ja `post_max_size` väärtusi (samm 6). Pildiraami enda piirid on 64 MiB ühe faili kohta ja kuni 20 MP pildi kohta.

**Pi 3 jääb pilte töödeldes aeglaseks.** Esimesel avamisel tehakse igast pildist vähendatud koopia, see võtab Pi 3 peal aega. Kui kaust on väga suur, anna sellele paar minutit või lisa pilte vähehaaval.

**Ekraan jääb mustaks või näitab konsooli.** Weston või Cog ei käivitu. Vaata logisid (SSH kaudu):

```
pgrep -a weston
pgrep -a cog
tail -n 50 ~/weston.log
```

Levinud põhjused:

- `vc4-kms-v3d` puudub `config.txt` failist (vt samm 7).
- Kasutaja pole gruppides `video`, `render`, `input`.
- `[output]` plokis on vale ekraani nimi. Kontrolli `cat /sys/class/drm/*/status`. HDMI puhul võid `[output]` ploki üldse ära jätta.
- Westonil on probleeme graafikaga. Proovi `[core]` alla lisada `use-pixman=true`.
- Cogi platvormi nimi on teises versioonis erinev. Vaata `cog --help` ja vaheta `--platform=wl` skriptis `/usr/local/bin/kiosk-browser`.

**Tahan Pi-d tavaliselt kasutada, mitte kioskina.** Lülita automaatne käivitus välja: kustuta `~/.profile` lõpust Westoni plokk ja käivita `sudo raspi-config nonint do_boot_behaviour B1`.

## Turvalisus

**Üleslaadimise lehel ei ole parooli.** See on meelega nii, sest kasutan seda oma koduvõrgus. Igaüks, kes sellele lehele võrgust ligi saab, saab ka fotosid lisada. Seega ära suuna ruuterist porti 80 internetti ja ära pane seda avalikku serverisse niisama üles.

Fotod ise on väljaspool Apache veebikausta, aga see ei asenda paroolikaitset. Sellest tasub aru saada enne, kui sama lahenduse kuhugi avalikku võrku paned.

## Litsents

MIT, vt `LICENSE`.

# 📸 Pildiraam — Raspberry Pi veebikiosk ja fotode üleslaadimine

**Kerge PHP-pildiraam, mis kuvab DSI-ekraanil fotosid slaidiesitlusena ning laseb uusi pilte telefonist või arvutist lisada.**

Loodud Raspberry Pi 2 Model B Rev 1.1, Raspberry Pi OS Lite / Raspbian GNU/Linux 13 (**Trixie**), Apache + PHP ning **Weston + Cog** jaoks. Töötab ka muudes PHP-d toetavates süsteemides.

> [!IMPORTANT]
> **Parooli ei ole.** Üleslaadimisaadressi võib kasutada igaüks, kes sellele ligi pääseb. Kasuta ainult usaldatud koduvõrgus. Ära ava Apache porti 80 internetti ega tee ruuteris port forwarding'ut.

## ✨ Võimalused

- 🖼️ Automaatne slaidiesitlus sujuva 1,25 s üleminekuga; vaikimisi uus foto iga **12 s**.
- 📱 Telefonis ja arvutis töötav ilus üleslaadimisleht; vali korraga **sadu või tuhandeid** pilte.
- ⚡ Failid laaditakse **ükshaaval**, nii ei piira PHP `max_file_uploads` korraga valitavate fotode arvu.
- 📐 Telefon või arvuti vähendab fotod enne saatmist kuni **1600 px** pikema küljeni (JPEG), et Raspberry Pi 2 mälu säästa.
- 🔄 Uued/kustutatud pildid ilmuvad esitlusse tavaliselt **30 sekundi** jooksul, PHP/CSS/JS muudatused käivitavad automaatse veebilehe laadimise; varuvärskendus iga **30 minuti** järel.
- 🕓 Kellaaeg ja eestikeelne kuupäev, pildi nimi ja loendur.
- 🖥️ Vertikaalne **720 × 1280** Raspberry Pi Touch Display 2; pildid mahuvad tervikuna ekraanile (`object-fit: contain`).
- 📁 Fotod asuvad **väljaspool Apache veebikausta**: `/home/saarmaa/pildid`.
- 🎨 Kergendatud JPEG-piltide vahemälu väljaspool veebikausta: `/var/cache/pildiraam`.
- 🔒 Failitüübi kontroll, juhuslikud failinimed ja tee-/sümbolviitekaitse; **kasutajakontosid ega paroole pole**.

## 🔗 Veebiaadressid

| Funktsioon | Raspberry Pi enda aadress | Telefonist/arvutist samas LAN-is |
|---|---|---|
| Slaidiesitlus | `http://127.0.0.1/pildiraam/` | `http://PI_IP/pildiraam/` |
| Fotode lisamine | `http://127.0.0.1/pildiraam/upload.php` | `http://PI_IP/pildiraam/upload.php` |
| Fotode nimekiri (JSON) | `http://127.0.0.1/pildiraam/api.php` | — |

Pi IP leiad käsuga `hostname -I`. Näiteks `192.168.1.100` on ainult näidisaadress.

## 📦 Projekti ülesehitus

```text
raspberry-pi-pildiraam/
├── README.md                         # Kogu paigaldus- ja kasutusjuhend
├── LICENSE                           # MIT
├── .github/workflows/syntax-check.yml  # Automaatne koodikontroll GitHubis
├── .gitignore                        # Jätab kohalikud seaded Gitist välja
├── config.php                        # Vaikeseaded
├── config.local.example.php          # Kohaliku seadistuse näidis
├── lib.php                           # Seadistus ja fotode nimekiri
├── index.php                         # Slaidiesitluse leht
├── api.php                           # Fotode nimekiri ja koodiversioon
├── photo.php                         # Foto mõõtu muutmine ja vahemälu
├── upload.php                        # Üleslaadimisleht ja API
├── assets/
│   ├── slideshow.css
│   ├── slideshow.js
│   ├── upload.css
│   └── upload.js
├── kiosk/
│   ├── weston.ini                    # Westoni DSI kioski näidis
│   ├── kiosk-browser                 # Cogi käivitusskript
│   └── 99-disable-touchscreen.rules  # Valikuline puute keeld
└── scripts/
    └── install.sh                    # Apache PHP-rakenduse paigaldaja
```

### Kuidas see töötab?

```text
Telefon / arvuti                    Raspberry Pi 2 + Touch Display 2
       │                                          ▲
       │  /pildiraam/upload.php                   │  Cog (Wayland)
       ▼                                          │
    Apache + PHP ── salvestab ──► ~/pildid ──► PHP photo.php
       │                                              ▲
       └──────────► api.php (fotode nimekiri) ─────────┘
```

## 1. Nõuded

- Raspberry Pi OS Lite / Raspbian GNU/Linux **13 Trixie**; 32-bitine `armhf` sobib.
- **Apache 2 + töötav PHP 8.4** (juba olemasolev Apache/PHP on täiesti sobiv).
- PHP moodulid **GD**, **Fileinfo**; **EXIF** on soovitatav, et JPEG-orientatsiooni arvestada.
- `acl`, et Apache saaks lugeda/kirjutada kodukausta fotokataloogi.
- Kioski jaoks **Weston 14** ja **Cog/WPE WebKit**; arvuti/telefoni veebis vaatamiseks ei ole neid vaja.
- Raspberry Pi Touch Display 2 kasutab **DSI-1**, vaikimisi portreeorientatsiooni **720 × 1280**.

Asjakohased allikad: [Raspberry Pi Touch Display 2](https://www.raspberrypi.com/documentation/accessories/touch-display-2.html), [Debian 13 PHP-GD pakett](https://packages.debian.org/trixie/php8.4-gd), [Weston 14 konfiguratsioon](https://manpages.debian.org/trixie/weston/weston.ini.5.en.html), [Cog käsureavalikud](https://manpages.debian.org/trixie/cog/cog.1.en.html).

## 2. Kiirpaigaldus (Apache ja PHP on juba olemas)

### 2.1 Paigalda laiendused

```bash
sudo apt update
sudo apt install -y php-cli php-gd acl curl
```

Kui `php -m | grep -Ei 'gd|fileinfo|exif'` näitab kõik kolm, on vajalikud moodulid olemas.

### 2.2 Too failid Raspberry Pi-sse

**Valik A — GitHubist** (pärast seda, kui oled hoidla GitHubi pannud):

```bash
sudo apt install -y git
git clone https://github.com/SINU_KASUTAJA/raspberry-pi-pildiraam.git
cd raspberry-pi-pildiraam
```

`SINU_KASUTAJA` on kohatäide – asenda oma GitHubi kasutajanimega.

**Valik B — ZIP-failist:** laadi projekt alla, kopeeri see Pi-sse ja paki lahti:

```bash
unzip raspberry-pi-pildiraam.zip
cd raspberry-pi-pildiraam
```

### 2.3 Paigalda PHP veebirakendus

```bash
sudo bash scripts/install.sh saarmaa
```

Paigaldaja:

1. Kopeerib PHP/HTML/CSS/JS failid kausta `/var/www/html/pildiraam`.
2. Loob `/home/saarmaa/pildid` ja `/var/cache/pildiraam`.
3. Annab Apache kasutajale `www-data` ainult vajalikud ligipääsuõigused ACL-iga.
4. Lisab Apache või PHP-FPM kasutatavale PHP-le `64M` failipiiri (iga foto kohta; `70M` POST).
5. Säilitab eelmised PHP-failid asukohas `/var/backups/pildiraam/...` ja jätab olemasoleva `config.local.php` alles.

Teise kasutajanime jaoks muuda käsu viimast argumenti, nt `sudo bash scripts/install.sh pi`.

> [!NOTE]
> Paigaldaja **ei** paigalda uut Apache serverit ega muuda olemasolevat Apache virtualhost'i. Eeldab, et su veebijuureks on `/var/www/html`. Kui sinu Apache kasutab teist veebijuurt, kohanda `WEBROOT` muutujat failis `scripts/install.sh`.

### 2.4 Kontrolli, kas Apache töötleb PHP-d

```bash
php -l /var/www/html/pildiraam/index.php
curl -s http://127.0.0.1/pildiraam/ | head -c 120
curl -s http://127.0.0.1/pildiraam/api.php
```

- `php -l` peab ütlema **No syntax errors detected**.
- Esimene HTTP-vastus peab algama `<!doctype html>` või sellele eelneva tühja reaga, **mitte** `<?php` ega `$exif` koodiga.
- API peab tagastama JSON-i, näiteks `{"version":"...","photos":[]}`.

**Kui PHP lähtekood ilmub ekraanile, peata seadistamine:** see pole brauseri värskendusprobleem – Apache ei käivita PHP-d vastavas asukohas. Kontrolli `sudo apache2ctl -M | grep -E 'php|proxy_fcgi'`, aktiivset virtualhost'i ja PHP-FPM teenust. Ära avalikusta sellist serverit võrku enne, kui PHP töötlemine on korras.

### 2.5 Lisa esimene foto

Telefonis/arvutis ava:

```text
http://PI_IP/pildiraam/upload.php
```

Vajuta **Vali fotod**. Võid valida korraga suure hulga fotosid. Telefon hoiab töötlemise ja saatmise järjekorras, et ei tarvitaks korraga palju mälu. Failide arvule programm eraldi piiri ei sea – tegelikuks piiriks on brauseri valikuvõimalused, võrguühendus ja Raspberry Pi kettaruum.

Soovi korral lisa foto SSH kaudu:

```bash
# Käivita oma arvutis (asenda PI_IP)
scp pilt.jpg saarmaa@PI_IP:/home/saarmaa/pildid/
```

## 3. Kioski kuvamine Raspberry Pi originaalekraanil

Kui Weston ja Cog sinu Pi-l juba töötavad, piisab URL-i seadistamisest. Kui need pole veel paigaldatud, vaata punktis 3.2 näidet.

### 3.1 Ühenda veebileht Cogiga

Raspberry Pi SSH terminalis:

```bash
mkdir -p ~/.config
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
sudo reboot
```

Pärast käivitumist näitab Pi fotosid. **Uute fotode lisamiseks ei pea Cogi, Westonit ega Pi-d taaskäivitama.**

### 3.2 Kiosk nullist (vajadusel)

```bash
sudo apt install --no-install-recommends -y weston cog
mkdir -p ~/.config
cp kiosk/weston.ini ~/.config/weston.ini
sudo install -m 755 kiosk/kiosk-browser /usr/local/bin/kiosk-browser
printf '%s\n' 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
sudo raspi-config
```

Vali `System Options` → `Boot / Auto Login` → **Console Autologin** (valiku täpne nimi võib erineda).

Lisa kasutaja `~/.profile` faili lõppu **üks kord**:

```bash
if [ -z "${WAYLAND_DISPLAY:-}" ] && [ "$(tty)" = "/dev/tty1" ]; then
    exec /usr/bin/weston \
      --backend=drm \
      --config="$HOME/.config/weston.ini" \
      --log="$HOME/weston.log"
fi
```

Tänu `tty1` kontrollile **ei käivitu Weston SSH kaudu sisselogimisel**. Kui kasutad juba töötavat `.profile` käivitusplokki, ära lisa seda uuesti.

```bash
sudo reboot
```

Westoni konfiguratsiooni põhisätted:

```ini
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
```

Touch Display 2 kuvab vaikimisi portreerežiimis `720 × 1280`. Kui pöörad füüsiliselt ekraani, kasuta Westoni `transform=rotate-90`, `rotate-180` või `rotate-270` vastavalt paigutusele; ära muuda toimivat DSI-draiverit lihtsalt pildi pööramiseks.

## 4. Puutetundlikkuse väljalülitamine (valikuline)

Kui tahad, et inimesed ei saaks pildiraami puudutades juhtida:

```bash
sudo install -m 644 kiosk/99-disable-touchscreen.rules \
  /etc/udev/rules.d/99-disable-touchscreen.rules
sudo reboot
```

See paneb `libinput` ignoreerima puuteekraani sisendsündmusi; pilt ja SSH jäävad tööle. Taastamiseks:

```bash
sudo rm /etc/udev/rules.d/99-disable-touchscreen.rules
sudo reboot
```

Aluseks: [libinput'i ametlik udev-seadistus](https://wayland.freedesktop.org/libinput/doc/latest/device-configuration-via-udev.html).

## 5. Seadistamine

Ära muuda GitHubi jagatavat `config.php` faili. Kohanda paigaldaja loodud `/var/www/html/pildiraam/config.local.php` faili, nt:

```php
<?php
return [
    'photo_dir' => '/home/saarmaa/pildid',
    'cache_dir' => '/var/cache/pildiraam',
    'slide_ms' => 12000,          // Foto vahetub iga 12 s järel
    'check_ms' => 30000,          // Uued fotod iga 30 s järel
    'full_reload_ms' => 1800000,  // Täisvärskendus 30 minuti järel
    'thumb_max_side' => 1600,     // JPEG vahemälu suurim külg
    'max_upload_bytes' => 67108864,
    'max_image_pixels' => 20000000,
    'timezone' => 'Europe/Tallinn',
];
```

Võimalikud näited:

| Soov | Seade |
|---|---|
| Vaheta iga 20 sekundit | `'slide_ms' => 20000` |
| Otsi uusi pilte iga 15 sekundit | `'check_ms' => 15000` |
| Vähenda mälukasutust | `'thumb_max_side' => 1280` |
| Peida kell või pildinimi | Muuda `index.php` ja `assets/slideshow.css` |

Brauser märkab PHP/CSS/JS ja kohaliku konfiguratsiooni muutusi API `version` järgi ja laadib esitluslehe uuesti. Muudetud fotosid tuvastatakse nime, faili muutmisaja ja suuruse alusel.

> [!TIP]
> Pildiraam toetab **JPG, PNG ja WebP** fotosid. Telefonist üles laadides teisendatakse need brauseris kuni 1600 piksli suuruseks JPEG-iks. **HEIC/HEIF** ei ole siin versioonis eraldi toetatud; vajadusel salvesta või ekspordi JPG-na. Suurte originaalide kopeerimisel käsitsi arvesta 20 MP piiranguga, sest Pi 2 RAM on 1 GB.

## 6. Uuendamine GitHubist

Pärast lähtekoodi GitHubis muutmist:

```bash
cd ~/raspberry-pi-pildiraam
git pull
sudo bash scripts/install.sh saarmaa
```

Pildiraami PHP/JS muudatused jõuavad ekraanile automaatselt järgmise kontrolliga (vaikimisi ~30 s). **Kioskifailid** (`kiosk/weston.ini`, `kiosk/kiosk-browser`) paigaldatakse eraldi, sest need võivad olla sinu seadmes kohandatud; vajadusel kopeeri need punktist 3.2 uuesti.

## 7. GitHubi avaldamine

Loo GitHubis **uus tühi hoidla**, näiteks `raspberry-pi-pildiraam`, ilma automaatselt genereeritud README ja LICENSE failideta. Seejärel käivita kohaliku projekti kaustas:

```bash
git init
git add .
git commit -m "Initial release: Raspberry Pi photo frame"
git branch -M main
git remote add origin https://github.com/SINU_KASUTAJA/raspberry-pi-pildiraam.git
git push -u origin main
```

Asenda `SINU_KASUTAJA`. GitHub võib käsurealt autentimiseks nõuda isiklikku juurdepääsutokenit või SSH-võtit. Alternatiivina ava GitHubi hoidla veebilehel ja laadi ZIP-ist lahti pakitud failid sinna üles.

GitHub Actions kontrollib igal `push`-il PHP, JavaScripti ja shell-skriptide süntaksit.

**Ära lisa GitHubi** oma päris fotosid, `.log` faile ega `config.local.php` kohalikku faili. `.gitignore` jätab need projektikaustas välja; fotod asuvad vaikimisi niikuinii väljaspool hoidlat.

## 8. Veaotsing

| Probleem | Kontroll / lahendus |
|---|---|
| PHP tekst (`<?php`, `$exif`) on ekraanil | Apache ei töötle PHP-d. Kontrolli Apache PHP moodulit / PHP-FPM seadistust ja punkt 2.4. |
| Ekraan näitab tühja albumit | `ls -lah /home/saarmaa/pildid`; kontrolli `api.php` vastust ja ACL õigusi. |
| Üleslaadimine ütleb 413 / 64 MiB | Kontrolli PHP `upload_max_filesize`, `post_max_size` ja Apache `LimitRequestBody` (kui kasutusel). |
| Üleslaadimine ütleb „kirjutamisõigus puudub” | `getfacl /home/saarmaa/pildid`; `www-data` peab sinna kirjutada saama. |
| Fotot ei saa avada | `php -m | grep -i gd`, foto mõõt max 20 MP; `sudo tail -n 40 /var/log/apache2/error.log`. |
| Cog ei käivitu | `pgrep -a cog`; `tail -n 80 ~/cog.log`. |
| Weston ei käivitu | `pgrep -a weston`; `tail -n 80 ~/weston.log`; ära käivita DRM-i otse SSH seansist. |
| Uued pildid ei ilmu | Ava `api.php`, kontrolli uue faili olemasolu ja `config.local.php` fotokausta. |
| Telefon ei pääse lehele | Kontrolli `hostname -I`, sama WiFi/LAN ning Apache porti 80. |
| Mõni pilt on külili | JPEG EXIF-pöörangud 3/6/8 arvestatakse; haruldaste peegeldusorientatsioonide puhul pööra originaal eelnevalt. |

Diagnostika näidiskäsud:

```bash
systemctl status apache2
curl -s http://127.0.0.1/pildiraam/api.php
php -l /var/www/html/pildiraam/index.php
pgrep -a weston
pgrep -a cog
sudo tail -n 50 /var/log/apache2/error.log
```

## 9. Privaatsus ja piirangud

- **Parooli ega kontosid pole**, nagu selle projekti eesmärk ette näeb. Seetõttu hoia teenus LAN-is, kasuta usaldatud võrku ja ära ava väljapoole.
- Fotod on serveris väljaspool veebijuurt; väljapoole jagatakse ainult `photo.php` kaudu töödeldud pildid.
- Failinimed puhastatakse ja iga üleslaadimine saab unikaalse nime. Piltide arvu kood ei piira, kuid salvestusruum ning brauseri ja Pi jõudlus piiravad praktilist mahtu.
- Üleslaadimise leht vajab JavaScripti. Kui vahetad telefonis lehelt ära või paned brauseri taustale, võib üleslaadimine katkeda. Selle jätkamiseks vali katkestatud failid uuesti.
- GIF-animatsioonid ja HEIC/HEIF ei ole toetatud. PNG läbipaistvus muutub serveri JPEG-vahemälus tumedaks taustaks.
- Üle 20 megapiksliseid **otse käsitsi kopeeritud** originaale ei töödelda automaatselt; üleslaadimisleht vähendab need enne saatmist. Pi 2 piiratud RAM-i tõttu on see tahtlik valik.
- Vanu vahemällu loodud eelvaatefaile automaatselt ei kustutata. Kui tühjendad või vahetad sageli väga suurt albumit, saad ruumi vabastada käsuga `sudo find /var/cache/pildiraam -maxdepth 1 -type f -name '*.jpg' -delete` (pärast pilte taas kuvades luuakse vahemälu uuesti).

## Litsents

MIT — vt [LICENSE](LICENSE).

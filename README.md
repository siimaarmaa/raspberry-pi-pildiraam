# 📷 Pildiraam — lihtne PHP-slaidiesitlus Raspberry Pi jaoks

Kerge, **kolme failiga** fotoraam: vertikaalne täisekraani slaidiesitlus ning telefonist või arvutist fotode lisamine. Töötab tavalise Apache + PHP veebiserveriga; pildiraami ekraanil kasutame Westonit ja Cogi.

Koostatud **Raspberry Pi 2 Model B Rev 1.1**, **Raspberry Pi OS Lite / Raspbian 13 (Trixie)** ja **Raspberry Pi Touch Display 2 (DSI, 720 × 1280)** jaoks.

> [!WARNING]
> **Parooli pole.** Kõik, kellel on üleslaadimislehele võrguühendus, saavad fotosid lisada. Kasuta seda ainult usaldatud kohalikus võrgus. Ära ava Apache porti 80 internetti ega suuna ruuterist porti edasi.

## Võimalused

- Fotod kaustast `/home/saarmaa/pildid`; neid ei pea Apache veebikausta kopeerima.
- Sujuv üleminek ja uus foto iga **12 sekundi** järel.
- Kell, eestikeelne kuupäev, foto nimi ja loendur.
- **Uued või kustutatud fotod jõuavad loendisse umbes 30 sekundi jooksul**, ilma Cogi taaskäivitamata; foto ekraanile jõudmine sõltub slaidide järjekorrast.
- `index.php` muutmisel värskendatakse brauser automaatselt; varuvärskendus iga 30 minuti järel.
- Eraldi, telefonis ja arvutis kasutatav üleslaadimisleht; vali korraga palju fotosid, need saadetakse serverisse **ükshaaval**.
- JPG, PNG ja WebP. Ühe faili suuruse ülempiir **64 MiB**, pildi eraldusvõime kuni **20 megapikslit**.
- Suured fotod vähendatakse serveris 1280 piksli sisse mahtuvaks JPEG-versiooniks ja salvestatakse vahemällu. Originaalid jäävad alles.
- Üles laaditud fotode nimed on unikaalsed, vanu faile ei kirjutata üle.
- Kontosid, andmebaasi, Composerit ega eraldi PHP-serverit ei ole vaja.

## Failid

```text
pildiraam/
├── index.php    # Pildiraam, fotode nimekiri ja piltide mõõtu muutmine
├── upload.php   # Mobiilne üleslaadimisleht ja failide vastuvõtmine
└── README.md    # See juhend (GitHubi jaoks)
```

`index.php` ja `upload.php` sisaldavad oma HTML-i, CSS-i ja JavaScripti. **Muid rakendusfaile ei kasutata.** `README.md` ei pea Apache veebikausta kopeerima.

## 1. Eeldused

Sul on juba töötavad **Apache 2** ja **PHP 8.x**. PHP peab olema Apachega seotud (kas `mod_php` või PHP-FPM kaudu). Vajalik on **PHP GD**; EXIF-orientatsiooni korrigeerimine toimub juhul, kui vastav PHP laiendus on saadaval. Näited on Debian/Raspbian 13 jaoks.

```bash
sudo apt update
sudo apt install -y php-gd acl curl
```

> Kui Apache näitab brauseris `<?php ...` või `$exif ...` lähtekoodi, **ära ava veebiserverit võrku** enne PHP töötlemise parandamist. `index.php` ei saa seda viga ise parandada.

## 2. Kaustad ja õigused

```bash
mkdir -p /home/saarmaa/pildid
sudo mkdir -p /var/www/html/pildiraam
sudo install -d -o www-data -g www-data -m 0750 /var/cache/pildiraam

# Apache peab saama kodukausta läbida ning fotokausta lugeda/kirjutada.
sudo setfacl -m u:www-data:--x /home/saarmaa
sudo setfacl -R -m u:www-data:rX /home/saarmaa/pildid
sudo setfacl -m u:www-data:rwx /home/saarmaa/pildid
sudo setfacl -d -m u:www-data:rwX /home/saarmaa/pildid
```

Fotode kaust on veebikaustast väljas. **Ära pane fotosid otse `/var/www/html` alla.** Vahemälu on `/var/cache/pildiraam` (Apache kasutaja `www-data` omanduses).

## 3. Paigalda kaks PHP-faili

Laadi hoidla alla või paki ZIP lahti. Käivita **projektikaustas**, kus on `index.php` ja `upload.php`:

```bash
php -l index.php
php -l upload.php
sudo install -m 0644 index.php upload.php /var/www/html/pildiraam/
```

Kui sul on juba varasem pildiraam, tee vajaduse korral enne kopeerimist vanast failist varukoopia. Uus versioon **ei vaja** eelmise ZIP-i `api.php`, `photo.php`, `lib.php`, `assets/`, `config.php` jms faile.

### Suurte failide lubamine

PHP vaikeseadistuses võib ühe foto ülempiir olla vaid 2 MiB. Sea ühe HTTP-päringu piirang vähemalt 64 MiB-ni. Üleslaadimisleht saadab mitu fotot ükshaaval, mitte ühe hiiglasliku POST-päringuna:

```bash
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
sudo systemctl try-restart php8.4-fpm 2>/dev/null || true
```

> Märkus: PHP 8.4 on Trixie vaikimisi versioon. Kui kasutad teist PHP-versiooni, kontrolli aktiivset PHP-FPM teenust ja Apache poolt kasutatavat PHP seadistust. Suurte fotode töötlemine tarbib Pi 2 mälu; 20 MP on rakenduse turva- ja jõudluspiir.

## 4. Testi Apachet ja PHP-d

Loo ajutine testfail ja vaata vastust:

```bash
printf '%s\n' '<?php echo "PHP_OK"; ?>' | sudo tee /var/www/html/pildiraam/php-test.php >/dev/null
curl -s http://127.0.0.1/pildiraam/php-test.php
sudo rm /var/www/html/pildiraam/php-test.php
```

Vastus peab olema täpselt `PHP_OK`. Seejärel:

```bash
curl -s http://127.0.0.1/pildiraam/ | head -c 100
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

Esimene käsk peab algama `<!doctype html>`-ga. Teine tagastab JSON-i, milles on `version` ja `photos`.

## 5. Ava pildiraam ja laadi fotod üles

Leia Raspberry Pi IP-aadress:

```bash
hostname -I
```

| Tegevus | Aadress |
|---|---|
| Pildiraam Pi ekraanil | `http://127.0.0.1/pildiraam/` |
| Pildiraam telefonis/arvutis | `http://PI_IP/pildiraam/` |
| Fotode lisamine telefonis/arvutis | `http://PI_IP/pildiraam/upload.php` |

Asenda `PI_IP` oma Pi võrguaadressiga (näiteks `192.168.1.100`). Telefoni ja Raspberry Pi peavad olema üksteisele võrgus ligipääsetavad.

Üleslaadimislehel vajuta **Vali fotod**, vali soovitud hulk pilte ja hoia leht üleslaadimise ajal avatuna. Eraldi failide arvu piiri rakendus ei sea, kuid tegelik arv oleneb brauserist, vabast mälust ja kettaruumist. Üle 20 MP pildid või üle 64 MiB failid lükatakse tagasi.

## 6. Cog ja Weston (kui kiosk on juba seadistatud)

Sinu olemasolevas Weston 14 seadistuses peab DSI-ekraan ja kiosk olema näiteks nii:

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

See on **näidis**, mitte ZIP-is eraldi seadistusfail. `transform=normal` sobib Touch Display 2 püstiasendisse, sest paneeli loomulik eraldusvõime on 720 × 1280. Kui ekraan on teistpidi, kohanda pööret.

Juba olemasoleva `/usr/local/bin/kiosk-browser` skripti korral muuda ainult avatud URL:

```bash
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
sudo reboot
```

Pärast seda uusi fotosid lisades **ei ole** vaja Raspberry Pi ega Cogi taaskäivitada.

## 7. Seadete muutmine

Slaidiesitluse seaded asuvad `index.php` JavaScripti lõigus:

```javascript
const delay = 12000; // 12 sekundit foto kohta
```

Kui muudad intervalli, muuda CSS-is samas failis ka `animation: fill 12s` väärtust, et edenemisriba kestus ühtiks. Uute fotode kontrollimise intervall on `setInterval(reload, 30000)` ehk 30 sekundit.

Kui kasutajanimi või asukoht on teine, muuda **mõlemas** PHP-failis `$photoDir` muutujat. Vahemälu asukoht on `index.php` alguses `$cacheDir`. Teise asukoha saad seadistada ka Apache keskkonnamuutujate `PILDIRAAM_PILDID` ja `PILDIRAAM_CACHE` kaudu.

## 8. Tõrkeotsing

| Probleem | Kontroll |
|---|---|
| PHP lähtekood kuvatakse brauseris | Apache ei töötle selle asukoha PHP-faile; kontrolli mod_php/PHP-FPM |
| Slaidiesitlus on tühi | `ls -la /home/saarmaa/pildid` ja `curl -s 'http://127.0.0.1/pildiraam/?list=1'` |
| Foto laadimine annab 500 | `php -m \| grep -i gd`, `ls -ld /var/cache/pildiraam`, Apache error.log |
| Üleslaadimine annab õiguste vea | `getfacl /home/saarmaa/pildid`, `sudo -u www-data test -w /home/saarmaa/pildid` |
| Üleslaadimine annab 413 või liiga suure faili vea | Kontrolli PHP `upload_max_filesize`, `post_max_size` ja Apache/proxy limiite |
| Cog ei avane | `pgrep -a weston; pgrep -a cog; tail -n 50 ~/weston.log` |
| Foto on liiga suur (üle 20 MP) | Vähenda seda telefonis/arvutis enne üleslaadimist |

## GitHubi lisamine

Loo GitHubis tühi hoidla, näiteks `raspberry-pi-pildiraam`. Projekti kaustas:

```bash
git init
git add README.md index.php upload.php
git commit -m 'Lisa PHP pildiraam ja üleslaadimine'
git branch -M main
git remote add origin https://github.com/SINU_KASUTAJA/raspberry-pi-pildiraam.git
git push -u origin main
```

Asenda `SINU_KASUTAJA` oma GitHubi kasutajanimega. GitHub võib küsida brauseri- või tokenipõhist autentimist.

---

**Privaatsus ja turvalisus:** Üleslaadimisleht ei küsi parooli, nagu selle lahenduse eesmärk ette näeb. Faili MIME-tüüpi ja pildipäist kontrollitakse, failidele antakse uued nimed ning fotod asuvad väljaspool veebijuurt. See ei asenda autentimist: ära tee teenust internetist ligipääsetavaks.

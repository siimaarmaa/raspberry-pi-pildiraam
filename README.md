# Pildiraam Raspberry Pi jaoks

Tegin selle väikese pildiraami ühe vana Raspberry Pi 2 jaoks. Mõte oli lihtne: ekraan võiks näidata kodus pilte ja uusi fotosid saaks telefonist juurde panna, ilma et peaks iga kord Pi-sse sisse logima.

See pole mingi suur pildihaldussüsteem. On kaks PHP faili ja kõik. Üks näitab pilte, teine laseb neid juurde laadida. Andmebaasi pole, kontosid ka mitte.

Algne masin, mille peal seda kasutan:

- Raspberry Pi 2 Model B Rev 1.1
- Raspberry Pi OS Lite / Raspbian 13 (Trixie)
- Raspberry Pi Touch Display 2, DSI ühendus (720 × 1280)
- Apache + PHP, ekraanil Weston ja Cog

Muul Linuxi masinal ja tavalises brauseris võib samamoodi töötada. Westoni ja Cogi pole vaja, kui tahad pilte lihtsalt arvutis brauserist vaadata.

## Mida see teeb

Pildid võetakse kaustast `/home/saarmaa/pildid`. Slaidid vahetuvad iga 12 sekundi järel ja üleminek on sujuv. Ekraanil on ka kell, kuupäev, foto nimi ning piltide arv.

Pildikausta kontrollitakse iga 30 sekundi järel. Kui lisad telefoni kaudu uusi pilte, tulevad need automaatselt nimekirja. Kohe järgmisel sekundil uut pilti muidugi ei näe, sest slaidiesitlus käib oma järjekorras edasi.

Toetatud on JPG, PNG ja WebP. Suuri pilte vähendatakse enne ekraanile saatmist ning väiksem koopia hoitakse vahemälus. Originaalfaili ei muudeta. Ühe üleslaaditava faili piir on 64 MiB ja foto tohib olla kuni 20 megapikslit. Pi 2 on vana masin, nii et väga suurte piltide hunnikuga tasub natuke arvestada.

## Failid

```text
pildiraam-minimaalne/
├── index.php     # slaidiesitlus ja piltide töötlemine
├── upload.php    # fotode lisamine telefonist või arvutist
└── README.md     # seesama juhend
```

CSS ja JavaScript on PHP failide sees. Rohkem faile projektil vaja pole.

## Paigaldamine

Eeldan, et Apache ja PHP on sul juba olemas ning Apache päriselt käivitab PHP-d. Näited on kirjutatud kasutajale `saarmaa`. Kui sul on teine kasutajanimi, vaheta see ka käskudes ära.

Kõigepealt vajalikud lisad:

```bash
sudo apt update
sudo apt install -y php-gd acl curl
```

Tee piltide kaust, veebikaust ja vahemälu:

```bash
mkdir -p /home/saarmaa/pildid
sudo mkdir -p /var/www/html/pildiraam
sudo install -d -o www-data -g www-data -m 0750 /var/cache/pildiraam
```

Apache töötab tavaliselt kasutajana `www-data`. Talle on vaja anda õigus piltide kausta lugeda ja sinna uusi fotosid kirjutada. Tervet kodukausta pole vaja kõigile avatuks teha.

```bash
sudo setfacl -m u:www-data:--x /home/saarmaa
sudo setfacl -R -m u:www-data:rX /home/saarmaa/pildid
sudo setfacl -m u:www-data:rwx /home/saarmaa/pildid
sudo setfacl -d -m u:www-data:rwX /home/saarmaa/pildid
```

Seejärel mine kausta, kus sul on need kaks PHP faili, ja kopeeri need Apache alla:

```bash
php -l index.php
php -l upload.php
sudo install -m 0644 index.php upload.php /var/www/html/pildiraam/
```

Kui vanad `index.php` ja `upload.php` on juba olemas, tee neist enne igaks juhuks varukoopia. See käsk kirjutab need üle.

### Suuremate fotode üleslaadimine

PHP vaikimisi üleslaadimise piir võib olla liiga väike. Allolev näide lubab kuni 64 MiB suuruse foto korraga. Kui valid telefonis näiteks 200 pilti, saadetakse need serverisse ükshaaval, mitte ühe suure päringuna.

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

Viimane käsk on PHP 8.4 FPM-i jaoks, kui seda kasutad. Kui FPM-i pole, ei ole sellest midagi. Eri PHP-versiooniga tuleb teenuse nimi vajadusel ära muuta.

## Kas PHP töötab?

Seda tasub enne pildiraami avamist proovida. Mul oli vahepeal selline jama, et brauser näitas päris PHP-koodi lehel tekstina.

```bash
printf '%s\n' '<?php echo "PHP_OK"; ?>' | sudo tee /var/www/html/pildiraam/php-test.php >/dev/null
curl -s http://127.0.0.1/pildiraam/php-test.php
sudo rm /var/www/html/pildiraam/php-test.php
```

Vastus peab olema `PHP_OK`. Kui näed `<?php` teksti, pole PHP Apachega korralikult ühendatud. See tuleb enne korda teha, muidu näitab brauser lähtekoodi. Ära tee sellist serverit internetist kättesaadavaks.

Võid ka otse pildiraami proovida:

```bash
curl -s http://127.0.0.1/pildiraam/ | head -c 100
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

Esimene peaks algama `<!doctype html>`-ga. Teine annab JSON-kujul fotode nimekirja. Kui kaust on tühi, on tühi nimekiri täiesti normaalne.

## Kuidas pilte lisada

Vaata Pi IP-aadressi:

```bash
hostname -I
```

Oletame, et see on `192.168.1.100`. Siis on aadressid sellised:

- Pildiraam: `http://192.168.1.100/pildiraam/`
- Fotode lisamine: `http://192.168.1.100/pildiraam/upload.php`
- Pi enda ekraanil: `http://127.0.0.1/pildiraam/`

Ava telefonis üleslaadimise aadress, vali fotod ja lase neil ära laadida. Võid valida suure hulga fotosid korraga. Lehel näed, palju on valmis ja kas mõni fail läks veaga. Telefon peab saama Raspberry Pi-ga samas võrgus ühendust; hoia lehte üleslaadimise ajal lahti.

Sama nimega pilte üle ei kirjutata, üles laaditud failid saavad endale uue nime.

## Pi ekraanil automaatselt käima

Kui sul Weston ja Cog juba töötavad, siis neid pole vaja uuesti installida. Muuda ainult Cogi veebiaadress:

```bash
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
sudo reboot
```

Minu Touch Display 2 jaoks on `~/.config/weston.ini` selline:

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

See eeldab, et `/usr/local/bin/kiosk-browser` on sul juba loodud ning loeb aadressi failist `~/.config/kiosk-url`. Kui sul on teistpidi paigaldatud ekraan, tuleb `transform` väärtust muuta. Touch Display 2 paneel on ise juba 720 × 1280 ehk püstine.

Kui esimest korda tööle saad, siis uusi pilte lisades enam Pi-d rebootima ei pea. Ka `index.php` muudatusi märkab brauser ligikaudu 30 sekundi jooksul. Lisaks värskendatakse leht igaks juhuks iga poole tunni järel.

## Kui tahad midagi muuta

`index.php` sees on näiteks selline rida:

```javascript
const delay = 12000;
```

See tähendab, et ühte pilti näidatakse 12 sekundit. Kui muudad näiteks `20000` peale, muuda samas failis CSS-i `animation: fill 12s` samuti `20s` peale, muidu edenemisriba ja slaidide aeg ei klapi.

Fotode kontrollimise intervall on JavaScriptis `setInterval(reload, 30000)`.

Kui kasutaja pole `saarmaa` või pildid on mujal, muuda **mõlemas PHP-failis** `$photoDir`. Vahemälu kaust on `index.php` alguses `$cacheDir`.

## Kui midagi ei tööta

**Lehel paistab PHP lähtekood.** Apache ei käivita PHP-d. Kontrolli PHP moodulit või PHP-FPM seadistust.

**Pildiraam ütleb, et pilte pole.** Kontrolli, kas `/home/saarmaa/pildid` sees on pildid ja kas Apache saab neid lugeda:

```bash
ls -la /home/saarmaa/pildid
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

**Fotot ei saa avada või tuleb HTTP 500.** Vaata, kas GD on olemas ning kas vahemälu on kirjutatav:

```bash
php -m | grep -i gd
ls -ld /var/cache/pildiraam
```

**Üleslaadimisel tuleb õiguste viga.** Vaata:

```bash
getfacl /home/saarmaa/pildid
sudo -u www-data test -w /home/saarmaa/pildid && echo OK
```

**Fail on liiga suur.** Kontrolli PHP `upload_max_filesize` ja `post_max_size` väärtusi. Selle pildiraami enda piirid on 64 MiB ühe faili kohta ja kuni 20 MP pildi kohta.

**Weston või Cog ei käivitu.** Siis on probleem tõenäoliselt kioskis, mitte PHP-s:

```bash
pgrep -a weston
pgrep -a cog
tail -n 50 ~/weston.log
```

## Veel üks asi

**Üleslaadimise lehel ei ole parooli.** See on meelega nii, sest kasutan seda oma koduvõrgus. Aga igaüks, kes sellele lehele võrgust ligi saab, saab ka fotosid lisada. Seega ära suuna ruuterist porti 80 internetti ja ära pane seda avalikku serverisse niisama üles.

Fotod ise on väljaspool Apache veebikausta, aga see ei asenda paroolikaitset. Sellest tasub aru saada enne kui sama lahenduse kuhugi avalikku võrku paned.

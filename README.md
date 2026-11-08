# Pildiraam Raspberry Pi jaoks

Tegin selle väikese pildiraami ühe vana Raspberry Pi 2 jaoks. Mõte oli lihtne: ekraan võiks näidata kodus pilte ja uusi fotosid saaks telefonist juurde panna, ilma et peaks iga kord Pi-sse sisse logima.

See pole mingi suur pildihaldussüsteem. Andmebaasi pole, kontosid ka mitte. Üks leht näitab pilte, teine laseb neid juurde laadida, pöörata ja kustutada.

Algne masin, mille peal seda kasutan:

- Raspberry Pi 2 Model B Rev 1.1
- Raspberry Pi OS Lite / Raspbian 13 (Trixie)
- Raspberry Pi Touch Display 2, DSI ühendus (720 × 1280)
- Apache + PHP, ekraanil Weston ja Cog

Muul Linuxi masinal ja tavalises brauseris võib samamoodi töötada. Westoni ja Cogi pole vaja, kui tahad pilte lihtsalt arvutis brauserist vaadata.

## Mida see teeb

**Slaidiesitlus** (`index.php`): pildid võetakse kaustast `/home/saarmaa/pildid`. Slaidid vahelduvad vaikimisi iga 12 sekundi järel sujuva üleminekuga. Ekraanil on kell, kuupäev, piltide loendur ja soovi korral ilm (SVG-ikoonidega, seega ei sõltu need fontidest). Järgmine foto laaditakse taustal ette.

Pildikausta kontrollitakse iga 30 sekundi järel. Uued pildid tulevad automaatselt nimekirja lõppu. Kohe järgmisel sekundil uut pilti muidugi ei näe, sest slaidiesitlus käib oma järjekorras edasi. Viimati näidatud foto jäetakse brauseris meelde, nii et lehe uuesti laadimine ei alusta esitlust otsast.

**Haldusleht** (`upload.php`): fotode lisamine (mitu korraga), pööramine 90° vasakule või paremale, kustutamine ja otsing failinime järgi. Sama nimega pilte üle ei kirjutata, üles laaditud failid saavad endale uue nime.

Toetatud on JPG, PNG ja WebP. Suuri pilte vähendatakse enne ekraanile saatmist (slaid 1280 px, haldusleht 400 px pisipilt) ning väiksem koopia hoitakse vahemälus. Pisipildid ja slaidid töötleb korraga ainult üks päring, et Pi 2 mälu ei saaks täis. Originaalfaili muudab ainult pööramine. Ühe üleslaaditava faili piir on 64 MiB ja foto tohib olla kuni 20 megapikslit. Pi 2 on vana masin, nii et väga suurte piltide hunnikuga tasub natuke arvestada.

Vahemälu koristab ennast ise: eelvaated, mille fotot enam pole, kustutatakse korra tunnis.

## Failid

```
pildiraam/
├── common.php    # seadistus ja ühised abifunktsioonid
├── index.php     # slaidiesitlus ja piltide töötlemine
├── upload.php    # fotode lisamine, pööramine ja kustutamine
└── README.md     # seesama juhend
```

CSS ja JavaScript on PHP failide sees. Rohkem faile projektil vaja pole.

## Paigaldamine

Eeldan, et Apache ja PHP on sul juba olemas ning Apache päriselt käivitab PHP-d. Näited on kirjutatud kasutajale `saarmaa`. Kui sul on teine kasutajanimi, vaheta see ka käskudes ära ja muuda `common.php` algusest `photoDir`.

Kõigepealt vajalikud lisad:

```
sudo apt update
sudo apt install -y php-gd acl curl
```

Tee piltide kaust, veebikaust ja vahemälu:

```
mkdir -p /home/saarmaa/pildid
sudo mkdir -p /var/www/html/pildiraam
sudo install -d -o www-data -g www-data -m 0750 /var/cache/pildiraam
```

Apache töötab tavaliselt kasutajana `www-data`. Talle on vaja anda õigus piltide kausta lugeda ja sinna uusi fotosid kirjutada. Tervet kodukausta pole vaja kõigile avatuks teha.

```
sudo setfacl -m u:www-data:--x /home/saarmaa
sudo setfacl -R -m u:www-data:rX /home/saarmaa/pildid
sudo setfacl -m u:www-data:rwx /home/saarmaa/pildid
sudo setfacl -d -m u:www-data:rwX /home/saarmaa/pildid
```

Seejärel mine kausta, kus sul on PHP failid, ja kopeeri need Apache alla:

```
php -l common.php
php -l index.php
php -l upload.php
sudo install -m 0644 common.php index.php upload.php /var/www/html/pildiraam/
```

Kui vanad failid on juba olemas, tee neist enne igaks juhuks varukoopia. See käsk kirjutab need üle. Uuel versioonil on kolm faili, nii et ära unusta `common.php`.

### Suuremate fotode üleslaadimine

PHP vaikimisi üleslaadimise piir võib olla liiga väike. Allolev näide lubab kuni 64 MiB suuruse foto korraga. Kui valid telefonis näiteks 200 pilti, saadetakse need serverisse ükshaaval, mitte ühe suure päringuna.

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
sudo systemctl try-restart php8.4-fpm 2>/dev/null || true
```

Viimane käsk on PHP 8.4 FPM-i jaoks, kui seda kasutad. Kui FPM-i pole, ei ole sellest midagi. Eri PHP-versiooniga tuleb teenuse nimi vajadusel ära muuta.

Kui fail on PHP piirist suurem, näed haldulehel nüüd selget teadet "ületab PHP post_max_size piiri".

## Kas PHP töötab?

Seda tasub enne pildiraami avamist proovida. Mul oli vahepeal selline jama, et brauser näitas päris PHP-koodi lehel tekstina.

```
printf '%s\n' '<?php echo "PHP_OK"; ?>' | sudo tee /var/www/html/pildiraam/php-test.php >/dev/null
curl -s http://127.0.0.1/pildiraam/php-test.php
sudo rm /var/www/html/pildiraam/php-test.php
```

Vastus peab olema `PHP_OK`. Kui näed `<?php` teksti, pole PHP Apachega korralikult ühendatud. See tuleb enne korda teha, muidu näitab brauser lähtekoodi. Ära tee sellist serverit internetist kättesaadavaks.

Võid ka otse pildiraami proovida:

```
curl -s http://127.0.0.1/pildiraam/ | head -c 100
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

Esimene peaks algama `<!doctype html>`-ga. Teine annab JSON-kujul fotode nimekirja. Kui kaust on tühi, on tühi nimekiri täiesti normaalne.

## Kuidas pilte lisada

Vaata Pi IP-aadressi:

```
hostname -I
```

Oletame, et see on `192.168.1.100`. Siis on aadressid sellised:

- Pildiraam: `http://192.168.1.100/pildiraam/`
- Fotode haldus: `http://192.168.1.100/pildiraam/upload.php`
- Pi enda ekraanil: `http://127.0.0.1/pildiraam/`

Ava telefonis haldusleht, vali fotod ja lase neil ära laadida. Võid valida suure hulga fotosid korraga. Lehel näed, palju on valmis ja kas mõni fail läks veaga. Telefon peab saama Raspberry Pi-ga samas võrgus ühendust; hoia lehte üleslaadimise ajal lahti.

## Pi ekraanil automaatselt käima

Kui sul Weston ja Cog juba töötavad, siis neid pole vaja uuesti installida. Muuda ainult Cogi veebiaadress:

```
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
sudo reboot
```

Minu Touch Display 2 jaoks on `~/.config/weston.ini` selline:

```
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

Kui esimest korda tööle saad, siis uusi pilte lisades enam Pi-d rebootima ei pea. Ka PHP failide muudatusi märkab brauser ligikaudu 30 sekundi jooksul. Lisaks värskendatakse leht igaks juhuks iga kuue tunni järel.

## Kui tahad midagi muuta

Kogu seadistus on failis `common.php`, funktsioonis `pildiraamConfig()`:

- `photoDir` ja `cacheDir`: fotode ja vahemälu kaust. Võid kasutada ka keskkonnamuutujaid `PILDIRAAM_PILDID` ja `PILDIRAAM_CACHE`.
- `slideSeconds`: kui mitu sekundit ühte fotot näidatakse. Edenemisriba kohandub ise, CSS-i muutma ei pea.
- `weather`: ilma koht, koordinaadid ja `enabled`. Pane `'enabled' => false`, kui ilma pole vaja.

**Ilm teeb välise päringu.** Kui ilm on sees, küsib brauser andmeid aadressilt `api.open-meteo.com`. Seal näeb teenus sinu IP-d ja koordinaate. Koordinaatidele sobib hästi linna või küla keskpunkt, täpset kodu asukohta pole vaja. Fotode näitamine ilmast ei sõltu.

## Kui midagi ei tööta

**Lehel paistab PHP lähtekood.** Apache ei käivita PHP-d. Kontrolli PHP moodulit või PHP-FPM seadistust.

**Pildiraam ütleb, et pilte pole.** Kontrolli, kas `/home/saarmaa/pildid` sees on pildid ja kas Apache saab neid lugeda:

```
ls -la /home/saarmaa/pildid
curl -s 'http://127.0.0.1/pildiraam/?list=1'
```

**Fotot ei saa avada või tuleb HTTP 500.** Vaata, kas GD on olemas ning kas vahemälu on kirjutatav:

```
php -m | grep -i gd
ls -ld /var/cache/pildiraam
```

**Üleslaadimisel tuleb õiguste viga.** Vaata:

```
getfacl /home/saarmaa/pildid
sudo -u www-data test -w /home/saarmaa/pildid && echo OK
```

**Fail on liiga suur.** Kontrolli PHP `upload_max_filesize` ja `post_max_size` väärtusi. Selle pildiraami enda piirid on 64 MiB ühe faili kohta ja kuni 20 MP pildi kohta.

**Ilma kohal on ruudud.** Uues versioonis kasutab ilm SVG-ikoone, nii et ruute enam ei tohiks tulla. Kui näed neid ikka, on vana `index.php` veel ekraanil: kopeeri failid uuesti ja oota 30 sekundit.

**Weston või Cog ei käivitu.** Siis on probleem tõenäoliselt kioskis, mitte PHP-s:

```
pgrep -a weston
pgrep -a cog
tail -n 50 ~/weston.log
```

## Turvalisus

**Haldusleht on vaikimisi ilma paroolita.** See on meelega nii, sest kasutan seda oma koduvõrgus. Aga igaüks, kes sellele lehele võrgust ligi saab, saab fotosid lisada, **pöörata (see kirjutab originaalfaili üle) ja kustutada**. Kustutamist tagasi võtta ei saa. Seega ära suuna ruuterist porti 80 internetti ja ära pane seda avalikku serverisse niisama üles.

Fotod ise on väljaspool Apache veebikausta, aga see ei asenda paroolikaitset.

### Valikuline: parool haldulehele

Slaidiesitlus jääb avatuks (Pi ekraan ei pea sisse logima), parool tuleb ainult `upload.php` ette:

```
sudo apt install -y apache2-utils
sudo htpasswd -c /etc/apache2/.htpasswd-pildiraam minunimi
```

Seejärel tee fail `/etc/apache2/conf-available/pildiraam-auth.conf`:

```
<Location "/pildiraam/upload.php">
    AuthType Basic
    AuthName "Pildiraam"
    AuthUserFile /etc/apache2/.htpasswd-pildiraam
    Require valid-user
</Location>
```

Ja lülita see sisse:

```
sudo a2enconf pildiraam-auth
sudo systemctl reload apache2
```

Pane tähele, et Basic-autentimine saadab parooli HTTP kaudu selgelt. Koduvõrgus on see tavaliselt sobiv, internetis mitte.
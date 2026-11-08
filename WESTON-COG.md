# Weston ja Cog: pildiraam Pi ekraanil täisekraanil

See juhend näitab, kuidas Raspberry Pi OS Lite peal panna pildiraam ekraanile täisekraanil ja iga käivitusega automaatselt tööle. Pildiraami enda paigaldus on [README.md](README.md) failis. Tee see kõigepealt valmis.

- **Weston** on Waylandi ekraaniserver. Kioskirežiimis (`kiosk-shell`) näitab ta ühte rakendust täisekraanil, ilma töölauata.
- **Cog** on väike WPE WebKit brauser. Ta on palju kergem kui Chromium, mis sobib vanale Pi 2-le.

Töölauda (labwc, X11 vms) pole vaja. Lite-versioon on kiirem ja võtab vähem mälu.

## Enne alustamist

Kontrolli, et pildiraam töötab Pi-l:

```
curl -s http://127.0.0.1/pildiraam/ | head -c 100
```

Vastus peab algama `<!doctype html>`-ga. Kui ei alga, lahenda see enne README järgi.

Näited on kasutajale `saarmaa`. Skriptid kasutavad `$HOME`, nii et kasutajanime neis muutma ei pea.

## 1. Paigalda paketid

```
sudo apt update
sudo apt install -y weston cog curl
```

Kui `apt` ei leia paketti `cog`, vaata `apt search cog`. Pakettide nimed võivad Debiani versioonide vahel muutuda.

## 2. Sisselogimine ilma parooli küsimata

Weston peab käivituma päris konsoolilt (`tty1`), et ta saaks ekraanile ligi. Selleks lülita sisse konsooli automaatne sisselogimine:

```
sudo raspi-config nonint do_boot_behaviour B2
```

`B2` tähendab "Console Autologin". Sama saad menüüst: `sudo raspi-config` → System Options → Boot / Auto Login → Console Autologin.

## 3. Aadress, mida brauser näitab

```
mkdir -p ~/.config
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
```

Aadressi hoitakse eraldi failis, et seda saaks muuta ilma skripte puudutamata.

## 4. Brauseri käivitusskript

Tee fail `/usr/local/bin/kiosk-browser`:

```
sudo tee /usr/local/bin/kiosk-browser >/dev/null <<'EOF'
#!/bin/sh
# Käivitab Cogi ja näitab aadressi failist ~/.config/kiosk-url.

URL="$(cat "$HOME/.config/kiosk-url" 2>/dev/null)"
[ -n "$URL" ] || URL="http://127.0.0.1/pildiraam/"

# Oota, kuni Apache vastab (kuni umbes 60 sekundit).
# Muidu näeks ekraan pärast alglaadimist vealehte.
i=0
while [ "$i" -lt 30 ]; do
    curl -fs -o /dev/null "$URL" && break
    i=$((i + 1))
    sleep 2
done

exec cog --platform=wl "$URL"
EOF
sudo chmod +x /usr/local/bin/kiosk-browser
```

`--platform=wl` tähendab, et Cog joonistab Waylandi akna sisse (Westoni).

## 5. Westoni seadistus

Tee fail `~/.config/weston.ini`:

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
watch=true
```

Mida read teevad:

- `shell=kiosk-shell.so` näitab ühte rakendust täisekraanil.
- `idle-time=0` ei lase ekraanil magama minna.
- `require-input=false` lubab käivituda ka siis, kui klaviatuuri või hiirt pole ühendatud.
- `[output]` ütleb, millist ekraani ja kuidas seadistada.
- `[autolaunch]` käivitab brauseri Westoniga koos. `watch=true` käivitab brauseri uuesti, kui see kokku jookseb.

### Ekraani nimi ja pööramine

Ekraani nime näed käsuga:

```
ls /sys/class/drm/
```

Nimekirjas on näiteks `card0-DSI-1` (DSI-ekraan) või `card0-HDMI-A-1` (HDMI). Pane `name=` järele ainult osa pärast `card0-`.

Kui pilt on valesti pööratud, muuda `transform` väärtust. Lubatud on `normal`, `90`, `180`, `270`, `flipped`, `flipped-90`, `flipped-180` ja `flipped-270`. Raspberry Pi Touch Display 2 paneel on ise juba 720 × 1280 ehk püstine, nii et tavaliselt sobib `normal`.

## 6. Käivita Weston automaatselt

Lisa faili `~/.bash_profile` lõppu:

```
cat >> ~/.bash_profile <<'EOF'

# Käivita pildiraami kiosk ainult esimesel konsoolil.
if [ -z "${WAYLAND_DISPLAY:-}" ] && [ "$(tty)" = "/dev/tty1" ]; then
    exec weston --log="$HOME/weston.log"
fi
EOF
```

`exec` asendab sisselogimise kesta Westoniga. Kui Weston lõpetab, logitakse kasutaja välja ja automaatne sisselogimine käivitab selle uuesti.

Taaskäivita Pi:

```
sudo reboot
```

Mõne hetke pärast peaks ekraanile ilmuma pildiraam.

## Ekraan ise ei tööta

Kui Touch Display 2 jääb pärast alglaadimist mustaks, kontrolli esmalt, kas süsteem tuvastab ekraani:

```
ls /sys/class/drm/
```

Kui DSI-ekraani seal pole, vaata faili `/boot/firmware/config.txt` ja Raspberry Pi dokumentatsiooni oma ekraani kohta. Mõnel Pi mudelil tuleb DSI-ekraani overlay `config.txt`-sse käsitsi lisada, ja see sõltub paneeli suurusest. Seda juhend ei kata, sest see sõltub riistvarast.

## Igapäevased käsud

Muuda näidatavat aadressi ja lae brauser uuesti:

```
echo 'http://127.0.0.1/pildiraam/' > ~/.config/kiosk-url
pkill cog
```

Weston käivitab brauseri `watch=true` tõttu ise uuesti ja loeb uue aadressi.

Käivita kogu kiosk uuesti, ilma Pi-d taaskäivitamata:

```
pkill weston
```

Peata kiosk hoolduseks (SSH kaudu). Kui peatad ainult Westoni, käivitab automaatne sisselogimine selle kohe uuesti, nii et peata kõigepealt `tty1` sisselogimine:

```
sudo systemctl stop getty@tty1
pkill weston
```

Uuesti käivitamiseks `sudo systemctl start getty@tty1` või taaskäivita Pi.

## Kui midagi ei tööta

**Ekraan on must ja midagi ei juhtu.** Vaata Westoni logi:

```
tail -n 50 ~/weston.log
```

Kui logis on ekraani nime viga, kontrolli `name=` väärtust käsuga `ls /sys/class/drm/`.

**Weston käivitub, aga brauserit pole.** Vaata, kas protsessid töötavad:

```
pgrep -a weston
pgrep -a cog
```

Käivita skript käsitsi SSH-st, et näha veateadet:

```
WAYLAND_DISPLAY=wayland-1 XDG_RUNTIME_DIR=/run/user/$(id -u) /usr/local/bin/kiosk-browser
```

**Brauser näitab vealehte.** Kontrolli, kas pildiraam vastab (`curl` käsk jaotises "Enne alustamist") ja kas aadress failis `~/.config/kiosk-url` on õige. Skript ootab Apache käivitumist kuni minuti, aga aeglase Pi 2 korral võib vaja minna rohkem. Lisa vajadusel skriptis tsükli arvu (`30`) suuremaks.

**Weston ei saa ekraanile ligi.** Veendu, et kasutaja kuulub vajalikesse gruppidesse, ja logi uuesti sisse:

```
sudo usermod -aG video,render,input "$USER"
sudo reboot
```

**Pilt on vales asendis.** Muuda `transform` väärtust failis `~/.config/weston.ini` ja käivita `pkill weston`.

**Ekraan läheb magama.** Kontrolli, et failis `~/.config/weston.ini` on `idle-time=0` jaotises `[core]`.

## Märkus

Pi 2 on aeglane. Pildiraam vähendab suured fotod serveris väiksemaks ja laeb järgmise foto ette, kuid esimene käivitus tühja vahemäluga võib võtta aega.
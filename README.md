# RELOG VETO — CS2 map veto

Tablet za timove + admin panel + overlay za vMix/OBS, u relog.gg brendingu.
Čist PHP (8.1+), bez baze — stanje je u `data/state.json`. Radi na bilo kom cPanelu.

## Stranice
| URL | Za koga |
|---|---|
| `/tablet?k=TABLET_KEY` | Tablet koji timovi koriste za ban/pick/side |
| `/admin` | Operater: timovi, logoi, format, map pool, undo/restart, rezime |
| `/overlay` | Browser input u vMixu/OBS-u, 1920×1080, transparentna pozadina (`?pos=top` za gore, `?preview` za sivu pozadinu pri testu) |

Sve se sinhronizuje uživo (polling ~0,7 s).

## Deploy
Svaki push na `main` ide automatski na server preko FTP-a (GitHub Actions, secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`).

Jednom, ručno na serveru (cPanel File Manager):
1. Kopiraj `config.local.example.php` u `config.local.php` i upiši `admin_token` i `tablet_key`. Deploy taj fajl nikad ne dira.
2. Folderi `data/` i `uploads/` moraju biti upisivi za PHP (755 je obično dovoljno).
3. Otvori `/admin`, uloguj se, kopiraj link za tablet iz kartice „Linkovi".

`.htaccess` već radi lepe URL-ove, blokira `config.php`/`lib.php`, zabranjuje pristup `data/` i izvršavanje PHP-a u `uploads/`.

## Logo i slike mapa
- Slike mapa, RLG logo, relog favicon i Anek Latin font su u `assets/` (self-hosted, radi i na LAN-u bez interneta).
- Slike mapa su u `assets/maps/<id>.jpg` — za zamenu ubaci fajl istog imena: `ancient, anubis, cache, dust2, inferno, mirage, nuke, overpass, train, vertigo` (.jpg/.png/.webp). Bez slike se prikazuje brendirana pozadina.

## Pravila veta
Team A uvek počinje (za promenu: „Zameni timove").
- **BO1:** B B B B B B → decider (stranu na decideru bira podešeni tim, default Team A)
- **BO3:** B B P P B B → decider (default knife)
- **BO5:** B B P P P P → decider (default knife)

Uz uključeno „Biranje strane", posle svakog picka protivnik bira CT/T.
Tablet traži potvrdu pre svakog bana/picka; zaštita od duplog tapa je na serveru.

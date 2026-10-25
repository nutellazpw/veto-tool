# RELOG VETO — CS2 map veto

Team tablet + admin panel + vMix/OBS overlay in relog.gg branding.
Plain PHP (8.1+), no database server — everything lives in JSON files in `data/`.

## Pages
| URL | For |
|---|---|
| `/tablet` | The tablet the teams use for ban / pick / side. Open to everyone, no key. |
| `/admin` | Operator: team database with seeds, match setup, format, map pool, Discord, undo/restart, summary |
| `/overlay` | vMix/OBS browser input, 1920×1080, transparent (`?pos=top` for the top of the screen, `?preview` for a grey test background) |

Everything syncs live (polling ~0.7 s).

## Admin workflow
1. **Team database** — add the teams once (name, seed 1–8, logo). Seeds are unique.
2. **Match setup** (top of the admin) — pick Team A and Team B from the dropdowns. The panel shows who the higher seed is, so you know whom to ask whether they want to be Team A (bans first) or Team B. Click **Set teams**.
   - Or use the coin toss — **🪙 Flip coin** on the tablet (once per match, before the veto starts) or **🪙 Coin toss** in the admin (any time, also to toss again): the server flips a fair coin, the tablet shows the flip, and the toss winner taps **Ban first** or **Ban second** on the tablet (the admin can also choose for them). Teams are swapped automatically so the right team starts. **Toss again** / **Clear toss** are in the coin card.
3. The teams play the veto on `/tablet`. Map choices are locked until the toss winner has chosen.
4. When the last step is played, the result is posted to Discord automatically (once per veto; **Post again** re-sends it).

Seeds are only visible in the admin, never on the tablet or the overlay.

## Deploy
Every push to `main` is uploaded via FTP (GitHub Actions, secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`).

Once, by hand on the server (cPanel File Manager):
1. Copy `config.local.example.php` to `config.local.php` and set `admin_token` (and optionally `discord_webhook`). Deploys never touch this file.
2. `data/` and `uploads/` must be writable by PHP (755 is usually enough).
3. Open `/admin` and log in.

Deploys also never touch `data/*.json` (veto state, team database, settings) or uploaded logos.

## Discord
Set the webhook URL in the admin (Discord card → Save → Send test), or as `discord_webhook` in `config.local.php`. The admin value wins.

## Assets
- Map images, RLG logo, relog favicon and the Anek Latin font live in `assets/` (self-hosted, works on a LAN without internet).
- Map images are `assets/maps/<id>.jpg` — to replace one, upload a file with the same name: `ancient, anubis, cache, dust2, inferno, mirage, nuke, overpass, train, vertigo` (.jpg/.png/.webp).

## Veto rules
Team A always starts (use **Swap teams** to change that).
- **BO1:** B B B B B B → decider (decider side picked by the chosen team, default Team A)
- **BO3:** B B P P B B → decider (default knife)
- **BO5:** B B P P P P → decider (default knife)

With side choice on, the opponent picks CT/T after every pick.
The tablet asks for confirmation before every ban/pick; double taps are rejected by the server.

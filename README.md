# EA GCal Sync

WordPress plugin, ktorý z udalostí vo zdieľanom Google kalendári vytvára príspevky `all-events` s vyplnenými ACF poliami. Nahrádza scenár v Make.com.

- **Žiadna prichádzajúca požiadavka na web.** Plugin sa raz za hodinu sám spýta Google (WP-Cron), takže Wordfence ani Cloudflare nemajú čo blokovať. Netreba Application Password.
- **Bez OAuth.** Prihlasuje sa cez Google service account, takže nič neexpiruje a netreba obnovovať prihlásenie.
- **Konfigurácia vo WordPresse.** Organizátorov, nastavenia aj log spravuješ v administrácii, ide to aj z mobilu.
- **Bezpečné voči ručným úpravám.** Čo admin opraví vo WordPresse, synchronizácia neprepíše.

## Inštalácia

### 1. Google Cloud (raz, asi 15 minút, ide aj z tabletu)

1. [console.cloud.google.com](https://console.cloud.google.com) → vytvoriť projekt, napr. `ea-gcal-sync`.
2. **APIs & Services → Library → Google Calendar API → Enable.**
3. **IAM & Admin → Service Accounts → Create service account.** Meno napr. `wordpress-sync`. Roly nepridávaj.
4. Otvor service account → **Keys → Add key → Create new key → JSON.** Stiahne sa súbor s kľúčom; zaobchádzaj s ním ako s heslom.
5. V Google Calendar: kalendár s akciami → **Settings and sharing → Share with specific people → Add people** → e-mail service accountu (`…@….iam.gserviceaccount.com`) s právom **See all event details**.
6. Tamtiež v časti **Integrate calendar** skopíruj **Calendar ID**.

> Odporúčanie: na akcie používaj samostatný kalendár (napr. „EA akcie“), nie osobný hlavný kalendár. Plugin importuje všetko, čo v kalendári je.

### 2. WordPress

1. Stiahni `ea-gcal-sync.zip` z [Releases](../../releases) (alebo `bin/build-zip.sh`).
2. **Plugins → Add New → Upload Plugin** → ZIP → **Activate**. Najprv na testovacej kópii webu, potom na produkcii.
3. **Settings → EA GCal Sync:** vlož Calendar ID a celý obsah JSON súboru → **Uložiť**.
4. **Náhľad najbližších udalostí** ukáže, ako budú akcie vyzerať. Nič sa pritom nezapisuje.
5. **Akcie → Organizátori** (vyžaduje ACF Pro): pridaj e-maily autorov udalostí, mená a fotky. Nastav predvoleného organizátora a fotku.

### 3. Prechod z Make.com

1. **Vypni scenár v Make.com** (inak by oba systémy vytvárali akcie súbežne).
2. Vo WordPresse klikni na **Synchronizovať teraz**. Prvý beh prejde všetky budúce udalosti.
3. Príspevky, ktoré kedysi vytvoril Make (rovnaký názov a dátum), plugin **prevezme**: spáruje ich s udalosťou, doplní len prázdne polia a nič neprepíše ani nezduplikuje.
4. V **Users → Profile → Application Passwords** zruš heslo, ktoré používal Make.

## Konvencia v popise udalosti

Riadok `Názov poľa: hodnota` v popise udalosti sa zapíše do ACF poľa s rovnakým labelom alebo názvom. Diakritika ani veľké písmená nerozhodujú. Tieto riadky sa nedostanú do textu príspevku.

```
Príďte na diskusiu o AI safety.

Jazyk: English
Přihlásit se: https://forms.gle/…
Organizátor: Jana Nováková
Kapacita: 30
```

| V kalendári | ACF pole |
| --- | --- |
| začiatok / koniec | `datum-eventu`, `cas-eventu`, `cas-konce-eventu` (časové pásmo webu, letný čas) |
| miesto `Názov, https://maps…` | `misto-eventu`, `misto-eventu-url`, `misto-eventu-text` („Google maps“) |
| autor udalosti (e-mail) | `organizator-eventu`, `fotka-organizatora-eventu` (z Akcie → Organizátori) |
| `Jazyk:` / `Language:` | `jazyk-eventu` (`Česky`, `English`, `Česky, English`; chýba → `Česky, English` + upozornenie) |
| `Přihlásit se:` / `Registrace:` | `url-eventu` |
| `Organizátor:` | prepíše organizátora; fotka sa hľadá podľa mena |
| `<Label hocijakého ACF poľa>:` | to pole. **Nové ACF pole funguje bez zmeny kódu.** |

Ďalšie aliasy sa dajú pridať filtrom `eags_aliases`.

## Ako synchronizácia rozhoduje

- Udalosť a príspevok sa párujú cez meta `_gcal_event_id`, takže nevznikajú duplikáty.
- **Nové akcie** sa ukladajú ako koncept (alebo sa hneď publikujú, ak to nastavíš a nič nechýba).
- **Zmena v kalendári** (čas, miesto, názov) sa prenesie, ale len do polí, ktoré admin ručne neupravil. Box „Google kalendár“ v editore ukáže, ktoré polia sú ručne upravené.
- **Zrušená udalosť** vráti príspevok do konceptov. Nič sa nemaže.
- **Príspevok v koši** alebo **odpojený** (checkbox v boxe „Google kalendár“) synchronizácia nemení.
- **Opakujúca sa udalosť** = jeden príspevok na každý výskyt.
- **Celodenné udalosti** sa preskakujú.
- Text príspevku sa nastaví len pri vytvorení; neskoršie zmeny popisu sa do textu neprenášajú.

## Keď niečo zlyhá

- Chyba sa zobrazí ako upozornenie v celej administrácii a príde e-mail (najviac jeden denne).
- Nová, zrušená alebo neúplná akcia → súhrnný e-mail (dá sa vypnúť).
- Log posledných behov je v **Settings → EA GCal Sync**.
- WP-Cron sa spúšťa návštevou webu. Keď treba hneď, klikni na **Synchronizovať teraz**.

## Aktualizácie

Po prvej inštalácii z GitHub Release sa ďalšie verzie ukazujú vo WordPresse ako bežná aktualizácia (vyžaduje verejný repozitár).

Vydanie novej verzie, dá sa aj z tabletu:

1. Zmeny v kóde: priamo na GitHube alebo cez [claude.ai/code](https://claude.ai/code).
2. **Releases → Draft a new release → tag `v0.2.0` → Publish.**
3. Workflow `release.yml` pustí testy, doplní verziu, zbalí ZIP a pripojí ho k release.
4. WordPress → **Dashboard → Updates** → aktualizovať.

## Vývoj

```bash
php tests/unit.php            # čisté funkcie (alebo: docker run --rm -v "$PWD":/app -w /app php:7.4-cli php tests/unit.php)
tests/e2e/run.sh [6.7.5]      # WordPress + MariaDB + ACF v Dockeri, Google API simulované
bin/build-zip.sh              # dist/ea-gcal-sync.zip z aktuálneho commitu
```

Štruktúra: `ea-gcal-sync/includes/` – `parse.php` (čisté funkcie), `google.php` (service account, Calendar API), `sync.php` (pravidlá synchronizácie), `fields.php` (ACF polia), `organizers.php`, `admin.php`, `metabox.php`, `updater.php`.

Požiadavky: WordPress 6.0+, PHP 7.4+ s OpenSSL, ACF (Pro pre stránku Organizátori).

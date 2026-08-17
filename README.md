# Serverloggen – PHP-prototyp

Krav: PHP 8 med `pdo_sqlite`/`sqlite3` (finns i den lokala PHP-installationen).

Starta från denna mapp:

```powershell
php -S localhost:8080 router.php
```

Öppna sedan http://localhost:8080. Databasen skapas automatiskt i `data/serverlogg.sqlite` och fylls med två demoföretag. Mappen `data` måste vara skrivbar för webbservern.

Standardinloggning är `admin` med lösenordet `password`. Skapa en ny personlig användare under Administration och ta därefter bort standardkontot. Sessioner gäller i två timmar och sidan återgår automatiskt till inloggningen när sessionen löper ut.

Under **Att följa upp** kan en företagsvis rapport öppnas. Använd webbläsarens utskriftsdialog för att skriva ut den eller välja **Spara som PDF**.

Under **Backup och återställning** kan en exporterad EventLogger JSON-fil importeras. Importen ersätter företag, servrar och loggar i en transaktion men behåller användarkonton. Ta alltid en ny export före återställning.

## Databasmotor

SQLite används som standard. För MySQL, kopiera `.env.example` till `.env`, aktivera MySQL-raderna och fyll i anslutningsuppgifterna. Skapa den tomma databasen först; tabeller och demodata skapas automatiskt vid första starten. `.env` och SQLite-databasen ignoreras av Git.

### SQLite på webbserver

PHP-processen måste kunna skriva i hela `data`-mappen, inte bara i själva databasfilen. SQLite skapar även `serverlogg.sqlite-wal` och `serverlogg.sqlite-shm`. Exempel på Linux med webbserveranvändaren `www-data`:

```bash
sudo chown -R www-data:www-data data
sudo chmod 775 data
```

Anpassa användaren efter webbhotellet. Om mappen inte är skrivbar visar inloggningssidan ett installationsfel med den exakta sökvägen i stället för ett generellt HTTP 500-fel.

`.env` läses direkt av applikationen och fungerar även på webbhotell där PHP-funktionen `putenv()` är avstängd.

### Skydda `.env` och databasen

Projektets `.htaccess` blockerar punktfiler och databasfiler på Apache. `web.config` gör motsvarande på IIS. PHP:s inbyggda server ska startas med `router.php` enligt kommandot ovan. Kontrollera efter publicering att både `/.env` och `/data/serverlogg.sqlite` ger 403 eller 404.

Det säkraste alternativet är att placera konfigurationsfilen utanför webbrooten och ange dess absoluta sökväg i servervariabeln `EVENTLOGGER_ENV_FILE`. Exempel:

```text
EVENTLOGGER_ENV_FILE=/home/konto/private/eventlogger.env
```

För Nginx ska följande dessutom finnas i webbplatsens serverblock:

```nginx
location ~ /\. { deny all; }
location ^~ /data/ { deny all; }
```

Prototypen har en autosparande start- och sluttid per företag och loggdatum, autosparande kryssrutor och kommentarer, företagsfältet **Bra att veta**, serverunika aktiva fält, valbar serverordning, uppföljningslista, JSON-export, aktiva/inaktiva företag och servrar samt administration/radering av loggar. Öppna vyer kontrollerar ändringar varannan sekund utan sidladdning.

## Automatisk FTP-publicering

Push till `main` startar `.github/workflows/deploy-ftp.yml`. Följande Repository Secrets måste finnas: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PROTOCOL`, `FTP_PORT` och `FTP_SERVER_DIR`. Workflowen laddar aldrig upp `.env`, `.git`, `.github` eller SQLite/WAL/SHM-filer.

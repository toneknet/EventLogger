# Serverloggen – PHP-prototyp

Krav: PHP 8 med `pdo_sqlite`/`sqlite3` (finns i den lokala PHP-installationen).

Starta från denna mapp:

```powershell
php -S localhost:8080
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

Prototypen har en autosparande start- och sluttid per företag och loggdatum, autosparande kryssrutor och kommentarer, företagsfältet **Bra att veta**, serverunika aktiva fält, valbar serverordning, uppföljningslista, JSON-export, aktiva/inaktiva företag och servrar samt administration/radering av loggar. Öppna vyer kontrollerar ändringar varannan sekund utan sidladdning.

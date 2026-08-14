# Serverloggen – PHP-prototyp

Krav: PHP 8 med `pdo_sqlite`/`sqlite3` (finns i den lokala PHP-installationen).

Starta från denna mapp:

```powershell
php -S localhost:8080
```

Öppna sedan http://localhost:8080. Databasen skapas automatiskt i `data/serverlogg.sqlite` och fylls med två demoföretag. Mappen `data` måste vara skrivbar för webbservern.

Prototypen har autosparande start- och sluttid, kryssrutor och kommentarer, företagsfältet **Bra att veta**, serverunika aktiva fält, valbar serverordning, aktiva/inaktiva företag och servrar samt administration/radering av loggar. Öppna vyer kontrollerar ändringar varannan sekund utan sidladdning.

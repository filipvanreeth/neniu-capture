# Neniu Capture

Applicatienaam: **Neniu Capture**. Het beoogde productiesubdomein is
`capture.neniu.help`; configureer DNS, HTTPS en de document root (`public/`)
in de hostingomgeving. Dit stelt de applicatie zelf niet in.

De PHP-namespace is `Neniu\Capture`, de Composer-package `neniu/capture` en
de npm-package `neniu-capture`. Bestaande `/verify/{code}`-routes en het
SQLite-bestand `storage/verify.sqlite` blijven behouden.

Foto- en locatieverificatie met PHP en SQLite.

## Vertalingen

Engels is de standaardtaal. Alle applicatieteksten staan in
`translations/en.php` en worden door PHP en JavaScript gedeeld.
Voeg voor een extra taal bijvoorbeeld `translations/nl.php` toe met dezelfde
sleutels en geef `['en', 'nl']` als ondersteunde talen mee aan de `Translator` in `public/index.php`.
Open vervolgens `?lang=nl`; verificatielinks en uploads behouden deze taal.
Ontbrekende vertalingen en onbekende talen vallen terug op Engels.
Gebruik `{message}` voor variabele tekst en `$translator->translate('sleutel', ['message' => $waarde])`
in PHP of `t('sleutel', { message: waarde })` in JavaScript.
Gebruik in Twig `{{ t('sleutel') }}`; HTML wordt automatisch geëscapet.
Browsermeldingen zoals geweigerde cameratoegang kunnen de browsertaal volgen.

## Lokaal met Lando

Installeer Lando met de PHP-plugin en een ondersteunde Docker-runtime.
De configuratie gebruikt PHP 8.3, Apache en Composer 2, volgens de
[Lando PHP-documentatie](https://docs.lando.dev/plugins/php/config.html).

```sh
lando start
lando lint
```

Open https://neniu-capture.lndo.site en vertrouw het lokale Lando-certificaat
zodat de browser camera- en locatietoegang toestaat.
Composer wordt tijdens de build automatisch uitgevoerd en controleert ook
of de SQLite-extensie beschikbaar is. SQLite gebruikt de bestaande map
`storage/` in het project; de gegevens blijven bij het stoppen bewaard.

Handige commando's:

```sh
lando composer install
lando composer check-platform-reqs
lando php -v
lando stop
```

Voer na wijzigingen aan `.lando.yml` of `.lando/php.ini` `lando rebuild -y` uit.

## Installatie

Vereist: PHP 8.1 of hoger, Composer en de PHP-extensies PDO en pdo_sqlite.

```sh
composer install
composer lint
```

De applicatie laadt de klassen in `src/` via Composer PSR-4-autoloading
(`vendor/autoload.php`).
SQLite maakt `storage/verify.sqlite` automatisch aan. De PHP-gebruiker moet
schrijfrechten op `storage/` hebben. Bestaande JSON-records worden bij het
openen van hun verificatielink geïmporteerd en blijven als back-up bewaard.

## Deployment

```sh
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
```

Gebruik HTTPS voor camera- en locatietoegang. Stel de document root van de
webserver uitsluitend in op de absolute map `public/`, nooit op de projectroot.
Alleen `public/index.php`, `public/.htaccess` en de gebouwde frontendbestanden
staan binnen de webroot. Opslag, configuratie, templates en afhankelijkheden
blijven daarbuiten. De `.htaccess` in de projectroot weigert toegang bij een
onjuiste document root; `storage/.htaccess` blijft een extra beveiliging.

Apache moet `mod_rewrite` en de regels in `public/.htaccess` toestaan.
De publieke configuratie schakelt directorylisting uit en blokkeert dotfiles,
waaronder `build/.vite/manifest.json`. PHP kan het manifest wel lokaal lezen.
Voor Nginx gebruik je bijvoorbeeld (pas het pad en de PHP-upstream aan):

```nginx
root /srv/neniu-capture/public;
index index.php;

location ~ /\. { deny all; }
location / { try_files $uri $uri/ /index.php?$query_string; }
location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
location ~ \.php$ { return 404; }
```

De ingebouwde PHP-webserver is alleen bedoeld voor lokale ontwikkeling.
Start deze met `php -S localhost:8000 -t public dev-router.php`; de ontwikkelrouter
geeft uitsluitend gebouwde assets rechtstreeks vrij.

Commit `composer.json` en `composer.lock`; upload bij deployment ook de
gegenereerde `vendor/`-map of voer Composer op de server uit.

## Uploadregistratie en verificatiesessies

Versie 1.1.0 breidt SQLite automatisch uit bij het openen van de applicatie.
Bestaande records en foto's blijven behouden; oudere records hebben geen nieuwe
metadata. Nieuwe records bevatten in de JSON-kolom `records.metadata`:

- `request` (bron: server): willekeurig verzoek-ID, ontvangsttijd in UTC met
  milliseconden, IP-adres met herkomst en applicatieversie.
- `browser` (bron: browser, niet onafhankelijk geverifieerd): User-Agent
  (maximaal 2048 bytes), opnametijd en tijdstip van de browserlocatiemeting,
  als UTC-tijden met milliseconden. De User-Agent is door de client opgegeven.
- `image` (bron: ontvangen bestand): breedte, hoogte en bestandsgrootte in bytes.
- `verification_session` (bron: server): sessie-ID, uitgiftetijd en vervaltijd.

`created_at` is voor nieuwe records het ontvangsttijdstip van het uploadverzoek.
De coördinaten en nauwkeurigheid blijven opgeslagen als `browser_geolocation`;
ze zijn niet noodzakelijk van GPS afkomstig. Browserklok, locatie en User-Agent
kunnen worden gemanipuleerd. Houd de serverklok gesynchroniseerd.
De extra metadata wordt opgeslagen, maar niet toegevoegd aan de publieke
verificatiepagina.

De browser vraagt vóór iedere opname met `POST action=session` een token aan.
Dat token is tien minuten geldig en wordt met `action=save` meegestuurd als
`session_token`, samen met `captured_at` en `location_timestamp` (Unix-tijd in
milliseconden). De server bewaart alleen de SHA-256-hash van het token.
Opslag van het record en verbruik van het token gebeuren in één transactie.
Onbekende, verlopen en gebruikte tokens leveren HTTP 409 op. Bij een nieuwe
opnamepoging vraagt de browser een nieuw token aan. De sessie koppelt een
inzending aan de tokenuitgifte; ze bewijst geen identiteit of live opname.

### Vertrouwde proxies

Standaard wordt alleen `REMOTE_ADDR` gebruikt. Stel achter een reverse proxy
of CDN de omgevingsvariabele `TRUSTED_PROXIES` in met een kommagescheiden lijst
van exacte proxy-IP-adressen (IPv4/IPv6, geen CIDR), bijvoorbeeld:

```text
TRUSTED_PROXIES=192.0.2.10,2001:db8::10
```

Gebruik uitsluitend de werkelijke adressen van je eigen vertrouwde proxies.
De server verwerkt `X-Forwarded-For` van rechts naar links zolang de huidige
hop vertrouwd is, en stopt bij het eerste niet-vertrouwde adres. Een ongeldige
keten valt terug op `REMOTE_ADDR`. Andere forwardingheaders worden genegeerd.
Configureer je proxy om deze header correct te zetten; voeg geen willekeurige
clientnetwerken aan de vertrouwenslijst toe.

### Controles

```sh
composer lint
composer test
```

De tests gebruiken een tijdelijke database en controleren schemamigratie,
metadatabehoud, proxyvertrouwen, timestamps, eenmalig gebruik, verval en rollback.


## Codestructuur

- `public/index.php`: maakt de applicatie aan en laat Twig de juiste pagina renderen.
- `src/Application.php`: verwerkt upload-, sessie- en verificatieverzoeken.
- `src/Database.php`: maakt de SQLite-verbinding en voert schemamigraties uit.
- `src/RecordRepository.php`: bewaart en leest records, inclusief JSON-import.
- `src/VerificationService.php`: geeft tokens uit en slaat inzendingen atomair op.
- `src/ClientIpResolver.php`: bepaalt het client-IP met vertrouwde proxies.
- `src/Clock.php`: levert UTC-tijden en converteert browsertimestamps.

Voer na het bijwerken van de autoloadconfiguratie `composer dump-autoload` uit.


## Routes

Routing gebruikt `nikic/fast-route` via `src/Router.php`:

- `GET /`: opnamepagina (`/index.php` blijft ook werken).
- `POST /`: sessie aanvragen (`action=session`) of uploaden (`action=save`).
- `GET /verify/{code}`: afzonderlijke verificatiepagina voor een code van 32
  hexadecimale tekens. Een ontbrekend record geeft HTTP 404.

De taal blijft in `?lang=en` staan. Verificatie verloopt uitsluitend via
`/verify/{code}`. Onbekende routes geven HTTP 404;
onjuiste methodes HTTP 405 met een `Allow`-header.

Apache moet `mod_rewrite` en `.htaccess`-regels toestaan. De rootconfiguratie
in `public/.htaccess` stuurt niet-bestaande paden naar `public/index.php`. Configureer bij Nginx een
vergelijkbare front-controllerregel en behoud de blokkering van opslag en vendor.
Lokaal kan de applicatie worden gestart met `php -S localhost:8000 -t public dev-router.php`.


## Twig-templates

`src/TemplateRenderer.php` configureert Twig met HTML-autoescaping en strikte
variabelen. De pagina's zijn opgesplitst in:

- `templates/base.html.twig`: gedeelde HTML-structuur en styling.
- `templates/capture.html.twig`: opnamepagina en camerabediening.
- `templates/verify.html.twig`: verificatieresultaat of melding bij ontbrekend record.

De Twig-functie `t()` gebruikt hetzelfde `Translator`-object als de applicatie. `json_script()`
serialiseert waarden veilig voor het JSON-configuratieblok van de capturepagina.
`composer test` controleert ook de templates en escaping.


## Frontend: npm en Vite

Gebruik Node.js 22.12 of hoger. PHP, FastRoute en Twig blijven de pagina's en
endpoints verzorgen. JavaScript gebruikt gewone ES-modules, zonder framework:

- `assets/js/capture.js`: initialisatie en camerabediening.
- `assets/js/camera.js`: camerastream en camerawissel.
- `assets/js/location.js`: browserlocatie.
- `assets/js/verification-api.js`: sessies en uploads.
- `assets/js/photo.js`: opname en fotopreview zonder tekstoverlay.
- `assets/css/app.css`: gedeelde styling.

Installeer en bouw de frontend na een checkout:

```sh
npm ci
npm run build
npm test
```

Vite schrijft bestanden met hashes en `.vite/manifest.json` naar `public/build/`.
`src/ViteAssets.php` leest het manifest en maakt URL's die ook onder een submap
werken. Twig laadt de gedeelde CSS op beide pagina's en de capturemodule alleen
op de opnamepagina. Configuratie (taal, vertalingen, endpoint) wordt veilig als
JSON doorgegeven; JavaScript bevat geen Twig-code.

### Ontwikkelen met automatisch herladen

Start Vite in één terminal:

```sh
VITE_BACKEND_ORIGIN=http://localhost:8000 npm run dev
```

Start de PHP-server in een tweede terminal:

```sh
APP_ENV=development VITE_DEV_SERVER=http://localhost:5173 php -S localhost:8000 -t public dev-router.php
```

Open `http://localhost:8000`. `VITE_DEV_SERVER` moet in de PHP-omgeving of `.env` ingesteld zijn;
zonder deze variabele gebruikt PHP de productiebuild. PHP laadt het `.env`-bestand in de projectroot via de standaard phpdotenv-loader. De ontwikkelrouter serveert uitsluitend de
gebouwde assets rechtstreeks en stuurt overige paden door naar de applicatie.

### Ontwikkelen binnen Lando

De Landofile bevat een aparte Node 24-service met npm-tooling en een HTTPS-proxy
voor Vite. De Node-plugin moet geïnstalleerd zijn (zo nodig:
`lando plugin-add @lando/node`). Na de wijziging van de Landofile:

```sh
lando rebuild -y
lando npm run dev
```

Laat het tweede commando draaien en open `https://neniu-capture.lndo.site`.
De assets en HMR-WebSocket lopen via `https://vite-neniu-capture.lndo.site`;
vertrouw de Lando-CA zodat de browser beide HTTPS-hosts accepteert. De proxy
gebruikt de interne poort 5173; de browser gebruikt de normale HTTPS-poort 443.
Vite luistert binnen de container via HTTP op `0.0.0.0:5173`. De Lando-proxy
verzorgt HTTPS met de door Lando gegenereerde certificaten. Tijdens de eerste
build en bij `lando rebuild` installeert de Node-service dependencies automatisch
met `npm ci --prefer-offline --no-audit --no-fund`. De Vite-server start via
`lando npm run dev`.

Andere beschikbare commando's:

```sh
lando node --version
lando npm run build
lando npm test
```

### Wisselen tussen development en productiepreview

`npm run dev` start de ontwikkelserver; `npm run build` genereert de
productiebestanden. Deze commando's veranderen `APP_ENV` niet: de PHP-omgeving
bepaalt welke assets de frontend gebruikt.

De assetmodus wordt gekozen via `.env`, zonder vaste PHP-overrides in Lando.
Voor development met Vite gebruik je:

```dotenv
APP_ENV=development
VITE_DEV_SERVER=https://vite-neniu-capture.lndo.site
```

Laat daarbij `lando npm run dev` draaien. Voor een productiepreview gebruik je:

```dotenv
APP_ENV=production
VITE_DEV_SERVER=
```

Voer `lando npm run build` uit en herlaad de pagina. De frontend gebruikt dan
uitsluitend de bestanden uit `public/build/`, via URL's als
`/build/assets/app-<hash>.css` en `/build/assets/capture-<hash>.js`.
In productie wordt een eventueel ingevulde `VITE_DEV_SERVER` genegeerd.
Vite hoeft niet te draaien. Na deze eenmalige Landofile-wijziging is een rebuild
nodig om oude containeroverrides te verwijderen; daarna volstaat een wijziging
van `.env` en het herladen van de pagina. Extern ingestelde servervariabelen
houden zoals gebruikelijk voorrang boven `.env`.

### CI/CD en deployment

Een CI/CD-runner heeft geen Lando nodig. Gebruik Node.js 22.12 of hoger en
PHP met de vereiste extensies. Voer vanuit de projectroot uit:

```sh
npm ci
npm run build
composer install --no-dev --optimize-autoloader --no-interaction
```

`npm ci` gebruikt de vastgelegde versies uit `package-lock.json`. Zorg dat ook
de ontwikkelafhankelijkheden van npm worden geïnstalleerd: Vite en Tailwind zijn
nodig tijdens de build. Bij een runner met `NODE_ENV=production` of een ingestelde
`omit=dev` gebruik je hiervoor `npm ci --include=dev`.

Voer in de buildomgeving `npm ci` en `npm run build` uit. Publiceer de volledige
map `public/build/`, inclusief `.vite/manifest.json`, samen met PHP, templates en
Composer-afhankelijkheden. De document root moet naar `public/` wijzen; houd de overige projectmappen erbuiten. Laat
`VITE_DEV_SERVER` op productie weg. Node.js is op de productieserver niet nodig.
`node_modules/` en de gegenereerde build worden niet in Git opgenomen;
`package-lock.json` wel. Stel op de productieserver `APP_ENV=production` in
en laat `VITE_DEV_SERVER` leeg of weg. Publiceer PHP, templates, manifest en de
bijbehorende gebouwde assets als één consistente release.

Behoud bij deployment de bestaande `storage/` en productie-`.env`; neem deze
niet over uit de checkout of lokale ontwikkelomgeving. De build is lokaal
gecontroleerd; er is nog geen specifieke CI/CD-pipeline in deze repository ingericht.

`npm test` controleert camerawissel, geweigerde camera- en locatietoegang en
upload- en netwerkfouten met nagebootste browser-API's. Echte toestelpermissies
en camerabeelden moeten daarnaast handmatig in de browser worden gecontroleerd.


## Tailwind CSS

De frontend gebruikt Tailwind CSS 4 via de officiële `@tailwindcss/vite`-plugin.
`assets/css/app.css` importeert Tailwind, stelt het systeemlettertype in en
registreert `templates/` expliciet als bron voor utilityclasses. Gebruik volledige
classnamen in Twig; bouw classnamen niet uit losse dynamische delen op, omdat
de scanner die niet kan herkennen. Gedeelde knopclasses staan als volledige
string in de capturetemplate.

De bestaande commando's blijven gelijk: `lando npm run dev` voor ontwikkeling
en `lando npm run build` voor productie. Tailwind genereert alleen CSS en voegt
geen JavaScript-runtime toe. De geïnstalleerde versies staan vastgelegd in
`package-lock.json`.


## Vertaalobject

`src/Translator.php` beheert de taalkeuze, Engelse fallback, vertaalberichten en
parametervervanging zonder globale variabelen of PHP-helperfuncties. Het object
wordt in `public/index.php` aangemaakt en geïnjecteerd in `Application` en
`TemplateRenderer`. Twig blijft `t()` gebruiken; JavaScript ontvangt een kopie
van de berichten via het JSON-configuratieblok.


## PHP-codestijl

PHP_CodeSniffer controleert PHP-code op PSR-12 met `phpcs.xml.dist`.
PHPCBF past automatisch herstelbare stijlproblemen aan:

```sh
composer cs
composer cs:fix
```

Binnen Lando: `lando composer cs` en `lando composer cs:fix`.
Na een rebuild zijn ook de shortcuts `lando cs` en `lando cs-fix` beschikbaar.
De controle omvat de frontcontroller, klassen, vertalingen, PHP-tests en lokale
ontwikkelrouter; dependencies en gegenereerde bestanden worden niet meegenomen.
Controleer na formatteren de resterende meldingen met `composer cs` en voer
`composer lint` en `composer test` uit. De formatter is een ontwikkelafhankelijkheid
en wordt bij `composer install --no-dev` niet op productie geïnstalleerd.


## Omgevingsconfiguratie en .env

PHP laadt `.env` rechtstreeks in `public/index.php` met
`Dotenv::createImmutable($projectRoot)->safeLoad()`, zonder eigen configuratieklasse. Kopieer bij installatie
`.env.example` naar `.env` in de projectroot (naast `composer.json`, buiten
`public/`) en beperk de bestandsrechten, bijvoorbeeld met `chmod 600 .env`.
`.env` en `.env.*` worden door Git genegeerd; alleen `.env.example` wordt gedeeld.
Bestaande server-/containeromgevingsvariabelen hebben voorrang boven `.env`.
PHP laadt alleen `.env`, geen `.env.local` of omgevingsspecifieke varianten.
Instellingen worden rechtstreeks gelezen uit `$_ENV` en `$_SERVER`;
`getenv()` wordt niet door de loader aangepast.

- `APP_ENV`: `production` (standaard), `development` of `testing`.
- `STORAGE_PATH`: standaard `storage`, relatief aan de projectroot of absoluut.
  Kies altijd een pad buiten `public/`.
- `TRUSTED_PROXIES`: kommagescheiden exacte proxy-IP-adressen, standaard leeg.
- `VITE_DEV_SERVER`: wordt alleen gebruikt bij development/testing en genegeerd op productie.

Zonder `.env` blijven de standaardinstellingen van toepassing. Een onleesbaar
of ongeldig `.env`-bestand levert een algemene HTTP 500 op, zonder
configuratiewaarden of parserdetails in de respons. PHP-fouten worden gelogd; foutweergave staat standaard uit.
De lokale `.env` staat op productiepreview; Lando legt de PHP-assetmodus niet vast. Publiceer de lokale `.env` niet op productie;
maak daar een apart bestand of gebruik de serveromgeving.

Vite leest alleen `VITE_`-instellingen voor zijn configuratie en geeft door
`envPrefix: []` geen .env-waarden automatisch aan browsercode door. Gebruik
nooit geheimen voor browserconfiguratie; Twig geeft alleen expliciet gekozen
publieke gegevens mee. Een `.env`-bestand versleutelt gegevens niet en vervangt
geen toegangsbeveiliging of veilig secretbeheer. Plaats er alleen werkelijk
gebruikte instellingen in; de applicatie heeft momenteel geen APP_KEY nodig.


## PHP-instellingen

Alle PHP-ini-instellingen staan in `.lando/php.ini`, waaronder uploadlimieten,
geheugenlimiet, tijdzone, `error_reporting`, `display_errors`,
`display_startup_errors` en `log_errors`. De applicatie gebruikt geen `ini_set()`
of `error_reporting()` en kent geen `APP_DEBUG`-instelling.

Lando laadt dit bestand via `services.appserver.config.php`. Voer na wijzigingen
`lando rebuild -y` uit om ze toe te passen. Neem voor productie dezelfde
instellingen over in de server-`php.ini` of een aanvullend `.ini`-bestand in de
PHP-configuratiemap en herlaad PHP-FPM/Apache. Configureer ook het logdoel daar.
Laat foutweergave op productie uitgeschakeld; eventuele lokale afwijkingen horen
uitsluitend in de PHP-configuratie, niet in `.env` of applicatiecode.


### Frontenddependencies in Lando

De Node-build voert `npm ci --prefer-offline --no-audit --no-fund` uit.
Na een wijziging van `package-lock.json` kun je `lando npm ci` uitvoeren zonder
de containers opnieuw op te bouwen. Een audit kan afzonderlijk met
`lando npm audit` worden uitgevoerd.

Na wijzigingen in `.lando.yml` is een rebuild nodig. Downloads kunnen de eerste
build vertragen; laat niet meerdere start-/rebuildcommando's tegelijk draaien.


### Npm-cache behouden bij een rebuild

De Node-service gebruikt `/app/.lando/npm-cache` als npm-cache. Deze map staat
op de projectmount, buiten `public/`, en wordt niet gecommit. Daardoor blijven
opgehaalde pakketten beschikbaar wanneer Lando de Node-container opnieuw maakt.
`npm ci` blijft bij iedere build de dependencies volgens het lockbestand opnieuw
installeren, maar kan daarvoor de bewaarde pakketbestanden gebruiken.
De build toont downloadvoortgang en beperkt de retry-/time-outinstellingen.
Een eerste download of een nieuw pakket kan nog steeds tijd kosten.

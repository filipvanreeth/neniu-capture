# Neniu Verify

Foto- en locatieverificatie met PHP en SQLite.

## Vertalingen

Engels is de standaardtaal. Alle applicatieteksten staan in
`translations/en.php` en worden door PHP en JavaScript gedeeld.
Voeg voor een extra taal bijvoorbeeld `translations/nl.php` toe met dezelfde
sleutels en registreer `nl` in `$supportedLocales` in `i18n.php`.
Open vervolgens `?lang=nl`; verificatielinks en uploads behouden deze taal.
Ontbrekende vertalingen en onbekende talen vallen terug op Engels.
Gebruik `{message}` voor variabele tekst en `t('sleutel', ['message' => $waarde])`
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

Open https://neniu-verify.lndo.site en vertrouw het lokale Lando-certificaat
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

Gebruik HTTPS voor camera- en locatietoegang. Op Apache moet `.htaccess`
ingeschakeld zijn zodat `storage/.htaccess` de opslagmap afschermt.
Configureer bij andere webservers een overeenkomstige blokkering voor
`/storage/` en `/vendor/`. De ingebouwde PHP-webserver is alleen bedoeld
voor lokale ontwikkeling en past `.htaccess` niet toe.

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

- `index.php`: maakt de applicatie aan en laat Twig de juiste pagina renderen.
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
stuurt niet-bestaande paden naar `index.php`. Configureer bij Nginx een
vergelijkbare front-controllerregel en behoud de blokkering van opslag en vendor.
Lokaal kan de applicatie worden gestart met `php -S localhost:8000 dev-router.php`.


## Twig-templates

`src/TemplateRenderer.php` configureert Twig met HTML-autoescaping en strikte
variabelen. De pagina's zijn opgesplitst in:

- `templates/base.html.twig`: gedeelde HTML-structuur en styling.
- `templates/capture.html.twig`: opnamepagina en camerabediening.
- `templates/verify.html.twig`: verificatieresultaat of melding bij ontbrekend record.

De Twig-functie `t()` gebruikt dezelfde vertalingen als PHP. `json_script()`
serialiseert waarden veilig voor het JSON-configuratieblok van de capturepagina.
`composer test` controleert ook de templates en escaping.


## Frontend: npm en Vite

Gebruik Node.js 22.12 of hoger. PHP, FastRoute en Twig blijven de pagina's en
endpoints verzorgen. JavaScript gebruikt gewone ES-modules, zonder framework:

- `assets/js/capture.js`: initialisatie en camerabediening.
- `assets/js/camera.js`: camerastream en camerawissel.
- `assets/js/location.js`: browserlocatie.
- `assets/js/verification-api.js`: sessies en uploads.
- `assets/js/photo.js`: opname en aparte geannoteerde preview.
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
VITE_DEV_SERVER=http://localhost:5173 php -S localhost:8000 dev-router.php
```

Open `http://localhost:8000`. `VITE_DEV_SERVER` moet in de omgeving van PHP staan;
zonder deze variabele gebruikt PHP de productiebuild. Een `.env`-bestand wordt
door PHP niet automatisch geladen. De ontwikkelrouter serveert uitsluitend de
gebouwde assets rechtstreeks en stuurt overige paden door naar de applicatie.

### Ontwikkelen binnen Lando

De Landofile bevat een aparte Node 24-service met npm-tooling en een HTTPS-proxy
voor Vite. De Node-plugin moet geïnstalleerd zijn (zo nodig:
`lando plugin-add @lando/node`). Na de wijziging van de Landofile:

```sh
lando rebuild -y
lando npm run dev
```

Laat het tweede commando draaien en open `https://neniu-verify.lndo.site`.
De assets en HMR-WebSocket lopen via `https://vite-neniu-verify.lndo.site`;
vertrouw de Lando-CA zodat de browser beide HTTPS-hosts accepteert. De proxy
gebruikt de interne poort 5173; de browser gebruikt de normale HTTPS-poort 443.
Vite luistert binnen de container via HTTP op `0.0.0.0:5173`. De Lando-proxy
verzorgt HTTPS met de door Lando gegenereerde certificaten. npm-afhankelijkheden worden tijdens de Node-build
met `npm ci` geïnstalleerd. De Vite-server start bewust via `lando npm run dev`.

Andere beschikbare commando's:

```sh
lando node --version
lando npm run build
lando npm test
```

PHP gebruikt in deze Lando-configuratie standaard de Vite-ontwikkelserver.
Om lokaal de productiebuild te bekijken, voer `lando npm run build` uit en zet
in een niet-gecommitteerde `.lando.local.yml`:

```yaml
services:
  appserver:
    overrides:
      environment:
        VITE_DEV_SERVER: ''
```

Voer daarna opnieuw `lando rebuild -y` uit. Verwijder de override om terug te
schakelen naar Vite. Zonder draaiende Vite-server is de ontwikkelconfiguratie
niet bruikbaar; de productiebuild wordt alleen geladen als `VITE_DEV_SERVER`
leeg is. Voor een afwijkende externe HTTPS-poort pas je zowel de ingestelde
origins als de PHP-asset-URL aan.

### Deployment

Voer in de buildomgeving `npm ci` en `npm run build` uit. Publiceer de volledige
map `public/build/`, inclusief `.vite/manifest.json`, samen met PHP, templates en
Composer-afhankelijkheden. De projectroot blijft de webroot. Laat
`VITE_DEV_SERVER` op productie weg. Node.js is op de productieserver niet nodig.
`node_modules/` en de gegenereerde build worden niet in Git opgenomen;
`package-lock.json` wel. Behoud het bestaande SQLite-bestand bij deployment.

`npm test` controleert camerawissel, geweigerde camera- en locatietoegang en
upload- en netwerkfouten met nagebootste browser-API's. Echte toestelpermissies
en camerabeelden moeten daarnaast handmatig in de browser worden gecontroleerd.

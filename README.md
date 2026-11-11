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
Escape vertalingen in HTML met `e(t('sleutel'))`.
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

- `index.php`: maakt de applicatie aan en rendert de bestaande pagina.
- `src/Application.php`: verwerkt upload-, sessie- en verificatieverzoeken.
- `src/Database.php`: maakt de SQLite-verbinding en voert schemamigraties uit.
- `src/RecordRepository.php`: bewaart en leest records, inclusief JSON-import.
- `src/VerificationService.php`: geeft tokens uit en slaat inzendingen atomair op.
- `src/ClientIpResolver.php`: bepaalt het client-IP met vertrouwde proxies.
- `src/Clock.php`: levert UTC-tijden en converteert browsertimestamps.

Voer na het bijwerken van de autoloadconfiguratie `composer dump-autoload` uit.

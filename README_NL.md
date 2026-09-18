# Festivalinfo MusicBrainz Artist Matcher

Een semi-automatische workflow om artiesten uit Festivalinfo te koppelen aan **MusicBrainz** en later ook aan **Spotify**.

Het project is opgebouwd rond één belangrijk uitgangspunt:

> **Een verkeerde artiestenkoppeling is schadelijker dan geen automatische koppeling.**

Artiestennamen kunnen dubbelzinnig zijn, op verschillende manieren geschreven worden of bijvoorbeeld verwijzen naar tribute-acts en samengestelde artiestennamen. Daarom vertrouwt de matcher niet op één enkele score, maar gebruikt hij meerdere signalen. Twijfelgevallen worden doorgestuurd naar handmatige controle.

---

## Doel

Festivalinfo bevat artiesten waarvoor nog niet altijd een externe platform-ID bekend is.

Dit project heeft als doel om:

- een waarschijnlijke MusicBrainz-artiest te vinden voor een Festivalinfo-artiest;
- verkeerde automatische matches zoveel mogelijk te voorkomen;
- gevonden kandidaten en bewijs/signalen op te slaan;
- twijfelgevallen naar handmatige review te sturen;
- een historie van beslissingen bij te houden;
- tijdelijke API-fouten later opnieuw te kunnen proberen;
- uiteindelijk bevestigde MusicBrainz-data te gebruiken om Spotify-koppelingen te verbeteren.

De huidige versie richt zich voornamelijk op **MusicBrainz**.

---

## Matching-flow

De huidige workflow ziet er ongeveer zo uit:

```text
Festivalinfo-artiest
        |
        v
Bestaande platformlinks controleren
        |
        v
MusicBrainz doorzoeken
        |
        v
Beste kandidaten verzamelen
        |
        v
Extra MusicBrainz-metadata ophalen
        |
        v
Matchsignalen beoordelen
        |
        +-----------------------------+
        |                             |
        v                             v
Sterk bewijs                  Twijfel / conflict
        |                             |
        v                             v
matched                    review / no_match
        |
        v
Kandidaat, beslissing en status opslaan
```

De huidige dry-run publiceert **geen nieuwe MusicBrainz-links naar `platform_link`**.

---

## Matchsignalen

De matcher gebruikt momenteel meerdere signalen.

### Naamvergelijking

Artiestennamen worden eerst genormaliseerd voordat ze met elkaar worden vergeleken.

Voorbeelden:

- omzetten naar kleine letters;
- bepaalde leestekens verwijderen;
- witruimte opschonen.

Daarna wordt gecontroleerd op:

- exacte match van de genormaliseerde naam;
- match met een MusicBrainz-alias.

### Verschil tussen kandidaat 1 en 2

MusicBrainz geeft zelf een relevantiescore terug.

De matcher vergelijkt de score van kandidaat 1 met kandidaat 2. Een groter verschil geeft extra vertrouwen dat de eerste kandidaat duidelijk beter past.

> De huidige margin-threshold is experimenteel en is nog geen definitieve productieregel.

### Samengestelde artiestennamen

Namen zoals:

```text
Artist A feat. Artist B
Artist A ft. Artist B
Artist A vs. Artist B
```

worden als risicovoller behandeld.

Deze worden niet automatisch geaccepteerd en gaan normaal gesproken naar `review`.

### Dubbele MusicBrainz-ID's

Voordat een kandidaat automatisch geaccepteerd kan worden, controleert het systeem of dezelfde MusicBrainz-ID al gekoppeld is aan een andere Festivalinfo-artiest.

Als dat zo is, gaat de match naar handmatige controle.

### Onafhankelijk Spotify-signaal

Als Festivalinfo al een Spotify-ID voor de artiest heeft, controleert het systeem of MusicBrainz een URL-relatie naar dezelfde Spotify-artiest bevat.

Hierdoor ontstaat onafhankelijk bewijs naast alleen de artiestennaam.

---

## Statussen

De workflow ondersteunt de volgende statussen:

```text
unprocessed
processing
matched
review
no_match
retry
failed
not_applicable
```

### Betekenis

**unprocessed**  
De artiest is nog niet verwerkt.

**processing**  
Een worker is de artiest momenteel aan het verwerken.

**matched**  
Er is voldoende bewijs gevonden voor een sterke match.

**review**  
De match is onzeker en moet handmatig gecontroleerd worden.

**no_match**  
Er is geen geloofwaardige MusicBrainz-kandidaat gevonden.

**retry**  
Er is een tijdelijke API- of netwerkfout opgetreden.

---

## Database-opzet

De nieuwere workflow gebruikt drie generieke tabellen.

### `artist_external_match_candidate`

Hierin worden gevonden externe kandidaten opgeslagen.

Belangrijke velden:

```text
artist_id
platform_id
external_identifier
candidate_name
candidate_disambiguation
source_method
provider_rank
provider_score
decision_score
evidence
status
query_variant
observed_at
run_id
```

Het veld `evidence` bevat JSON met signalen zoals:

```json
{
  "exact_name": true,
  "alias_match": false,
  "margin_to_second": 24,
  "duplicate_external_id": false,
  "spotify_relation_match": true,
  "composite_name": false
}
```

---

### `artist_external_match_decision`

Deze tabel bevat de beslissingsgeschiedenis.

Hiermee kan later worden teruggekeken:

- welk besluit is genomen;
- bij welke kandidaat het besluit hoorde;
- waarom het besluit is genomen;
- welke run het besluit heeft geproduceerd;
- of het besluit automatisch of redactioneel is genomen.

---

### `artist_external_match_state`

Deze tabel bevat de huidige workflowstatus van iedere artiest.

Belangrijke velden:

```text
artist_id
musicbrainz_status
spotify_status
attempt_count
next_attempt_at
locked_at
locked_by
last_error
resolved_by
resolved_at
```

Deze tabel vormt de basis voor een restartbare worker-flow.

---

## Prioriteit voor actieve artiesten

De matcher geeft momenteel voorrang aan artiesten met toekomstige Festivalinfo-activiteit.

Daarvoor wordt gekeken naar:

- toekomstige evenementen;
- toekomstige festivals;
- festivals die niet zijn afgelast.

Artiesten die al een MusicBrainz-link in `platform_link` hebben, worden overgeslagen.

Hierdoor wordt eerst gewerkt aan artiesten die op dit moment het meest relevant zijn.

---

## MusicBrainz API

Het project gebruikt de publieke MusicBrainz WS/2 API.

Belangrijke endpoints:

```text
/artist/?query=...
/artist/{mbid}?inc=aliases+url-rels
```

De client bevat onder andere:

- een herkenbare User-Agent;
- JSON-responses;
- een request-timeout;
- rate limiting;
- retry-logica voor tijdelijke fouten;
- afhandeling van HTTP 429 en 503.

De huidige minimale tijd tussen requests is ongeveer **1,2 seconde**.

---

## Bestanden

### `MusicBrainzClient.php`

Herbruikbare MusicBrainz API-client.

Verantwoordelijk voor:

- zoeken naar artiesten;
- detailinformatie ophalen;
- rate limiting;
- retries;
- HTTP-requests.

### `dry_run_musicbrainz.php`

De belangrijkste experimentele matching-workflow.

Dit script:

- selecteert actieve artiesten zonder MusicBrainz-link;
- zoekt in MusicBrainz;
- beoordeelt de beste kandidaat;
- controleert aliassen en dubbele ID's;
- vergelijkt Spotify-relaties;
- slaat kandidaten op;
- slaat beslissingen op;
- werkt de state bij;
- registreert retry-fouten;
- toont statistieken van de run.

Het script schrijft **niet** naar `platform_link`.

### `match_musicbrainz.php`

Een eerder prototype van de matcher.

Deze versie gebruikt een eenvoudiger gewogen score op basis van naamovereenkomst en de MusicBrainz-zoekscore.

Het bestand wordt bewaard als prototype en geschiedenis, maar is niet de huidige uiteindelijke matchinglogica.

### `review_musicbrainz.php`

Prototype van de handmatige reviewpagina.

Hiermee kan een gebruiker:

- gevonden kandidaten bekijken;
- de juiste MusicBrainz-artiest kiezen;
- aangeven dat geen kandidaat correct is.

### Testbestanden

De repository bevat meerdere scripts voor het testen van de matchinglogica:

```text
test_musicbrainz_client.php
test_musicbrainz_testset.php
test_musicbrainz_signals.php
test_musicbrainz_decision_signals.php
test_musicbrainz_decision.php
```

Deze testen onder andere:

- verbinding met MusicBrainz;
- ranking van kandidaten;
- aliassen;
- land en artiesttype;
- samengestelde namen;
- scoreverschillen;
- dubbele externe ID's;
- beslisregels.

---

## Testset

Er wordt een kleine gelabelde testset gebruikt om de matchinglogica te controleren.

Voorbeelden:

```text
Arctic Monkeys
Chef'Special
P!nk
Nova
Arctic Monkeys Tribute Band
Onbekende Lokale Band
Artist A feat. Artist B
```

De testset bevat bewust:

- eenvoudige matches;
- dubbelzinnige artiestennamen;
- tribute-acts;
- niet-bestaande artiesten;
- samengestelde artiestennamen.

De testset is nog klein. Automatische thresholds moeten daarom op een grotere gelabelde dataset worden getest voordat automatische publicatie in productie verstandig is.

---

## Voorbeeld van de beslislogica

Een vereenvoudigde versie van de huidige logica:

```text
Samengestelde artiestennaam?
    -> review

MusicBrainz-ID al gekoppeld aan andere artiest?
    -> review

Exacte naam of alias-match
EN voldoende margin
EN onafhankelijk Spotify-signaal?
    -> matched

Anders
    -> review
```

Als MusicBrainz geen kandidaten teruggeeft:

```text
normale artiest     -> no_match
samengestelde naam  -> review
```

Tijdelijke API-fouten worden:

```text
retry
```

---

## Dry-run statistieken

De dry-run houdt bij hoeveel artiesten eindigen als:

```text
matched
review
no_match
retry
```

Hierdoor kunnen wijzigingen in de matchingregels worden geëvalueerd voordat er echte links worden gepubliceerd.

---

## Restartbare worker-flow

De volgende fase is om de dry-run om te zetten naar een restartbare worker.

De state-tabel ondersteunt hiervoor al velden zoals:

```text
processing
attempt_count
next_attempt_at
locked_at
locked_by
```

De bedoelde flow is:

```text
1. Zoek een geschikte artiest
2. Claim de artiest
3. Zet de status op processing
4. Lock de artiest
5. Voer de matcher uit
6. Sla kandidaat + beslissing op
7. Werk de uiteindelijke status bij
8. Probeer tijdelijke fouten later opnieuw
```

Dit moet voorkomen dat meerdere workers tegelijkertijd dezelfde artiest verwerken.

---

## Veiligheidsprincipes

Het project gebruikt een aantal regels om foutieve koppelingen zoveel mogelijk te voorkomen:

- bestaande handmatige `platform_link`-koppelingen nooit overschrijven;
- dubbele externe ID's altijd naar review sturen;
- MusicBrainz-score alleen als ranking gebruiken, niet als bewijs;
- onzekere artiesten handmatig laten controleren;
- tijdelijke API-fouten als `retry` behandelen en niet als `no_match`;
- samengestelde namen voorzichtig behandelen;
- normalisatie niet te agressief maken;
- pas publiceren naar productie nadat thresholds goed zijn gevalideerd.

---

## Vereisten

Typische lokale ontwikkelomgeving:

```text
PHP 8+
MySQL 8+
cURL-extensie
mbstring-extensie
PDO MySQL
```

Tijdens ontwikkeling wordt lokaal gewerkt met de database:

```text
festivalinfo-test
```

---

## Dry-run uitvoeren

Plaats de PHP-bestanden in dezelfde lokale projectmap en zorg dat de databaseverbinding overeenkomt met je eigen omgeving.

Open daarna:

```text
dry_run_musicbrainz.php
```

via je lokale PHP-server.

Bijvoorbeeld met XAMPP:

```text
http://localhost/.../dry_run_musicbrainz.php
```

Het script toont onder andere:

- gevonden kandidaat;
- MusicBrainz-ID;
- scores;
- matchsignalen;
- uiteindelijk besluit;
- samenvatting van de run.

---

## Huidige status

### Afgerond

- MusicBrainz API-client;
- API rate limiting;
- retry-logica;
- zoekvarianten;
- exacte naamvergelijking;
- alias-matching;
- margin tussen kandidaten;
- detectie van samengestelde namen;
- detectie van dubbele MBID's;
- controle van Spotify-relaties;
- gelabelde testcases;
- dry-run workflow;
- opslaan van kandidaten;
- auditlog van beslissingen;
- state-opslag;
- retry-state;
- initialiseren van de queue met actieve artiesten.

### In ontwikkeling

- veilig claimen van één artiest;
- `processing`-locks;
- retry/backoff-planning;
- restartbare batchverwerking.

### Gepland

- redactionele review op basis van de nieuwe generieke tabellen;
- gecontroleerd publiceren naar `platform_link`;
- Spotify-matching op basis van bevestigde MusicBrainz-matches;
- grotere gelabelde evaluatieset;
- calibratie van thresholds;
- productie-monitoring.

---

## Opmerking

Deze repository bevat momenteel ontwikkel- en testcode.

De huidige thresholds en beslisregels zijn experimenteel en moeten verder worden getest voordat automatische artiestenkoppelingen veilig naar productie kunnen worden geschreven.

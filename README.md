# nl.onvergetelijk.googlesync

Eigen CiviCRM-extensie die de **notificatie-groepen** van OZK (notif_deel / notif_leid /
notif_kamp / notif_staf, per kampkort) synchroniseert naar **Google Workspace groepen**.

Deze extensie is gebouwd omdat de officiële `org.civicrm.googlegroups` extensie te
onbetrouwbaar bleek voor de sync-logica. We hergebruiken die extensie wél nog voor de
*read-only* en *credential*-kant (token, `getmembers`, `getgroups`), maar de bepaling van
**wie er in een groep hoort** en de schrijf-acties (subscribe/deletemember) doen we zelf,
met uitgebreide `wachthond`-logging en geknipte debug.

Licentie: [AGPL-3.0](LICENSE.txt).

## Hoe het werkt

Per Google Group bepaalt de extensie de gewenste ledenlijst en maakt Google exact gelijk
aan CiviCRM (toevoegen wat ontbreekt, verwijderen wat te veel is — met optionele whitelist).

**Bron van waarheid** = de CiviCRM `notificatie_*`-groepen:

- De `notif_deel/leid/kamp`-groepen zijn **smart groups** met een saved search op de
  **Email**-entity. Die search filtert op `location_type_id:name = notif_<type>`, lidmaatschap
  van groep **456 (DITJAAR Kampstaf)** en `ditjaar_kampkort`. Het juiste **notif-type-adres**
  (location 16/17/18) rolt er dus automatisch uit — niet het primaire adres.
- **`notif_staf` valt buiten scope**: die groepen worden door deze extensie bewust NIET
  met Google Groups gesynct.

De koppeling CiviCRM-groep ↔ Google Group ID staat in `civicrm_value_googlegroup_settings`
(beheerd door de officiële extensie + UI). `googlesync_get_configured_groups()` leest die
tabel; dat is de autoritatieve lijst voor de bulk-sync.

## Samenhang met email.php en acl.php

Er zijn drie sporen rondom Google Groups; deze extensie is het derde:

- **`nl.onvergetelijk.email` — real-time, per contact.** Bij het opslaan van een (staf-)contact
  pusht `email.php` het notif_deel/leid/kamp-adres naar de juiste Google-groep en haalt oude
  adressen weg. Dat loopt via `googlegroup_subscribe()` / `googlegroup_deletemember()` in
  `email.helpers.php`, die de **officiële** `civicrm_api3('Googlegroups', …)` aanroepen. Dekt
  notif_deel/leid/kamp (niet staf). Dit is de onmiddellijke wijziging.
- **`nl.onvergetelijk.googlesync` (deze extensie) — periodieke drift-correctie.** Maakt hele
  groepen gelijk aan de CiviCRM-bron en repareert wat real-time miste, met eigen subscribe/delete.
- **`nl.onvergetelijk.acl` — niet betrokken.** Doet geen actieve Google-sync. De dode Google-IDs
  in zijn kampmap zijn opgeschoond; oude sync-code staat enkel (uitgecommentarieerd) in `archive/acl.php`.

Samengevat: edits gaan **direct** via email.php (officiële API); de **nachtelijke** job hier
corrigeert drift over alle gekoppelde groepen (eigen API).

## Belangrijkste functies

| Functie | Bestand | Doel |
|---------|---------|------|
| `googlesync_sync_configured()` | `googlesync.sync.php` | **Bulk-sync** van álle gekoppelde groepen (aanbevolen voor scheduled job) |
| `googlesync_sync_group($kampkort,$notiftype)` | `googlesync.sync.php` | Sync één specifieke groep |
| `googlesync_sync_kamp($kampkort)` | `googlesync.sync.php` | Sync alle notiftypes van één kamp |
| `googlesync_sync_all()` | `googlesync.sync.php` | Sync alle kampen via de kampmap |
| `googlesync_get_group_emails($cid,$ssid)` | `googlesync.sync.php` | Centrale resolver: gewenste adressen van een groep |
| `googlesync_get_configured_groups()` | `googlesync.kampdata.php` | Alle in googlegroups gekoppelde groepen (uit DB) |
| `googlesync_get_kampmap()` / `_get_group_id()` | `googlesync.kampdata.php` | Kampkort+notiftype → Google Group ID |
| `googlesync_subscribe()` / `_deletemember()` | `googlesync.helpers.php` | Eigen robuuste schrijf-acties |
| `googlesync_getmembers()` / `_getgroups()` | `googlesync.helpers.php` | Read-only via officiële `Googlegroups` API |

## Getting Started

Sync draait via een **dagelijkse Scheduled Job** ("Onvergetelijk - Google Sync", auto-aangemaakt)
die de API-actie `Googlesync.sync` aanroept. Handmatig of als dry-run:

```bash
cv api3 Googlesync.sync                      # echte sync, scope=configured (alle gekoppelde groepen)
cv api3 Googlesync.sync dry_run=1            # alleen rapporteren, niets wijzigen
cv api3 Googlesync.sync scope=all dry_run=1  # alleen de kampmap notif-groepen
```

Of vanuit code:

```php
$resultaten = googlesync_sync_configured([], $dry_run = FALSE);  // alle gekoppelde groepen
$resultaten = googlesync_sync_kamp('kk1',  [], $dry_run = FALSE); // één kamp
```

Elke sync-functie heeft `$dry_run` als laatste parameter. Vereist:
`org.civicrm.googlegroups` geïnstalleerd en geconfigureerd (OAuth-token + domeinen).
De officiële "Google Groups Sync" job is bewust gedeactiveerd ten gunste van deze extensie.

## Known Issues

- **`notif_staf`** valt bewust buiten scope — wordt niet met Google gesynct.
- **`civicrm_value_googlegroup_settings` bevat ~79 gekoppelde groepen**, niet alleen de
  notif-groepen. `googlesync_sync_configured()` pakt ze allemaal — scope dit voordat je
  het productief draait.
- PHPUnit e2e-tests in `tests/phpunit/Civi/Googlesync/` (KampdataTest, ResolverTest,
  IntegratieEmailAclTest). Draaien: `phpunit9 --configuration=phpunit.xml.dist`.

Zie [MEMORY.md](MEMORY.md) voor de volledige context, datamodel en historie.

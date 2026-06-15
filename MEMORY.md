# Memory — nl.onvergetelijk.googlesync
_Bijgewerkt: 2026-06-05_

Eigen Google Workspace-groepssync voor de OZK notificatie-groepen. Vervangt het vertrouwen
op `org.civicrm.googlegroups` voor de **sync-logica**; die officiële extensie blijft wel de
basis voor credentials/token en de read-only API's.

---

## Tests

PHPUnit e2e-tests (`tests/phpunit/Civi/Googlesync/`), draaien met
`phpunit9 --configuration=phpunit.xml.dist` (20 tests):
- **KampdataTest** — pure logica: kampmailbox-mapping, notiftypes, get_group_id (kampmap),
  excluded groups, apply_kampmailbox (deel/leid/kamp wel, staf niet, idempotent), clip-helper.
- **ResolverTest** (transactioneel) — `googlesync_get_group_emails`: gewone groep → primaire
  adressen; **onbekende saved search → NULL** (overslaan, niet leegmaken); configured-groups
  structuur + uitsluiting testgroep.
- **IntegratieEmailAclTest** — cross-extensie: email.php's Google-IDs == kampmap (real-time
  guard), wrappers bestaan; acl.php bevat **geen** actieve Googlegroups-call én geen Google-IDs
  meer; **live kamp-dubbelcheck**: elke gekoppelde groep met kampkort wijst naar de juiste
  Google-groep.

---

## Bestandsstructuur

| Bestand | Rol |
|---------|-----|
| `googlesync.php` | Hooks (config/install/enable) + `require_once` van de losse bestanden |
| `googlesync.kampdata.php` | Kampmap (kampkort+notiftype → Google Group ID) + `googlesync_get_configured_groups()` |
| `googlesync.helpers.php` | `_googlesync_clip()`, `get_client()`, `subscribe()`, `deletemember()`, `getmembers()`, `getgroups()` |
| `googlesync.sync.php` | Sync-logica: `sync_group/kamp/all/configured`, `get_group_emails`, `_get_civicrm_emails` |

`getmembers()` en `getgroups()` hergebruiken `civicrm_api3('Googlegroups', ...)` (read-only,
stabiel). `subscribe()` en `deletemember()` zijn eigen robuuste implementaties. Debug-logging
loopt via `wachthond` (kanaal `googlesync.*`); grote lijsten worden geknipt via `_googlesync_clip()`.

---

## Samenhang met email.php en acl.php

Drie sporen rondom Google Groups; deze extensie is alleen het derde:

| Spoor | Extensie | Wat | API |
|---|---|---|---|
| **Real-time, per contact** | `nl.onvergetelijk.email` | Bij opslaan van een contact (staf-functies) wordt het notif_deel/leid/kamp-adres naar de juiste Google-groep ge-subscribed en worden oude/privé/werk-adressen ge-unsubscribed | **officiële** `civicrm_api3('Googlegroups', ...)` |
| **Periodieke drift-correctie (hele groepen)** | `nl.onvergetelijk.googlesync` (deze) | Maakt elke Google-groep gelijk aan de CiviCRM-bron; vult/ruimt op wat real-time miste | **eigen** subscribe/deletemember |
| **(historisch / inactief)** | `nl.onvergetelijk.acl` | Geen actieve sync meer | — |

**email.php** (`email.php` regels ~706–855, blokken 2.1/2.2/2.3): bepaalt de Google Group ID via
`match($ditjaar_part_kampkort)` op de **raw value** (kk1/kk2/…) en pusht per contact het
notif-type-adres. Wrappers `googlegroup_subscribe()` / `googlegroup_deletemember()` staan in
`email.helpers.php` en roepen de **officiële** Googlegroups-API aan. Dekt notif_deel/leid/kamp;
**niet** notif_staf. Dit is het directe, onmiddellijke pad — complementair aan deze extensie.

**acl.php**: doet **geen** actieve Google-sync. De oude `googlegroup_subscribe`/`deletemember`-logica
staat enkel (uitgecommentarieerd) in `archive/acl.php`. De `'google'`-sleutels in de kampmap-array
(acl.php ~369–377) waren **dode leftover-data** (nergens uitgelezen) en zijn **verwijderd**
(2026-06-05, back-up `/tmp/acl.php.bak-20260605`). ACL is geen onderdeel van de sync.

Let op: email.php gebruikt nog de **officiële** extensie-API. Als die officiële extensie ooit
verdwijnt, moet `googlegroup_subscribe/deletemember` in email.helpers.php omgezet worden naar de
eigen helpers (`googlesync_subscribe` / `googlesync_deletemember`).

---

## Scope: alle gekoppelde groepen (entity-bewust)

`googlesync_get_group_emails()` is **entity-bewust** en dekt alle ~78 gekoppelde groepen:
- smart group op **Email**-entity (notif-groepen) → email-kolom = notif-type-adres;
- smart group op **andere entity** (Participant/Contact, bijv. ditjaardeel_ / ditjaarleid_) →
  draait de saved search op zijn eigen entity, verzamelt contact-id's, pakt **primair** adres
  via `_googlesync_primary_emails()`;
- gewone groep → primair adres van 'Added' leden.

**RETURN-CONTRACT:** array = bepaald (mag leeg → groep leegmaken); **NULL = overslaan**
(niets op Google wijzigen) — voorkomt dat een mislukte resolve een groep leegmaakt.

**KAMP-DUBBELCHECK (beide sync-paden):** zowel `sync_group()` (notif, kampkort uit kampmap) als
`sync_configured()` (kampkort uit CiviCRM-naam) vergelijken via `_googlesync_kamp_mismatch()` de
verwachte kampkort-token met die van de gekoppelde Google-groepnaam. De Google-namen worden
eenmalig gecached opgehaald (`_googlesync_google_group_names()` → getgroups). Bij **mismatch**
wordt de groep **NIET gesynct** + log-waarschuwing (`kamp_mismatch`). Vangt mismappings zoals
destijds notif_leid bk1↔bk2. Huidige stand: 0 mismatches (27 notif + 78 configured).

Uitgesloten groepen: `googlesync_excluded_group_names()` → nu `notif_emails_test_1744` (testgroep).

Eerste volledige `scope=configured` sync (2026-06-05): 78 groepen, +32 / −17, 0 fouten, idempotent.
Deelnemer-groepen (ditjaardeel_*) worden meegesynct met **primair** adres (zoals de moeder-extensie).
Géén whitelist voor webteam+*-adressen (keuze: gewoon meesyncen).

---

## Bron van waarheid

Wie/welk adres in een Google Group hoort = de **CiviCRM `notificatie_*`-groep**:

- `notif_deel/leid/kamp` zijn **smart groups** met saved search op de **Email**-entity.
  De search filtert: lid van groep **456 (DITJAAR Kampstaf)** + `location_type_id:name = notif_<type>`
  + `ditjaar_kampkort = <raw value>`, status `Added`. Resultaat = het **notif-type-adres**
  (location 16/17/18), niet het primaire adres.
- `notif_staf_*` zijn gewone groepen (1700–1708) **zonder** Google Group ID → sync slaat ze over.

Koppeling CiviCRM-groep ↔ Google Group ID: tabel `civicrm_value_googlegroup_settings`
(`entity_id` = group id, `gc_group_id` = Workspace Group ID). Beheerd door de officiële
extensie + UI. `googlesync_get_configured_groups()` leest die (peildatum: ~79 gekoppelde groepen,
50 met saved search — dus méér dan alleen de notif-groepen).

`googlesync_get_group_emails($civi_group_id, $saved_search_id)` is de centrale resolver:
smart group → draait de saved search; gewone groep → primair adres van Added-leden.

---

## Planning / scheduled job

- **API-actie** `Googlesync.sync` (`api/v3/Googlesync/Sync.php`), params: `scope` (`configured`
  = alle ~79 gekoppelde groepen [default] | `all` = kampmap notif-groepen) en `dry_run` (0/1).
  Handmatig: `cv api3 Googlesync.sync dry_run=1 scope=all`.
- **Managed Scheduled Job** (`googlesync.mgd.php`): "Onvergetelijk - Google Sync", **Daily**,
  `scope=configured`. Job-id 256 (auto-aangemaakt; `update=unmodified` dus admin-toggle blijft staan).
- **Job 131** (officiële `Googlegroups.sync`) is **gedeactiveerd** (2026-06-05) — die draaide
  met membership + primair adres en zou onze kampmailboxen 's nachts terugdraaien.
- Dry-run zit in alle sync-functies als laatste param `$dry_run=TRUE`; resultaat bevat
  `gepland_toevoegen` / `gepland_verwijderen` (wat zou gebeuren) en `toegevoegd` / `verwijderd`
  (wat echt gebeurde). Performance dry-run: ~300 ms/groep (netwerk-gebonden, getmembers).

---

## Kampmailboxen (altijd in de groep)

Elke kamp heeft een eigen postbus die **standaard altijd** in notif_deel / notif_leid /
notif_kamp moet zitten (NIET notif_staf). De sync voegt deze toe aan de *gewenste* lijst
(`_googlesync_apply_kampmailbox`), dus ze worden toegevoegd waar ze ontbreken én nooit
verwijderd. Mapping in `googlesync_get_kampmailbox()`:

| kampkort | mailbox | kampkort | mailbox |
|---|---|---|---|
| kk1 | kinderkamp1@onvergetelijk.nl | tk2 | tienerkamp2@onvergetelijk.nl |
| kk2 | kinderkamp2@onvergetelijk.nl | jk1 | jeugdkamp1@onvergetelijk.nl |
| bk1 | brugkamp1@onvergetelijk.nl | jk2 | jeugdkamp2@onvergetelijk.nl |
| bk2 | brugkamp2@onvergetelijk.nl | top | topkamp@onvergetelijk.nl |
| tk1 | tienerkamp1@onvergetelijk.nl | | |

`googlesync_kampmailbox_notiftypes()` = `['notif_deel','notif_leid','notif_kamp']`.

---

## Location types (notificatie-e-mail)

| location_type_id | naam | constante in sync.php |
|---|---|---|
| 16 | notif_deel | `GOOGLESYNC_LOC_NOTIF_DEEL` |
| 17 | notif_leid | `GOOGLESYNC_LOC_NOTIF_LEID` |
| 18 | notif_kamp | `GOOGLESYNC_LOC_NOTIF_KAMP` |
| 19 | notif_staf | **buiten scope** — wordt door deze extensie NIET gesynct |

---

## Mapping: CiviCRM-groep ↔ Google Group ID (peildatum 2026-06-05)

| kampkort | civi group (deel) | google deel | civi group (kamp) | google kamp | civi group (leid) | google leid |
|---|---|---|---|---|---|---|
| kk1 | 1709 | 01baon6m3wo0451 | 1718 | 03whwml44cp9k45 | 1727 | 00gjdgxs35zo5jv |
| kk2 | 1710 | 00vx12273fgfnd5 | 1719 | 02fk6b3p0ikk00x | 1731 | 01302m921zcwabc |
| bk1 | 1711 | 00lnxbz9161bbzw | 1720 | 049x2ik50nuxsf9 | 1743 | 0319y80a3e30qbg |
| bk2 | 1712 | 0147n2zr2s87rx7 | 1721 | 03rdcrjn4882pxp | 1728 | 00kgcv8k0qhgq6a |
| tk1 | 1713 | 02xcytpi1fs7xwo | 1722 | 01ksv4uv0jwe4ss | 1733 | 02s8eyo137koo5d |
| tk2 | 1714 | 01opuj5n2028q4s | 1723 | 03l18frh2n6xt74 | 1734 | 00kgcv8k2o7zuqf |
| jk1 | 1715 | 02bn6wsx3827ior | 1724 | 01baon6m39ciju5 | 1736 | 03oy7u294kt7vtn |
| jk2 | 1716 | 030j0zll0m5pg5h | 1725 | 0279ka6533bbb65 | 1737 | 04i7ojhp2hwyjk2 |
| top | 1717 | 00haapch3zvbjru | 1726 | 00kgcv8k1t3von2 | 1738 | 017dp8vu3t3rwb4 |

notif_staf: civi 1700–1708 — **buiten scope**, wordt bewust niet met Google gesynct.

De Google IDs staan ook hard in `googlesync.kampdata.php` (kampmap) én in `email.php`
(real-time per-contact sync). De extensie matcht kampmap → configured groups op Google ID.

---

## Datacleanup-historie (2026-06-05)

**Option-name bug (root cause).** Kampkort-optie `kk1` (option_group 651 `kampkort`,
option_value id **5658**) had `name='KK2'` i.p.v. `KK1` → botste met kk2. Daardoor matchte
`kampkort:name = "KK2"` zowel kk1 als kk2. Gefixt naar `KK1`.

**Meegenomen searches:**
- `1647` evaluatie wk1: kampkort `KK2`→`KK1`
- `1856` LYBUNT: `KK2`→`KK1,KK2`
- `1863` verwijderd (was nergens in een groep gebruikt)
- De **27 notif-saved-searches** (1441–1469) herschreven naar één canonieke template,
  kampkort op **raw value** (`kk1`), testcondities (`last_name="kinderkamp1"` e.d.),
  `kamptype_id`/`kamplang`-varianten en aggregate-selects verwijderd.

**Groep-rename.** CiviCRM-groep **1728** hernoemd `notificatie_leid_bk1_1728` →
`notificatie_leid_bk2_1728` (was mis-named; title `notif_leid_bk2` en Google ID waren al bk2).

Back-up van alle gewijzigde rijen: `/tmp/googlesync_backup_20260605.txt`.

---

## Verificatie / debug commando's

```bash
# DB-toegang: CiviCRM-tabellen via cv (stdin), Drupal-tabellen via drush -r <docroot>
echo "SELECT v.id,v.value,v.name,v.label FROM civicrm_option_value v JOIN civicrm_option_group g ON g.id=v.option_group_id WHERE g.name='kampkort' ORDER BY v.weight" | /home/webteam/buildkit/bin/cv sql

# Dubbele option-names binnen één group (de collisie-detector):
echo "SELECT g.name,v.name,COUNT(*),GROUP_CONCAT(v.value) FROM civicrm_option_value v JOIN civicrm_option_group g ON g.id=v.option_group_id GROUP BY v.option_group_id,v.name HAVING COUNT(*)>1" | /home/webteam/buildkit/bin/cv sql

# Gekoppelde groepen:
echo "SELECT entity_id, gc_group_id FROM civicrm_value_googlegroup_settings WHERE gc_group_id<>'' ORDER BY entity_id" | /home/webteam/buildkit/bin/cv sql

# Functioneel testen (extensie moet enabled zijn):
cd /var/www/vhosts/ozkprod/web && /home/webteam/buildkit/bin/cv scr /pad/naar/test.php
```

Debug-niveaus `wachthond`: 1=mijlpaal, 2=scheidingslijn, 3=variabele, 7=API-params, 9=API-result.

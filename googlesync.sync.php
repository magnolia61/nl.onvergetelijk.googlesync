<?php

/**
 * =======================================================================================
 * googlesync.sync.php
 * =======================================================================================
 * Hoog-niveau sync logica: bepaalt WIE er in een Google Group hoort te zitten op
 * basis van CiviCRM-data, en stuurt de juiste subscribe/deletemember aanroepen.
 *
 * Aanpak per sync:
 *   1. Haal de huidige leden op uit Google (via googlesync_getmembers)
 *   2. Haal de gewenste leden op uit CiviCRM (Email met het juiste location_type_id)
 *   3. Bereken het verschil: wie moet erbij, wie moet eraf
 *   4. Voer de wijzigingen door via googlesync_subscribe en googlesync_deletemember
 *
 * Dit is een VOLLEDIGE sync: de Google Group wordt exact gelijkgemaakt aan wat
 * CiviCRM als correct beschouwt. Adressen die wél in Google staan maar niet in
 * CiviCRM worden verwijderd (tenzij ze op de whitelist staan — zie googlesync_sync_group).
 *
 * Location type IDs voor notificatie-e-mails (zie nl.onvergetelijk.email):
 *   16 = notif_deel
 *   17 = notif_leid
 *   18 = notif_kamp
 *   19 = notif_staf  — BUITEN SCOPE: wordt door deze extensie NIET gesynct
 *
 * =======================================================================================
 * FUNCTIE-INDEX
 * =======================================================================================
 *   googlesync_sync_group()       — sync één specifieke Google Group op basis van kampkort + notiftype
 *   googlesync_sync_kamp()        — sync alle drie notiftypes voor één kamp (excl. notif_staf)
 *   googlesync_sync_all()         — sync alle kampen en alle notiftypes (via de kampmap)
 *   googlesync_sync_configured()  — sync ALLE in googlegroups geconfigureerde groepen (uit DB)
 *   _googlesync_get_civicrm_emails() — (intern) haal de gewenste e-mails op uit CiviCRM
 * =======================================================================================
 */

// =======================================================================================
// CONSTANTEN: location_type_id per notificatietype
// =======================================================================================
// Deze IDs zijn geconfigureerd in CiviCRM en gekoppeld aan e-mailadressen van contacten.
// Ze corresponderen 1-op-1 met de notiftypes in googlesync_get_kampmap().
define('GOOGLESYNC_LOC_NOTIF_DEEL',   16);
define('GOOGLESYNC_LOC_NOTIF_LEID',   17);
define('GOOGLESYNC_LOC_NOTIF_KAMP',   18);
define('GOOGLESYNC_LOC_NOTIF_STAF',   19); // Placeholder, nog niet gesynct

// =======================================================================================
// 1. SYNC ÉÉN GOOGLE GROUP
// =======================================================================================

/**
 * Synchroniseert één specifieke Google Group naar de huidige CiviCRM-data.
 *
 * De functie:
 *   1. Bepaalt de Google Group ID via kampkort + notiftype
 *   2. Haalt de huidige leden op vanuit Google
 *   3. Haalt de gewenste leden op vanuit CiviCRM (alle contacten met het juiste notif-email)
 *   4. Berekent: toe_te_voegen = in_civicrm MINUS in_google
 *                te_verwijderen = in_google MINUS in_civicrm (minus whitelist)
 *   5. Voert de wijzigingen door
 *
 * De $whitelist parameter bevat e-mailadressen die NOOIT verwijderd worden, ook al
 * staan ze niet in CiviCRM. Handig voor service-accounts of technische adressen
 * die handmatig zijn toegevoegd aan de Google Group.
 *
 * @param string $kampkort    Bijv. 'kk1', 'bk2', 'top'
 * @param string $notiftype   'notif_deel', 'notif_leid', of 'notif_kamp'
 * @param array  $whitelist   E-mailadressen die nooit verwijderd worden (optioneel)
 * @param bool   $dry_run     TRUE = alleen berekenen, NIETS wijzigen op Google
 *
 * @return array  [
 *   'success'           => bool,
 *   'dry_run'           => bool,
 *   'kampkort'          => string,
 *   'notiftype'         => string,
 *   'google_group_id'   => string|NULL,
 *   'google_voor'       => int   (aantal leden vóór sync),
 *   'google_na'         => int   (aantal leden ná sync, op basis van berekening),
 *   'gepland_toevoegen' => array (wat toegevoegd zou/is worden),
 *   'gepland_verwijderen'=> array (wat verwijderd zou/is worden),
 *   'toegevoegd'        => array (daadwerkelijk toegevoegd; leeg bij dry_run),
 *   'verwijderd'        => array (daadwerkelijk verwijderd; leeg bij dry_run),
 *   'error'             => string,
 * ]
 */
function googlesync_sync_group(string $kampkort, string $notiftype, array $whitelist = [], bool $dry_run = FALSE): array {

    $extdebug = 'googlesync.sync';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC GROUP [$kampkort][$notiftype]",          "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // ---------------------------------------------------------------------------------
    // 1.1 Google Group ID ophalen
    // ---------------------------------------------------------------------------------
    $google_group_id = googlesync_get_group_id($kampkort, $notiftype);

    if (!$google_group_id) {
        $error = "Geen Google Group ID gevonden voor [$kampkort][$notiftype].";
        wachthond($extdebug, 1, "### GOOGLESYNC SKIP: $error");
        return [
            'success'         => FALSE,
            'kampkort'        => $kampkort,
            'notiftype'       => $notiftype,
            'google_group_id' => NULL,
            'google_voor'     => 0,
            'google_na'       => 0,
            'toegevoegd'      => [],
            'verwijderd'      => [],
            'error'           => $error,
        ];
    }

    wachthond($extdebug, 3, 'google_group_id', $google_group_id);

    // ---------------------------------------------------------------------------------
    // 1.1b KAMP-DUBBELCHECK: betreft de gekoppelde Google-groep wel kamp $kampkort?
    // Bij mismatch NIET syncen (anders zouden we naar het verkeerde kamp schrijven).
    // ---------------------------------------------------------------------------------
    $kamp_mismatch = _googlesync_kamp_mismatch($kampkort, $google_group_id);
    if ($kamp_mismatch) {
        wachthond($extdebug, 1, "### GOOGLESYNC OVERGESLAGEN (kamp-mismatch) [$kampkort][$notiftype]", $kamp_mismatch);
        return [
            'success'             => FALSE,
            'overgeslagen'        => TRUE,
            'kamp_mismatch'       => TRUE,
            'dry_run'             => $dry_run,
            'kampkort'            => $kampkort,
            'notiftype'           => $notiftype,
            'google_group_id'     => $google_group_id,
            'google_voor'         => 0,
            'google_na'           => 0,
            'gepland_toevoegen'   => [],
            'gepland_verwijderen' => [],
            'toegevoegd'          => [],
            'verwijderd'          => [],
            'error'               => $kamp_mismatch,
        ];
    }

    // ---------------------------------------------------------------------------------
    // 1.2 Huidige leden ophalen vanuit Google
    // ---------------------------------------------------------------------------------
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC GROUP 1.2 GOOGLE LEDEN OPHALEN",        "[$google_group_id]");
    wachthond($extdebug, 2, "########################################################################");

    $result_getmembers = googlesync_getmembers($google_group_id);

    if (!$result_getmembers['success']) {
        return [
            'success'         => FALSE,
            'kampkort'        => $kampkort,
            'notiftype'       => $notiftype,
            'google_group_id' => $google_group_id,
            'google_voor'     => 0,
            'google_na'       => 0,
            'toegevoegd'      => [],
            'verwijderd'      => [],
            'error'           => $result_getmembers['error'],
        ];
    }

    // Maak een platte array van e-mailadressen (lowercase voor case-insensitive vergelijking).
    $google_emails_nu = array_map('strtolower', array_values($result_getmembers['data']));
    // GEKNIPTE debug: een groep kan honderden leden hebben.
    wachthond($extdebug, 3, 'google_emails_nu (geknipt)', _googlesync_clip($google_emails_nu));

    // ---------------------------------------------------------------------------------
    // 1.3 Gewenste leden ophalen vanuit CiviCRM
    // ---------------------------------------------------------------------------------
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC GROUP 1.3 CIVICRM EMAILS OPHALEN",      "[$notiftype]");
    wachthond($extdebug, 2, "########################################################################");

    $result_civicrm = _googlesync_get_civicrm_emails($kampkort, $notiftype);

    if (!$result_civicrm['success']) {
        return [
            'success'         => FALSE,
            'kampkort'        => $kampkort,
            'notiftype'       => $notiftype,
            'google_group_id' => $google_group_id,
            'google_voor'     => count($google_emails_nu),
            'google_na'       => count($google_emails_nu),
            'toegevoegd'      => [],
            'verwijderd'      => [],
            'error'           => $result_civicrm['error'],
        ];
    }

    $civicrm_emails = array_map('strtolower', $result_civicrm['data']);
    // Forceer de eigen kampmailbox erin (deel/leid/kamp).
    $civicrm_emails = _googlesync_apply_kampmailbox($civicrm_emails, $kampkort, $notiftype);
    // GEKNIPTE debug.
    wachthond($extdebug, 3, 'civicrm_emails (geknipt)', _googlesync_clip($civicrm_emails));

    // ---------------------------------------------------------------------------------
    // 1.4 Verschil berekenen
    // ---------------------------------------------------------------------------------
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC GROUP 1.4 DIFF BEREKENEN");
    wachthond($extdebug, 2, "########################################################################");

    // Toe te voegen: staat in CiviCRM maar nog niet in Google.
    $toe_te_voegen = array_values(array_diff($civicrm_emails, $google_emails_nu));

    // Te verwijderen: staat in Google maar niet meer in CiviCRM.
    // Whitelist-adressen worden altijd overgeslagen.
    $whitelist_lower  = array_map('strtolower', $whitelist);
    $kandidaat_delete = array_diff($google_emails_nu, $civicrm_emails);
    $te_verwijderen   = array_values(array_diff($kandidaat_delete, $whitelist_lower));

    wachthond($extdebug, 3, 'toe_te_voegen',  $toe_te_voegen);
    wachthond($extdebug, 3, 'te_verwijderen', $te_verwijderen);

    // ---------------------------------------------------------------------------------
    // 1.5 Wijzigingen doorvoeren (of bij dry-run: alleen berekenen)
    // ---------------------------------------------------------------------------------
    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC GROUP 1.5 WIJZIGINGEN", $dry_run ? "[DRY-RUN]" : "[DOORVOEREN]");
    wachthond($extdebug, 2, "########################################################################");

    $toegevoegd  = [];
    $verwijderd  = [];
    $sync_errors = [];

    if ($dry_run) {
        // Niets wijzigen — alleen rapporteren wat er zou gebeuren.
        wachthond($extdebug, 1, "### GOOGLESYNC DRY-RUN", "zou +" . count($toe_te_voegen) . " / -" . count($te_verwijderen));
    } else {
        if (!empty($toe_te_voegen)) {
            $result_subscribe = googlesync_subscribe($google_group_id, $toe_te_voegen);
            if ($result_subscribe['success']) {
                $toegevoegd = $toe_te_voegen;
                wachthond($extdebug, 1, "### GOOGLESYNC SUBSCRIBE OK", count($toegevoegd) . " adressen toegevoegd");
            } else {
                $sync_errors[] = "subscribe: " . $result_subscribe['error'];
                wachthond($extdebug, 1, "### GOOGLESYNC SUBSCRIBE ERROR", $result_subscribe['error']);
            }
        }

        if (!empty($te_verwijderen)) {
            $result_delete = googlesync_deletemember($google_group_id, $te_verwijderen);
            if ($result_delete['success']) {
                $verwijderd = $te_verwijderen;
                wachthond($extdebug, 1, "### GOOGLESYNC DELETE OK", count($verwijderd) . " adressen verwijderd");
            } else {
                $sync_errors[] = "deletemember: " . $result_delete['error'];
                wachthond($extdebug, 1, "### GOOGLESYNC DELETE ERROR", $result_delete['error']);
            }
        }
    }

    // Bij dry-run is er feitelijk niets toegevoegd/verwijderd, dus de "na"-stand = de huidige stand.
    $google_na = $dry_run
        ? count($google_emails_nu)
        : count($google_emails_nu) + count($toegevoegd) - count($verwijderd);

    wachthond($extdebug, 1, "### GOOGLESYNC SYNC GROUP [$kampkort][$notiftype]",          "[KLAAR]");

    return [
        'success'             => empty($sync_errors),
        'dry_run'             => $dry_run,
        'kampkort'            => $kampkort,
        'notiftype'           => $notiftype,
        'google_group_id'     => $google_group_id,
        'google_voor'         => count($google_emails_nu),
        'google_na'           => $google_na,
        'gepland_toevoegen'   => $toe_te_voegen,
        'gepland_verwijderen' => $te_verwijderen,
        'toegevoegd'          => $toegevoegd,
        'verwijderd'          => $verwijderd,
        'error'               => implode('; ', $sync_errors),
    ];
}

// =======================================================================================
// 2. SYNC ÉÉN KAMP
// =======================================================================================

/**
 * Synchroniseert alle drie notiftypes (deel, leid, kamp) voor één kamp.
 *
 * Dit is een handig startpunt als je één kamp wilt resetten of initieel wilt vullen.
 * notif_staf valt BUITEN scope van deze extensie en wordt niet gesynct.
 *
 * @param string $kampkort   Bijv. 'kk1', 'bk2', 'top'
 * @param array  $whitelist  E-mailadressen die nooit verwijderd worden (optioneel)
 *
 * @return array  [notiftype => sync_result_array]  (zie googlesync_sync_group)
 */
function googlesync_sync_kamp(string $kampkort, array $whitelist = [], bool $dry_run = FALSE): array {

    $extdebug = 'googlesync.sync';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC KAMP [$kampkort]",                      $dry_run ? "[DRY-RUN]" : "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // De drie notiftypes die we voor elk kamp synchroniseren (notif_staf: buiten scope).
    $notiftypes = ['notif_deel', 'notif_leid', 'notif_kamp'];

    $resultaten = [];

    foreach ($notiftypes as $notiftype) {
        wachthond($extdebug, 1, "### GOOGLESYNC SYNC KAMP: starten notiftype", $notiftype);
        $resultaten[$notiftype] = googlesync_sync_group($kampkort, $notiftype, $whitelist, $dry_run);
    }

    wachthond($extdebug, 1, "### GOOGLESYNC SYNC KAMP [$kampkort]",                      "[KLAAR]");

    return $resultaten;
}

// =======================================================================================
// 3. SYNC ALLES
// =======================================================================================

/**
 * Synchroniseert alle kampen en alle notiftypes.
 *
 * Loopt over alle kampkorts uit googlesync_get_kampmap() en synct elk kamp volledig.
 * Retourneert een geneste array: [kampkort => [notiftype => sync_result_array]].
 *
 * Gebruik dit als scheduled job (bijv. nachtelijks) om drift te corrigeren.
 * Real-time sync bij e-mailadressen verloopt via de nl.onvergetelijk.email extensie.
 *
 * @param array  $whitelist  E-mailadressen die nooit verwijderd worden (optioneel)
 *
 * @return array  [kampkort => [notiftype => sync_result_array]]
 */
function googlesync_sync_all(array $whitelist = [], bool $dry_run = FALSE): array {

    $extdebug = 'googlesync.sync';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC ALL",                                   $dry_run ? "[DRY-RUN]" : "[START]");
    wachthond($extdebug, 2, "########################################################################");

    $kampmap    = googlesync_get_kampmap();
    $resultaten = [];

    foreach (array_keys($kampmap) as $kampkort) {
        wachthond($extdebug, 1, "### GOOGLESYNC SYNC ALL: starten kamp", $kampkort);
        $resultaten[$kampkort] = googlesync_sync_kamp($kampkort, $whitelist, $dry_run);
    }

    // Samenvatting voor de logs.
    $totaal_toegevoegd  = 0;
    $totaal_verwijderd  = 0;
    $totaal_fouten      = 0;
    foreach ($resultaten as $kamp => $kamp_resultaten) {
        foreach ($kamp_resultaten as $notiftype => $res) {
            $totaal_toegevoegd  += count($res['toegevoegd'] ?? []);
            $totaal_verwijderd  += count($res['verwijderd'] ?? []);
            if (!($res['success'] ?? TRUE)) {
                $totaal_fouten++;
            }
        }
    }

    wachthond($extdebug, 1, "### GOOGLESYNC SYNC ALL SAMENVATTING", [
        'kampen_gesynchroniseerd'   => count($resultaten),
        'totaal_toegevoegd'         => $totaal_toegevoegd,
        'totaal_verwijderd'         => $totaal_verwijderd,
        'totaal_fouten'             => $totaal_fouten,
    ]);
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC ALL",                                   "[KLAAR]");

    return $resultaten;
}

// =======================================================================================
// 4. SYNC ALLE GECONFIGUREERDE GROEPEN (autoritatief, uit DB)
// =======================================================================================

/**
 * Synchroniseert ALLE CiviCRM-groepen die in de officiële googlegroups-koppeltabel
 * gekoppeld zijn aan een Google Group (zie googlesync_get_configured_groups).
 *
 * Dit is de AANBEVOLEN bulk-sync voor een scheduled job: de lijst komt live uit de DB,
 * dus nieuwe/gewijzigde koppelingen worden automatisch meegenomen zonder code-aanpassing.
 * (notif_staf valt buiten scope en heeft geen Google-koppeling, dus komt hier niet voor.)
 *
 * Per groep:
 *   1. Huidige Google-leden ophalen (via googlesync_getmembers → officiële API)
 *   2. Gewenste CiviCRM-leden ophalen: de contacten die ECHT lid zijn van de CiviCRM-groep
 *      (status 'Added'), met hun primaire/relevante e-mailadres
 *   3. Diff toepassen via subscribe/deletemember
 *
 * @param array $whitelist  E-mailadressen die nooit verwijderd worden (optioneel)
 * @param bool  $dry_run    TRUE = alleen berekenen, NIETS wijzigen op Google
 *
 * @return array  [civi_group_id => sync_result_array]
 */
function googlesync_sync_configured(array $whitelist = [], bool $dry_run = FALSE): array {

    $extdebug = 'googlesync.sync';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC SYNC CONFIGURED",                            $dry_run ? "[DRY-RUN]" : "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // Haal de autoritatieve lijst gekoppelde groepen op uit de DB.
    $configured_groups = googlesync_get_configured_groups();
    wachthond($extdebug, 3, 'aantal geconfigureerde groepen', count($configured_groups));

    // DUBBELCHECK: Google-groepsnamen worden eenmalig (gecached) opgehaald in
    // _googlesync_kamp_mismatch(); zo controleren we per groep of de kampkort-token van
    // de CiviCRM-naam overeenkomt met die van de gekoppelde Google-groep.

    $resultaten = [];

    foreach ($configured_groups as $civi_group_id => $details) {

        $google_group_id  = $details['google_group_id'];
        $civi_group_name  = $details['civi_group_name'];

        wachthond($extdebug, 2, "########################################################################");
        wachthond($extdebug, 1, "### GOOGLESYNC SYNC CONFIGURED: $civi_group_name (civi $civi_group_id)", "[$google_group_id]");
        wachthond($extdebug, 2, "########################################################################");

        // 0. DUBBELCHECK: kampkort van CiviCRM-naam vs gekoppelde Google-groepnaam.
        //    Bij mismatch NIET syncen (de koppeling wijst naar het verkeerde kamp).
        $kk_civi  = _googlesync_kampkort_uit_naam($civi_group_name);
        $mismatch = $kk_civi ? _googlesync_kamp_mismatch($kk_civi, $google_group_id) : NULL;
        if ($mismatch) {
            $mismatch = "$civi_group_name → $mismatch";
            wachthond($extdebug, 1, "### GOOGLESYNC OVERGESLAGEN (kamp-mismatch)", $mismatch);
            $resultaten[$civi_group_id] = [
                'success'         => FALSE,
                'overgeslagen'    => TRUE,
                'kamp_mismatch'   => TRUE,
                'dry_run'         => $dry_run,
                'civi_group_id'   => $civi_group_id,
                'civi_group_name' => $civi_group_name,
                'google_group_id' => $google_group_id,
                'error'           => $mismatch,
            ];
            continue;
        }

        // 1. Huidige Google-leden ophalen.
        $result_getmembers = googlesync_getmembers($google_group_id);
        if (!$result_getmembers['success']) {
            $resultaten[$civi_group_id] = [
                'success'         => FALSE,
                'civi_group_id'   => $civi_group_id,
                'civi_group_name' => $civi_group_name,
                'google_group_id' => $google_group_id,
                'error'           => $result_getmembers['error'],
            ];
            continue;
        }
        $google_emails_nu = array_map('strtolower', array_values($result_getmembers['data']));
        wachthond($extdebug, 3, 'google_emails_nu (geknipt)', _googlesync_clip($google_emails_nu));

        // 2. Gewenste CiviCRM-leden ophalen via de centrale resolver. Voor een smart group
        //    (saved_search_id gevuld) draaien we de saved search → die levert het juiste
        //    notif-type-adres (location_type 16/17/18). Voor een gewone groep vallen we
        //    terug op het primaire adres van de 'Added' leden.
        $civicrm_emails = googlesync_get_group_emails($civi_group_id, $details['saved_search_id'] ?? NULL);

        // NULL = kon niet betrouwbaar bepalen → groep OVERSLAAN, niets op Google wijzigen.
        if ($civicrm_emails === NULL) {
            wachthond($extdebug, 1, "### GOOGLESYNC OVERGESLAGEN", "$civi_group_name (civi $civi_group_id) — gewenste lijst onbepaald");
            $resultaten[$civi_group_id] = [
                'success'         => TRUE,
                'overgeslagen'    => TRUE,
                'dry_run'         => $dry_run,
                'civi_group_id'   => $civi_group_id,
                'civi_group_name' => $civi_group_name,
                'google_group_id' => $google_group_id,
                'error'           => 'overgeslagen: gewenste lijst kon niet bepaald worden',
            ];
            continue;
        }

        // Forceer de eigen kampmailbox erin als dit een notif_<type>_<kampkort> groep is.
        // We leiden type + kampkort af uit de titel (bijv. 'notif_leid_bk2'); niet-notif
        // groepen matchen niet en blijven ongemoeid.
        if (preg_match('/^notif_(deel|leid|kamp|staf)_([a-z0-9]+)$/i', (string) ($details['civi_group_title'] ?? ''), $m)) {
            $civicrm_emails = _googlesync_apply_kampmailbox($civicrm_emails, strtolower($m[2]), 'notif_' . strtolower($m[1]));
        }

        // Contact-specifieke e-mail-overrides (bijv. Daniël Fritschij op de hoofdkeuken-*
        // groepen: @onvergetelijk.nl i.p.v. zijn primaire persoonlijke adres).
        $civicrm_emails = _googlesync_apply_contact_email_overrides($civicrm_emails, $civi_group_id);

        wachthond($extdebug, 3, 'civicrm_emails (geknipt)', _googlesync_clip($civicrm_emails));

        // 3. Diff toepassen.
        $whitelist_lower = array_map('strtolower', $whitelist);
        $toe_te_voegen   = array_values(array_diff($civicrm_emails, $google_emails_nu));
        $te_verwijderen  = array_values(array_diff(array_diff($google_emails_nu, $civicrm_emails), $whitelist_lower));

        wachthond($extdebug, 3, 'toe_te_voegen (geknipt)',  _googlesync_clip($toe_te_voegen));
        wachthond($extdebug, 3, 'te_verwijderen (geknipt)', _googlesync_clip($te_verwijderen));

        $toegevoegd  = [];
        $verwijderd  = [];
        $sync_errors = [];

        if ($dry_run) {
            wachthond($extdebug, 1, "### GOOGLESYNC DRY-RUN", "$civi_group_name: zou +" . count($toe_te_voegen) . " / -" . count($te_verwijderen));
        } else {
            if (!empty($toe_te_voegen)) {
                $res = googlesync_subscribe($google_group_id, $toe_te_voegen);
                $res['success'] ? $toegevoegd = $toe_te_voegen : $sync_errors[] = 'subscribe: ' . $res['error'];
            }
            if (!empty($te_verwijderen)) {
                $res = googlesync_deletemember($google_group_id, $te_verwijderen);
                $res['success'] ? $verwijderd = $te_verwijderen : $sync_errors[] = 'deletemember: ' . $res['error'];
            }
        }

        $resultaten[$civi_group_id] = [
            'success'             => empty($sync_errors),
            'dry_run'             => $dry_run,
            'civi_group_id'       => $civi_group_id,
            'civi_group_name'     => $civi_group_name,
            'google_group_id'     => $google_group_id,
            'google_voor'         => count($google_emails_nu),
            'google_na'           => $dry_run ? count($google_emails_nu) : count($google_emails_nu) + count($toegevoegd) - count($verwijderd),
            'gepland_toevoegen'   => $toe_te_voegen,
            'gepland_verwijderen' => $te_verwijderen,
            'toegevoegd'          => $toegevoegd,
            'verwijderd'          => $verwijderd,
            'error'               => implode('; ', $sync_errors),
        ];
    }

    wachthond($extdebug, 1, "### GOOGLESYNC SYNC CONFIGURED",                            "[KLAAR]");

    return $resultaten;
}

// =======================================================================================
// INTERN: kampkort-token uit een groeps-/Google-naam halen (voor de dubbelcheck)
// =======================================================================================

/**
 * Haalt de kampkort-token (kk1, kk2, bk1, …, top) uit een groeps- of Google-naam.
 * Gebruikt voor de consistentie-dubbelcheck in googlesync_sync_configured().
 *
 * @param string $naam  Bijv. 'ditjaardeel_kk1_1325' of 'onvergetelijk.nl:ditjaarleid_kk1::team-kk1@…'
 *
 * @return string|NULL  lowercase token, of NULL als er geen kampkort in de naam zit
 */
function _googlesync_kampkort_uit_naam(string $naam): ?string {
    if (preg_match('/(?<![a-z0-9])(kk1|kk2|bk1|bk2|tk1|tk2|jk1|jk2|top)(?![a-z0-9])/i', $naam, $m)) {
        return strtolower($m[1]);
    }
    return NULL;
}

/**
 * (Intern) Alle Google-groepsnamen, EENMALIG opgehaald en gecached per request.
 * Zo kan de kamp-dubbelcheck in zowel sync_group() als sync_configured() draaien
 * zonder per groep een getgroups-call te doen.
 *
 * @return array  [google_group_id => 'domein:naam::email']  (leeg bij API-fout)
 */
function _googlesync_google_group_names(): array {
    static $cache = NULL;
    if ($cache !== NULL) {
        return $cache;
    }
    $result = googlesync_getgroups();
    $cache  = !empty($result['success']) ? $result['data'] : [];
    return $cache;
}

/**
 * (Intern) Kamp-dubbelcheck: betreft de gekoppelde Google-groep hetzelfde kamp als verwacht?
 *
 * @param string $kampkort_verwacht  Bijv. 'kk1' (uit kampmap of CiviCRM-naam)
 * @param string $google_group_id    De gekoppelde Google Group ID
 *
 * @return string|NULL  Mismatch-melding, of NULL als consistent / niet te controleren
 */
function _googlesync_kamp_mismatch(string $kampkort_verwacht, string $google_group_id): ?string {
    $namen  = _googlesync_google_group_names();
    $g_naam = $namen[$google_group_id] ?? NULL;
    if (!$g_naam) {
        return NULL;  // Google-naam onbekend → niet te controleren
    }
    $kk_google = _googlesync_kampkort_uit_naam($g_naam);
    if (!$kk_google) {
        return NULL;  // geen kampkort in de Google-naam → niet te controleren
    }
    $kampkort_verwacht = strtolower($kampkort_verwacht);
    if ($kk_google !== $kampkort_verwacht) {
        return "KAMP-MISMATCH: verwacht '$kampkort_verwacht' maar Google-groep is '$g_naam' ($kk_google)";
    }
    return NULL;
}

// =======================================================================================
// INTERN: kampmailbox forceren in de gewenste lijst
// =======================================================================================

/**
 * Voegt de eigen kampmailbox toe aan de gewenste e-mailijst, voor de notif-types
 * waar dat hoort (deel/leid/kamp — zie googlesync_kampmailbox_notiftypes).
 *
 * Omdat het adres in de GEWENSTE lijst terechtkomt, wordt het automatisch toegevoegd
 * als het op Google ontbreekt, én nooit verwijderd (wat gewenst is, blijft staan).
 *
 * @param array  $emails     Bestaande (lowercase) gewenste adressen
 * @param string $kampkort   Bijv. 'kk1'
 * @param string $notiftype  'notif_deel' | 'notif_leid' | 'notif_kamp' | 'notif_staf'
 *
 * @return array  De lijst, eventueel aangevuld met de kampmailbox
 */
function _googlesync_apply_kampmailbox(array $emails, string $kampkort, string $notiftype): array {

    $extdebug = 'googlesync.sync';

    if (!in_array($notiftype, googlesync_kampmailbox_notiftypes(), TRUE)) {
        return $emails;  // bijv. notif_staf: geen kampmailbox
    }

    $mailbox = googlesync_get_kampmailbox($kampkort);
    if ($mailbox) {
        $mailbox = strtolower($mailbox);
        if (!in_array($mailbox, $emails, TRUE)) {
            $emails[] = $mailbox;
            wachthond($extdebug, 3, "kampmailbox geforceerd toegevoegd [$kampkort][$notiftype]", $mailbox);
        }
    }

    return $emails;
}

/**
 * (Intern) Contact-specifieke e-mail-overrides: voor bepaalde combinaties van
 * CiviCRM-groep + contact gebruiken we een ANDER adres dan het primaire adres dat
 * _googlesync_primary_emails() zou opleveren. Handig als iemand voor één specifieke
 * rol een apart (bijv. functioneel) adres moet gebruiken, zonder zijn CiviCRM-brede
 * primaire e-mail te wijzigen.
 *
 * @return array  [civi_group_id => [contact_id => override e-mailadres]]
 */
function _googlesync_contact_email_overrides(): array {
    return [
        // Daniël Fritschij: hoofdkeuken-* [ACL] (kk1/kk2/bk1/bk2/tk1/tk2/jk1/jk2/top).
        // Voor deze groepen specifiek zijn @onvergetelijk.nl-adres i.p.v. zijn
        // primaire (persoonlijke gmail) adres.
        1675 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1676 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1677 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1678 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1679 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1680 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1681 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1682 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
        1683 => [19210 => 'daniel.fritschij@onvergetelijk.nl'],
    ];
}

/**
 * (Intern) Past de contact-specifieke e-mail-overrides toe op een resultaatlijst van
 * googlesync_get_group_emails(). Verwijdert het primaire adres van de betreffende
 * contactpersoon (indien aanwezig) en voegt het override-adres toe — maar ALLEEN als
 * de contactpersoon daadwerkelijk actief lid is van deze groep.
 *
 * @param array $emails         Resultaat van googlesync_get_group_emails()
 * @param int   $civi_group_id  CiviCRM group ID
 *
 * @return array  Aangepaste e-maillijst
 */
function _googlesync_apply_contact_email_overrides(array $emails, int $civi_group_id): array {

    $extdebug  = 'googlesync.sync';
    $overrides = _googlesync_contact_email_overrides()[$civi_group_id] ?? [];

    if (empty($overrides)) {
        return $emails;
    }

    foreach ($overrides as $contact_id => $override_email) {

        // Alleen toepassen als de contactpersoon daadwerkelijk actief lid is van deze groep.
        $is_member = (bool) civicrm_api4('GroupContact', 'get', [
            'checkPermissions' => FALSE,
            'select'           => ['id'],
            'where'            => [
                ['group_id',   '=', $civi_group_id],
                ['contact_id', '=', $contact_id],
                ['status',     '=', 'Added'],
            ],
            'limit' => 1,
        ])->count();

        if (!$is_member) {
            continue;
        }

        $override_email = strtolower($override_email);

        // Verwijder het primaire adres van deze contactpersoon uit de lijst (indien aanwezig).
        foreach (_googlesync_primary_emails([$contact_id]) as $primary_email) {
            $key = array_search($primary_email, $emails, TRUE);
            if ($key !== FALSE) {
                unset($emails[$key]);
            }
        }

        if (!in_array($override_email, $emails, TRUE)) {
            $emails[] = $override_email;
            wachthond($extdebug, 3, "e-mail-override toegepast [group $civi_group_id][contact $contact_id]", $override_email);
        }
    }

    return array_values($emails);
}

// =======================================================================================
// INTERN: gewenste e-mailadressen ophalen uit CiviCRM
// =======================================================================================

/**
 * Centrale resolver: geeft de e-mailadressen die in de Google Group HOREN te zitten,
 * op basis van de CiviCRM-groep.
 *
 * BRON VAN WAARHEID (entity-bewust):
 *   - SMART GROUP op de EMAIL-entity (de notif-groepen): we draaien de saved search en
 *     pakken de 'email'-kolom direct. Die search filtert op location_type notif_deel/leid/kamp,
 *     dus we krijgen exact het juiste NOTIF-TYPE-adres (location 16/17/18).
 *   - SMART GROUP op een ANDERE entity (Participant/Contact, bijv. ditjaardeel of ditjaarleid):
 *     we draaien de saved search op zijn eigen entity, verzamelen de contact-id's en pakken
 *     daarvan het PRIMAIRE adres (zoals de officiële moeder-extensie deed).
 *   - GEWONE GROEP (geen saved search): primaire adres van de 'Added' leden.
 *
 * RETURN-CONTRACT:
 *   - array  = succesvol bepaald (mag leeg zijn → groep moet leeg worden gemaakt)
 *   - NULL   = kon NIET betrouwbaar bepalen → caller MOET de groep OVERSLAAN en NIETS
 *              op Google wijzigen (anders zouden we per ongeluk alle leden verwijderen)
 *
 * @param int      $civi_group_id    CiviCRM group ID
 * @param int|NULL $saved_search_id  Saved-search ID als de groep een smart group is
 *
 * @return array|NULL  lowercase e-mailadressen (uniek), of NULL = overslaan
 */
function googlesync_get_group_emails(int $civi_group_id, ?int $saved_search_id = NULL): ?array {

    $extdebug = 'googlesync.sync';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC GET GROUP EMAILS [civi $civi_group_id]",     "[ss: " . ($saved_search_id ?? 'geen') . "]");
    wachthond($extdebug, 2, "########################################################################");

    $emails = [];

    if ($saved_search_id) {

        // Saved search ophalen (entity + params).
        $saved_search = civicrm_api4('SavedSearch', 'get', [
            'checkPermissions' => FALSE,
            'where'            => [['id', '=', $saved_search_id]],
        ])->first();

        if (!$saved_search || empty($saved_search['api_params'])) {
            wachthond($extdebug, 1, "### GOOGLESYNC OVERSLAAN: saved search $saved_search_id onbruikbaar");
            return NULL;  // niets wijzigen
        }

        $entity = $saved_search['api_entity'] ?? 'Email';
        $params = $saved_search['api_params'];
        $params['checkPermissions'] = FALSE;
        wachthond($extdebug, 3, 'saved search entity', $entity);

        if ($entity === 'Email') {

            // --- Email-entity (notif-groepen): direct de email-kolom ---
            try {
                $rows = civicrm_api4('Email', 'get', $params);
            } catch (\Throwable $e) {
                wachthond($extdebug, 1, "### GOOGLESYNC OVERSLAAN: Email-search $saved_search_id faalde", $e->getMessage());
                return NULL;
            }
            foreach ($rows as $row) {
                if (!empty($row['email'])) { $emails[] = strtolower($row['email']); }
            }

        } else {

            // --- Andere entity (Participant/Contact/...): contact-id's → primair adres ---
            // We overschrijven de select naar alleen het contact-id veld en strippen
            // groupBy/having (sommige searches hebben aggregate-selects voor weergave).
            $id_field = ($entity === 'Contact') ? 'id' : 'contact_id';
            $params['select']  = [$id_field];
            $params['groupBy'] = [];
            $params['having']  = [];

            try {
                $rows = civicrm_api4($entity, 'get', $params);
            } catch (\Throwable $e) {
                wachthond($extdebug, 1, "### GOOGLESYNC OVERSLAAN: {$entity}-search $saved_search_id faalde", $e->getMessage());
                return NULL;  // niet leegmaken bij twijfel
            }

            $contact_ids = [];
            foreach ($rows as $row) {
                $cid = $row[$id_field] ?? NULL;
                if ($cid) { $contact_ids[(int) $cid] = TRUE; }
            }
            $contact_ids = array_keys($contact_ids);
            wachthond($extdebug, 3, 'contact_ids count', count($contact_ids));

            if (!empty($contact_ids)) {
                $emails = _googlesync_primary_emails($contact_ids);
            }
        }

    } else {

        // --- GEWONE GROEP: primaire adressen van de 'Added' leden ---
        $member_rows = civicrm_api4('GroupContact', 'get', [
            'checkPermissions' => FALSE,
            'select'           => ['contact_id'],
            'where'            => [
                ['group_id', '=', $civi_group_id],
                ['status',   '=', 'Added'],
            ],
        ]);
        $contact_ids = [];
        foreach ($member_rows as $row) {
            if (!empty($row['contact_id'])) { $contact_ids[(int) $row['contact_id']] = TRUE; }
        }
        $contact_ids = array_keys($contact_ids);
        wachthond($extdebug, 3, 'gewone groep contact_ids count', count($contact_ids));

        if (!empty($contact_ids)) {
            $emails = _googlesync_primary_emails($contact_ids);
        }
    }

    // Uniek + opgeschoond.
    $emails = array_values(array_unique(array_filter($emails)));
    wachthond($extdebug, 3, 'group emails (geknipt)', _googlesync_clip($emails));
    wachthond($extdebug, 1, "### GOOGLESYNC GET GROUP EMAILS [civi $civi_group_id]",     "[KLAAR]");

    return $emails;
}

/**
 * (Intern) Geeft de primaire, verzendbare e-mailadressen van een set contact-id's.
 * Filtert on-hold, opt-out, do-not-email en verwijderde contacten eruit.
 *
 * @param array $contact_ids  Lijst contact-id's
 *
 * @return array  lowercase e-mailadressen
 */
function _googlesync_primary_emails(array $contact_ids): array {

    if (empty($contact_ids)) { return []; }

    $rows = civicrm_api4('Email', 'get', [
        'checkPermissions' => FALSE,
        'select'           => ['email'],
        'where'            => [
            ['contact_id',              'IN', $contact_ids],
            ['is_primary',              '=',  TRUE],
            ['on_hold',                 '=',  0],
            ['contact_id.is_opt_out',   '=',  FALSE],
            ['contact_id.do_not_email', '=',  FALSE],
            ['contact_id.is_deleted',   '=',  FALSE],
            ['email',                   'IS NOT NULL'],
        ],
    ]);

    $emails = [];
    foreach ($rows as $row) {
        if (!empty($row['email'])) { $emails[] = strtolower($row['email']); }
    }
    return $emails;
}

/**
 * (Intern) Kampkort + notiftype → gewenste e-mailadressen.
 *
 * Dunne wrapper voor de kampkort-gebaseerde sync (googlesync_sync_group). Resolvet
 * kampkort+notiftype naar de bijbehorende CiviCRM-groep en delegeert naar
 * googlesync_get_group_emails(). De koppeling loopt via de Google Group ID:
 *   kampkort+notiftype --(kampmap)--> google_group_id --(configured groups)--> civi_group_id.
 *
 * @param string $kampkort   Bijv. 'kk1', 'bk2', 'top'
 * @param string $notiftype  'notif_deel', 'notif_leid', 'notif_kamp', 'notif_staf'
 *
 * @return array  ['success' => bool, 'data' => [email, ...], 'error' => string]
 */
function _googlesync_get_civicrm_emails(string $kampkort, string $notiftype): array {

    $extdebug = 'googlesync.sync';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC GET CIVICRM EMAILS [$kampkort][$notiftype]", "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // Stap 1: Google Group ID bepalen uit de kampmap.
    $google_group_id = googlesync_get_group_id($kampkort, $notiftype);
    if (!$google_group_id) {
        $error = "Geen Google Group ID voor [$kampkort][$notiftype] (mogelijk notif_staf zonder ID).";
        wachthond($extdebug, 1, "### GOOGLESYNC SKIP", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    // Stap 2: De gekoppelde CiviCRM-groep (+ evt. saved search) opzoeken via de
    // autoritatieve configured-groups lijst, gematcht op de Google Group ID.
    $configured = googlesync_get_configured_groups();
    $match = NULL;
    foreach ($configured as $details) {
        if ($details['google_group_id'] === $google_group_id) {
            $match = $details;
            break;
        }
    }

    if (!$match) {
        $error = "Google Group $google_group_id is niet gekoppeld in googlegroups (geen CiviCRM-groep gevonden).";
        wachthond($extdebug, 1, "### GOOGLESYNC SKIP", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    wachthond($extdebug, 3, 'gematchte civi groep', $match['civi_group_id'] . ' / ' . $match['civi_group_name']);

    // Stap 3: Delegeer naar de centrale resolver.
    $emails = googlesync_get_group_emails($match['civi_group_id'], $match['saved_search_id'] ?? NULL);

    // NULL = onbepaald → signaleer als fout zodat sync_group de groep overslaat (niet leegmaakt).
    if ($emails === NULL) {
        $error = "Gewenste lijst onbepaald voor civi groep {$match['civi_group_id']} — groep overgeslagen.";
        wachthond($extdebug, 1, "### GOOGLESYNC OVERSLAAN", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    wachthond($extdebug, 1, "### GOOGLESYNC GET CIVICRM EMAILS [$kampkort][$notiftype]", "[KLAAR]");

    return ['success' => TRUE, 'data' => $emails, 'error' => ''];
}

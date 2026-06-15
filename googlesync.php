<?php

/**
 * =======================================================================================
 * googlesync.php — nl.onvergetelijk.googlesync
 * =======================================================================================
 * Entry point voor de Google Workspace Sync extensie van OZK.
 *
 * Deze extensie synchroniseert notificatie-e-mailadressen van contacten naar
 * Google Workspace Groups. De drie notifcatietypes zijn:
 *   - notif_deel  (location_type_id 16): deelnemersgerichte notificaties
 *   - notif_leid  (location_type_id 17): leidingsgerichte notificaties
 *   - notif_kamp  (location_type_id 18): kampbrede notificaties
 *
 * De extensie biedt:
 *   1. Helper-functies om direct één adres toe te voegen of te verwijderen
 *      (aanroepbaar vanuit nl.onvergetelijk.email en nl.onvergetelijk.acl)
 *   2. Bulk sync-functies: één kamp, één notiftype, of alle kampen tegelijk
 *   3. Opvraag-functies: huidige ledenlijst vanuit Google Workspace ophalen
 *
 * Credentials: we hergebruiken de OAuth2-instellingen van org.civicrm.googlegroups
 * (opgeslagen in civicrm_setting onder 'googlegroups_settings'), maar via onze
 * eigen stabiele client-wrapper met betere foutafhandeling.
 *
 * =======================================================================================
 * BESTAND-INDEX
 * =======================================================================================
 *   googlesync.php          — dit bestand: hooks + require's
 *   googlesync.kampdata.php — kampkort → Google Group ID mappings
 *   googlesync.helpers.php  — laag-niveau Google API wrappers (client, subscribe, delete, getmembers)
 *   googlesync.sync.php     — hoog-niveau sync logica (één groep / één kamp / alles)
 * =======================================================================================
 */

require_once 'googlesync.civix.php';
require_once 'googlesync.kampdata.php';
require_once 'googlesync.helpers.php';
require_once 'googlesync.sync.php';

use CRM_Googlesync_ExtensionUtil as E;

// =======================================================================================
// CIVIX HOOKS — automatisch gegenereerd, niet aanpassen
// =======================================================================================

/**
 * Implements hook_civicrm_config().
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function googlesync_civicrm_config(&$config): void {
    _googlesync_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function googlesync_civicrm_install(): void {
    _googlesync_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function googlesync_civicrm_enable(): void {
    _googlesync_civix_civicrm_enable();
}

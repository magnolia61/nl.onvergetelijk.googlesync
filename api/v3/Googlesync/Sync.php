<?php

/**
 * =======================================================================================
 * APIv3 actie: Googlesync.sync
 * =======================================================================================
 * Wrapper rond de sync-functies zodat de sync vanuit een CiviCRM Scheduled Job (of
 * handmatig via `cv api3 Googlesync.sync`) gedraaid kan worden.
 *
 * Parameters:
 *   scope    'configured' (default) = alle gekoppelde groepen | 'all' = kampmap-notif-groepen
 *   dry_run  1 = alleen berekenen, niets wijzigen (default 0)
 * =======================================================================================
 */

/**
 * Spec van de parameters.
 */
function _civicrm_api3_googlesync_sync_spec(&$spec) {
    $spec['scope'] = [
        'title'        => 'Scope',
        'description'  => "'configured' (alle gekoppelde groepen) of 'all' (kampmap notif-groepen)",
        'type'         => CRM_Utils_Type::T_STRING,
        'api.default'  => 'configured',
    ];
    $spec['dry_run'] = [
        'title'        => 'Dry run',
        'description'  => '1 = alleen berekenen, niets wijzigen op Google',
        'type'         => CRM_Utils_Type::T_BOOLEAN,
        'api.default'  => 0,
    ];
}

/**
 * Googlesync.sync — draait de sync en geeft een samenvatting terug.
 */
function civicrm_api3_googlesync_sync($params) {

    $extdebug = 'googlesync.api';
    $scope    = ($params['scope'] ?? 'configured') === 'all' ? 'all' : 'configured';
    $dry_run  = !empty($params['dry_run']);

    wachthond($extdebug, 1, "### GOOGLESYNC API sync", "scope=$scope dry_run=" . ($dry_run ? '1' : '0'));

    // Draai de gekozen sync.
    if ($scope === 'all') {
        $resultaten = googlesync_sync_all([], $dry_run);
        // sync_all is genest [kampkort][notiftype]; plat slaan voor de telling.
        $plat = [];
        foreach ($resultaten as $perType) {
            foreach ($perType as $r) { $plat[] = $r; }
        }
    } else {
        $resultaten = googlesync_sync_configured([], $dry_run);
        $plat = array_values($resultaten);
    }

    // Samenvatting opbouwen.
    $tot_toegevoegd = 0;
    $tot_verwijderd = 0;
    $tot_fouten     = 0;
    foreach ($plat as $r) {
        $tot_toegevoegd += count($r['toegevoegd'] ?? []);
        $tot_verwijderd += count($r['verwijderd'] ?? []);
        if (!($r['success'] ?? TRUE)) { $tot_fouten++; }
    }

    $samenvatting = [
        'scope'           => $scope,
        'dry_run'         => $dry_run,
        'groepen'         => count($plat),
        'toegevoegd'      => $tot_toegevoegd,
        'verwijderd'      => $tot_verwijderd,
        'fouten'          => $tot_fouten,
    ];
    wachthond($extdebug, 1, "### GOOGLESYNC API sync KLAAR", $samenvatting);

    return civicrm_api3_create_success([$samenvatting], $params, 'Googlesync', 'sync');
}

<?php

/**
 * =======================================================================================
 * googlesync.helpers.php
 * =======================================================================================
 * Laag-niveau wrappers rond de Google Workspace Directory API.
 *
 * Deze functies vormen de directe brug naar Google. Ze zijn bewust eenvoudig gehouden:
 * één ding doen, foutmeldingen netjes teruggeven, geen sync-logica bevatten.
 *
 * Credentials: we hergebruiken de OAuth2-token die ooit is opgeslagen via org.civicrm.googlegroups
 * (civicrm_setting 'googlegroups_settings'), maar lezen/schrijven die rechtstreeks via de
 * Setting-API — geen dependency meer op de klassen van die extensie, dus werkt ook als
 * die extensie disabled is. Zo hoef je niet opnieuw te autoriseren.
 * De Google PHP client library is afkomstig uit de vendor-map van diezelfde extensie
 * (puur een bestandspad, geen CiviCRM-hook — werkt dus ook als die extensie disabled is).
 *
 * Alle functies retourneren een resultaat-array met de sleutels:
 *   'success'  bool    TRUE als de API-call geslaagd is
 *   'data'     mixed   Retourdata bij succes (array of lege array)
 *   'error'    string  Foutmelding bij mislukking (leeg string bij succes)
 *
 * =======================================================================================
 * FUNCTIE-INDEX
 * =======================================================================================
 *   _googlesync_clip()             — (intern) knipt grote arrays in voor leesbare debug
 *   googlesync_get_client()        — initialiseert en retourneert een Google_Client
 *   googlesync_subscribe()         — voegt e-mailadressen toe aan een Google Group
 *   googlesync_deletemember()      — verwijdert e-mailadressen uit een Google Group
 *   googlesync_getmembers()        — haalt de huidige ledenlijst op (via officiële Googlegroups API)
 *   googlesync_getgroups()         — haalt alle Google Groups op (via officiële Googlegroups API)
 * =======================================================================================
 */

// =======================================================================================
// 0. DEBUG-HELPER: GEKNIPTE LOGGING
// =======================================================================================

/**
 * Knipt een (mogelijk grote) array in zodat de debug-logs leesbaar blijven.
 *
 * Een Google Group kan honderden leden hebben; die volledig loggen maakt de logs
 * onbruikbaar. Deze helper geeft het totaal aantal terug plus de eerste $max items.
 *
 * @param array $data   De array om te knippen
 * @param int   $max    Maximaal aantal items om te tonen (default 10)
 *
 * @return array  ['totaal' => int, 'getoond' => int, 'sample' => array]
 */
function _googlesync_clip(array $data, int $max = 10): array {
    return [
        'totaal'  => count($data),
        'getoond' => min(count($data), $max),
        'sample'  => array_slice($data, 0, $max, TRUE),
    ];
}

// =======================================================================================
// 1. CLIENT
// =======================================================================================

/**
 * Initialiseert de Google_Client met het token uit de org.civicrm.googlegroups instellingen.
 *
 * We laden de vendor autoload van de officiële extensie zodat we geen dubbele dependency
 * nodig hebben. Als die extensie ooit verdwijnt, kunnen we hier eenvoudig overstappen
 * op onze eigen vendor map door het pad te wijzigen.
 *
 * @return array  ['success' => bool, 'client' => Google_Client|NULL, 'error' => string]
 */
function googlesync_get_client(): array {

    $extdebug = 'googlesync.helpers';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC 1.0 GET CLIENT",                              "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // Laad de Google PHP Client Library via de vendor-map van org.civicrm.googlegroups.
    // Die extensie beheert de OAuth2-credentials en we hergebruiken ze hier.
    $vendor_autoload = dirname(__DIR__) . '/org.civicrm.googlegroups/vendor/autoload.php';
    if (!file_exists($vendor_autoload)) {
        $error = "Google vendor autoload niet gevonden op: $vendor_autoload";
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR: $error");
        return ['success' => FALSE, 'client' => NULL, 'error' => $error];
    }
    include_once $vendor_autoload;

    // Haal de opgeslagen OAuth2-instellingen rechtstreeks op via de Setting-API. Dit is
    // dezelfde civicrm_setting ('googlegroups_settings') die org.civicrm.googlegroups
    // gebruikt, maar we lezen 'm direct — zo blijven we werken ongeacht of die extensie
    // actief is (haar CRM_Googlegroups_Utils-klasse laadt anders niet).
    $params_setting_get = [
        'name' => 'googlegroups_settings',
    ];
    wachthond($extdebug, 7, 'params_setting_get', $params_setting_get);
    $settings = civicrm_api3('Setting', 'getvalue', $params_setting_get);
    wachthond($extdebug, 9, 'settings (gefilterd — geen secrets in log)', [
        'client_id_aanwezig'      => !empty($settings['client_id']),
        'client_secret_aanwezig'  => !empty($settings['client_secret']),
        'access_token_aanwezig'   => !empty($settings['access_token']),
        'domeinen'                => $settings['domains'] ?? [],
    ]);

    if (empty($settings['client_id']) || empty($settings['client_secret'])) {
        $error = "Google credentials niet geconfigureerd in org.civicrm.googlegroups instellingen.";
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR: $error");
        return ['success' => FALSE, 'client' => NULL, 'error' => $error];
    }

    // Bouw de Google_Client op met de opgeslagen credentials.
    $client = new Google_Client();
    $client->setClientId($settings['client_id']);
    $client->setClientSecret($settings['client_secret']);
    $client->setApplicationName('OZK CiviCRM GoogleSync');
    $client->setAccessType('offline');
    $client->addScope(Google_Service_Directory::ADMIN_DIRECTORY_GROUP);

    // Haal het opgeslagen token op en ververs het.
    // fetchAccessTokenWithRefreshToken() geeft een nieuw access token en slaat dit op.
    if (empty($settings['access_token'])) {
        $error = "Geen opgeslagen Google access token. Her-autorisatie vereist via org.civicrm.googlegroups instellingen.";
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR: $error");
        return ['success' => FALSE, 'client' => NULL, 'error' => $error];
    }

    $client->fetchAccessTokenWithRefreshToken($settings['access_token']);

    // Controleer of het token na het verversen nog steeds geldig is.
    if ($client->isAccessTokenExpired()) {
        $error = "Google access token verlopen en kan niet worden ververst. Her-autorisatie vereist.";
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR: $error");
        // Wis het verlopen token zodat de admin op de settingspagina ziet dat her-autorisatie nodig is.
        // (Zelfde logica als CRM_Googlegroups_Utils::setSettings(), maar zonder klasse-afhankelijkheid.)
        civicrm_api3('Setting', 'create', [
            'googlegroups_settings' => array_merge($settings, ['access_token' => '']),
        ]);
        return ['success' => FALSE, 'client' => NULL, 'error' => $error];
    }

    wachthond($extdebug, 1, "### GOOGLESYNC 1.0 GET CLIENT",                              "[OK]");

    return ['success' => TRUE, 'client' => $client, 'error' => ''];
}

// =======================================================================================
// 2. SUBSCRIBE
// =======================================================================================

/**
 * Voegt één of meerdere e-mailadressen toe aan een Google Group.
 *
 * @param string $google_group_id   De interne Workspace Group ID (hex string)
 * @param array  $emails            Platte array van e-mailadressen, bijv. ['a@b.nl', 'c@d.nl']
 * @param string $role              Google Workspace role: 'MEMBER' (default), 'OWNER', 'MANAGER'
 *
 * @return array  ['success' => bool, 'data' => array, 'error' => string]
 */
function googlesync_subscribe(string $google_group_id, array $emails, string $role = 'MEMBER'): array {

    $extdebug = 'googlesync.helpers';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC 2.0 SUBSCRIBE",                              "[$google_group_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Verwijder lege waarden en duplicaten om onnodige API-calls te voorkomen.
    $emails_clean = array_values(array_unique(array_filter($emails)));

    wachthond($extdebug, 3, 'google_group_id',  $google_group_id);
    wachthond($extdebug, 3, 'role',             $role);
    wachthond($extdebug, 3, 'emails_clean',     $emails_clean);

    if (empty($emails_clean)) {
        wachthond($extdebug, 1, "### GOOGLESYNC SKIP SUBSCRIBE: lege emails-lijst", $google_group_id);
        return ['success' => TRUE, 'data' => [], 'error' => ''];
    }

    // Haal de client op. Bij fout geven we de fout direct terug zonder verder te gaan.
    $client_result = googlesync_get_client();
    if (!$client_result['success']) {
        return ['success' => FALSE, 'data' => [], 'error' => $client_result['error']];
    }
    $client = $client_result['client'];

    // Stel de timeout in zodat het script niet onbeperkt kan hangen bij netwerkproblemen.
    $original_timeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', 15);

    try {
        // Gebruik batch-modus: alle inserts worden in één HTTP-request naar Google gestuurd.
        // Efficiënter dan één request per adres, en minder kans op rate-limit fouten.
        $client->setUseBatch(TRUE);
        $service  = new Google_Service_Directory($client);
        $batch    = $service->createBatch();

        foreach ($emails_clean as $email) {

            $params_member_insert = [
                'email' => $email,
                'role'  => $role,
            ];
            wachthond($extdebug, 7, 'params_member_insert', $params_member_insert);

            $member = new Google_Service_Directory_Member();
            $member->setEmail($email);
            $member->setRole($role);
            $batch->add($service->members->insert($google_group_id, $member));
        }

        $result_batch = $batch->execute();
        wachthond($extdebug, 9, 'result_batch subscribe', $result_batch);

    } catch (\Throwable $e) {
        ini_set('default_socket_timeout', $original_timeout);
        $client->setUseBatch(FALSE);
        $error = "GOOGLESYNC subscribe fout voor group $google_group_id: " . $e->getMessage();
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    ini_set('default_socket_timeout', $original_timeout);
    $client->setUseBatch(FALSE);

    wachthond($extdebug, 1, "### GOOGLESYNC 2.0 SUBSCRIBE",                              "[OK]");

    return ['success' => TRUE, 'data' => $result_batch ?? [], 'error' => ''];
}

// =======================================================================================
// 3. DELETEMEMBER
// =======================================================================================

/**
 * Verwijdert één of meerdere e-mailadressen uit een Google Group.
 *
 * We gebruiken de 'defer'-truc: door de client tijdelijk op defer-modus te zetten
 * voordat we members->delete() aanroepen, krijgen we een Request-object terug i.p.v.
 * een Response-object. Dat Request-object voegen we toe aan de batch. Zonder deze
 * truc geeft Google een Response terug aan de batch, wat recursiefouten geeft.
 *
 * @param string $google_group_id   De interne Workspace Group ID (hex string)
 * @param array  $emails            Platte array van e-mailadressen om te verwijderen
 *
 * @return array  ['success' => bool, 'data' => array, 'error' => string]
 */
function googlesync_deletemember(string $google_group_id, array $emails): array {

    $extdebug = 'googlesync.helpers';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC 3.0 DELETEMEMBER",                           "[$google_group_id]");
    wachthond($extdebug, 2, "########################################################################");

    // Schoon de lijst op.
    $emails_clean = array_values(array_unique(array_filter($emails)));

    wachthond($extdebug, 3, 'google_group_id',  $google_group_id);
    wachthond($extdebug, 3, 'emails_clean',     $emails_clean);

    if (empty($emails_clean)) {
        wachthond($extdebug, 1, "### GOOGLESYNC SKIP DELETEMEMBER: lege emails-lijst", $google_group_id);
        return ['success' => TRUE, 'data' => [], 'error' => ''];
    }

    $client_result = googlesync_get_client();
    if (!$client_result['success']) {
        return ['success' => FALSE, 'data' => [], 'error' => $client_result['error']];
    }
    $client = $client_result['client'];

    $original_timeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', 15);

    try {
        $service  = new Google_Service_Directory($client);
        $batch    = $service->createBatch();

        foreach ($emails_clean as $email) {

            wachthond($extdebug, 7, 'deletemember request voor email', $email);

            // 1. Tijdelijk op defer zodat members->delete() een Request teruggeeft
            //    en het niet meteen uitvoert. Zonder defer geeft het een Response,
            //    wat de batch laat crashen met een vage recursie-fout.
            $client->setDefer(TRUE);
            $request = $service->members->delete($google_group_id, $email);
            $batch->add($request);
            $client->setDefer(FALSE);
        }

        $result_batch = $batch->execute();
        wachthond($extdebug, 9, 'result_batch deletemember', $result_batch);

    } catch (\Throwable $e) {
        ini_set('default_socket_timeout', $original_timeout);
        $client->setDefer(FALSE);
        $error = "GOOGLESYNC deletemember fout voor group $google_group_id: " . $e->getMessage();
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    ini_set('default_socket_timeout', $original_timeout);

    wachthond($extdebug, 1, "### GOOGLESYNC 3.0 DELETEMEMBER",                           "[OK]");

    return ['success' => TRUE, 'data' => $result_batch ?? [], 'error' => ''];
}

// =======================================================================================
// 4. GETMEMBERS
// =======================================================================================

/**
 * Haalt de huidige ledenlijst op van één Google Group.
 *
 * Gebruikt dezelfde gedeelde client als subscribe/deletemember (googlesync_get_client()),
 * met paginatie via members->listMembers(). Voorheen liep dit via de API van
 * org.civicrm.googlegroups; die is overgenomen zodat deze extensie niet meer afhankelijk
 * is van het actief zijn van die extensie.
 *
 * De debug-logging is GEKNIPT via _googlesync_clip(): een groep kan honderden leden
 * hebben en die willen we niet integraal in de logs zien.
 *
 * @param string $google_group_id   De interne Workspace Group ID (hex string)
 *
 * @return array  ['success' => bool, 'data' => [member_id => email], 'error' => string]
 */
function googlesync_getmembers(string $google_group_id): array {

    $extdebug = 'googlesync.helpers';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC 4.0 GETMEMBERS",                             "[$google_group_id]");
    wachthond($extdebug, 2, "########################################################################");

    $client_result = googlesync_get_client();
    if (!$client_result['success']) {
        return ['success' => FALSE, 'data' => [], 'error' => $client_result['error']];
    }
    $client = $client_result['client'];

    $members = [];

    try {
        $service    = new Google_Service_Directory($client);
        $page_token = '';
        do {
            $opt_params = ['pageToken' => $page_token];
            wachthond($extdebug, 7, 'opt_params listMembers', $opt_params);
            $results = $service->members->listMembers($google_group_id, $opt_params);
            foreach ($results->getMembers() as $result) {
                $members[$result['id']] = $result['email'];
            }
            $page_token = $results->nextPageToken;
        } while ($page_token);
    } catch (\Throwable $e) {
        $error = "GOOGLESYNC getmembers fout voor group $google_group_id: " . $e->getMessage();
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    // GEKNIPTE debug: alleen totaal + een sample, niet de hele ledenlijst.
    wachthond($extdebug, 9, 'members (geknipt)', _googlesync_clip($members));

    wachthond($extdebug, 1, "### GOOGLESYNC 4.0 GETMEMBERS",                             "[OK]");

    return ['success' => TRUE, 'data' => $members, 'error' => ''];
}

// =======================================================================================
// 5. GETGROUPS
// =======================================================================================

/**
 * Haalt ALLE Google Groups op die binnen de geconfigureerde Workspace-domeinen bestaan.
 *
 * LET OP: dit is de complete lijst zoals Google die kent — NIET de lijst die in CiviCRM
 * gekoppeld is om te syncen. Voor die laatste, zie googlesync_get_configured_groups()
 * in googlesync.kampdata.php.
 *
 * Gebruikt dezelfde gedeelde client als de andere functies; de domeinlijst komt uit
 * dezelfde 'googlegroups_settings' (key 'domains'). Voorheen liep dit via de API van
 * org.civicrm.googlegroups; die is overgenomen zodat deze extensie niet meer afhankelijk
 * is van het actief zijn van die extensie.
 *
 * Retourneert een map van [google_group_id => 'domein:naam::email'].
 *
 * @return array  ['success' => bool, 'data' => [group_id => 'domein:naam::email'], 'error' => string]
 */
function googlesync_getgroups(): array {

    $extdebug = 'googlesync.helpers';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC 5.0 GETGROUPS",                              "[START]");
    wachthond($extdebug, 2, "########################################################################");

    $client_result = googlesync_get_client();
    if (!$client_result['success']) {
        return ['success' => FALSE, 'data' => [], 'error' => $client_result['error']];
    }
    $client = $client_result['client'];

    $settings = civicrm_api3('Setting', 'getvalue', ['name' => 'googlegroups_settings']);
    $domains  = $settings['domains'] ?? [];
    wachthond($extdebug, 7, 'domains', $domains);

    $groups = [];

    try {
        $service = new Google_Service_Directory($client);
        foreach ($domains as $domain) {
            $page_token = '';
            do {
                $opt_params = ['domain' => trim($domain), 'pageToken' => $page_token];
                wachthond($extdebug, 7, 'opt_params listGroups', $opt_params);
                $results = $service->groups->listGroups($opt_params);
                foreach ($results->getGroups() as $result) {
                    $groups[$result['id']] = "{$domain}:{$result['name']}::{$result['email']}";
                }
                $page_token = $results->nextPageToken;
            } while ($page_token);
        }
    } catch (\Throwable $e) {
        $error = "GOOGLESYNC getgroups fout: " . $e->getMessage();
        wachthond($extdebug, 1, "### GOOGLESYNC ERROR", $error);
        return ['success' => FALSE, 'data' => [], 'error' => $error];
    }

    // GEKNIPTE debug: er kunnen tientallen/honderden groepen zijn.
    wachthond($extdebug, 9, 'groups (geknipt)', _googlesync_clip($groups));

    wachthond($extdebug, 1, "### GOOGLESYNC 5.0 GETGROUPS",                              "[OK]");

    return ['success' => TRUE, 'data' => $groups, 'error' => ''];
}

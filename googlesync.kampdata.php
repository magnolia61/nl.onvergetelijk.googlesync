<?php

/**
 * =======================================================================================
 * googlesync.kampdata.php
 * =======================================================================================
 * Centrale databron: kampkort → Google Group IDs per notificatietype.
 *
 * Elk kamp heeft drie Google Groups (one-way notificatielijsten):
 *   - notif_deel  Ontvangers zijn ouders/deelnemers. E-mailadres = notif_deel van leiding
 *   - notif_leid  Ontvangers zijn leidinggevenden. E-mailadres = notif_leid van leiding
 *   - notif_kamp  Ontvangers zijn de hele kampgroep. E-mailadres = notif_kamp van leiding
 *
 * BUITEN SCOPE: notif_staf wordt door deze extensie BEWUST NIET met Google Groups
 * gesynct. De notif_staf_* CiviCRM-groepen bestaan wel, maar krijgen geen Google-sync.
 *
 * De Google Group ID is de interne Workspace-identifier (hex string), NIET het e-mailadres
 * van de groep. Deze ID is stabiel en verandert niet bij een naamswijziging van de groep.
 *
 * Bronnen:
 *   - nl.onvergetelijk.email/email.php (match-statements per notiftype)
 *   - nl.onvergetelijk.acl/acl.php (kampdata array, 'google' sleutel = notif_deel)
 *
 * =======================================================================================
 * FUNCTIE-INDEX
 * =======================================================================================
 *   googlesync_get_kampmap()           — retourneert de volledige kampkort→notiftype mapping
 *   googlesync_get_group_id()          — geeft één Google Group ID terug voor kamp + notiftype
 *   googlesync_get_configured_groups() — alle CiviCRM-groepen die in googlegroups zijn
 *                                        gekoppeld om te syncen (bron van waarheid uit DB)
 * =======================================================================================
 *
 * TWEE BRONNEN VAN GROEP-MAPPINGS:
 *   1. googlesync_get_kampmap()           — HARD-CODED per kampkort+notiftype. Gebruikt
 *                                           voor real-time sync van één contact (vanuit email.php),
 *                                           waar we vanuit een kampkort de juiste groep willen.
 *   2. googlesync_get_configured_groups() — LIVE uit de DB-tabel die de officiële
 *                                           org.civicrm.googlegroups extensie beheert. Dit is de
 *                                           AUTORITATIEVE lijst voor BULK sync: alles wat een admin
 *                                           via de UI heeft gekoppeld. Hiermee pakken we ook
 *                                           automatisch nieuwe/gewijzigde koppelingen mee.
 * =======================================================================================
 */

// =======================================================================================
// INTERNE DATA: kampkort → Google Group IDs
// =======================================================================================
// LET OP: dit is een functie (geen globale variabele) zodat de data niet per ongeluk
// overschreven kan worden door andere extensies.

/**
 * Retourneert de volledige kampmap: kampkort → [notiftype => google_group_id].
 *
 * Kampkorts: kk1, kk2, bk1, bk2, tk1, tk2, jk1, jk2, top
 * Notiftypes: notif_deel, notif_leid, notif_kamp  (notif_staf: buiten scope)
 *
 * @return array  [kampkort => [notiftype => google_group_id]]
 */
function googlesync_get_kampmap(): array {

    // De Google Group IDs zijn de interne Workspace hex-identifiers.
    // Ze zijn afkomstig uit de match-statements in nl.onvergetelijk.email/email.php
    // en de kampdata array in nl.onvergetelijk.acl/acl.php.
    return [
        'kk1' => [
            'notif_deel'  => '01baon6m3wo0451',
            'notif_leid'  => '00gjdgxs35zo5jv',
            'notif_kamp'  => '03whwml44cp9k45',        ],
        'kk2' => [
            'notif_deel'  => '00vx12273fgfnd5',
            'notif_leid'  => '01302m921zcwabc',
            'notif_kamp'  => '02fk6b3p0ikk00x',        ],
        'bk1' => [
            'notif_deel'  => '00lnxbz9161bbzw',
            'notif_leid'  => '0319y80a3e30qbg',
            'notif_kamp'  => '049x2ik50nuxsf9',        ],
        'bk2' => [
            'notif_deel'  => '0147n2zr2s87rx7',
            'notif_leid'  => '00kgcv8k0qhgq6a',
            'notif_kamp'  => '03rdcrjn4882pxp',        ],
        'tk1' => [
            'notif_deel'  => '02xcytpi1fs7xwo',
            'notif_leid'  => '02s8eyo137koo5d',
            'notif_kamp'  => '01ksv4uv0jwe4ss',        ],
        'tk2' => [
            'notif_deel'  => '01opuj5n2028q4s',
            'notif_leid'  => '00kgcv8k2o7zuqf',
            'notif_kamp'  => '03l18frh2n6xt74',        ],
        'jk1' => [
            'notif_deel'  => '02bn6wsx3827ior',
            'notif_leid'  => '03oy7u294kt7vtn',
            'notif_kamp'  => '01baon6m39ciju5',        ],
        'jk2' => [
            'notif_deel'  => '030j0zll0m5pg5h',
            'notif_leid'  => '04i7ojhp2hwyjk2',
            'notif_kamp'  => '0279ka6533bbb65',        ],
        'top' => [
            'notif_deel'  => '00haapch3zvbjru',
            'notif_leid'  => '017dp8vu3t3rwb4',
            'notif_kamp'  => '00kgcv8k1t3von2',        ],
    ];
}

/**
 * Geeft de Google Group ID terug voor een specifiek kamp en notificatietype.
 *
 * @param string $kampkort    Bijv. 'kk1', 'bk2', 'top'
 * @param string $notiftype   'notif_deel', 'notif_leid', of 'notif_kamp'
*
 * @return string|NULL        Google Group ID, of NULL als combinatie onbekend is
 */
function googlesync_get_group_id(string $kampkort, string $notiftype): ?string {

    $extdebug = 'googlesync.kampdata';

    $kampmap = googlesync_get_kampmap();

    $google_group_id = $kampmap[$kampkort][$notiftype] ?? NULL;

    wachthond($extdebug, 3, "googlesync_get_group_id [$kampkort][$notiftype]", $google_group_id ?? 'NULL');

    return $google_group_id;
}

// =======================================================================================
// KAMPMAILBOX — de eigen postbus van elk kamp (moet altijd in de notif-groepen)
// =======================================================================================

/**
 * De notif-types waarin de eigen kampmailbox ALTIJD aanwezig moet zijn.
 * Bewust NIET notif_staf (die heeft geen kampmailbox-rol).
 */
function googlesync_kampmailbox_notiftypes(): array {
    return ['notif_deel', 'notif_leid', 'notif_kamp'];
}

/**
 * Geeft het vaste kampmailbox-adres voor een kampkort.
 *
 * Deze postbussen moeten standaard in notif_deel/leid/kamp zitten (zie
 * googlesync_kampmailbox_notiftypes) — ook als CiviCRM ze niet als lid kent.
 * De sync voegt ze daarom altijd toe aan de gewenste lijst (en verwijdert ze
 * dus nooit, want wat in de gewenste lijst staat wordt nooit ge-unsubscribed).
 *
 * @param string $kampkort  Bijv. 'kk1', 'bk2', 'top'
 *
 * @return string|NULL  bijv. 'kinderkamp1@onvergetelijk.nl', of NULL bij onbekend kampkort
 */
function googlesync_get_kampmailbox(string $kampkort): ?string {

    // kampkort -> lokaal deel van het mailadres (@onvergetelijk.nl).
    $mailbox_map = [
        'kk1' => 'kinderkamp1', 'kk2' => 'kinderkamp2',
        'bk1' => 'brugkamp1',   'bk2' => 'brugkamp2',
        'tk1' => 'tienerkamp1', 'tk2' => 'tienerkamp2',
        'jk1' => 'jeugdkamp1',  'jk2' => 'jeugdkamp2',
        'top' => 'topkamp',
    ];

    $local = $mailbox_map[strtolower($kampkort)] ?? NULL;

    return $local ? $local . '@onvergetelijk.nl' : NULL;
}

// =======================================================================================
// CONFIGURED GROUPS — alle koppelingen die in googlegroups zijn ingesteld om te syncen
// =======================================================================================

/**
 * Haalt alle CiviCRM-groepen op die via de officiële org.civicrm.googlegroups extensie
 * gekoppeld zijn aan een Google Group (en dus geconfigureerd zijn om te syncen).
 *
 * De koppeling wordt door die extensie opgeslagen in de aangepaste-veld-tabel
 * 'civicrm_value_googlegroup_settings':
 *   - entity_id    = CiviCRM group ID
 *   - gc_group_id  = Google Workspace Group ID (hex string)
 *
 * Alleen rijen met een NIET-lege gc_group_id zijn daadwerkelijk gekoppeld; groepen
 * zonder Google Group ID (zoals de notif_staf_* groepen op dit moment) worden
 * overgeslagen.
 *
 * Dit is de AUTORITATIEVE lijst voor bulk sync: wat een admin via de UI heeft
 * gekoppeld, wordt hier teruggegeven — inclusief toekomstige koppelingen.
 *
 * @return array  [
 *     civi_group_id => [
 *         'civi_group_id'   => int,
 *         'civi_group_name' => string,
 *         'civi_group_title'=> string,
 *         'google_group_id' => string,
 *         'saved_search_id' => int|NULL,   // gevuld = smart group (bron = saved search)
 *     ],
 *     ...
 * ]
 */
/**
 * Groepsnamen die NOOIT meegesynct worden (test-/wegwerpgroepen).
 * Uitbreidbaar; match is op de exacte machine-naam van de CiviCRM-groep.
 */
function googlesync_excluded_group_names(): array {
    return [
        'notif_emails_test_1744',   // testgroep, niet in productie syncen
    ];
}

function googlesync_get_configured_groups(): array {

    $extdebug = 'googlesync.kampdata';

    wachthond($extdebug, 2, "########################################################################");
    wachthond($extdebug, 1, "### GOOGLESYNC GET CONFIGURED GROUPS",                       "[START]");
    wachthond($extdebug, 2, "########################################################################");

    // We lezen rechtstreeks de koppeltabel van de officiële extensie. Een directe query
    // is hier het meest betrouwbaar: het is precies de tabel die de UI ook vult, en we
    // joinen met civicrm_group voor naam/titel. (APIv4 kent deze custom-tabel niet als entity.)
    $sql = "
        SELECT  s.entity_id            AS civi_group_id,
                g.name                 AS civi_group_name,
                g.title                AS civi_group_title,
                s.gc_group_id          AS google_group_id,
                g.saved_search_id      AS saved_search_id
        FROM    civicrm_value_googlegroup_settings s
        INNER JOIN civicrm_group g ON g.id = s.entity_id
        WHERE   s.gc_group_id IS NOT NULL
          AND   s.gc_group_id <> ''
          AND   g.is_active = 1
        ORDER BY g.name
    ";
    wachthond($extdebug, 7, 'sql configured groups', $sql);

    // Groepen die we NOOIT meesyncen (test-/wegwerpgroepen).
    $uitgesloten = googlesync_excluded_group_names();

    $dao    = CRM_Core_DAO::executeQuery($sql);
    $groups = [];

    while ($dao->fetch()) {
        if (in_array($dao->civi_group_name, $uitgesloten, TRUE)) {
            wachthond($extdebug, 3, 'configured group UITGESLOTEN', $dao->civi_group_name);
            continue;
        }
        $groups[$dao->civi_group_id] = [
            'civi_group_id'    => (int) $dao->civi_group_id,
            'civi_group_name'  => $dao->civi_group_name,
            'civi_group_title' => $dao->civi_group_title,
            'google_group_id'  => $dao->google_group_id,
            'saved_search_id'  => $dao->saved_search_id ? (int) $dao->saved_search_id : NULL,
        ];
    }

    wachthond($extdebug, 3, 'configured groups count', count($groups));
    wachthond($extdebug, 1, "### GOOGLESYNC GET CONFIGURED GROUPS",                       "[OK]");

    return $groups;
}

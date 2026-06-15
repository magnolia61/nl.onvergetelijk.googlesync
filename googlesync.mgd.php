<?php

/**
 * Managed entity: dagelijkse Scheduled Job die Googlesync.sync draait.
 *
 * Verschijnt in Beheer → Systeeminstellingen → Scheduled Jobs. Wordt automatisch
 * aangemaakt bij het inschakelen van de extensie. 'update' => 'unmodified' zorgt dat
 * handmatige aanpassingen (bijv. aan/uit of frequentie) door een admin NIET overschreven
 * worden bij een volgende cache-flush.
 */
return [
    [
        'name'    => 'Cron_Googlesync_Sync',
        'entity'  => 'Job',
        'update'  => 'unmodified',
        'cleanup' => 'always',
        'params'  => [
            'version'       => 4,
            'values'        => [
                'name'          => 'Onvergetelijk - Google Sync',
                'description'   => 'Synct de in googlegroups gekoppelde CiviCRM-groepen naar Google Workspace (eigen extensie nl.onvergetelijk.googlesync). Vervangt de officiële Google Groups Sync.',
                'run_frequency' => 'Daily',
                'api_entity'    => 'Googlesync',
                'api_action'    => 'sync',
                'parameters'    => "scope=configured",
                'is_active'     => TRUE,
            ],
        ],
    ],
];

<?php

namespace Civi\Googlesync;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests voor de centrale resolver googlesync_get_group_emails() en
 * googlesync_get_configured_groups().
 *
 * @group e2e
 *
 * De resolver bepaalt WIE er in een Google-groep hoort. Cruciaal is het RETURN-CONTRACT:
 *   - array  = bepaald (mag leeg zijn → groep leegmaken)
 *   - NULL   = onbepaald → groep OVERSLAAN (nooit per ongeluk leegmaken)
 *
 * Scenario's:
 *   A: Gewone groep → primaire adressen van 'Added' leden
 *   B: Niet-bestaande saved search → NULL (overslaan, niet leegmaken)
 *   C: googlesync_get_configured_groups() structuur + uitsluiting testgroep
 */
class ResolverTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('googlesync_get_group_emails')) {
      $this->markTestSkipped('googlesync-functies niet beschikbaar; is nl.onvergetelijk.googlesync geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### SCENARIO A: GEWONE GROEP → PRIMAIRE ADRESSEN VAN 'ADDED' LEDEN
  // ########################################################################

  public function testGewoneGroepGeeftPrimaireAdressen() {
    // Maak een gewone (niet-smart) groep.
    $groep = civicrm_api4('Group', 'create', [
      'checkPermissions' => FALSE,
      'values' => [
        'name'       => 'gs_test_plain_' . uniqid(),
        'title'      => 'GS Test Plain',
        'is_active'  => TRUE,
      ],
    ])->first();
    $gid = $groep['id'];

    // Contact met primair adres, lid (Added) van de groep.
    $cid = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name'   => 'Resolver',
      'last_name'    => 'Test',
    ])['id'];
    civicrm_api4('Email', 'create', [
      'checkPermissions' => FALSE,
      'values' => ['contact_id' => $cid, 'email' => 'Resolver.Test@Voorbeeld.NL', 'is_primary' => TRUE],
    ]);
    civicrm_api4('GroupContact', 'create', [
      'checkPermissions' => FALSE,
      'values' => ['group_id' => $gid, 'contact_id' => $cid, 'status' => 'Added'],
    ]);

    $emails = googlesync_get_group_emails($gid, NULL);

    $this->assertIsArray($emails);
    // Lowercase genormaliseerd:
    $this->assertContains('resolver.test@voorbeeld.nl', $emails);
  }

  // ########################################################################
  // ### SCENARIO B: NIET-BESTAANDE SAVED SEARCH → NULL (OVERSLAAN)
  // ########################################################################

  public function testOnbekendeSavedSearchGeeftNull() {
    // Een saved_search_id dat niet bestaat → resolver kan niets bepalen → NULL.
    // Dit is de veiligheidsklep: caller MOET de groep dan overslaan i.p.v. leegmaken.
    $resultaat = googlesync_get_group_emails(999999, 999999999);
    $this->assertNull($resultaat, 'onbekende saved search moet NULL (overslaan) geven, geen lege array');
  }

  // ########################################################################
  // ### SCENARIO C: CONFIGURED GROUPS — STRUCTUUR + UITSLUITING
  // ########################################################################

  public function testConfiguredGroupsStructuurEnUitsluiting() {
    $groepen = googlesync_get_configured_groups();
    $this->assertIsArray($groepen);

    // De testgroep moet zijn uitgesloten.
    foreach ($groepen as $g) {
      $this->assertNotSame('notif_emails_test_1744', $g['civi_group_name'],
        'uitgesloten testgroep mag niet in configured groups zitten');
    }

    // Elke entry heeft de verwachte sleutels.
    if (!empty($groepen)) {
      $eerste = reset($groepen);
      foreach (['civi_group_id', 'civi_group_name', 'civi_group_title', 'google_group_id', 'saved_search_id'] as $sleutel) {
        $this->assertArrayHasKey($sleutel, $eerste);
      }
      // google_group_id is altijd gevuld (filter in de query).
      $this->assertNotEmpty($eerste['google_group_id']);
    }
  }

}

<?php

namespace Civi\Googlesync;

use Civi\Test\EndToEndInterface;

/**
 * Cross-extensie tests: wat doen nl.onvergetelijk.email en nl.onvergetelijk.acl
 * met betrekking tot Google-sync, en blijven die consistent met deze extensie?
 *
 * @group e2e
 *
 * Achtergrond (zie ook MEMORY.md van deze extensie):
 *   - email.php = REAL-TIME sync per contact via match($kampkort) op de RAW value, met de
 *     wrappers googlegroup_subscribe()/deletemember() → officiële Googlegroups-API.
 *   - acl.php   = GEEN actieve Google-sync (meer); bewaart alleen Group IDs als referentie.
 *
 * Deze tests bewaken die afspraken zodat de drie sporen niet uit elkaar lopen.
 */
class IntegratieEmailAclTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  private function extDir(): string {
    return realpath(__DIR__ . '/../../../../..');  // .../civicrm_extensions
  }

  // ########################################################################
  // ### EMAIL: real-time mapping consistent met de kampmap
  // ########################################################################

  /**
   * De Google Group IDs die email.php hardcoded in zijn match()-blokken gebruikt,
   * MOETEN gelijk zijn aan de kampmap van deze extensie. Anders pushen de real-time
   * sync (email.php) en de drift-correctie (googlesync) naar verschillende groepen.
   */
  public function testEmailMappingConsistentMetKampmap() {
    if (!function_exists('googlesync_get_kampmap')) {
      $this->markTestSkipped('googlesync niet beschikbaar.');
    }
    $emailPhp = $this->extDir() . '/nl.onvergetelijk.email/email.php';
    if (!is_readable($emailPhp)) {
      $this->markTestSkipped("email.php niet gevonden op $emailPhp");
    }
    $src = file_get_contents($emailPhp);

    foreach (googlesync_get_kampmap() as $kampkort => $perType) {
      foreach ($perType as $notiftype => $googleId) {
        if (empty($googleId)) {
          continue;
        }
        // Verwacht in email.php een regel als:  'kk1'   => '01baon6m3wo0451',
        $patroon = "/'" . preg_quote($kampkort, '/') . "'\s*=>\s*'" . preg_quote($googleId, '/') . "'/";
        $this->assertMatchesRegularExpression(
          $patroon, $src,
          "email.php mist of wijkt af voor [$kampkort][$notiftype] => $googleId"
        );
      }
    }
  }

  /**
   * De real-time sync leunt op deze wrapper-functies (in email.helpers.php).
   */
  public function testEmailWrappersBestaan() {
    if (!function_exists('googlegroup_subscribe') || !function_exists('googlegroup_deletemember')) {
      $this->markTestSkipped('email-wrappers niet geladen; is nl.onvergetelijk.email actief?');
    }
    $this->assertTrue(function_exists('googlegroup_subscribe'));
    $this->assertTrue(function_exists('googlegroup_deletemember'));
  }

  // ########################################################################
  // ### DUBBELCHECK: koppeling CiviCRM-groep ↔ juiste Google-groep (kamp)
  // ########################################################################

  /**
   * Voor elke gekoppelde groep met een kampkort in de naam (notif_*, ditjaardeel_*,
   * ditjaarleid_*, …) moet de gekoppelde Google-groep hetzelfde kamp betreffen.
   * Vangt mismappings zoals destijds bij notif_leid (civi=bk1, Google=bk2).
   *
   * Live test: vraagt de Google-groepsnamen op via de officiële API.
   */
  public function testConfiguredKoppelingenKampConsistent() {
    if (!function_exists('googlesync_get_configured_groups') || !function_exists('_googlesync_kampkort_uit_naam')) {
      $this->markTestSkipped('googlesync niet beschikbaar.');
    }
    $gg = googlesync_getgroups();
    if (empty($gg['success'])) {
      $this->markTestSkipped('getgroups niet beschikbaar (geen Google-verbinding in deze omgeving).');
    }
    $google_namen = $gg['data'];

    $gecontroleerd = 0;
    foreach (googlesync_get_configured_groups() as $g) {
      $kk_civi = _googlesync_kampkort_uit_naam($g['civi_group_name']);
      $g_naam  = $google_namen[$g['google_group_id']] ?? NULL;
      if (!$kk_civi || !$g_naam) {
        continue;  // geen kampkort in naam, of Google-naam onbekend → niet te checken
      }
      $kk_google = _googlesync_kampkort_uit_naam($g_naam);
      if (!$kk_google) {
        continue;
      }
      $gecontroleerd++;
      $this->assertSame($kk_civi, $kk_google,
        "Kamp-mismatch: CiviCRM-groep '{$g['civi_group_name']}' is gekoppeld aan Google-groep '$g_naam'");
    }
    $this->assertGreaterThan(0, $gecontroleerd, 'er zijn koppelingen met kampkort gecontroleerd');
  }

  // ########################################################################
  // ### ACL: doet GEEN actieve Google-sync (meer)
  // ########################################################################

  /**
   * acl.php mag GEEN actieve Googlegroups-API-aanroep bevatten. De oude sync-logica
   * hoort uitsluitend in archive/acl.php te staan.
   */
  public function testAclGeenActieveGoogleSync() {
    $aclPhp = $this->extDir() . '/nl.onvergetelijk.acl/acl.php';
    if (!is_readable($aclPhp)) {
      $this->markTestSkipped("acl.php niet gevonden op $aclPhp");
    }
    $src = file_get_contents($aclPhp);

    $this->assertStringNotContainsString("civicrm_api3('Googlegroups'", $src,
      'acl.php mag geen actieve Googlegroups-API-aanroep bevatten (sync hoort niet in acl)');
    $this->assertStringNotContainsString('googlegroup_subscribe(', $src,
      'acl.php mag geen googlegroup_subscribe-aanroep bevatten (sync hoort niet in acl)');
  }

  /**
   * acl.php hoort GEEN Google Group IDs (meer) te bevatten: dat was dode leftover-data
   * (de 'google'-sleutel in de kampmap werd nergens uitgelezen) en is opgeschoond.
   * Deze test bewaakt dat ze niet terugkruipen.
   */
  public function testAclBevatGeenGoogleIdsMeer() {
    $aclPhp = $this->extDir() . '/nl.onvergetelijk.acl/acl.php';
    if (!is_readable($aclPhp)) {
      $this->markTestSkipped("acl.php niet gevonden op $aclPhp");
    }
    $src = file_get_contents($aclPhp);
    $this->assertStringNotContainsString("'google' =>", $src,
      "acl.php hoort geen 'google'-sleutel (dode Google-ID-data) meer te bevatten");
    $this->assertStringNotContainsString('01baon6m3wo0451', $src,
      'opgeschoonde Google Group ID hoort niet meer in acl.php te staan');
  }

}

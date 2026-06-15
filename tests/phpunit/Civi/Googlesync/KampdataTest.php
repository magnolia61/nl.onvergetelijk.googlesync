<?php

namespace Civi\Googlesync;

use Civi\Test\EndToEndInterface;

/**
 * Tests voor de pure mapping-/logica-functies van nl.onvergetelijk.googlesync.
 *
 * @group e2e
 *
 * Dekt:
 *   - googlesync_get_kampmailbox()        kampkort → vaste kampmailbox
 *   - googlesync_kampmailbox_notiftypes() welke notif-types een kampmailbox krijgen
 *   - googlesync_get_group_id()           kampkort+notiftype → Google Group ID (kampmap)
 *   - googlesync_excluded_group_names()   uitgesloten groepen
 *   - _googlesync_apply_kampmailbox()     forceren van de kampmailbox in de gewenste lijst
 *   - _googlesync_clip()                  geknipte debug-helper
 */
class KampdataTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('googlesync_get_kampmailbox')) {
      $this->markTestSkipped('googlesync-functies niet beschikbaar; is nl.onvergetelijk.googlesync geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### KAMPMAILBOX-MAPPING
  // ########################################################################

  public function testKampmailboxMappingAlleKampen() {
    $verwacht = [
      'kk1' => 'kinderkamp1@onvergetelijk.nl',
      'kk2' => 'kinderkamp2@onvergetelijk.nl',
      'bk1' => 'brugkamp1@onvergetelijk.nl',
      'bk2' => 'brugkamp2@onvergetelijk.nl',
      'tk1' => 'tienerkamp1@onvergetelijk.nl',
      'tk2' => 'tienerkamp2@onvergetelijk.nl',
      'jk1' => 'jeugdkamp1@onvergetelijk.nl',
      'jk2' => 'jeugdkamp2@onvergetelijk.nl',
      'top' => 'topkamp@onvergetelijk.nl',
    ];
    foreach ($verwacht as $kampkort => $mailbox) {
      $this->assertSame($mailbox, googlesync_get_kampmailbox($kampkort), "kampmailbox voor $kampkort");
    }
  }

  public function testKampmailboxOnbekendKampkortGeeftNull() {
    $this->assertNull(googlesync_get_kampmailbox('xyz'));
    $this->assertNull(googlesync_get_kampmailbox(''));
  }

  public function testKampmailboxIsHoofdletterongevoelig() {
    $this->assertSame('kinderkamp1@onvergetelijk.nl', googlesync_get_kampmailbox('KK1'));
  }

  // ########################################################################
  // ### NOTIFTYPES DIE EEN KAMPMAILBOX KRIJGEN
  // ########################################################################

  public function testKampmailboxNotiftypes() {
    $types = googlesync_kampmailbox_notiftypes();
    $this->assertContains('notif_deel', $types);
    $this->assertContains('notif_leid', $types);
    $this->assertContains('notif_kamp', $types);
    // notif_staf valt bewust BUITEN scope:
    $this->assertNotContains('notif_staf', $types);
  }

  // ########################################################################
  // ### KAMPMAP: kampkort+notiftype → Google Group ID
  // ########################################################################

  public function testGetGroupIdBekend() {
    // Vaste IDs uit de kampmap (consistent met email.php).
    $this->assertSame('01baon6m3wo0451', googlesync_get_group_id('kk1', 'notif_deel'));
    $this->assertSame('00gjdgxs35zo5jv', googlesync_get_group_id('kk1', 'notif_leid'));
    $this->assertSame('03whwml44cp9k45', googlesync_get_group_id('kk1', 'notif_kamp'));
    $this->assertSame('017dp8vu3t3rwb4', googlesync_get_group_id('top', 'notif_leid'));
  }

  public function testGetGroupIdOnbekendGeeftNull() {
    $this->assertNull(googlesync_get_group_id('xyz', 'notif_deel'));
    $this->assertNull(googlesync_get_group_id('kk1', 'notif_onbekend'));
    // notif_staf staat niet (meer) in de kampmap:
    $this->assertNull(googlesync_get_group_id('kk1', 'notif_staf'));
  }

  // ########################################################################
  // ### UITGESLOTEN GROEPEN
  // ########################################################################

  public function testExcludedGroupNames() {
    $this->assertContains('notif_emails_test_1744', googlesync_excluded_group_names());
  }

  // ########################################################################
  // ### APPLY KAMPMAILBOX (forceren in de gewenste lijst)
  // ########################################################################

  public function testApplyKampmailboxVoegtToeBijDeel() {
    $emails = ['iemand@voorbeeld.nl'];
    $resultaat = _googlesync_apply_kampmailbox($emails, 'kk1', 'notif_deel');
    $this->assertContains('kinderkamp1@onvergetelijk.nl', $resultaat);
    $this->assertContains('iemand@voorbeeld.nl', $resultaat);
  }

  public function testApplyKampmailboxNietBijStaf() {
    $emails = ['iemand@voorbeeld.nl'];
    $resultaat = _googlesync_apply_kampmailbox($emails, 'kk1', 'notif_staf');
    $this->assertNotContains('kinderkamp1@onvergetelijk.nl', $resultaat);
    $this->assertSame($emails, $resultaat, 'staf-lijst moet onveranderd blijven');
  }

  public function testApplyKampmailboxIdempotent() {
    // Mailbox staat er al in → niet dubbel toevoegen.
    $emails = ['kinderkamp1@onvergetelijk.nl', 'iemand@voorbeeld.nl'];
    $resultaat = _googlesync_apply_kampmailbox($emails, 'kk1', 'notif_kamp');
    $aantal = count(array_keys($resultaat, 'kinderkamp1@onvergetelijk.nl'));
    $this->assertSame(1, $aantal, 'kampmailbox mag niet dubbel voorkomen');
  }

  // ########################################################################
  // ### KAMPKORT-TOKEN UIT NAAM (voor de consistentie-dubbelcheck)
  // ########################################################################

  public function testKampkortUitNaam() {
    $this->assertSame('kk1', _googlesync_kampkort_uit_naam('ditjaardeel_kk1_1325'));
    $this->assertSame('kk1', _googlesync_kampkort_uit_naam('onvergetelijk.nl:ditjaarleid_kk1::team-kk1@onvergetelijk.nl'));
    $this->assertSame('bk2', _googlesync_kampkort_uit_naam('notif_leid_bk2'));
    $this->assertSame('top', _googlesync_kampkort_uit_naam('ditjaardeel_top_1333'));
    // Geen kampkort in de naam → NULL.
    $this->assertNull(_googlesync_kampkort_uit_naam('DITJAAR_Bestuur_455'));
    $this->assertNull(_googlesync_kampkort_uit_naam('hoofdkeuken_alles_ACL'));
  }

  // ########################################################################
  // ### CLIP-HELPER
  // ########################################################################

  public function testClipKnaptGroteLijstIn() {
    $groot = range(1, 100);
    $clip = _googlesync_clip($groot, 10);
    $this->assertSame(100, $clip['totaal']);
    $this->assertSame(10, $clip['getoond']);
    $this->assertCount(10, $clip['sample']);
  }

}

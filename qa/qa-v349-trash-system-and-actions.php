<?php
/**
 * ============================================================================
 *  QA SUITE: Feria Ravan v3.4.9 — Universal Trash System, Soft-Deletes & Action Fixes
 * ============================================================================
 */
declare(strict_types=1);

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'feria_test');
define('DB_USER', 'feria');
define('DB_PASS', 'feria_password');
define('DB_PREFIX', 'fr_');
define('SITE_URL', 'http://127.0.0.1:8000');
define('FR_ROOT', '/home/user/feria-ravan/feria-ravan');
define('FR_ENV', 'production');

require_once FR_ROOT . '/includes/version.php';
require_once FR_ROOT . '/includes/db.php';
require_once FR_ROOT . '/includes/functions.php';
require_once FR_ROOT . '/includes/security.php';
require_once FR_ROOT . '/includes/auth.php';
require_once FR_ROOT . '/core/TrashManager.php';
require_once FR_ROOT . '/includes/update.php';

$passed = 0;
$failed = 0;

function it(string $desc, bool $cond, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc} " . ($details ? "({$details})" : "") . "\n";
    }
}

echo "====================================================================\n";
echo " QA SUITE: Feria Ravan v3.4.9 - Trash System & Action Handlers QA\n";
echo "====================================================================\n\n";

// --- 1. Version & Schema Verification ---
echo "--- 1. Version and Database Schema ---\n";
it('Version is advanced to 3.4.9 or higher', version_compare(FR_RELEASE, '3.4.9', '>='), 'Actual: ' . FR_RELEASE);
it('FR_VERSION constant is 3.4.9 or higher', version_compare(FR_VERSION, '3.4.9', '>='), 'Actual: ' . FR_VERSION);

TrashManager::ensureSchema();

foreach (['attempts', 'events', 'users', 'tests', 'tickets', 'event_registrations', 'reviews'] as $tbl) {
    $cols = array_column(DB::all("SHOW COLUMNS FROM " . DB::t($tbl)), 'Field');
    it("Table {$tbl} has deleted_at column", in_array('deleted_at', $cols, true));
    it("Table {$tbl} has deleted_by column", in_array('deleted_by', $cols, true));
}

// Check index on deleted_at
$idxAttempts = array_column(DB::all("SHOW INDEX FROM " . DB::t('attempts')), 'Column_name');
it('attempts has index on deleted_at', in_array('deleted_at', $idxAttempts, true));

// --- 2. Test Fixtures Setup ---
echo "\n--- 2. Test Fixtures Setup ---\n";
// Create test user
$userMob = '09391112233';
DB::run("DELETE FROM " . DB::t('users') . " WHERE mobile=?", [$userMob]);
$uid = DB::insert('users', [
    'first_name'      => 'کامران',
    'last_name'       => 'کریمی',
    'mobile'          => $userMob,
    'status'          => 'active',
    'password_hash'   => password_hash('Pass123456', PASSWORD_ARGON2ID),
    'mobile_verified' => 1,
    'created_at'      => date('Y-m-d H:i:s'),
]);
it('Test user created', $uid > 0);

// Create test attempt
$tid = (int)DB::val("SELECT id FROM " . DB::t('tests') . " WHERE status<>'trash' LIMIT 1");
if (!$tid) {
    $tid = DB::insert('tests', ['title' => 'تست آزمایشی سطل زباله', 'slug' => 'trash-test-qa', 'status' => 'active']);
}
$attId = DB::insert('attempts', [
    'user_id'    => $uid,
    'test_id'    => $tid,
    'mode'       => 'full',
    'status'     => 'completed',
    'started_at' => date('Y-m-d H:i:s'),
]);
it('Test attempt created', $attId > 0);

// Create test workshop/event
$evId = DB::insert('events', [
    'title'      => 'کارگاه "مهارت‌های زوجین" و تست‌های شخصیت',
    'slug'       => 'workshop-quote-test-' . time(),
    'kind'       => 'workshop',
    'status'     => 'active',
    'price'      => 150000,
    'starts_at'  => date('Y-m-d H:i:s', strtotime('+5 days')),
    'created_at' => date('Y-m-d H:i:s'),
]);
it('Test event with quotes in title created', $evId > 0);

// Create test ticket
$ticketId = DB::insert('tickets', [
    'user_id'    => $uid,
    'subject'    => 'مشکل در دانلود فایل کارنامه',
    'status'     => 'open',
    'priority'   => 'normal',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
DB::insert('ticket_replies', [
    'ticket_id'  => $ticketId,
    'author_id'  => $uid,
    'body'       => 'سلام فایل کارنامه برای من باز نمی‌شود.',
    'created_at' => date('Y-m-d H:i:s'),
]);
it('Test ticket created with reply', $ticketId > 0);

// --- 3. Soft-Delete Operations (انتقال به سطل زباله) ---
echo "\n--- 3. Soft-Delete Operations (انتقال به سطل زباله) ---\n";

// 3.1 Trash Attempt
$ok = TrashManager::trash('attempt', $attId, 999);
it('TrashManager::trash attempt succeeded', $ok);
$attRow = DB::one("SELECT deleted_at, deleted_by FROM " . DB::t('attempts') . " WHERE id=?", [$attId]);
it('Attempt deleted_at is populated', !empty($attRow['deleted_at']));
it('Attempt deleted_by is recorded', (int)$attRow['deleted_by'] === 999);

// 3.2 Trash Event
$ok = TrashManager::trash('event', $evId, 999);
it('TrashManager::trash event succeeded', $ok);
$evRow = DB::one("SELECT deleted_at, status FROM " . DB::t('events') . " WHERE id=?", [$evId]);
it('Event deleted_at is populated', !empty($evRow['deleted_at']));
it('Event status is marked inactive', $evRow['status'] === 'inactive');

// 3.3 Trash User
$ok = TrashManager::trash('user', $uid, 999);
it('TrashManager::trash user succeeded', $ok);
$uRow = DB::one("SELECT deleted_at, status FROM " . DB::t('users') . " WHERE id=?", [$uid]);
it('User deleted_at is populated', !empty($uRow['deleted_at']));

// 3.4 Trash Ticket
$ok = TrashManager::trash('ticket', $ticketId, 999);
it('TrashManager::trash ticket succeeded', $ok);
$tRow = DB::one("SELECT deleted_at FROM " . DB::t('tickets') . " WHERE id=?", [$ticketId]);
it('Ticket deleted_at is populated', !empty($tRow['deleted_at']));

// --- 4. Trash Statistics & Listing ---
echo "\n--- 4. Trash Statistics and Unified Listing ---\n";
$stats = TrashManager::getStats();
it('Global stats reports at least 1 trashed attempt', $stats['attempts'] >= 1);
it('Global stats reports at least 1 trashed event', $stats['events'] >= 1);
it('Global stats reports at least 1 trashed user', $stats['users'] >= 1);
it('Global stats reports at least 1 trashed ticket', $stats['tickets'] >= 1);
it('Global stats total is sum of parts', $stats['total'] >= 4);

$userStats = TrashManager::getStats($uid);
it('User scoped stats reports user trashed attempt', $userStats['attempts'] >= 1);
it('User scoped stats reports user trashed ticket', $userStats['tickets'] >= 1);

$listAll = TrashManager::getList('all');
it('TrashManager::getList returns items', count($listAll) >= 4);

$attemptFound = false;
$eventFound = false;
$userFound = false;
$ticketFound = false;

foreach ($listAll as $it) {
    if ($it['type'] === 'attempt' && $it['id'] === $attId) $attemptFound = true;
    if ($it['type'] === 'event' && $it['id'] === $evId) $eventFound = true;
    if ($it['type'] === 'user' && $it['id'] === $uid) $userFound = true;
    if ($it['type'] === 'ticket' && $it['id'] === $ticketId) $ticketFound = true;
}

it('Trashed attempt exists in unified list', $attemptFound);
it('Trashed event exists in unified list', $eventFound);
it('Trashed user exists in unified list', $userFound);
it('Trashed ticket exists in unified list', $ticketFound);

// --- 5. Restoration Operations (بازیابی) ---
echo "\n--- 5. Restoration Operations (بازیابی) ---\n";

// 5.1 Restore User
$ok = TrashManager::restore('user', $uid);
it('TrashManager::restore user succeeded', $ok);
$uRow = DB::one("SELECT deleted_at, status FROM " . DB::t('users') . " WHERE id=?", [$uid]);
it('User deleted_at is now null', $uRow['deleted_at'] === null);
it('User status is restored to active', $uRow['status'] === 'active');

// 5.2 Restore Event
$ok = TrashManager::restore('event', $evId);
it('TrashManager::restore event succeeded', $ok);
$evRow = DB::one("SELECT deleted_at, status FROM " . DB::t('events') . " WHERE id=?", [$evId]);
it('Event deleted_at is now null', $evRow['deleted_at'] === null);
it('Event status is restored to active', $evRow['status'] === 'active');

// 5.3 Restore Ticket
$ok = TrashManager::restore('ticket', $ticketId, $uid);
it('TrashManager::restore ticket succeeded', $ok);
$tRow = DB::one("SELECT deleted_at FROM " . DB::t('tickets') . " WHERE id=?", [$ticketId]);
it('Ticket deleted_at is now null', $tRow['deleted_at'] === null);

// 5.4 Restore Attempt
$ok = TrashManager::restore('attempt', $attId, $uid);
it('TrashManager::restore attempt succeeded', $ok);
$attRow = DB::one("SELECT deleted_at FROM " . DB::t('attempts') . " WHERE id=?", [$attId]);
it('Attempt deleted_at is now null', $attRow['deleted_at'] === null);

// --- 6. Permanent Purge (حذف قطعی و نهایی) ---
echo "\n--- 6. Permanent Purge (حذف قطعی و نهایی) ---\n";

// Trash them again before purging
TrashManager::trash('ticket', $ticketId);
TrashManager::trash('attempt', $attId);
TrashManager::trash('event', $evId);

// Purge ticket
$ok = TrashManager::purge('ticket', $ticketId, $uid);
it('TrashManager::purge ticket succeeded', $ok);
$tCheck = DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE id=?", [$ticketId]);
it('Ticket row is permanently removed from database', (int)$tCheck === 0);
$rCheck = DB::val("SELECT COUNT(*) FROM " . DB::t('ticket_replies') . " WHERE ticket_id=?", [$ticketId]);
it('Ticket replies are cascaded and removed', (int)$rCheck === 0);

// Purge attempt
$ok = TrashManager::purge('attempt', $attId, $uid);
it('TrashManager::purge attempt succeeded', $ok);
$aCheck = DB::val("SELECT COUNT(*) FROM " . DB::t('attempts') . " WHERE id=?", [$attId]);
it('Attempt row is permanently removed from database', (int)$aCheck === 0);

// Purge event
$ok = TrashManager::purge('event', $evId);
it('TrashManager::purge event succeeded', $ok);
$eCheck = DB::val("SELECT COUNT(*) FROM " . DB::t('events') . " WHERE id=?", [$evId]);
it('Event row is permanently removed from database', (int)$eCheck === 0);

// Clean up user
TrashManager::trash('user', $uid);
$ok = TrashManager::purge('user', $uid);
it('TrashManager::purge user succeeded', $ok);
$uCheck = DB::val("SELECT COUNT(*) FROM " . DB::t('users') . " WHERE id=?", [$uid]);
it('User row is permanently removed from database', (int)$uCheck === 0);

// --- 7. Empty Trash Execution ---
echo "\n--- 7. Empty Trash Execution ---\n";
$dummyTid1 = DB::insert('tickets', ['user_id' => 1, 'subject' => 'Dummy 1', 'deleted_at' => date('Y-m-d H:i:s')]);
$dummyTid2 = DB::insert('tickets', ['user_id' => 1, 'subject' => 'Dummy 2', 'deleted_at' => date('Y-m-d H:i:s')]);
it('Dummy trashed tickets created', $dummyTid1 > 0 && $dummyTid2 > 0);

$emptied = TrashManager::emptyTrash('ticket');
it('TrashManager::emptyTrash for tickets deleted items', $emptied >= 2);
$rem = (int)DB::val("SELECT COUNT(*) FROM " . DB::t('tickets') . " WHERE id IN (?,?)", [$dummyTid1, $dummyTid2]);
it('Dummy trashed tickets no longer exist', $rem === 0);

// --- 8. User Edit and File Existence ---
echo "\n--- 8. Controllers and UI Files Verification ---\n";
it('admin/trash.php exists', file_exists(FR_ROOT . '/admin/trash.php'));
it('panel/trash.php exists', file_exists(FR_ROOT . '/panel/trash.php'));
it('core/TrashManager.php exists', file_exists(FR_ROOT . '/core/TrashManager.php'));

// --- 9. Registration & Review Trash Capabilities ---
echo "\n--- 9. Registration & Review Trash Capabilities ---\n";
it('normalizeType maps plural attempts to attempt', TrashManager::normalizeType('attempts') === 'attempt');
it('normalizeType maps registrations to registration', TrashManager::normalizeType('registrations') === 'registration');
it('normalizeType maps workshops to event', TrashManager::normalizeType('workshops') === 'event');

// Test registration soft-delete and restore
$testEvId = (int)DB::val("SELECT id FROM " . DB::t('events') . " LIMIT 1");
if ($testEvId <= 0) {
    $testEvId = DB::insert('events', [
        'title' => 'کارگاه تست برای زباله‌دان',
        'kind' => 'workshop',
        'price' => 50000,
        'status' => 'active',
    ]);
}
$rndMob = '0912' . random_int(1000000, 9999999);
$testRegUser = DB::insert('users', ['first_name' => 'کاربر', 'last_name' => 'رویداد', 'mobile' => $rndMob]);
$testRegId = DB::insert('event_registrations', [
    'event_id' => $testEvId,
    'user_id'  => $testRegUser,
    'order_id' => 0,
    'status'   => 'pending',
]);
it('Test registration fixture created', $testRegId > 0);

$regTrashed = TrashManager::trash('registration', $testRegId, 1);
it('TrashManager::trash registration succeeded', $regTrashed === true);
$regTrashCheck = DB::one("SELECT deleted_at FROM " . DB::t('event_registrations') . " WHERE id=?", [$testRegId]);
it('Registration deleted_at is populated', !empty($regTrashCheck['deleted_at']));

$regList = TrashManager::getList('registrations', 10);
$foundRegInList = false;
foreach ($regList as $itReg) {
    if ($itReg['type'] === 'registration' && $itReg['id'] === $testRegId) {
        $foundRegInList = true;
        break;
    }
}
it('Trashed registration appears in getList(registrations)', $foundRegInList);

$regRestored = TrashManager::restore('registration', $testRegId);
it('TrashManager::restore registration succeeded', $regRestored === true);
$regPurged = TrashManager::purge('registration', $testRegId);
it('TrashManager::purge registration succeeded', $regPurged === true);
DB::run("DELETE FROM " . DB::t('users') . " WHERE id=?", [$testRegUser]);

// Test review soft-delete and restore
$testRevId = DB::insert('reviews', [
    'context'     => 'workshop',
    'ref_id'      => $testEvId,
    'author_name' => 'کاربر دیدگاه',
    'body'        => 'این یک نظر آزمایشی برای سطل زباله است.',
    'status'      => 'approved',
]);
it('Test review fixture created', $testRevId > 0);

$revTrashed = TrashManager::trash('review', $testRevId, 1);
it('TrashManager::trash review succeeded', $revTrashed === true);
$revTrashCheck = DB::one("SELECT deleted_at, status FROM " . DB::t('reviews') . " WHERE id=?", [$testRevId]);
it('Review deleted_at is populated', !empty($revTrashCheck['deleted_at']));
it('Review status marked rejected when trashed', $revTrashCheck['status'] === 'rejected');

$revList = TrashManager::getList('reviews', 10);
$foundRevInList = false;
foreach ($revList as $itRev) {
    if ($itRev['type'] === 'review' && $itRev['id'] === $testRevId) {
        $foundRevInList = true;
        break;
    }
}
it('Trashed review appears in getList(reviews)', $foundRevInList);

$revRestored = TrashManager::restore('review', $testRevId);
it('TrashManager::restore review succeeded', $revRestored === true);
$revPurged = TrashManager::purge('review', $testRevId);
it('TrashManager::purge review succeeded', $revPurged === true);

// Test user edit logic without prompt blocker
$editUid = DB::insert('users', [
    'first_name' => 'سعید',
    'last_name'  => 'سعادتی',
    'mobile'     => '09120000001',
    'status'     => 'active',
]);
DB::update('users', [
    'first_name' => 'سعید رضا',
    'job_title'  => 'روانشناس بالینی',
], 'id=?', [$editUid]);
$updated = DB::one("SELECT first_name, job_title FROM " . DB::t('users') . " WHERE id=?", [$editUid]);
it('User edit succeeds cleanly in database', $updated['first_name'] === 'سعید رضا' && $updated['job_title'] === 'روانشناس بالینی');
DB::run("DELETE FROM " . DB::t('users') . " WHERE id=?", [$editUid]);

echo "\n====================================================================\n";
echo " QA SUITE COMPLETED: {$passed} Passed, {$failed} Failed\n";
echo "====================================================================\n";

if ($failed > 0) exit(1);

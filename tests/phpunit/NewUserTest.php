<?php
namespace MediaWiki\Extensions\Auth_remoteuser\Tests;

require_once 'Auth_remoteuserTestCase.php';

/**
 * Test requests coming from new users unknown to the wiki database as of yet.
 *
 * @group Auth_remoteuser
 * @group Database
 */
class NewUserTest extends Auth_remoteuserTestCase {

	protected function setUpSessionProvider() {
		putenv( 'REMOTE_USER=AuthRemoteUser' );
		global $wgGroupPermissions;
		$wgGroupPermissions['*']['createaccount'] = true;
		$wgGroupPermissions['*']['autocreateaccount'] = true;
	}

	public function testUserIsLoggedIn() {
		$this->assertTrue(
			\MediaWiki\Session\SessionManager::singleton()->getGlobalSession()->getUser()->isLoggedIn(),
			'User is not logged in.'
		);
	}

}
?>

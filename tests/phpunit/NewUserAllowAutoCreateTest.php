<?php
namespace MediaWiki\Extensions\Auth_remoteuser\Tests;

require_once 'Auth_remoteuserTestCase.php';

/**
 * @group Auth_remoteuser
 * @group Database
 */
class NewUserAllowAutoCreateTest extends Auth_remoteuserTestCase {

	protected function setUpSessionProvider() {
		putenv( 'REMOTE_USER=AuthRemoteUser' );
		global $wgGroupPermissions;
		$wgGroupPermissions['*']['createaccount'] = false;
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

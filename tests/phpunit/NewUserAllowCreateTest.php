<?php
namespace MediaWiki\Extensions\Auth_remoteuser\Tests;

require_once 'Auth_remoteuserTestCase.php';

/**
 * @group Auth_remoteuser
 * @group Database
 */
class NewUserAllowCreateTest extends Auth_remoteuserTestCase {

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

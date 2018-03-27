<?php
namespace MediaWiki\Extensions\Auth_remoteuser\Tests;

require_once 'Auth_remoteuserTestCase.php';

/**
 * @group Auth_remoteuser
 * @group Database
 */
class NewUserForbidCreateTest extends Auth_remoteuserTestCase {

	protected function setUpSessionProvider() {
		putenv( 'REMOTE_USER=AuthRemoteUser' );
		global $wgGroupPermissions;
		$wgGroupPermissions['*']['createaccount'] = false;
		$wgGroupPermissions['*']['autocreateaccount'] = false;
	}

	public function testUserIsAnonymous() {
		$this->assertTrue(
			\MediaWiki\Session\SessionManager::singleton()->getGlobalSession()->getUser()->isAnon(),
			'User is not anonymous.'
		);
	}

}
?>

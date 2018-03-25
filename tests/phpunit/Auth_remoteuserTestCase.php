<?php
/**
 * This file is part of the MediaWiki extension Auth_remoteuser.
 *
 * Copyright (C) 2018 Stefan Engelhardt and others (for a complete list of
 *                    authors see the file `extension.json`)
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program (see the file `COPYING`); if not, write to the
 *
 *   Free Software Foundation, Inc.,
 *   59 Temple Place, Suite 330,
 *   Boston, MA 02111-1307
 *   USA
 *
 * @file
 */
namespace MediaWiki\Extensions\Auth_remoteuser\Tests;

use MediaWiki\Extensions\Auth_remoteuser\AuthRemoteuserSessionProvider;
use MediaWiki\Session\SessionManager;
use MediaWiki\Auth\AuthManager;
use Wikimedia\TestingAccessWrapper;
use PHPUnit_Framework_TestResult;
use MediaWikiTestCase;
use MWException;
use ExtensionRegistry;
use User;

/**
 * Auth_remoteuser extension unit tests needs special preparation because
 * `MediaWikiTestCase` sets up an initialized environment where the
 * `SessionProvider` has been chosen already.
 *
 * @group Auth_remoteuser
 * @group Database
 */
abstract class Auth_remoteuserTestCase extends MediaWikiTestCase {

	/**
	 * Each test should setup its environment by overwriting this method and
	 * calling `parent::setUp()` as its first command. It will take care of
	 * initializing Auth_remoteuser specific environment.
	 */
	protected function setUp() {
		parent::setUp();

		# Skip each test if extension is not loaded at all.
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Auth_remoteuser' ) ) {
			$this->markTestSkipped( 'Auth_remoteuser extension not installed/enabled' );
			return;
		}

		# Because the extension alters the database ( creating new users, setting
		# user preferences, etc.) we ensure that unit tests run on a temporary
		# database.
		global $wgDBprefix;
		if (
			$wgDBprefix !== MediaWikiTestCase::DB_PREFIX
			&& $wgDBprefix !== MediaWikiTestCase::ORA_DB_PREFIX
		) {
			throw new MWException( "Can't test on real database!" );
		}
	
		# MediaWikis unit test bootstrapping calls `Setup.php`, but that doesn't
		# execute `AuthManager::autoCreateUser()` if in `$wgCommandLineMode`. We
		# have to call it explicitely. At this point of (test) execution our
		# SessionProvider should be selected already and has identified an user
		# unknown to the database as of yet.
		#
		# If the user must exist for the test case, than add it to the test
		# database with `User::addToDatabase()` in `self::addDBDataOnce()`, which
		# is getting called prior to this `self::setUp()` method.
		#
		# @see Setup.php
		# @see tests/phpunit/phpunit.php
		# @see maintenance/doMaintenance.php
		# @see self::addDBData()
		$sessionUser = SessionManager::getGlobalSession()->getUser(); 
		if ( $sessionUser->getId() === 0 && User::isValidUserName( $sessionUser->getName() ) ) { 
			AuthManager::singleton()->autoCreateUser( 
				$sessionUser, 
				AuthManager::AUTOCREATE_SOURCE_SESSION, 
				true 
			);
		}

		# Just some test environment preparation.
		$this->setContentLang( 'en' );
		$this->setUserLang( 'en' );
	}

	/** 
	 * Our extension configuration has to be done before `self::setUp()`, because
	 * our parent selected the SessionProvider for the current test request in its
	 * `parent:run()` method prior to `parent::setUp()` already.
	 * 
	 * Subclasses of our own should overwrite our own method
	 * `self::setUpSessionProvider()` instead of `self::setUp()`.
	 *
	 * @see self::setUpSessionProvider()
	 * @see MediaWikiTestCase::run()
	 * @see MediaWikiTestCase::doLeightweightServiceReset()
	 * @see SessionManager::resetCache()
	 */
	public function run( PHPUnit_Framework_TestResult $result = null ) {

		$this->stashMwGlobals( [
			// MW options
			'wgGroupPermissions',
			// extension options
			'wgAuthRemoteuserUserName',
			'wgAuthRemoteuserUserNameReplaceFilter',
			'wgAuthRemoteuserUserNameBlacklistFilter',
			'wgAuthRemoteuserUserNameWhitelistFilter',
			'wgAuthRemoteuserUserPrefs',
			'wgAuthRemoteuserUserPrefsForced',
			'wgAuthRemoteuserUserUrls',
			'wgAuthRemoteuserAllowUserSwitch',
			'wgAuthRemoteuserRemoveAuthPagesAndLinks',
			'wgAuthRemoteuserPriority',
			// legacy extension options
			'wgAuthRemoteuserAuthz',
			'wgAuthRemoteuserName',
			'wgAuthRemoteuserMail',
			'wgAuthRemoteuserNotify',
			'wgAuthRemoteuserDomain',
			'wgAuthRemoteuserMailDomain'
		] );

		$this->setUpSessionProvider();

		return parent::run( $result );
	}

	abstract protected function setUpSessionProvider();

	/**
	 * Every Auth_remoteuser test case needs at least to test if the selected
	 * session provider is of extensions own type.
	 */
	public function testAuthRemoteuserSessionProviderUsed() {
		$this->assertType(
			AuthRemoteuserSessionProvider::class,
			SessionManager::singleton()->getGlobalSession()->getProvider(),
			'Current session not provided by Auth_remoteuser'
		);
	}

}
?>

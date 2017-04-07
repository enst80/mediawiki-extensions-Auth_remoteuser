<?php
/**
 * This file is part of the MediaWiki extension Auth_remoteuser.
 *
 * Copyright (C) 2017 Stefan Engelhardt and others (for a complete list of
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
namespace MediaWiki\Extensions\Auth_remoteuser;

use MediaWiki\Session\CookieSessionProvider;
use MediaWiki\Session\SessionManager;
use MediaWiki\Session\SessionInfo;
use MediaWiki\Session\UserInfo;
use WebRequest;
use Hooks;
use Sanitizer;
use User;
use Closure;

/**
 * MediaWiki session provider for arbitrary user name sources.
 *
 * A `UserNameSessionProvider` uses a given user name (the remote source takes
 * total responsibility in authenticating that user) and tries to tie it to an
 * according local MediaWiki user.
 *
 * This provider acts the same as the `CookieSessionProvider` but in contrast
 * does not allow anonymous users per session as the `CookieSessionProvider`
 * does. In this case a user will be set which is identified by the given
 * user name. Additionally this provider will create a new session with the
 * given user if no session exists for the current request. The default
 * `CookieSessionProvider` creates new sessions on specific user activities
 * only.
 *
 * @see CookieSessionProvider::provideSessionInfo()
 * @version 2.0.0
 * @since 2.0.0
 */
class UserNameSessionProvider extends CookieSessionProvider {

	/**
	 * The remote user name(s) given as an array.
	 *
	 * @var string[]
	 * @since 2.0.0
	 */
	protected $remoteUserNames;

	/**
	 * Holds additional information and preference options about an user.
	 *
	 * @var array
	 * @since 2.0.0
	 */
	protected $userProps;

	/**
	 * Indicates if additional user properties should be applied to a user only in
	 * the moment of local account creation or on each request.
	 *
	 * @var boolean
	 * @since 2.0.0
	 */
	protected $forceUserProps;

	/**
	 * Indicates if local users (as of yet unknown to the MediaWiki database)
	 * should be created automatically.
	 *
	 * @var boolean
	 * @since 2.0.0
	 */
	protected $autoCreateUser;

	/**
	 * Indicates if the automatically logged-in user can switch to another local
	 * MediaWiki account while still beeing identified by the remote user name.
	 *
	 * @var boolean
	 * @since 2.0.0
	 */
	protected $switchUser;

	/**
	 * Indicates if special pages related to authentication getting removed by us.
	 *
	 * @var boolean
	 * @since 2.0.0
	 */
	protected $removeAuthPagesAndLinks;

	/**
	 * The constructor processes the class configuration.
	 *
	 * In addition to the keys of the parents constructor parameter `params` this
	 * constructor evaluates the following keys:
	 * * `remoteUserNames` - Either a string/closure or an array of
	 *   strings/closures listing the given remote user name(s). Each closure
	 *   should return a string.
	 * * `userProps` - User preferences applied to the identified MediaWiki user
	 *   given as an array of key value pairs. Each key relates to an according
	 *   user preference option of the same name. Closures as values getting
	 *   evaluated and should return the according value. The following keys are
	 *   handled individually:
	 *   * `realname` - Specifies the users real (display) name.
	 *   * `emailaddress` - Specifies the users email address.
	 * * `forceUserProps` - @see self::$forceUserProps
	 * * `autoCreateUser` - @see self::$autoCreateUser
	 * * `switchUser` - @see self::$switchUser
	 * * `removeAuthPagesAndLinks` - @see self::$removeAuthPagesAndLinks
	 *
	 * @since 2.0.0
	 */
	public function __construct( $params = [] ) {

		# The cookie prefix used by our parent will be the same as our class name to
		# not interfere with cookies set by other instances of our parent.
		$prefix = str_replace( '\\', '_', get_class( $this ) );
		$params += [
			'sessionName' => $prefix . '_session',
			'cookieOptions' => []
		];
		$params[ 'cookieOptions' ] += [ "prefix" => $prefix ];

		parent::__construct( $params );

		# Setup configuration defaults.
		$defaults = [
			'remoteUserNames' => [],
			'userProps' => null,
			'forceUserProps' => false,
			'autoCreateUser' => true,
			'switchUser' => false,
			'removeAuthPagesAndLinks' => true
		];

		# Sanitize configuration and apply to own members.
		foreach( $defaults as $key => $default) {
			$value = $default;
			if ( array_key_exists( $key, $params ) ) {
				switch( $key ) {
					case 'remoteUserNames':
						$value = [];
						$names = $params[ $key ];
						if ( ! is_array( $names ) ) {
							$names = [ $names ];
						}
						$value = $names;
						break;
					case 'userProps':
						if ( is_array( $params[ $key ] ) ) {
							$value = $params[ $key ];
						}
						break;
					default:
						$value = (bool)$params[ $key ];
						break;
				}
			}
			$this->{ $key } = $value;
		}

	}

	/**
	 * Method get's called by the SessionManager on every request for each
	 * SessionProvider installed to determine if this SessionProvider has
	 * identified a possible session.
	 *
	 * @since 2.0.0
	 */
	public function provideSessionInfo( WebRequest $request ) {

		# Loop through user names given by all remote sources. First hit, which
		# matches a usable local user name, will be used for our SessionInfo then.
		foreach ( $this->remoteUserNames as $remoteUserName ) {

			if ( $remoteUserName instanceof Closure ) {
				$remoteUserName = call_user_func( $remoteUserName );
			}

			# Keep info about remote-to-local user name processing for logging and
			# and later for provider metadata.
			$metadata = [ 'remoteUserName' => (string)$remoteUserName ];

			if ( !is_string( $remoteUserName ) || empty( $remoteUserName ) ) {
				$this->logger->warning(
					"Can't login remote user '{remoteUserName}' automatically. " .
					"Given remote user name is not of type string or empty.",
					$metadata
				);
				continue;
			}

			# Process each given remote user name if needed, e.g. strip NTLM domain,
			# replace characters, rewrite to another username or even blacklist it by
			# returning false. This can be used by the wiki administrator to adjust
			# this SessionProvider to his specific needs.
			$filteredUserName = $remoteUserName;
			if ( !Hooks::run( 'Auth_remoteuser_filterUserName', [ &$filteredUserName ] ) ) {
				$metadata[ 'filteredUserName' ] = $filteredUserName;
				$this->logger->warning(
					"Can't login remote user '{remoteUserName}' automatically. " .
					"Blocked this user when filtering it to '{filteredUserName}'.",
					$metadata
				);
				continue;
			}
			$metadata[ 'filteredUserName' ] = $filteredUserName;

			# Create a UserInfo (and User) object by given user name. The factory
			# method will take care of correct validation of user names. It will also
			# canonicalize it (e.g. transform the first letter to uppercase).
			#
			# An exception gets thrown when the given user name is not 'usable' as an
			# user name for the wiki, either blacklisted or contains invalid characters
			# or is an ip address.
			#
			# @see User::getCanonicalName()
			# @see User::isUsableName()
			# @see Title::newFromText()
			try {
				$userInfo = UserInfo::newFromName( $filteredUserName, true );
			} catch ( InvalidArgumentException $e ) {
				$metadata[ 'exception' ] = $e;
				$this->logger->warning(
					"Can't login remote user '{remoteUserName}' automatically. " .
					"Filtered name '{filteredUserName}' is not usable as an " .
					"user name in MediaWiki.: {exception}",
					$metadata
				);
				continue;
			}
			$metadata[ 'userId' ] = $userInfo->getId();
			$metadata[ 'canonicalUserName' ] = $userInfo->getName();

			# We aren't allowed to autocreate new users, therefore we won't provide any
			# session infos.
			#
			# @see User::isAnon()
			# @see User::isLoggedIn()
			# @see UserInfo::getId()
			if ( !( $this->autoCreateUser || $userInfo->getId() ) ) {
				$this->logger->warning(
					"Can't login remote user '{remoteUserName}' automatically. " .
					"Creation of new users not allowed in current configuration.",
					$metadata
				);
				continue;
			}

			# Let our parent class find a valid SessionInfo.
			$sessionInfo = parent::provideSessionInfo( $request );

			# Our parent class provided a session info, but the `$wgGroupPermission` for
			# creating user accounts was changed while using this extension. This leds
			# to a behaviour where a new user won't be created automatically even if the
			# wiki administrator enabled (auto) account creation by setting the specific
			# group permission `createaccount` or `autocreateaccount` to `true`.
			#
			# This happens in rare circurmstances only: The global permission forbids
			# account creation. A new user requests the wiki and a specific session is
			# getting created (by our parent). Then the `AuthManager` permits further
			# account creation attempts due to global permission by marking this session
			# as blacklistet. This blacklist info is stored inside the session itself.
			# Now when the wiki admin changes the global account creation permission to
			# `true` and the new user wants to access the wiki, then his account is not
			# created automatically due to this blacklist marking inside his current
			# session, identified by our parent class. Hence we throw this session away
			# in these rare cases (We could manipulate the session itself and delete that
			# `AuthManager::AutoCreateBlacklist` key instead, but that would look like
			# real hacked code inside this method).
			#
			# @see AuthManager::AutoCreateBlacklist
			# @see AuthManager::autoCreateUser()
			if ( $sessionInfo && ! $userInfo->getId() ) {
				$anon = $userInfo->getUser();
				$permissions = $anon->getGroupPermissions( $anon->getEffectiveGroups() );
				if ( in_array( 'autocreateaccount', $permissions, true ) ||
					in_array( 'createaccount', $permissions, true ) ) {
					$this->logger->warning("Renew session due to global permission change " .
						"in (auto) creating new users.");
					$sessionInfo = null;
				}
			}

			# Our parent class couldn't provide any info. This means we can create a
			# new session with our identified user.
			if ( ! $sessionInfo ) {
				$sessionInfo = new SessionInfo( $this->priority, [
					'provider' => $this,
					'id' => $this->manager->generateSessionId(),
					'userInfo' => $userInfo
					]
				);
			}

			# The current session identifies an anonymous user, therefore we have to
			# use the forceUse flag to set our identified user. If we are configured
			# to forbid user switching, force the usage of our identified user too.
			if ( !$sessionInfo->getUserInfo() || !$sessionInfo->getUserInfo()->getId()
				|| ( !$this->switchUser && $sessionInfo->getUserInfo()->getId() !== $userInfo->getId() ) ) {
				$sessionInfo = new SessionInfo( $sessionInfo->getPriority(), [
					'copyFrom' => $sessionInfo,
					'userInfo' => $userInfo,
					'forceUse' => true
					]
				);
			}

			# Store info about user in the provider metadata.
			$metadata[ 'canonicalUserNameUsed' ] = $sessionInfo->getUserInfo()->getName();
			$sessionInfo = new SessionInfo( $sessionInfo->getPriority(), [
				'copyFrom' => $sessionInfo,
				'metadata' => $metadata
				]
			);

			return $sessionInfo;
		}

		# We didn't identified anything, so let other SessionProviders do their work.
		return null;
	}

	/**
	 * Never use the stored metadata and return the provided one in any case.
	 *
	 * But let our parents implementation of this method decide on his own for the
	 * other members.
	 *
	 * @since 2.0.0
	 */
	public function mergeMetadata( array $savedMetadata, array $providedMetadata ) {
		$keys = [
			'userId',
			'remoteUserName',
			'filteredUserName',
			'canonicalUserName',
			'canonicalUserNameUsed'
		];
		foreach ( $keys as $key ) {
			$savedMetadata[ $key ] = $providedMetadata[ $key ];
		}
		return parent::mergeMetadata( $savedMetadata, $providedMetadata );
	}

	/**
	 * The SessionManager selected us as the SessionProvider for this request.
	 *
	 * Now we can add additional information to the requests user object and
	 * remove some special pages and personal urls from the clients frontend.
	 *
	 * @since 2.0.0
	 */
	public function refreshSessionInfo( SessionInfo $info, WebRequest $request, &$metadata ) {

		$this->logger->info( "Setting up auto login session for remote user name " .
			"'{remoteUserName}' (mapped to MediaWiki user '{canonicalUserName}', " .
			"currently active as MediaWiki user '{canonicalUserNameUsed}').",
			$metadata
		);

		$disableSpecialPages = [];

		# This can only be true, if our `switchUser` member is set to true and the
		# user identified by us uses another local wiki user for this session.
		$switchedUser = ( $info->getUserInfo()->getId() !== $metadata[ 'userId' ] ) ? true : false;

		# Disable password related special pages and hide preference option.
		if ( ! $switchedUser ) {
			$disableSpecialPages += [ 'ChangePassword', 'PasswordReset' ];
			global $wgHiddenPrefs;
			$wgHiddenPrefs[] = 'password';
		}

		# Set user preference default values.
		if ( ! $switchedUser && $this->userProps ) {

			$container = [
				'properties' => $this->userProps,
				'metadata' => $metadata
			];

			# Forcing user preferences is useful if users real name or email is provided
			# by an external source and must not be changed by the user himself.
			#
			# @see $wgGroupPermissions['user']['editmyoptions']
			if ( $this->forceUserProps ) {

				$container [ 'saveToDB' ] = true;
				self::setUserProps(
					$container,
					$info->getUserInfo()->getUser(),
					true
				);

				# Do not hide forced preferences completely by using the global
				# `$wgHiddenPrefs`, because we still want them to be shown to the user.
				# Therefore use the according hook to disable their editing capabilities.
				$properties = array_keys( $this->userProps );
				Hooks::register(
					'GetPreferences',
					function( $user, &$prefs ) use ( $properties ) {
						foreach( $properties as $property ) {

							if ( $property === 'email' ) {
								$property = 'emailaddress';
							}

							if ( ! isset( $prefs[ $property ] ) ) {
								continue;
							}

							# Email preference needs special treatment, because it will display a
							# link to change the address. We have to replace that with the address
							# only.
							if ( $property === 'emailaddress' ) {
								$prefs[ $property ][ 'default' ] = $user->getEmail() ?
									htmlspecialchars( $user->getEmail() ) : '';
							}

							$prefs[ $property ][ 'disabled' ] = 'disabled';

						}
					}
				);

				# Disable special pages related to email preferences.
				if ( array_key_exists( 'email', $this->userProps ) ) {
					$disableSpecialPages += [ 'ChangeEmail', 'Confirmemail', 'Invalidateemail' ];
				}

			# Only add additional information to the user object if the user must be
			# created in the local database with this request.
			} elseif ( !$info->getUserInfo()->getId() ) {

				Hooks::register(
					'LocalUserCreated', [
						__CLASS__ . '::setUserProps',
						$container
					]
				);

			}

		}

		# Disable any special pages related to user authentication.
		if ( ! $this->switchUser ) {
			$disableSpecialPages += [
				'Userlogin',
				'Userlogout',
				'CreateAccount',
				'LinkAccounts',
				'UnlinkAccounts',
				'ChangeCredentials',
				'RemoveCredentials'
			];
		}

		# Don't remove anything (besides `CreateAccount` maybe).
		# Be aware of the following security vulnerability. If someone created an
		# account with the same name as a new and authenticated user has, then this
		# new user will have full access to that account, because we will authorize
		# him by his name only (without password verification).
		if ( ! $this->removeAuthPagesAndLinks ) {
			$disableSpecialPages = [];
		}

		Hooks::register(
			'SpecialPage_initList', [
				function ( &$specials ) use ( $disableSpecialPages ) {
					foreach ( $disableSpecialPages as $page ) {
						unset( $specials[ $page ] );
					}
					return true;
				}
			]
		);

		# Let us remove the `logout` link in any case (independent of our
		# `switchUser` setting), because using an anonymous user is something we
		# want to avert while using this extension.
		Hooks::register( 'PersonalUrls', [
			function ( &$personalurls, &$title ) {
				unset( $personalurls[ 'logout' ] );
				return true;
			}
			]
		);

		return true;
	}

	/**
	 * We do support user switching (as inherited by our parent).
	 *
	 * This setting let us support the behaviour of Auth_remoteuser versions prior
	 * 2.0.0, where switching the logged-in local user (as denoted by the wiki
	 * database) wasn't possible.
	 *
	 * User switching is useful when your remote user is tied to a local wiki user,
	 * but needs access as another local user, e.g. a bot account, which in itself
	 * can never be identified as any remote user.
	 *
	 * @since 2.0.0
	 */
	public function canChangeUser() {
		return ( $this->switchUser ) ? parent::canChangeUser() : false;
	}

	/**
	 * Helper method to supplement (new local) users with additional information.
	 *
	 * This method can be used as a callback into the `LocalUserCreated` hook. The
	 * first parameter contains an array of key => value pairs, where the keys
	 * `realname` and `email` are taken for the users real name and email address.
	 * All other keys in that array will be handled as an option into the users
	 * preferences. Each value can also be of type Closure to get called when the
	 * value gets evaluated. This type of late binding should then return the real
	 * value and could be useful, if you want to delegate the execution of code to
	 * a point where it is really needed and not inside `LocalSettings.php`.
	 *
	 * @param array $container Key value store with the following elements:
	 *    `properties` => Array of user information and preferences.
	 *    `metadata` => Provider metadata of the current request.
	 * @param User $user
	 * @param boolean $autoCreated
	 * @see User::setRealName()
	 * @see User::setEmail()
	 * @see User::setOption()
	 * @since 2.0.0
	 */
	public static function setUserProps( $container, $user, $autoCreated = false ) {

		if ( is_array( $container ) && isset( $container[ 'properties' ] ) && is_array( $container[ 'properties' ] ) && $user instanceof User && $autoCreated ) {

			# Create a copy of our provider metadata.
			$metadata = ( isset( $container[ 'metadata' ] ) ) ? [] + $container[ 'metadata' ] : [];
			# Provide a switch to save changes to the database with this funtion call.
			$saveToDB = ( isset( $container[ 'saveToDB' ] ) ) ? $container[ 'saveToDB' ] : false;
			# Mark changes to prevent superfluous database writings.
			$dirty = false;

			foreach ( $container[ 'properties' ] as $option => $value ) {

				# If the given value is a closure, call it to get the value. All of our
				# provider metadata is exposed to this function as first parameter. But
				# because it is given by reference we created a copy of it beforehand to
				# not let the function change our metadata.
				if ( $value instanceof Closure ) {
					$value = call_user_func(
						$value,
						$metadata
					);
				}

				switch ( $option ) {
					case 'realname':
						if ( is_string( $value ) && $value !== $user->getRealName() ) {
							$dirty = true;
							$user->setRealName( $value );
						}
						break;
					case 'email':
						if ( Sanitizer::validateEmail( $value ) && $value !== $user->getEmail() ) {
							$dirty = true;
							$user->setEmail( $value );
							$user->confirmEmail();
						}
						break;
					default:
						if ( $value != $user->getOption( $option ) ) {
							$dirty = true;
							$user->setOption( $option, $value );
						}
				}
			}

			# Only update database if something has changed.
			if ( $saveToDB && $dirty ) {
				$user->saveSettings();
			}
		}

	}

	/**
	 * Helper method to apply replacement patterns to a remote user name
	 * before using it as an identifier into the local wiki user database.
	 *
	 * Method uses the `Auth_remoteuser_filterUserName` hook.
	 *
	 * Some examples:
	 * ```
	 * '/_/' => ' '                   // replace underscore character with space
	 * '/@domain.example.com$/' => '' // strip Kerberos principal from back
	 * '/^domain\\\\/' => ''          // remove NTLM domain from front
	 * '/johndoe/' => 'Admin'         // rewrite username
	 * ```
	 *
	 * @param array $params Array of search and replace patterns.
	 * @throws UnexpectedValueException Wrong parameter type given.
	 * @see preg_replace()
	 * @since 2.0.0
	 */
	public static function setUserNameFilters( $params = [] ) {

		if ( !is_array( $params ) ) {
			throw new UnexpectedValueException( __METHOD__ . ' expects an array as parameter.' );
		}

		Hooks::register( 'Auth_remoteuser_filterUserName', [
			function ( $replacepatterns, &$username ) {

				$replaced = $username;

				foreach ( $replacepatterns as $pattern => $replacement ) {
					$replaced = preg_replace( $pattern, $replacement, $replaced );
					if ( null === $replaced ) { break; }
				}

				if ( null === $replaced ) {
					return false;
				}

				$username = $replaced;
				return true;
			},
			$params
			]
		);

	}

}


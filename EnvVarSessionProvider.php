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
use GlobalVarConfig;
use Sanitizer;
use User;

/**
 * Session provider for the Auth_remoteuser extension.
 *
 * An EnvVarSessionProvider uses an environment variable set by the webserver
 * specifying the user (identifying the user is the purpose of the webservers
 * authentication system) and tries to tie it to an according local wiki user.
 *
 * This provider acts the same as the CookieSessionProvider but in contrast
 * does not allow anonymous users per session as the CookieSessionProvider
 * does. In this case a user will be set which is identified by the given
 * environment variable. Additionally this provider will create a new session
 * with the given user if no session exists for the current request. The
 * default CookieSessionProvider creates new sessions on specific user
 * activities only (@see CookieSessionProvider on lines 180-182).
 *
 * In fact, this provider just supplements the default CookieSessionProvider,
 * so set the priorities in your MediaWiki accordingly. Give this provider a
 * higher priority than CookieSessionProvider to get an automatic login and the
 * default CookieSessionProvider as a fallback, when no environment variable is
 * set.
 *
 * @version 2.0.0
 * @since 2.0.0
 */
class EnvVarSessionProvider extends CookieSessionProvider {

	/**
	 * The environment variable name(s) given as an array.
	 *
	 * @var string[]
	 * @since 2.0.0
	 */
	protected $envVarName;

	/**
	 * Indicates if the automatically logged-in user can switch to another local
	 * account while still beeing identified by the remote variable.
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
	 * Indicates if local users (as of yet unknown to the wiki database) should be
	 * created automatically.
	 *
	 * @var boolean
	 * @since 2.0.0
	 */
	protected $autoCreateUser;

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
	 * The constructor processes the extension configuration.
	 *
	 * @see $wgAuthRemoteuserEnvVarName
	 * @see $wgAuthRemoteuserPriority
	 * @see $wgAuthRemoteuserAllowUserSwitch
	 * @see $wgAuthRemoteuserRemoveAuthPagesAndLinks
	 * @see $wgAuthRemoteuserAutoCreateUser
	 * @see $wgAuthRemoteuserFacetUserName
	 * @see $wgAuthRemoteuserUserProps
	 * @see $wgAuthRemoteuserForceUserProps
	 * @since 2.0.0
	 */
	public function __construct( $params = [] ) {

		# Process our extension specific configuration, but don't overwrite our
		# parents $this->config property, because doing so will clash with the
		# SessionManager setting of that property due to a different prefix used.
		$conf = new GlobalVarConfig( 'wgAuthRemoteuser' );

		# Specify the priority we will give to SessionInfo objects. Validation will
		# be done by our parents constructor.
		if ( $conf->has( 'Priority' ) ) {
			$params[ 'priority' ] = $conf->get( 'Priority' );
		}

		# The cookie prefix used by our parent will be the same as our class name to
		# not interfere with cookies set by other instances of our parent.
		$prefix = str_replace( '\\', '_', __CLASS__ );
		$params += [
			"sessionName" => $prefix . '_session',
			"cookieOptions" => []
		];
		$params[ 'cookieOptions' ] += [ "prefix" => $prefix ];

		parent::__construct( $params );

		# The EnvVarName configuration value could be a string or an array of
		# strings, but we want an array in any case.
		$this->envVarName = [];
		if ( $conf->has( 'EnvVarName' ) ) {
			$names = $conf->get( 'EnvVarName' );
			if ( is_string( $names ) ) {
				$this->envVarName = [ $names ];
			} elseif ( is_array( $names ) ) {
				foreach ( $names as $name ) {
					if ( is_string( $name ) ) {
						$this->envVarName[] = $name;
					}
				}
			}
		}

		$this->switchUser = ( $conf->has( 'AllowUserSwitch' ) ) ? (bool)$conf->get( 'AllowUserSwitch' ) : false;

		$this->removeAuthPagesAndLinks = ( $conf->has( 'RemoveAuthPagesAndLinks' ) ) ? (bool)$conf->get( 'RemoveAuthPagesAndLinks' ) : true;

		$this->autoCreateUser = ( $conf->has( 'AutoCreateUser' ) ) ? (bool)$conf->get( 'AutoCreateUser' ) : true;

		# The environment variable should be processed before used as an identifier
		# into the local user database, so set up an according callback to be used
		# when the `Auth_remoteuser_filterUserName` hook runs.
		#
		# @see self::facetUserName()
		if ( $conf->has( 'FacetUserName' ) ) {
			self::facetUserName( $conf->get( 'FacetUserName' ) );
		}

		$this->userProps = ( $conf->has( 'UserProps' ) && is_array( $conf->get( 'UserProps' ) ) ) ? $conf->get( 'UserProps' ) : null;

		$this->forceUserProps = ( $conf->has( 'ForceUserProps' ) ) ? (bool)$conf->get( 'ForceUserProps' ) : true;

	}

	/**
	 * Method get's called by the SessionManager on every request for each
	 * SessionProvider installed to determine if this SessionProvider has
	 * identified a possible session.
	 *
	 * @since 2.0.0
	 */
	public function provideSessionInfo( WebRequest $request ) {

		foreach ( $this->envVarName as $envVarName ) {

			# Get the environment variable value. Will return false if it doesn't
			# exist.
			$userName = getenv( $envVarName );

			if ( $userName !== false ) {

				# Process the given environment variable if needed, e.g. strip NTLM
				# domain, replace characters, rewrite to another username or even
				# blacklist. This can be used by the wiki administrator to adjust this
				# SessionProvider to his specific needs.
				if ( !Hooks::run( 'Auth_remoteuser_filterUserName', [ &$userName ] ) ) {
					continue;
				}

				# Create a UserInfo (and User) object by given user name. The factory
				# method will take care of correct validation of user names. It will also
				# transform the first letter to uppercase.
				#
				# An exception gets thrown when the given user name is not 'usable' as an
				# user name for the wiki, either blacklisted or contains invalid characters
				# or is an ip address.
				#
				# @see User::getCanonicalName()
				# @see User::isUsableName()
				# @see Title::newFromText()
				try {
					$userInfo = UserInfo::newFromName( $userName, true );
				} catch ( InvalidArgumentException $e ) {
					continue;
				}

				# We aren't allowed to autocreate new users, therefore we won't provide any
				# session infos.
				#
				# @see User::isAnon()
				# @see User::isLoggedIn()
				# @see UserInfo::getId()
				if ( !( $this->autoCreateUser || $userInfo->getId() ) ) {
					continue;
				}

				# Let our parent class find a valid SessionInfo.
				$sessionInfo = parent::provideSessionInfo( $request );

				# Our parent class couldn't provide any info. This means we can create a
				# new session with our identified user.
				if ( !$sessionInfo ) {
					$sessionInfo = new SessionInfo( $this->priority, [
						"provider" => $this,
						"id" => $this->manager->generateSessionId(),
						"userInfo" => $userInfo
						]
					);
				}

				# The current session identifies an anonymous user, therefore we have to
				# use the forceUse flag to set our identified user. If we are configured
				# to forbid user switching, force the usage of our identified user too.
				if ( !$sessionInfo->getUserInfo() || !$sessionInfo->getUserInfo()->getId()
					|| ( !$this->switchUser && $sessionInfo->getUserInfo()->getId() !== $userInfo->getId() ) ) {
					$sessionInfo = new SessionInfo( $sessionInfo->getPriority(), [
						"copyFrom" => $sessionInfo,
						"userInfo" => $userInfo,
						"forceUse" => true
						]
					);
				}

				# Store id of user identified by environment variable in the provider
				# metadata.
				$sessionInfo = new SessionInfo( $sessionInfo->getPriority(), [
					"copyFrom" => $sessionInfo,
					"metadata" => [
						"userId" => $userInfo->getId()
						]
					]
				);

				return $sessionInfo;

			}

		}

		# We didn't identified anything, so let other SessionProviders do their work.
		return null;
	}

	/**
	 * Never use the stored metadata and return the provided one in any case.
	 *
	 * @since 2.0.0
	 */
	public function mergeMetadata( array $savedMetadata, array $providedMetadata ) {
		$savedMetadata[ 'userId' ] = $providedMetadata[ 'userId' ];
		return parent::mergeMetadata( $savedMetadata, $providedMetadata );
	}

	/**
	 * The SessionManager selected us as the SessionProvider for this request.
	 *
	 * Now we can add additional information to the requests user object and
	 * remove some special pages and personal urls from the clients wiki.
	 *
	 * @since 2.0.0
	 */
	public function refreshSessionInfo( SessionInfo $info, WebRequest $request, &$metadata ) {

		# If the user identified by the environment variable is the same as the
		# the one used for this session, then add additional information to the
		# user object. They can only differ if our property `switchUser` is true.
		if ( $this->userProps && $info->getUserInfo()->getId() === $metadata[ 'userId' ] ) {

			# Force the setting of our user properties on each request virtually
			# overwrites the users own preference settings. Useful if users real name
			# or email is provided by an external source and must not be changed by
			# the user in MediaWiki itself.
			#
			# @see $wgGroupPermissions['user']['editmyoptions']
			if ( $this->forceUserProps ) {

				self::setUserProps(
					$this->userProps,
					$info->getUserInfo()->getUser(),
					true
				);
				$info->getUserInfo()->getUser()->saveSettings();

			# Only add additional information to the user object if the user must be
			# created in the local database with this request.
			} elseif ( !$info->getUserInfo()->getId() ) {

				Hooks::register(
					'LocalUserCreated', [
						__CLASS__ . '::setUserProps',
						$this->userProps
					]
				);

			}

		}

		if ( $this->removeAuthPagesAndLinks ) {

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

			if ( !$this->switchUser ) {
				Hooks::register( 'SpecialPage_initList', [
					function ( &$specials ) {
						foreach ( [
							'Userlogin',
							'Userlogout',
							'CreateAccount',
							'LinkAccounts',
							'UnlinkAccounts',
							'ChangeCredentials',
							'RemoveCredentials'
						] as $page ) {
							unset( $specials[ $page ] );
						}
						return true;
					}
					]
				);
			}

		}

		return true;
	}

	/**
	 * Can the client use another local user as the one we have identified.
	 *
	 * With our ChangeUser configuration value we do support the behaviour of
	 * Auth_remoteuser versions prior 2.0.0, where changing the local user wasn't
	 * possible.
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
	 * given parameter specifies an array of key => value pairs, where the keys
	 * `realname` and `email` are taken for the users real name and email address.
	 * All other keys in that array will be handled as an option into the users
	 * preferences.
	 *
	 * @param array $properties Array of user information and preferences.
	 * @param User $user
	 * @param boolean $autoCreated
	 * @see User::setRealName()
	 * @see User::setEmail()
	 * @see User::setOption()
	 * @since 2.0.0
	 */
	public static function setUserProps( $properties, $user, $autoCreated = false ) {

		if ( is_array( $properties ) && $user instanceof User && $autoCreated ) {
			foreach ( $properties as $option => $value ) {
				switch ( $option ) {
					case 'realname':
						if ( is_string( $value ) ) {
							$user->setRealName( $value );
						}
						break;
					case 'email':
						if ( Sanitizer::validateEmail( $value ) ) {
							$user->setEmail( $value );
							$user->confirmEmail();
						}
						break;
					default:
						$user->setOption( $option, $value );
				}
			}
		}

	}

	/**
	 * Helper method to apply replacement patterns to the environment variable
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
	public static function facetUserName( $params = [] ) {

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


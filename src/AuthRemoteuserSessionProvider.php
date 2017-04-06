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

use MediaWiki\Extensions\Auth_remoteuser\UserNameSessionProvider;
use Hooks;
use GlobalVarConfig;

/**
 * Session provider for the Auth_remoteuser extension.
 *
 * A RemoteUserSessionProvider uses a given user name (given by an arbitrary
 * source, which takes total responsibility in authenticating that user) and
 * tries to tie it to an according local wiki user.
 *
 * This provider acts the same as the CookieSessionProvider but in contrast
 * does not allow anonymous users per session as the CookieSessionProvider
 * does. In this case a user will be set which is identified by the given
 * user name. Additionally this provider will create a new session with the
 * given user if no session exists for the current request. The default
 * `CookieSessionProvider` creates new sessions on specific user activities
 * only (@see `CookieSessionProvider` on lines 180-182).
 *
 * In fact, this provider acts the same as the default `CookieSessionProvider`,
 * so set the priorities in your MediaWiki accordingly. Give this provider a
 * higher priority than CookieSessionProvider to get an automatic login and the
 * default CookieSessionProvider as a fallback, when no remote user name is
 * given.
 *
 * @version 2.0.0
 * @since 2.0.0
 */
class AuthRemoteuserSessionProvider extends UserNameSessionProvider {

	/**
	 * The constructor processes the extension configuration.
	 *
	 * Legacy extension parameters are still fully supported, but new parameters
	 * taking precedence over legacy ones. List of legacy parameters:
	 * * `$wgAuthRemoteuserAuthz`      equivalent to disabling the extension
	 * * `$wgAuthRemoteuserName`       superseded by `$wgRemoteuserUserProps`
	 * * `$wgAuthRemoteuserMail`       superseded by `$wgRemoteuserUserProps`
	 * * `$wgAuthRemoteuserNotify`     superseded by `$wgRemoteuserUserProps`
	 * * `$wgAuthRemoteuserDomain`     superseded by `$wgRemoteuserUserNameFilters`
	 * * `$wgAuthRemoteuserMailDomain` superseded by `$wgRemoteuserUserProps`
	 *
	 * @see $wgAuthRemoteuserUserNames
	 * @see $wgAuthRemoteuserUserNameFilters
	 * @see $wgAuthRemoteuserUserProps
	 * @see $wgAuthRemoteuserForceUserProps
	 * @see $wgAuthRemoteuserAutoCreateUser
	 * @see $wgAuthRemoteuserAllowUserSwitch
	 * @see $wgAuthRemoteuserRemoveAuthPagesAndLinks
	 * @see $wgAuthRemoteuserPriority
	 * @since 2.0.0
	 */
	public function __construct( $params = [] ) {

		# Process our extension specific configuration, but don't overwrite our
		# parents $this->config property, because doing so will clash with the
		# SessionManager setting of that property due to a different prefix used.
		$conf = new GlobalVarConfig( 'wgAuthRemoteuser' );

		# The `UserNames` configuration value defaults to the environment variable
		# `REMOTE_USER`.
		if ( $conf->has( 'UserNames' ) ) {
			$params[ 'remoteUserNames' ] = $conf->get( 'UserNames' );
		} else {
			$params[ 'remoteUserNames' ] = [ getenv( 'REMOTE_USER' ) ];
		}

		# Evaluate user properties of type array only.
		if ( $conf->has( 'UserProps' ) && is_array( $conf->get( 'UserProps' ) ) ) {
			$params[ 'userProps' ] = $conf->get( 'UserProps' );
		}

		# The remote user name should be processed before used as an identifier into
		# the local user database, so set up an according callback to be used when
		# the `Auth_remoteuser_filterUserName` hook runs.
		#
		# @see self::setUserNameFilters()
		if ( $conf->has( 'UserNameFilters' ) ) {
			self::setUserNameFilters( $conf->get( 'UserNameFilters' ) );
		}

		# Process configuration parameters of type boolean.
		$booleans = [
			'ForceUserProps' => 'forceUserProps',
			'AutoCreateUser' => 'autoCreateUser',
			'AllowUserSwitch' => 'switchUser',
			'RemoveAuthPagesAndLinks' => 'removeAuthPagesAndLinks'
		];
		foreach( $booleans as $configKey => $paramKey ) {
			if ( $conf->has( $configKey ) ) {
				$params[ $paramKey ] = (bool)$conf->get( $configKey );
			}
		}

		# Specify the priority we will give to SessionInfo objects. Validation will
		# be done by our parents constructor.
		if ( $conf->has( 'Priority' ) ) {
			$params[ 'priority' ] = $conf->get( 'Priority' );
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserAuthz`.
		#
		# Turning all off (no autologin) will be attained by evaluating nothing.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'Authz' ) && ! $conf->get( 'Authz' ) ) {
			$params[ 'remoteUserNames' ] = [];
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserName`.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'Name' ) && is_string( $conf->get( 'Name' ) ) && $conf->get( 'Name' ) !== '' ) {
			$params[ 'userProps' ] += [ 'realname' => $conf->get( 'Name' ) ];
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserMail`.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'Mail' ) && is_string( $conf->get( 'Mail' ) ) && $conf->get( 'Mail' ) !== '' ) {
			$params[ 'userProps' ] += [ 'email' => $conf->get( 'Mail' ) ];
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserNotify`.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'Notify' ) ) {
			$notify = $conf->get( 'Notify' ) ? 1 : 0;
			$params[ 'userProps' ] += [
				'enotifminoredits' => $notify,
				'enotifrevealaddr' => $notify,
				'enotifusertalkpages' => $notify,
				'enotifwatchlistpages' => $notify
			];
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserDomain`.
		#
		# Ignored when the new equivalent `$wgAuthRemoteuserUserNameFilters` is set.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'Domain' ) && is_string( $conf->get( 'Domain' ) ) && $conf->get( 'Domain' ) !== '' && ! $conf->has( 'UserNameFilters' ) ) {
			self::setUserNameFilters( [
				'/@' . $conf->get( 'Domain' ) . '$/' => '',
				'/^' . $conf->get( 'Domain' ) . '\\\\/' => ''
				]
			);
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserMailDomain`.
		#
		# Can't be used directly at this point of execution until we have our a valid
		# user object with the according user name. Therefore we have to use the
		# closure feature of our user property values to defer the evaluation.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'MailDomain' ) && is_string ( $conf->get( 'MailDomain' ) ) && $conf->get( 'MailDomain' ) !== '' ) {
			$domain = $conf->get( 'MailDomain' );
			$params[ 'userProps' ] += [
				'email' => function( $metadata ) use( $domain )  {
					return $metadata[ 'remoteUserName' ] . '@' . $domain;
				}
			];
		}

		parent::__construct( $params );
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


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
 * @version 2.0.0
 * @since 2.0.0
 */
class AuthRemoteuserSessionProvider extends UserNameSessionProvider {

	const HOOKNAME = "AuthRemoteuserFilterUserName";

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
	 * List of global configuration parameters:
	 * * `$wgAuthRemoteuserUserName`
	 * * `$wgAuthRemoteuserUserNameReplaceFilter`
	 * * `$wgAuthRemoteuserUserProps`
	 * * `$wgAuthRemoteuserForceUserProps`
	 * * `$wgAuthRemoteuserAllowUserSwitch`
	 * * `$wgAuthRemoteuserRemoveAuthPagesAndLinks`
	 * * `$wgAuthRemoteuserPriority`
	 *
	 * @since 2.0.0
	 */
	public function __construct( $params = [] ) {

		# Process our extension specific configuration, but don't overwrite our
		# parents `$this->config` property, because doing so will clash with the
		# SessionManager setting of that property due to a different prefix used.
		$conf = new GlobalVarConfig( 'wgAuthRemoteuser' );

		$mapping = [
			'UserName' => 'remoteUserNames',
			'UserProps' => 'userProps',
			'ForceUserProps' => 'forceUserProps',
			'AllowUserSwitch' => 'switchUser',
			'RemoveAuthPagesAndLinks' => 'removeAuthPagesAndLinks',
			'Priority' => 'priority'
		];

		foreach ( $mapping as $confkey => $key ) {
			if ( $conf->has( $confkey ) ) {
				$params[ $key ] = $conf->get( $confkey );
			}
		}

		if ( $conf->has( 'UserNameReplaceFilter' ) ) {
			self::setUserNameReplaceFilter( $conf->get( 'UserNameReplaceFilter' ) );
		}

		# Set default remote user name source if no other is specified.
		if ( !isset( $params[ 'remoteUserNames' ] ) ) {
			$params[ 'remoteUserNames' ] = [
				getenv( 'REMOTE_USER' ),
				getenv( 'REDIRECT_REMOTE_USER' )
			];
		}

		# Prepare `userProps` configuration for legacy parameter evaluation.
		if ( !isset( $params[ 'userProps' ] ) ||
			!is_array( $params[ 'userProps' ] ) ) {
			$params[ 'userProps' ] = [];
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserAuthz`.
		#
		# Turning all off (no autologin) will be attained by evaluating nothing.
		#
		# @deprecated 2.0.0
		if ( $conf->has( 'Authz' ) && !$conf->get( 'Authz' ) ) {
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
		if ( $conf->has( 'Domain' ) && is_string( $conf->get( 'Domain' ) ) && $conf->get( 'Domain' ) !== '' && !$conf->has( 'UserNameReplaceFilter' ) ) {
			self::setUserNameReplaceFilter( [
				'@' . $conf->get( 'Domain' ) . '$' => '',
				'^' . $conf->get( 'Domain' ) . '\\' => ''
			] );
		}

		# Evaluation of legacy parameter `$wgAuthRemoteuserMailDomain`.
		#
		# Can't be used directly at this point of execution until we have our a valid
		# user object with the according user name. Therefore we have to use the
		# closure feature of the user property values to defer the evaluation.
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

		if ( count( $params[ 'userProps' ] ) < 1 ) {
			unset( $params[ 'userProps' ] );
		}

		parent::__construct( $params );
	}

	/**
	 * Helper method to apply replacement patterns to a remote user name
	 * before using it as an identifier into the local wiki user database.
	 *
	 * Method uses the provided hook and accepts regular expressions as
	 * search patterns.
	 *
	 * Some examples:
	 * ```
	 * '_' => ' '                   // replace underscore character with space
	 * '@domain.example.com$' => '' // strip Kerberos principal from back
	 * '^domain\\' => ''            // remove NTLM domain from front
	 * 'johndoe' => 'Admin'         // rewrite username
	 * ```
	 *
	 * @param array $replacepatterns Array of search and replace patterns.
	 * @throws UnexpectedValueException Wrong parameter type given.
	 * @see preg_replace()
	 * @since 2.0.0
	 */
	public static function setUserNameReplaceFilter( $replacepatterns = [] ) {

		if ( !is_array( $replacepatterns ) ) {
			throw new UnexpectedValueException( __METHOD__ . ' expects an array as parameter.' );
		}

		Hooks::register(
			static::HOOKNAME,
			function ( &$username ) use ( $replacepatterns ) {

				foreach ( $replacepatterns as $pattern => $replacement ) {

					$pattern = str_replace( '\\', '\\\\', $pattern );
					$pattern = str_replace( '/', '\\/', $pattern );
					$replaced = preg_replace( "/$pattern/", $replacement, $username );
					if ( null === $replaced ) {
						return false;
					}
					$username = $replaced;

				}

				return true;
			}
		);

	}

}


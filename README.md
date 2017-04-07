Auth_remoteuser
===============

Auth_remoteuser is an extension for MediaWiki 1.27 and up which logs-in users
into mediawiki automatically if they are already authenticated by a remote
source. This can be anything ranging from webserver environment variables to
request headers to arbitrary external sources if at least the remote user name
maps to an existing user name in the local wiki database (or it can be created
if the extension was instructed to do so). The external source takes total
responsibility in authenticating an authorized user.
Because it is implemented as a SessionProvider in MediaWikis AuthManager stack,
which was introduced with MediaWiki 1.27, you need a version of Auth_remoteuser
below 2.0.0 to use this extension in MediaWiki 1.26 and below.


Requirements
------------

* MediaWiki 1.27+


Installation
------------

Copy this extension directory `Auth_remoteuser/` into your mediawiki extension
folder `extensions/`. Then add the following line to your global configuration
file `LocalSettings.php`:

    wfLoadExtension( 'Auth_remoteuser' );

Take account of the global permissions for account creation. At least one of
them must be `true` for anonymous users to led this extension create accounts
for them (independent of its own configuration setting `AutoCreateUser` below):

    $wgGroupPermission['*']['createaccount'] = true;

    // or if account creation by anonymous users is forbidden
    $wgGroupPermission['*']['createaccount'] = false;
    $wgGroupPermission['*']['autocreateaccount'] = true;


Configuration
-------------

You can adjust the behaviour of the extension to suit your needs by using a
set of global configuration variables all starting with `$wgAuthRemoteuser`.
Just add them to your `LocalSettings.php`. Default values, which you don't
have to set explicitly are marked with the `// default` comment.

* Set the name(s) to use for mapping into the local wiki user database. This
  can either be a simple string, a closure or a mixed array of strings and/or
  closures. If the value is `null`, the extension defaults to using the
  environment variables `REMOTE_USER` and `REDIRECT_REMOTE_USER`. The first
  name in the given list, which matches an user name in the local wiki
  database will be taken for login. Examples:

        $wgAuthRemoteuserUserName = null; // default

        $wgAuthRemoteuserUserName = ""; // Will evaluate to nothing.
        $wgAuthRemoteuserUserName = []; // Will evaluate to nothing.

        // This is not adviced, because it will evaluate every visitor to
        // the same user.
        $wgAuthRemoteuserUserName = "Everybody";

        // Iterate through an array of given user name sources.
        $wgAuthRemoteuserUserName = [ $_SERVER[ 'REMOTE_USER' ];
        $wgAuthRemoteuserUserName[] = $_SERVER[ 'REDIRECT_REMOTE_USER' ];
        $wgAuthRemoteuserUserName[] = $_SERVER[ 'LOGON_USER' ];

        // Create a closure instead of providing strings directly.
        $wgAuthRemoteUserNames = function() {
            $credentials = explode( ':', $_SERVER[ 'HTTP_AUTHORIZATION' ] );
            $username = $credentials[0];
            $password = $credentials[1];
            return MyOwnAuthorizer::authenticate( $username, $password )
                ? $username : null;
        };

* If you are using other SessionProvider extensions besides this one, you
  have to specify their significance by using an ascending priority:

        $wgAuthRemoteuserPriority = 50; // default

        $wgAuthRemoteuserPriority = SessionInfo::MAX_PRIORITY;

* Indicate wether a new user, authenticated by the webserver and identified
  by this extension, but yet unknown to the wiki should be created or not:

        $wgAuthRemoteuserAutoCreateUser = true; // default

        $wgAuthRemoteuserAutoCreateUser = false;

  This setting is independent of the global permission for account creation
  `$wgGroupPermission[]['autocreateaccount']`. Set this configuration to
  `false` if you're using other session providers and all but this one can
  create new users.

* When you need to process your environment variable value before it can be
  used as an identifier into the wiki username list, for example to strip
  a Kerberos principal from the end or replacing some invalid characters, set
  an array of replacement patterns to the following configuration variable:

        $wgAuthRemoteuserUserNameReplaceFilter = array(); // default

        $wgAuthRemoteuserUserNameReplaceFilter = array(
            '_' => ' ',                   // replace underscores with spaces
            '@domain.example.com$' => '', // strip Kerberos principal from back
            '^domain\\' => '',            // strip NTLM domain from front
            'johndoe' => 'Admin'          // rewrite user johndoe to user Admin
        );

  If you need further processing, maybe blacklisting some usernames or
  something else, you can use the hook `AuthRemoteuserFilterUserName`
  provided by this extension. Just have a look at mediawikis Hook
  documentation on how to register additional functions to this hook.
  For example, if you need to forbid the automatic login for specific user
  accounts all starting with the same characters, you would implement this
  as follows:

        Hooks::register( 'AuthRemoteuserFilterUserName',
            function ( &$userName ) {
                $needle = 'f_';
                $length = strlen( $needle );
                return !(substr( $userName, 0, $length ) === $needle );
            }
        );

* By default this extension mimics the behaviour of Auth_remoteuser
  versions prior 2.0.0, which prohibits using another local user then the
  one identified by the environment variable. You can change this behaviour
  with the following configuration:

        $wgAuthRemoteuserAllowUserSwitch = false; // default

        $wgAuthRemoteuserAllowUserSwitch = true;

* As an immutable SessionProvider (see `AllowUserSwitch` config above) all
  special pages and login/logout links for authentication aren't needed
  anymore by the identified user. If you still want them to be shown, for
  example if you are using other session providers besides this one, then
  set the following accordingly:

        $wgAuthRemoteuserRemoveAuthPagesAndLinks = true; // default

        $wgAuthRemoteuserRemoveAuthPagesAndLinks = false;

* When you have further user information available in your environment, which
  can be tied to a created user, for example email address or real name, then
  use the following configuration variable. It expects an array of key value
  pairs of which 'realname' and 'email' corresponds to the new users real name
  and email address, while you can specify further key value pairs to get them
  mapped to according users preferences:

        $wgAuthRemoteuserUserProps = array(); // default

        // set email only
        $wgAuthRemoteuserUserProps = array(
            'email' => $_SERVER[ 'AUTHENTICATE_MAIL' ],
        );

        // set real name, email and some preference options
        $wgAuthRemoteuserUserProps = array(
            'realname' => $_SERVER[ 'AUTHENTICATE_DISPLAYNAME' ],
            'email' => $_SERVER[ 'AUTHENTICATE_MAIL' ],
            'language' => 'en',
            'disablemail' => 0,
            'ccmeonemails' => 1,
            'enotifwatchlistpages' => 1,
            'enotifusertalkpages' => 1,
            'enotifminoredits' => 1
        );

  You can specify an anonymous function for the values too. These closures
  getting called when the actual value is needed, and not when it is declared
  inside your `LocalSettings.php`. The first parameter given to the function
  is an associative array with the following keys:
  * `userId` - id of user in local wiki database or 0 if new/anonymous
  * `remoteUserName` - value as given by the environment
  * `filteredUserName` - after running `AuthRemoteuserFilterUserName` hook
  * `canonicalUserName` - representation in the local wiki database
  * `canonicalUserNameUsed` - the user name used for the current session

  Take the following as an example in which a shellscript is getting executed
  only when a user is created and not on every page reload:

        $wgAuth_remoteuser_ForceUserProps = false;
        $wgAuth_remoteuser_UserProps = array(
            'email' => function( $data ) {
                $name = $data[ 'remoteUserName' ];
                return shell_exec( "/usr/bin/get_mail.sh '$name'" );
            }
        )

* If you have user properties specified (see `UserProps` config above), then
  you can set them for new users only (see `AutoCreateUser` config) or force
  their setting. For example if your users email is specified by an external
  source and you don't want the user to change this email inside MediaWiki,
  then set the following configuration variable to true:

        $wgAuthRemoteuserForceUserProps = true; // default

        $wgAuthRemoteuserForceUserProps = false;


Upgrade
-------

This extension doesn't uses any database entries, therefore you don't need
that extension to be enabled while upgrading. Just disable it and after
you have upgraded your wiki, reenable this extension.


Upgrading from versions prior 2.0.0
-----------------------------------

All legacy configuration parameters are still fully supported. You don't
have to rewrite your old `LocalSettings.php` settings for this extension.
But to assist you in transitioning of old configuration parameters to new
ones, the following list can guide you:

* `$wgAuthRemoteuserAuthz`
  This parameter has no equivalent new parameter, because you can achive
  the same with not loading the extension at all.
* `$wgAuthRemoteuserName` - Superseded by `$wgRemoteuserUserProps`.
* `$wgAuthRemoteuserMail` - Superseded by `$wgRemoteuserUserProps`.
* `$wgAuthRemoteuserNotify` - Superseded by `$wgRemoteuserUserProps`.
* `$wgAuthRemoteuserDomain` - Superseded by `$wgRemoteuserUserNameReplaceFilter`.
* `$wgAuthRemoteuserMailDomain` - Superseded by `$wgRemoteuserUserProps`.


Additional notes
----------------

For a complete list of authors and any further documentation see the file
`extension.json` or the `Special:Version` page on your wiki installation
after you have enabled this extension.

For the license see the file `COPYING`.

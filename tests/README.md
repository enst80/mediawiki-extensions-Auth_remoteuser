Auth_remoteuser unit tests
==========================

For users
---------

The `/tests` subdirectory is excluded from distribution tarballs by default. If
you want to use the unit tests either clone the extension repository or in case
of managing extensions with Composer use its `--prefer-source` option.

The extension unit tests become part of MediaWikis core unit tests when you start
the unit testing framework of MediaWiki. To test the Auth_remoteuser unit tests
only issue one of the following commands from inside your MediaWiki installation
root folder:

*
    tests/phpunit/phpunit.php extensions/Auth_remoteuser/tests/
*
    tests/phpunit/phpunit.php --testdox extensions/Auth_remoteuser/tests/

Or when using MediaWiki `vagrant` environment, connect to your machine and run
the test suite from inside your MediaWiki root folder there:

*
    sudo -u www-data php tests/phpunit/phpunit.php --wiki wiki --configuration tests/phpunit/suite.xml extensions/Auth_remoteuser/tests/

For developers
--------------

Each new test case should inherit from the `Auth_remoteuserTestCase` class. The
default `MediaWikiTestCase` sets up the `SessionProvider` in its own `setUp()`
method, therefore you need to configure the tests for Auth_remoteuser before
this method get's called. Use the provided method `setUpSessionProvider()` for
that.


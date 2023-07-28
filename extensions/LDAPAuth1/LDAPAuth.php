<?php

// Replace with the LDAP attribute mappings

/**
 * LDAPAuth extension - LDAP authentication for MediaWiki
 *
 * @file
 * @ingroup Extensions
 */

 if (!defined('MEDIAWIKI')) {
    die();
}

$wgExtensionCredits['other'][] = [
    'path' => __FILE__,
    'name' => 'LDAPAuth',
    'version' => '1.0',
    'author' => 'Madhuri',
    'url' => 'http://localhost/mywiki',
    'description' => 'LDAP authentication for MediaWiki',
];

$wgExtensionMessagesFiles['LDAPAuth'] = __DIR__ . '/LDAPAuth.i18n.php';

$wgAutoloadClasses['LDAPAuthHooks'] = __DIR__ . '/LDAPAuthHooks.php';

// Hooks
$wgHooks['UserLoadFromSession'][] = 'LDAPAuthHooks::onUserLoadFromSession';
$wgHooks['UserGetReservedUsernames'][] = 'LDAPAuthHooks::onUserGetReservedUsernames';
$wgHooks['AuthChangeFormFields'][] = 'LDAPAuthHooks::onAuthChangeFormFields';

// Additional configuration options
$wgLDAPAuthDomain = 'grupopremo.local'; // Replace with your LDAP domain
$wgLDAPAuthServer = 'ldap://cpdad01.grupopremo.local'; // Replace with your LDAP server
$wgLDAPAuthSearchBase = 'ou=script user,dc=grupopremo,dc=local'; // Replace with the LDAP search base
$wgLDAPAuthSearchAttributes = [
    'name' => 'uid',
    'email' => 'mail',
]; // Replace with the LDAP attribute mappings

// Enable LDAP authentication
$wgAuth = new LDAPAuth();

/**
 * LDAPAuth class
 */
class LDAPAuth extends AuthPlugin {
    public function __construct() {
        parent::__construct();

        // Add any additional setup code here
    }

    public function authenticate($username, $password) {
        global $wgLDAPAuthServer, $wgLDAPAuthSearchBase, $wgLDAPAuthSearchAttributes;

        $ldapConn = ldap_connect($wgLDAPAuthServer);
        if (!$ldapConn) {
            return self::AUTH_FAIL;
        }

        ldap_set_option($ldapConn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapConn, LDAP_OPT_REFERRALS, 0);

        $searchFilter = '(' . $wgLDAPAuthSearchAttributes['name'] . '=' . $username . ')';
        $searchResult = ldap_search($ldapConn, $wgLDAPAuthSearchBase, $searchFilter);
        $entry = ldap_first_entry($ldapConn, $searchResult);
        if (!$entry) {
            return self::AUTH_FAIL;
        }

        $dn = ldap_get_dn($ldapConn, $entry);

        if (ldap_bind($ldapConn, $dn, $password)) {
            // Authentication successful
            ldap_close($ldapConn);
            return self::AUTH_SUCCESS;
        } else {
            // Authentication failed
            ldap_close($ldapConn);
            return self::AUTH_FAIL;
        }
    }

    public function autoCreate() {
        return false; // Disable auto-creation of user accounts
    }
    public function initUser(&$user, &$autocreate, $username = null) {
        // Set additional user properties as needed
        // For example, you can set the user's email address
        if (!is_null($username)) {
            $user->setEmail($username . '@example.com');
        }
    }

    public function updateUser(&$user, $username) {
        global $wgLDAPAuthSearchAttributes;

        // Update user properties based on LDAP attributes
        // For example, you can update the user's email address
        if (!empty($wgLDAPAuthSearchAttributes['email'])) {
            $emailAttr = $wgLDAPAuthSearchAttributes['email'];
            $email = ldap_get_values($ldapConn, $entry, $emailAttr);
            if (!empty($email[0])) {
                $user->setEmail($email[0]);
            }
        }
    }

    // Implement other necessary functions
    // ...
}

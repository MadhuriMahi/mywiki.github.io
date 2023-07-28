<?php
namespace mywiki\LDAPAuth;


use MediaWiki\Auth\PrimaryAuthenticationProvider;
use MediaWiki\Hook\UserLoadFromSessionHook;
use MediaWiki\Hook\LoginFormPreFormHook;
use MediaWiki\Hook\UserGetReservedUsernamesHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Session\SessionManager;
use MediaWiki\User\UserIdentity;



function retrieveUserFromLDAP($username) {
    // LDAP server connection parameters
    $ldapServer = 'ldap://cpdad01.grupopremo.local';
    $ldapPort = 389;
    $ldapBindDN = 'cn=premo ad authentication,dc=grupopremo,dc=local';
    $ldapBindPassword = 'Pq6AKz7o.Nv5';

    // Connect to the LDAP server
    $ldapConn = ldap_connect($ldapServer, $ldapPort);
    if (!$ldapConn) {
        // Failed to connect to LDAP server
        return null;
    }

    // Bind to the LDAP server
    $ldapBind = ldap_bind($ldapConn, $ldapBindDN, $ldapBindPassword);
    if (!$ldapBind) {
        // Failed to bind to LDAP server
        return null;
    }

    // Search for the user in LDAP
    $ldapSearchBase = 'ou=Premo,dc=grupopremo,dc=local';
    $ldapSearchFilter = "(uid=$username)";
    $ldapSearchResult = ldap_search($ldapConn, $ldapSearchBase, $ldapSearchFilter);
    $ldapEntry = ldap_first_entry($ldapConn, $ldapSearchResult);
    if (!$ldapEntry) {
        // User not found in LDAP
        return null;
    }

    // Retrieve user attributes from LDAP entry
    $ldapAttributes = ldap_get_attributes($ldapConn, $ldapEntry);

    // Create an object to store LDAP attributes
    $ldapUser = new \stdClass();
    $ldapUser->setEmail($ldapAttributes['mail'][0]);
    $ldapUser->setRealName($ldapAttributes['cn'][0]);

    // Add more attributes as needed

    // Close the LDAP connection
    ldap_close($ldapConn);

    // Return the LDAP user object
    return $ldapUser;
}

class Hooks implements UserLoadFromSessionHook, LoginFormPreFormHook, UserGetReservedUsernamesHook {
    public static function onLoginFormPreForm(\LoginTemplate &$template) {
        // Get the current page title
        $title = $template->getData()['page']['title'] ?? null;

        // Check if the current login page is for LDAP authentication
        if ($title === 'Special:UserLogin' && $template->getRequest()->getSessionData('wsUserID') === null) {
            // Add the LDAP login message to the login form
            $ldapMessage = '<div class="ldap-login-message">This is LDAP login.</div>';
            $template->set('header', $ldapMessage . $template->get('header'));

            // Log the display of the LDAP message
            $logger = LoggerFactory::getInstance('LDAPAuth');
            $logger->debug('Displaying LDAP login message');
        }

        return true;
    }

    public static function onUserLoadFromSession(UserIdentity &$user) {
        // Implement your logic to load user from session
        // For example, you can retrieve user information from LDAP and update the User object accordingly
        $ldapUser = retrieveUserFromLDAP($user->getName());
        if ($ldapUser) {
            // Update the user object with LDAP attributes
            $user->setEmail($ldapUser->getEmail());
            $user->setRealName($ldapUser->getRealName());
            // Update other user attributes as needed
        }

        // Log the user load from session
        $logger = LoggerFactory::getInstance('LDAPAuth');
        $logger->debug("UserLoadFromSession hook executed for user: " . $user->getName());
    }

    public static function onUserGetReservedUsernames(array &$reservedUsernames) {
        // Add reserved usernames if needed
        // For example, you can add a specific LDAP reserved username
        $reservedUsernames[] = 'ldapadmin';

        // Log the reserved usernames
        $logger = LoggerFactory::getInstance('LDAPAuth');
        $logger->debug("UserGetReservedUsernames hook executed with reserved usernames: " . implode(', ', $reservedUsernames));
    }
}


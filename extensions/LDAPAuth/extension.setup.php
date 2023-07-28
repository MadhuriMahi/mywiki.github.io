<?php

$wgExtensionCredits['other'][] = array(
    'path' => __FILE__,
    'name' => 'LDAPAuth',
    'author' => 'Your Name',
    'description' => 'LDAP authentication extension for MediaWiki',
    'version' => '1.0.0',
);

$wgAutoloadClasses['LDAPAuth\\AuthenticationProvider'] = __DIR__ . '/LDAPAuth/AuthenticationProvider.php';
$wgAutoloadClasses['LDAPAuth\\Hooks'] = __DIR__ . '/LDAPAuth/Hooks.php';

$wgHooks['UserLoadFromSession'][] = 'LDAPAuth\\Hooks::onUserLoadFromSession';
$wgHooks['UserGetReservedUsernames'][] = 'LDAPAuth\\Hooks::onUserGetReservedUsernames';

$wgExtensionMessagesFiles['LDAPAuth'] = __DIR__ . '/LDAPAuth.i18n.php';
// Register the custom special page
$wgSpecialPages['UserLogin'] = 'CustomAuthSpecialPage';
$wgSpecialPageGroups['UserLogin'] = 'login';

// Ensure that the autoloader can find your special page class
$wgAutoloadClasses['CustomAuthSpecialPage'] = __DIR__ . '/CustomAuthSpecialPage.php';
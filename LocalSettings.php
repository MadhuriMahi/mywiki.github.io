<?php
# This file was automatically generated by the MediaWiki 1.40.0
# installer. If you make manual changes, please keep track in case you
# need to recreate them later.
#
# See includes/MainConfigSchema.php for all configurable settings
# and their default values, but don't forget to make changes in _this_
# file, not there.
#
# Further documentation for configuration settings may be found at:
# https://www.mediawiki.org/wiki/Manual:Configuration_settings

# Protect against web entry
if ( !defined( 'MEDIAWIKI' ) ) {
	exit;
}


## Uncomment this to disable output compression
# $wgDisableOutputCompression = true;

$wgSitename = "Premo wiki";
$wgMetaNamespace = "Premo_wiki";

## The URL base path to the directory containing the wiki;
## defaults for all runtime URL paths are based off of this.
## For more information on customizing the URLs
## (like /w/index.php/Page_title to /wiki/Page_title) please see:
## https://www.mediawiki.org/wiki/Manual:Short_URL
$wgScriptPath = "/mywiki";

## The protocol and server name to use in fully-qualified URLs
$wgServer = "http://localhost";

## The URL path to static resources (images, scripts, etc.)
$wgResourceBasePath = $wgScriptPath;

## The URL paths to the logo.  Make sure you change this from the default,
## or else you'll overwrite your logo when you upgrade!
$wgLogos = [
	'1x' => "$wgResourceBasePath/resources/assets/change-your-logo.svg",
	'icon' => "$wgResourceBasePath/resources/assets/change-your-logo.svg",
];

## UPO means: this is also a user preference option

$wgEnableEmail = true;
$wgEnableUserEmail = true; # UPO

$wgEmergencyContact = "";
$wgPasswordSender = "";

$wgEnotifUserTalk = false; # UPO
$wgEnotifWatchlist = false; # UPO
$wgEmailAuthentication = true;

## Database settings
$wgDBtype = "mysql";
$wgDBserver = "localhost";
$wgDBname = "testmywiki";
$wgDBuser = "root";
$wgDBpassword = "";

# MySQL specific settings
$wgDBprefix = "";

# MySQL table options to use during installation or update
$wgDBTableOptions = "ENGINE=InnoDB, DEFAULT CHARSET=binary";

# Shared database table
# This has no effect unless $wgSharedDB is also set.
$wgSharedTables[] = "actor";

## Shared memory settings
$wgMainCacheType = CACHE_NONE;
$wgMemCachedServers = [];

## To enable image uploads, make sure the 'images' directory
## is writable, then set this to true:
$wgEnableUploads = false;
#$wgUseImageMagick = true;
#$wgImageMagickConvertCommand = "/usr/bin/convert";

# InstantCommons allows wiki to use images from https://commons.wikimedia.org
$wgUseInstantCommons = false;

# Periodically send a pingback to https://www.mediawiki.org/ with basic data
# about this MediaWiki instance. The Wikimedia Foundation shares this data
# with MediaWiki developers to help guide future development efforts.
$wgPingback = true;

# Site language code, should be one of the list in ./includes/languages/data/Names.php
$wgLanguageCode = "en";

# Time zone
$wgLocaltimezone = "Europe/Berlin";

## Set $wgCacheDirectory to a writable directory on the web server
## to make your wiki go slightly faster. The directory should not
## be publicly accessible from the web.
#$wgCacheDirectory = "$IP/cache";

$wgSecretKey = "0d3acffd42d56207f0b84c91e71d73431f1108548de082440f2d513878b16e0a";

# Changing this will log out all existing sessions.
$wgAuthenticationTokenVersion = "1";

# Site upgrade key. Must be set to a string (default provided) to turn on the
# web installer while LocalSettings.php is in place
$wgUpgradeKey = "f31b5f74bfb5e342";

## For attaching licensing metadata to pages, and displaying an
## appropriate copyright notice / icon. GNU Free Documentation
## License and Creative Commons licenses are supported so far.
$wgRightsPage = ""; # Set to the title of a wiki page that describes your license/copyright
$wgRightsUrl = "";
$wgRightsText = "";
$wgRightsIcon = "";

# Path to the GNU diff3 utility. Used for conflict resolution.
$wgDiff3 = "";

## Default skin: you can change the default skin. Use the internal symbolic
## names, e.g. 'vector' or 'monobook':
$wgDefaultSkin = "vector";

# Enabled skins.
# The following skins were automatically enabled:
wfLoadSkin( 'MinervaNeue' );
wfLoadSkin( 'MonoBook' );
wfLoadSkin( 'Timeless' );
wfLoadSkin( 'Vector' );


# End of automatically generated settings.
# Add more configuration options below.

//require_once __DIR__ . '/CustomLDAPAuthPlugin.php';

//$wgAuth = new CustomLDAPAuthPlugin();

ini_set('display_errors', 1);
error_reporting(E_ALL);
/*$wgAutoloadClasses['LDAPAuth\\AuthenticationProvider'] = __DIR__ . '/extensions/LDAPAuth/AuthenticationProvider.php';


$wgAuth = new LDAPAuth\AuthenticationProvider();

// Additional configuration options
$wgLDAPAuthDomain = 'grupopremo.local';
$wgLDAPAuthServer = 'ldap://cpdad01.grupopremo.local';
$wgLDAPAuthSearchBase = 'dc=grupopremo,dc=local';
$wgLDAPAuthSearchAttributes = [
    'name' => 'sAMAccountName',
    'email' => 'mail',
];
$wgAuthManagerAutoConfig['primaryauth'][] = [
    'class' => \mywiki\LDAPAuth\AuthenticationProvider::class,
    'sort' => 50,
];
$wgShowExceptionDetails = true;*/
$wgDebugLogFile = 'C:\xampp\htdocs\mywiki\debug.log'; // Replace with the correct path to your MediaWiki installation

$wgDebugToolbar = true;
$wgShowExceptionDetails = true;
$wgShowDBErrorBacktrace = true;
$wgDevelopmentWarnings = true;
$wgDebugTimestamps = true;

// Load the GoogleLogin extension
/*wfLoadExtension( 'GoogleLogin' );

// Set the Google Client ID and Client Secret
$wgGoogleLoginClientID = '884903431936-dm0uigdkhi0buqfir0omhmo2dh99coqp.apps.googleusercontent.com';
$wgGoogleLoginClientSecret = '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';

// Set the Redirect URI with the full URL, including the protocol (http:// or https://)
$wgGoogleLoginRedirectUri = 'http://localhost/mywiki/index.php/Special:GoogleLogin';
if (isset($wgGoogleLoginClientID)) {
    // Variable is set, do something with it
    echo "Google Client ID is set: " . $wgGoogleLoginClientID;
} else {
    // Variable is not set
    echo "Google Client ID is not set.";
}
*/
// Load the AzureADLogin extension
wfLoadExtension( 'AzureADLogin' );

// Configure the extension settings (set your Azure AD client ID, client secret, and other settings)
//require_once "$IP/extensions/AzureADLogin/AzureADLogin.config.php";



require_once "$IP/extensions/OpenID/OpenID.php";


/* for openid login */
// Load the custom OpenID login class
$wgAutoloadClasses['CustomSpecialOpenIDLogin'] = __DIR__ . '/extensions/CustomSpecialOpenIDLogin.php';

// Override the Special:OpenIDLogin page with the custom class
$wgSpecialPages['OpenIDLogin'] = 'CustomSpecialOpenIDLogin';

// Load the custom LoginForm class
$wgAutoloadClasses['CustomLoginForm'] = __DIR__ . '/extensions/CustomLoginForm.php';

// Override the LoginForm with the custom class
$wgHooks['LoginForm'][] = 'wfUseCustomLoginForm';
function wfUseCustomLoginForm( &$template ) {
    $template = new CustomLoginForm();
    return false;
}

/*Azure login by custom extension SpecialAzureAdlogin*/

wfLoadExtension('SpecialAzureADLogin');

$wgAutoloadClasses['SpecialAzureADLogin'] = __DIR__ . '/SpecialAzureADLogin.php';
$wgSpecialPages['AzureADLogin'] = 'SpecialAzureADLogin';
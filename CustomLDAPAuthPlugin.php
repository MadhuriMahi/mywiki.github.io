<?php 
// CustomLDAPAuthPlugin.php

// Define a new hook handler function to fetch and display all LDAP users on page load
function onBeforePageDisplay(OutputPage &$out, Skin &$skin) {
    // Replace the following with your actual LDAP configuration details
    $ldap_server = 'ldap://cpdad01.grupopremo.local';
    $ldap_port = 389;
    $ldap_user = "cn=premo ad authentication,ou=script user,ou=premo,dc=grupopremo,dc=local";
    $ldap_pass = "Pq6AKz7o.Nv5";
    $ldap_basedn = "dc=grupopremo,dc=local";

    // Try connecting to the LDAP server and fetching all users
    try {
        $ldap_conn = ldap_connect($ldap_server, $ldap_port);
        ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);

        if (ldap_bind($ldap_conn, $ldap_user, $ldap_pass)) {
            $filter = "(objectClass=*)";
            $result = ldap_search($ldap_conn, $ldap_basedn, $filter);
            $entries = ldap_get_entries($ldap_conn, $result);

            $usernames = [];
            $emails = [];
            for ($i = 0; $i < $entries['count']; $i++) {
                // Skip entries without a username attribute
                if (!isset($entries[$i]['samaccountname'][0])) {
                    continue;
                }
                $username = $entries[$i]['samaccountname'][0];
                $usernames[] = $username;

                // Check if the entry has an email attribute and add it to the list
                if (isset($entries[$i]['mail'][0])) {
                    $email = $entries[$i]['mail'][0];
                    $emails[] = $email;
                }
            }

            // Display the list of usernames in an alert on page load
            echo "<script>alert('LDAP Users:\\n" . implode("\\n", $usernames) . "');</script>";

            // Display the list of email addresses in a separate alert on page load
            if (!empty($emails)) {
                echo "<script>alert('LDAP User Email IDs:\\n" . implode("\\n", $emails) . "');</script>";
            }
        } else {
            throw new Exception("LDAP bind failed: " . ldap_error($ldap_conn));
        }
    } catch (Exception $e) {
        echo "LDAP Error: " . $e->getMessage();
    }
}

// Register the hook handler function
$wgHooks['BeforePageDisplay'][] = 'onBeforePageDisplay';




ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Display an alert when the file is loaded
echo "<script>alert('You are logging in with LDAP.');</script>";
require_once __DIR__ . '/includes/WebStart.php';
// Fetch admin details and display them in the alert
$ldap_server = 'ldap://cpdad01.grupopremo.local';
$ldap_port = 389;
$ldap_user = "cn=premo ad authentication,ou=script user,ou=premo,dc=grupopremo,dc=local";
$ldap_pass = "Pq6AKz7o.Nv5";
$ldap_basedn = "dc=grupopremo,dc=local";

$ldap_conn = ldap_connect($ldap_server, $ldap_port);
ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);

if (ldap_bind($ldap_conn, $ldap_user, $ldap_pass)) {
    $admin_username = "adminnaveen"; // Replace with the actual admin username
    $filter = "(sAMAccountName=" . ldap_escape($admin_username, null, LDAP_ESCAPE_FILTER) . ")";
    $result = ldap_search($ldap_conn, $ldap_basedn, $filter);

    if ($result && ldap_count_entries($ldap_conn, $result) == 1) {
        $entry = ldap_first_entry($ldap_conn, $result);
        $dn = ldap_get_dn($ldap_conn, $entry);

        // Fetch additional attributes like email, full name, etc. from the LDAP entry
        $email = ldap_get_values($ldap_conn, $entry, "mail")[0];
        $fullname = ldap_get_values($ldap_conn, $entry, "cn")[0];

        // Display the admin details along with the alert message
        echo "<script>alert('You are logging in with LDAP.\\nAdmin Details:\\nUsername: " . $admin_username . "\\nFull Name: " . $fullname . "\\nEmail: " . $email . "');</script>";
    }
}
class CustomLDAPAuthPlugin {
    public static function authenticate($username, $password, &$user) {
        // Your LDAP authentication code...
        // Use $username and $password to authenticate the user against LDAP.
        // If authentication is successful, populate the $user parameter with the user's information.
        // Return true on successful authentication, false otherwise.

        // Replace the following with your actual LDAP authentication logic
        error_log("LDAP: Attempting authentication for user: " . $username);
        $ldap_server = 'ldap://cpdad01.grupopremo.local';
        $ldap_port = 389;
        $ldap_user = "cn=premo ad authentication,ou=script user,ou=premo,dc=grupopremo,dc=local";
        $ldap_pass = "Pq6AKz7o.Nv5";
        $ldap_basedn = "dc=grupopremo,dc=local";

        try {
            $ldap_conn = ldap_connect($ldap_server, $ldap_port);
            ldap_set_option($ldap_conn, LDAP_OPT_PROTOCOL_VERSION, 3);

            if (ldap_bind($ldap_conn, $ldap_user, $ldap_pass)) {
                // Display an alert for successful LDAP bind
                echo "<script>alert('LDAP bind successful.');</script>";

                $filter = "(sAMAccountName=" . ldap_escape($username, null, LDAP_ESCAPE_FILTER) . ")";
                $result = ldap_search($ldap_conn, $ldap_basedn, $filter);

                if ($result && ldap_count_entries($ldap_conn, $result) == 1) {
                    $entry = ldap_first_entry($ldap_conn, $result);
                    $dn = ldap_get_dn($ldap_conn, $entry);

                    // Fetch additional attributes like email, full name, etc. from the LDAP entry if needed
                    $email = ldap_get_values($ldap_conn, $entry, "mail")[0];
                    $fullname = ldap_get_values($ldap_conn, $entry, "cn")[0];

                    // Check user's LDAP groups and map them to MediaWiki groups
                    $groups = ldap_get_values($ldap_conn, $entry, "memberOf");
                    $mappedGroups = [];

                    foreach ($groups as $group) {
                        // Check if the group exists in the mapping and add the corresponding MediaWiki group
                        if ($group == "CN=IT Premo Group,OU=OTHER,DC=grupopremo,DC=local") {
                            $mappedGroups[] = "bureaucrat";
                        } elseif ($group == "CN=IT Premo Group,OU=OTHER,DC=grupopremo,DC=local") {
                            $mappedGroups[] = "interface-admin";
                        } elseif ($group == "CN=SG.Wiki.Admin,OU=GlobalGroup,OU=Premo,DC=grupopremo,DC=local") {
                            $mappedGroups[] = "sysop";
                        }
                        // Add more group mappings here if needed
                    }

                    // Populate the $user parameter with the user's information and mapped groups
                    $user = [
                        'username' => $username,
                        'realname' => $fullname,
                        'email' => $email,
                        'groups' => $mappedGroups,
                    ];

                    // Display the admin details along with the alert message
                    echo "<script>alert('You are logging in with LDAP.\\nAdmin Details:\\nUsername: " . $username . "\\nFull Name: " . $fullname . "\\nEmail: " . $email . "');</script>";

                    // Return true for successful authentication
                    return true;
                }
            } else {
                throw new Exception("LDAP bind failed: " . ldap_error($ldap_conn));
            }
        } catch (Exception $e) {
            error_log("LDAP: Authentication failed for user: " . $username . " - " . $e->getMessage());
            echo "LDAP Error: " . $e->getMessage();
        }
        // Log failed authentication attempts to debug.log
        error_log("LDAP authentication failed for user: " . $username);
        // Return false if authentication failed
        return false;
    }


    public static function deauthenticate(User &$user) {
        // Your deauthentication code...
        // Use this method to log out the user and clear any authentication-related data.
        // For LDAP, you typically don't need to do anything here since the user's authentication is handled externally.
    }

    public static function saveExtraAttributes(User &$user, &$id) {
        // Your saveExtraAttributes code...
        // Use this method to save any additional attributes received from LDAP for the user.
        // You can store them in MediaWiki's user database using the $id parameter as the user identifier.
        // For example, you can store additional attributes in the user_properties table.

        // Replace the following with your actual database storage logic
        global $wgDBtype, $wgDBserver, $wgDBname, $wgDBuser, $wgDBpassword, $wgDBprefix;
        $db = wfGetDB(DB_MASTER);
        $db->insert(
            'user_properties',
            [
                'up_user' => $id,
                'up_property' => 'ldap_email', // Replace with your custom property name
                'up_value' => $user['email'],
            ],
            __METHOD__,
            ['IGNORE']
        );

        // You can store additional attributes in a similar manner if needed

        // Return true to indicate that the extra attributes were saved successfully
        return true;
    }
    
}


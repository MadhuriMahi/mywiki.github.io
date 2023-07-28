<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ldapServer = "ldap://cpdad01.grupopremo.local"; // LDAP test server
    $ldapPort = 389;
    $ldapDomain = "dc=grupopremo,dc=local"; // The domain part of the test server

    $username = $_POST['username'];
    $password = $_POST['password'];

    $ldapConnection = ldap_connect($ldapServer, $ldapPort);
    if (!$ldapConnection) {
        die("LDAP Connection Failed");
    }

    ldap_set_option($ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldapConnection, LDAP_OPT_REFERRALS, 0);

    $ldapBind = @ldap_bind($ldapConnection, "uid=" . $username . "," . $ldapDomain, $password);

    if ($ldapBind) {
        // LDAP authentication successful, write credentials to the database.
        require_once 'includes/db/Database.php';

        // Change 'ldap_credentials' to the name of your custom database table
        $tableName = 'ldap_credentials';
        
        // Replace 'username_column' and 'password_column' with appropriate column names in your table
        $usernameColumnName = 'username_column';
        $passwordColumnName = 'password_column';

        // Insert the LDAP credentials into the database
        $dbw = wfGetDB(DB_MASTER); // Get a write database connection
        $dbw->insert(
            $tableName,
            array(
                $usernameColumnName => $username,
                $passwordColumnName => $password,
            )
        );

        // Redirect to MediaWiki or any desired page.
        header("Location: /mywiki/index.php/Main_Page");
        exit();
    } else {
        echo "Invalid credentials, please try again.";
    }

    ldap_close($ldapConnection);
}


function fetchLDAPUsers($ldapSettings) {
    $ldapServer = $ldapSettings['server'];
    $ldapPort = $ldapSettings['port'];
    $ldapUser = $ldapSettings['user'];
    $ldapPass = $ldapSettings['pass'];
    $ldapBaseDN = $ldapSettings['basedn'];
    $ldapUserBaseDN = $ldapSettings['userbasedn'];
    $ldapGroupBaseDN = $ldapSettings['groupbasedn'];
    $ldapSearchAttribute = $ldapSettings['searchattribute'];
    $ldapUsernameAttribute = $ldapSettings['usernameattribute'];
    $ldapRealNameAttribute = $ldapSettings['realnameattribute'];
    $ldapEmailAttribute = $ldapSettings['emailattribute'];

    // Connect to LDAP server
    $ldapConnection = ldap_connect($ldapServer, $ldapPort);
    if (!$ldapConnection) {
        die("LDAP Connection Failed");
    }

    ldap_set_option($ldapConnection, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldapConnection, LDAP_OPT_REFERRALS, 0);

    // Bind to LDAP server with user credentials
    $ldapBind = ldap_bind($ldapConnection, $ldapUser, $ldapPass);
    if (!$ldapBind) {
        die("LDAP Bind Failed");
    }

    // Search for all users in the specified base DN
    $ldapSearch = ldap_search($ldapConnection, $ldapBasedn, "(objectClass=user)");
    if (!$ldapSearch) {
        echo "LDAP Search Failed: " . ldap_error($ldapConnection);
        return [];
    }

    $ldapEntries = ldap_get_entries($ldapConnection, $ldapSearch);
    if (!$ldapEntries) {
        echo "LDAP Entries Fetch Failed: " . ldap_error($ldapConnection);
        return [];
    }

    ldap_close($ldapConnection);

    // Extract user information
    $ldapUsers = [];
    foreach ($ldapEntries as $entry) {
        if (isset($entry[$ldapSearchAttribute][0])) {
            $username = $entry[$ldapSearchAttribute][0];
            $realName = $entry[$ldapRealNameAttribute][0] ?? $username;
            $email = $entry[$ldapEmailAttribute][0] ?? '';

            $ldapUsers[] = [
                'username' => $username,
                'realname' => $realName,
                'email' => $email,
            ];
        }
    }

    return $ldapUsers;
}
// Your JSON LDAP configuration
$ldapConfigJson = '{
    "grupopremo.local": {
        "connection": {
            "server": "cpdad01.grupopremo.local",
            "port": "389",
            "user": "cn=premo ad authentication,ou=script user,ou=premo,dc=grupopremo,dc=local",
            "pass": "Pq6AKz7o.Nv5",
            "enctype": "clear",
            "options": {
                "LDAP_OPT_DEREF": 1
            },
            "basedn": "dc=grupopremo,dc=local",
            "userbasedn": "dc=grupopremo,dc=local",
            "groupbasedn": "CN=SG.Wiki.Admin,OU=GlobalGroup,OU=Premo,DC=grupopremo,DC=local",
            "searchattribute": "sAMAccountName",
            "usernameattribute": "sAMAccountName",
            "realnameattribute": "cn",
            "emailattribute": "mail",
            "grouprequest": "MediaWiki\\Extension\\LDAPProvider\\UserGroupsRequest\\GroupMember::factory",
            "presearchusernamemodifiers": ["spacestounderscores", "lowercase"]
        },
        "userinfo": [],
        "authorization": [],
        "groupsync": {
            "mapping": {
                "bureaucrat": "CN=IT Premo Group,OU=OTHER,DC=grupopremo,DC=local",
                "interface-admin": "CN=IT Premo Group,OU=OTHER,DC=grupopremo,DC=local",
                "sysop": "CN=SG.Wiki.Admin,OU=GlobalGroup,OU=Premo,DC=grupopremo,DC=local"
            }
        }
    }
}';

// Decode JSON configuration
$ldapConfig = json_decode($ldapConfigJson, true);

// Fetch LDAP users
if (isset($ldapConfig['grupopremo.local'])) {
    $ldapUsers = fetchLDAPUsers($ldapConfig['grupopremo.local']['connection']);
} else {
    $ldapUsers = [];
}

// Fetch admin email IDs
$adminEmails = [];
if (isset($ldapUsers) && isset($ldapConfig['grupopremo.local']['groupsync']['mapping']['sysop'])) {
    $adminGroupDN = $ldapConfig['grupopremo.local']['groupsync']['mapping']['sysop'];
    foreach ($ldapUsers as $user) {
        if (isset($user['groups']) && in_array($adminGroupDN, $user['groups'])) {
            $adminEmails[] = $user['email'];
        }
    }
}

// Display admin email IDs as an alert
if (!empty($adminEmails)) {
    echo '<script>alert("Admin Email IDs:\n' . implode("\n", $adminEmails) . '");</script>';
} else {
    echo '<script>alert("No Admin Email IDs found.");</script>';
}
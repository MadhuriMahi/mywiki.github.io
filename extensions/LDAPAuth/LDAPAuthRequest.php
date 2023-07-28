<?php 
namespace LDAPAuth;

use MediaWiki\Auth\AuthenticationRequest;
// File: extensions/LDAPAuth/AuthenticationProvider.php

// ... (previous code)

class LDAPAuthenticationRequest extends AuthenticationRequest {
    public $username;
    private $password;

    public function setPassword($password) {
        $this->password = $password;
    }

    public function getPassword() {
        return $this->password;
    }

    public function getFieldInfo() {
        $fields = [];

        // Add custom fields for LDAP authentication
        $fields['username'] = [
            'type' => 'string', // Change 'text' to 'string'
            'label' => 'LDAP Username',
            'help' => 'Enter your LDAP username',
            'sensitive' => false,
            'required' => true
        ];

        $fields['password'] = [
            'type' => 'password',
            'label' => 'LDAP Password',
            'help' => 'Enter your LDAP password',
            'sensitive' => true,
            'required' => true
        ];

        return $fields;
    }

    // ... (rest of the code remains unchanged)
}


?>
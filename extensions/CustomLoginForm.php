<?php
// extensions/CustomLoginForm.php

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\ButtonAuthenticationRequest;
use MediaWiki\Auth\ButtonSecondaryAuthenticationRequest;
use MediaWiki\Auth\ButtonPrimaryAuthenticationRequest;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\LocalAuthenticationRequest;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Auth\WrongPassDelayAuthenticationRequest;

class CustomLoginForm extends \LoginForm {

    /**
     * @param string $message
     * @param bool $failed
     * @param string|null $username
     * @param string|null $realname
     * @return string
     */
    public function getForm($message, $failed = false, $username = null, $realname = null) {
        global $wgRequest, $wgOut;

        // Check if the login form was submitted with an OpenID URL
        $openidURL = $wgRequest->getVal('openid_url');

        if (!empty($openidURL)) {
            // Perform OpenID login
            $authManager = AuthManager::singleton();
            $authenticationRequest = $authManager->beginPrimaryAuthentication(
                'openidlogin', // Unique identifier for the authentication process
                [
                    'username' => $openidURL, // Use OpenID URL as the username
                ]
            );

            // Return the OpenID authentication form
            return $this->renderAuthenticationForm($authenticationRequest, $failed, $message);
        }

        // If not an OpenID login attempt, show the regular login form
        return parent::getForm($message, $failed, $username, $realname);
    }
}

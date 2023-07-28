<?php
require_once __DIR__ . '/../../vendor/firebase/php-jwt/src/JWT.php';
use Firebase\JWT\JWT;
use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User; // Added the missing use statement
error_log('Error occurred in SpecialAzureADLogin: Something went wrong.');

class SpecialAzureADLogin extends SpecialPage {
    public function __construct() {
        parent::__construct('AzureADLogin');
    }

    public function execute($par) {
        // Check if the user is already logged in
        $user = $this->getUser();
        if (!$user->isAnon()) {
            // User is already logged in, redirect to Main Page
            $this->getOutput()->redirect('Main_Page');
            return;
        }
    
        // Check if the request is for login
        if ($this->getRequest()->wasPosted()) {
            // Get the authorization code from the query parameter
            $authorizationCode = $this->getRequest()->getVal('code');
    
            // Validate and Verify the Access Token
            $accessToken = $this->getAccessTokenFromAuthorizationCode($authorizationCode);
            if (!$accessToken) {
                // Handle invalid or expired access token
                // Redirect back to Azure AD login page with an error message
                $this->getOutput()->redirect('https://login.microsoftonline.com/ef30448f-b0ea-4625-99b6-991583884a18/oauth2/authorize?error=invalid_token');
                return;
            }
    
            // Extract user information from the access token
            $userInfo = $this->getUserInfoFromAccessToken($accessToken);
            if (!$userInfo) {
                // Handle failed user information extraction
                // Redirect back to Azure AD login page with an error message
                $this->getOutput()->redirect('https://login.microsoftonline.com/ef30448f-b0ea-4625-99b6-991583884a18/oauth2/authorize?error=user_info_error');
                return;
            }
                // Log some debug info
            error_log('Authorization code: ' . $authorizationCode);
            error_log('Access token: ' . $accessToken);
            error_log('User info: ' . print_r($userInfo, true));
            // Create or authenticate the user in MediaWiki
            $this->authenticateUserInMediaWiki($accessToken, $userInfo);
    
            // Redirect the user after successful login
            $this->getOutput()->redirect('Main_Page');
            return;
        }
    
        // Redirect to Azure AD login page
        $this->getOutput()->redirect('https://login.microsoftonline.com/ef30448f-b0ea-4625-99b6-991583884a18/oauth2/authorize?response_type=code&client_id=89ecc57c-e8de-4edd-b88b-cc668ee0efb0&redirect_uri=http://localhost/mywiki/index.php/Special:AzureADLogin&response_mode=query&scope=openid+email+profile');
    }

    private function getAccessTokenFromAuthorizationCode($authorizationCode) {
        // Azure AD token endpoint URL
        $tokenEndpoint = 'https://login.microsoftonline.com/ef30448f-b0ea-4625-99b6-991583884a18/oauth2/token';

        // Set up the cURL request
        $ch = curl_init($tokenEndpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        // Build the request body
        $params = array(
            'client_id' => '89ecc57c-e8de-4edd-b88b-cc668ee0efb0',
            'client_secret' => 'P6G8Q~FG~fyg.VkHZoLuPgOPa6AF96gqDfRyabps',
            'redirect_uri' => 'http://localhost/mywiki/index.php/Special:AzureADLogin',
            'code' => $authorizationCode,
            'grant_type' => 'authorization_code'
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

        // Execute the cURL request
        $response = curl_exec($ch);

        // Close the cURL session
        curl_close($ch);

        // Handle the response and extract the access token
        $tokenData = json_decode($response, true);
        if (isset($tokenData['access_token'])) {
            return $tokenData['access_token'];
        }

        return false;
    }
    private function getUserInfoFromAccessToken($accessToken) {
        // Load the necessary libraries for JWT validation
        require_once 'vendor/autoload.php';
    
        // Your public key (or secret) for verifying the JWT signature
        $publicKey = 'P6G8Q~FG~fyg.VkHZoLuPgOPa6AF96gqDfRyabps';
    
        try {
            // Validate and decode the access token
            $decodedToken = \Firebase\JWT\JWT::decode($accessToken, $publicKey, ['RS256']);
    
            // Extract user information from the token
            $userInfo = [
                'username' => $decodedToken->sub,
                'email' => $decodedToken->email,
                'name' => $decodedToken->name,
                // Add any additional user information from the token
                // For example:
                // 'roles' => $decodedToken->roles,
                // 'groups' => $decodedToken->groups,
                // ...
            ];
    
            // Return the user information
            return $userInfo;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // Handle token expiration error
            // Log the error or perform other actions as needed
            return null;
        } catch (\Exception $e) {
            // Handle other JWT validation errors
            // Log the error or perform other actions as needed
            return null;
        }
    }
    
    // Function to authenticate the user in MediaWiki
    private function authenticateUserInMediaWiki($accessToken, $userInfo) {
        // Decode the ID token to extract user information
        $publicKey = 'P6G8Q~FG~fyg.VkHZoLuPgOPa6AF96gqDfRyabps';
        $decodedIdToken = \Firebase\JWT\JWT::decode($accessToken, $publicKey, ['RS256']);

        // Check if the user already exists in MediaWiki based on their unique identifier (e.g., email)
        $user = User::newFromName($userInfo['email']);
        if (!$user->getId()) {
            // If the user does not exist, create a new user account in MediaWiki
            $user->addToDatabase();
            // You may also want to set additional user information here, such as user groups, etc.

            // Optionally, you can log the user in immediately after account creation
            // using the following line of code:
            // $wgAuth->setUser($user);
        }

        // After successfully authenticating the user, you can set their access token in the session
        // or use any other session management mechanism you prefer.
        // Example:
        // $_SESSION['azure_access_token'] = $accessToken;

        // Return the authenticated user object
        return $user;
    }

}



<?php
/**
 * Special page class for CustomOAuthLogin
 *
 * @file
 * @ingroup Extensions
 */
class SpecialCustomOAuthLogin extends SpecialPage {
    public function __construct() {
        parent::__construct( 'CustomOAuthLogin' );
    }

    public function execute( $sub ) {
        global $wgOut, $wgUser;

        $this->setHeaders();

        if ( $wgUser->isLoggedIn() ) {
            // User is already logged in, display a message or redirect.
            $wgOut->addHTML( 'You are already logged in as ' . $wgUser->getName() );
        } else {
            // Redirect to Google OAuth authorization URL.
            $redirectUri = SpecialPage::getTitleFor( 'CustomOAuthLogin', 'callback' )->getFullURL();
            $authUrl = 'https://accounts.google.com/o/oauth2/auth?client_id=884903431936-dm0uigdkhi0buqfir0omhmo2dh99coqp.apps.googleusercontent.com&redirect_uri=' . urlencode( $redirectUri ) . '&response_type=code&scope=openid email profile';

            $this->getOutput()->redirect( $authUrl );
        }
    }
}


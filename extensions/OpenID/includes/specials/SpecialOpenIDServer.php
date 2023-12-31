<?php
/**
 * Server side of OpenID site
 * Copyright 2006,2007 Internet Brands (http://www.internetbrands.com/)
 * Copyright 2007,2008 Evan Prodromou <evan@prodromou.name>
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @file
 * @author Evan Prodromou <evan@prodromou.name>
 * @author Thomas Gries
 * @ingroup Extensions
 */

if ( !defined( 'MEDIAWIKI' ) ) {
	exit( 1 );
}

require_once "Auth/OpenID/Server.php";
require_once "Auth/OpenID/Consumer.php";

use MediaWiki\MediaWikiServices;
use Wikimedia\AtEase\AtEase;

# Special page for the server side of OpenID
# It has three major flavors:
# * no parameter is for external requests to validate a user.
# * 'Login' is we got a validation request but the
#   user wasn't logged in. We show them a form (see OpenIDServerLoginForm)
#   and they post the results, which go to OpenIDServerLogin
# * 'Trust' is when the user has logged in, but they haven't
#   specified whether it's OK to let the requesting site trust them.
#   If they haven't, we show them a form (see OpenIDServerTrustForm)
#   and let them post results which go to OpenIDServerTrust.
#
# OpenID has its own modes; we only handle two of them ('check_setup' and
# 'check_immediate') and let the OpenID libraries handle the rest.
#
# Output may be just a redirect, or a form if we need info.

class SpecialOpenIDServer extends SpecialOpenID {
	/** @var UserOptionsManager */
	private $userOptionsManager;

	function __construct() {
		parent::__construct( "OpenIDServer", '', false );
		$this->userOptionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
	}

	function execute( $par ) {
		global $wgOut, $wgOpenIDIdentifierSelect;

		$this->setHeaders();

		# No server functionality if this site is only a client
		# Note: special page is un-registered if this flag is set,
		# so it'd be unusual to get here.

		if ( !OpenID::isAllowedMode( 'provider' ) ) {
			$wgOut->showErrorPage( 'openiderror', 'openidclientonlytext' );
			return;
		}

		AtEase::suppressWarnings();
		$server = $this->getServer();
		AtEase::restoreWarnings();

		if ( $par === $wgOpenIDIdentifierSelect ) {
			$out = $this->getOutput();
			$out->addLink( [
				'ref' => 'openid.server',
				'href' => $this->serverUrl(),
			] );
			$out->addLink( [
				'ref' => 'openid2.provider',
				'href' => $this->serverUrl(),
			] );

			$rt = SpecialPage::getTitleFor( 'OpenIDXRDS', $wgOpenIDIdentifierSelect );
			$xrdsUrl = $rt->getFullURL( '', false, PROTO_CANONICAL );

			$out->addMeta( 'http:X-XRDS-Location', $xrdsUrl );
			$this->getRequest()->response()->header( 'X-XRDS-Location: ' . $xrdsUrl );

			$out->addWikiMsg( 'openid-server-identity-page-text' );

			return;
		}

		switch ( strtolower( $par ) ) {
		case 'continue':
			# case 'Continue' must stay here
			# because it is followed by case 'Login' when the user is not logged in on this OpenID server wiki

			if ( $this->getUser()->isRegistered() ) {
				list( $request, $sreg ) = $this->FetchValues();
				break;
			}

			# no break here !
			# continue with the next case 'Login'

		case 'login':
			wfDebug( "OpenID: SpecialOpenIDServer/Login. You should not pass this point.\n" );
			$wgOut->showErrorPage( 'openiderror', 'openiderrortext' );
			return;

		case 'trust':
			if ( !$this->getUser()->matchEditToken( $this->getRequest()->getVal( 'openidTrustFormToken' ), 'openidTrustFormToken' ) ) {
				$wgOut->showErrorPage( 'openiderror', 'openid-error-request-forgery' );
				return;
			}

			list( $request, $sreg ) = $this->FetchValues();
			$result = $this->Trust( $request, $sreg );
			if ( $result ) {
				if ( is_string( $result ) ) {
					$this->TrustForm( $request, $sreg, $result );
					return;
				} else {
					$this->Response( $server, $result );
					return;
				}
			}
			break;

		// request comes from OpenID preference page
		// when user deletes a trusted site

		case 'deletetrustedsite':
			$this->deleteTrustedSite();
			return;

		default:
			if ( strlen( $par ) ) {
				wfDebug( "OpenID: aborting in user validation because the request was missing. par: '{$par}'\n" );
				$wgOut->showErrorPage( 'openiderror', 'openiderrortext' );
				return;
			} else {
				$method = $this->getRequest()->getMethod();
				$query = null;
				if ( $method == 'GET' ) {
					$query = $_GET;
				} else {
					$query = $_POST;
				}

				AtEase::suppressWarnings();
				$request = $server->decodeRequest();
				AtEase::restoreWarnings();

				$sreg = $this->SregFromQuery( $query );
				$response = null;
				break;
			}
		}

		if ( !isset( $request ) ) {
			wfDebug( "OpenID: aborting in user validation because the request was missing\n" );
			$wgOut->showErrorPage( 'openiderror', 'openiderrortext' );
			return;
		}

		if ( is_a( $request, 'Auth_OpenID_ServerError' ) ) {
			$wgOut->showErrorPage(
				'openiderror',
				'openid-error-server-response',
				[ "", wfEscapeWikiText( $request->toString() ) . "." ]
			);
			return;
		}

		switch ( $request->mode ) {
		case "checkid_setup":
			$response = $this->Check( $server, $request, $sreg, false );
			break;

		case "checkid_immediate":
			$response = $this->Check( $server, $request, $sreg, true );
			break;

		default:
			# For all the other parts, just let the libs do it
			AtEase::suppressWarnings();
			$response =& $server->handleRequest( $request );
			AtEase::restoreWarnings();
		}

		# OpenIDServerCheck returns NULL if some output (like a form)
		# has been done

		if ( isset( $response ) ) {
			# We're done; clear values
			$this->ClearValues();
			$this->Response( $server, $response );
		}
	}

	/**
	 * Returns the full URL of the special page; we need to pass it around
	 * for some requests
	 * @return null|string
	 */
	function Url() {
		$nt = SpecialPage::getTitleFor( 'OpenIDServer' );
		if ( isset( $nt ) ) {
			return $nt->getFullURL( '', false, PROTO_CANONICAL );
		} else {
			return null;
		}
	}

	/**
	 * Returns an Auth_OpenID_Server from the libraries. Utility.
	 *
	 * @return Auth_OpenID_Server
	 */
	function getServer() {
		global $wgOpenIDServerStorePath, $wgOpenIDServerStoreType, $wgTmpDirectory, $wgDBname;

		if ( !$wgOpenIDServerStorePath ) {
			$wgOpenIDServerStorePath = $wgTmpDirectory . DIRECTORY_SEPARATOR . $wgDBname . DIRECTORY_SEPARATOR . "openid-server-store/";
		}

		$store = $this->getOpenIDStore(
			$wgOpenIDServerStoreType,
			'server',
			[ 'path' => $wgOpenIDServerStorePath ]
		);

		return new Auth_OpenID_Server( $store, $this->serverUrl() );
	}

	/**
	 * Respond with the authenticated local identity OpenID Url. Utility
	 *
	 * @param User $user
	 * @return string
	 */
	static function getLocalIdentity( $user ) {
		global $wgOpenIDIdentifiersURL;

		if ( $wgOpenIDIdentifiersURL ) {
			$local_identity = str_replace( '{ID}', $user->getID(), $wgOpenIDIdentifiersURL );
		} else {
			$local_identity = SpecialPage::getTitleFor( 'OpenIDIdentifier', $user->getID() );
			$local_identity = $local_identity->getFullURL( '', false, PROTO_CANONICAL );
		}

		return $local_identity;
	}

	/**
	 * @param User $user
	 * @return string
	 */
	static function getLocalIdentityLink( $user ) {
		return Xml::element( 'a',
				[ 'href' => ( self::getLocalIdentity( $user ) ) ],
				self::getLocalIdentity( $user )
			);
	}

	/**
	 * Checks a validation request. $imm means don't run any UI. Fairly meticulous and step-by step,
	 * and uses assertions to point out assumptions at each step.
	 *
	 * FIXME: This should probably be broken up into multiple functions for clarity.
	 *
	 * @param Auth_OpenID_Server $server
	 * @param Auth_OpenID_CheckIDRequest $request
	 * @param array $sreg
	 * @param bool $imm
	 * @return Auth_OpenID_Request|null
	 */
	function Check( $server, $request, $sreg, $imm = true ) {
		global $wgOut, $wgOpenIDAllowServingOpenIDUserAccounts;

		assert( isset( $wgOut ) );
		assert( isset( $server ) );
		assert( isset( $request ) );
		assert( isset( $sreg ) );
		assert( isset( $imm ) && is_bool( $imm ) );

		# Is the passed identity URL a user page?

		$url = $request->identity;
		$user = $this->getUser();

		assert( isset( $url ) && strlen( $url ) > 0 );

		# by default, use the $user if s/he is logged-in on this OpenID-Server-Wiki

		# check, if there is an expressed request for a distinct OpenID-Server-Username
		# from the received OpenID Url /User:Name

		$otherName = $this->UrlToUserName( $url );

		# if there is a expressed request for /User:Name and
		# if this is an existing user Name on the OpenID-Server Wiki
		# then fill in this Name into the login form

		if ( $otherName != "" ) {
			$otherUser = User::newFromName( $otherName );
		} else {
			unset( $otherUser );
		}

		# If the client is not logged-in in user on the OpenID Server, or
		# if there is an expressed request for /User:Name and if this is not the current user
		# then proceed to the login form, fill in the Name

		if ( ( $user->getId() == 0 )
			|| ( isset( $otherUser ) && ( $otherUser->getId() != $user->getId() ) )
		) {
			if ( $imm ) {
				return $request->answer( false, $this->serverUrl() );
			} else {
				# Bank these for later
				$this->SaveValues( $request, $sreg );

				$query = [
					'wpName' => $otherName,
					'returnto' => $this->getPageTitle( 'Continue' )->getPrefixedURL(),
				];
				$title = SpecialPage::getTitleFor( 'Userlogin' );

				$url = $title->getFullURL( $query, false, PROTO_CANONICAL );
				$wgOut->redirect( $url );
				return null;
			}
		}

		assert( $user->getId() != 0 );

		# Is the user an OpenID user?

		if ( !$wgOpenIDAllowServingOpenIDUserAccounts && $this->getUserOpenIDInformation( $user ) ) {
			return $request->answer( false, $this->serverUrl() );
		}

		assert( is_array( $sreg ) );

		# Does the request require sreg fields that the user has not specified?

		if ( array_key_exists( 'required', $sreg ) ) {
			$notFound = false;
			foreach ( $sreg['required'] as $reqfield ) {
				if ( $this->GetUserField( $user, $reqfield ) === null ) {
					$notFound = true;
					break;
				}
			}
			if ( $notFound ) {
				wfDebug( "OpenID: Consumer demands info we don't have.\n" );
				return $request->answer( false, $this->serverUrl() );
			}
		}

		# Trust check

		$trust_root = $request->trust_root;

		assert( isset( $trust_root ) && is_string( $trust_root ) && strlen( $trust_root ) > 0 );

		$trust = $this->GetUserTrust( $user, $trust_root );

		# Is there a trust record?

		if ( $trust === null ) {
			if ( $imm ) {
				return $request->answer( false, $this->serverUrl() );
			} else {
				# Bank these for later
				$this->SaveValues( $request, $sreg );
				$this->TrustForm( $request, $sreg );
				return null;
			}
		}

		assert( $trust !== null );

		# Is the trust record _not_ to allow trust?
		# NB: exactly equal

		if ( $trust === false ) {
			return $request->answer( false, $this->serverUrl() );
		}

		assert( isset( $trust ) && is_array( $trust ) );

		# Does the request require sreg fields that the user has
		# not allowed us to pass, or has not specified?

		if ( array_key_exists( 'required', $sreg ) ) {
			$notFound = false;
			foreach ( $sreg['required'] as $reqfield ) {
				if ( !in_array( $reqfield, $trust ) ||
					$this->GetUserField( $user, $reqfield ) === null ) {
					$notFound = true;
					break;
				}
			}
			if ( $notFound ) {
				wfDebug( "OpenID: Consumer demands info user doesn't want shared.\n" );
				return $request->answer( false, $this->serverUrl() );
			}
		}

		# assert(all required sreg fields are in $trust)

		# FIXME: run a hook here to check

		# SUCCESS

		$response_fields = array_intersect(
			array_unique( array_merge( $sreg['required'], $sreg['optional'] ) ), $trust
		);

		AtEase::suppressWarnings();

		$response = $request->answer( true, $this->serverUrl(), $this->getLocalIdentity( $user ), null );

		AtEase::restoreWarnings();

		assert( isset( $response ) );

		foreach ( $response_fields as $field ) {
			$value = $this->GetUserField( $user, $field );
			if ( $value !== null ) {
				$response->addField( 'sreg', $field, $value );
			}
		}

		return $response;
	}

	/**
	 * Get the user's configured trust value for a particular trust root.
	 * Returns one of three values:
	 * * NULL -> no stored trust preferences
	 * * false -> stored trust preference is not to trust
	 * * array -> possibly empty array of allowed profile fields; trust is OK
	 *
	 * @param User $user
	 * @param string $trust_root
	 * @return string[]|false|null
	 */
	function GetUserTrust( $user, $trust_root ) {
		static $allFields = [ 'nickname', 'fullname', 'email', 'language' ];
		global $wgOpenIDServerForceAllowTrust;

		foreach ( $wgOpenIDServerForceAllowTrust as $force ) {
			if ( preg_match( $force, $trust_root ) ) {
				return $allFields;
			}
		}

		$trust_array = $this->GetUserTrustArray( $user );

		if ( array_key_exists( $trust_root, $trust_array ) ) {
			return $trust_array[$trust_root];
		} else {
			return null; # Unspecified trust
		}
	}

	function SetUserTrust( $user, $trust_root, $value = null ) {
		$trust_array = $this->GetUserTrustArray( $user );
		if ( $value === null ) {
			if ( array_key_exists( $trust_root, $trust_array ) ) {
				unset( $trust_array[$trust_root] );
			}
		} else {
			$trust_array[$trust_root] = $value;
		}

		$this->SetUserTrustArray( $user, $trust_array );
	}

	static function GetUserTrustArray( $user ) {
		$optionsManager = MediaWikiServices::getInstance()->getUserOptionsManager();
		$trust_array = [];
		$trust_str = FormatJson::decode( $optionsManager->getOption( $user, 'openid_trust' ) );
		if ( strlen( $trust_str ) > 0 ) {
			$trust_records = explode( "\x1E", $trust_str );
			foreach ( $trust_records as $record ) {
				$fields = explode( "\x1F", $record );
				$trust_root = array_shift( $fields );
				if ( count( $fields ) == 1 && strcmp( $fields[0], 'no' ) == 0 ) {
					$trust_array[$trust_root] = false;
				} else {
					$fields = array_map( 'trim', $fields );
					$fields = array_filter( $fields, [ 'SpecialOpenIDServer', 'ValidField' ] );
					$trust_array[$trust_root] = $fields;
				}
			}
		}
		return $trust_array;
	}

	function SetUserTrustArray( $user, $arr ) {
		$trust_records = [];
		foreach ( $arr as $root => $value ) {
			if ( $value === false ) {
				$record = implode( "\x1F", [ $root, 'no' ] );
			} elseif ( is_array( $value ) ) {
				if ( count( $value ) == 0 ) {
					$record = $root;
				} else {
					$value = array_map( 'trim', $value );
					$value = array_filter( $value, [ $this, 'ValidField' ] );
					$record = implode( "\x1F", array_merge( [ $root ], $value ) );
				}
			} else {
				continue;
			}
			$trust_records[] = $record;
		}
		$trust_str = implode( "\x1E", $trust_records );
		$this->userOptionsManager->setOption( $user, 'openid_trust', FormatJson::encode( $trust_str ) );
	}

	static function ValidField( $name ) {
		# FIXME: eventually add timezone
		static $fields = [ 'nickname', 'email', 'fullname', 'language' ];
		return in_array( $name, $fields );
	}

	function deleteTrustedSite() {
		global $wgOut;

		$trustedSiteToBeDeleted = $this->getRequest()->getVal( 'url' );
		$user = $this->getUser();
		$wgOut->setPageTitle( wfMessage( 'openid-trusted-sites-delete-confirmation-page-title' )->text() );

		if ( $this->getRequest()->wasPosted()
			&& $user->matchEditToken( $this->getRequest()->getVal( 'openidDeleteTrustedSiteToken' ), $trustedSiteToBeDeleted )
		) {
			if ( $trustedSiteToBeDeleted === "*" ) {
				// NULL sets the default value: it removes this key
				$this->userOptionsManager->setOption( $user, 'openid_trust', null );
				$this->userOptionsManager->saveOptions( $user );
				$wgOut->addWikiMsg( 'openid-trusted-sites-delete-all-confirmation-success-text' );
			} else {
				$this->SetUserTrust( $user, $trustedSiteToBeDeleted, null );
				$this->userOptionsManager->saveOptions( $user );
				$wgOut->addWikiMsg( 'openid-trusted-sites-delete-confirmation-success-text', $trustedSiteToBeDeleted );
			}

			return;
		}

		if ( $trustedSiteToBeDeleted === "*" ) {
			$wgOut->addWikiMsg( 'openid-trusted-sites-delete-all-confirmation-question' );
		} else {
			$wgOut->addWikiMsg( 'openid-trusted-sites-delete-confirmation-question', $trustedSiteToBeDeleted );
		}

		$wgOut->addHtml(
			Xml::openElement( 'form',
				[
					'action' => $this->getPageTitle( 'DeleteTrustedSite' )->getLocalUrl(),
					'method' => 'post'
				]
			) .
			Xml::submitButton( wfMessage( 'openid-trusted-sites-delete-confirmation-button-text' )->text() ) . "\n" .
			Html::Hidden( 'url', $trustedSiteToBeDeleted ) . "\n" .
			Html::Hidden( 'openidDeleteTrustedSiteToken', $user->getEditToken( $trustedSiteToBeDeleted ) ) . "\n" .
			Xml::closeElement( 'form' )
		);
	}

	function SregFromQuery( $query ) {
		$sreg = [
			'required' => [],
			'optional' => [],
			'policy_url' => null
		];
		if ( array_key_exists( 'openid.sreg.required', $query ) ) {
			$sreg['required'] = explode( ',', $query['openid.sreg.required'] );
		}
		if ( array_key_exists( 'openid.sreg.optional', $query ) ) {
			$sreg['optional'] = explode( ',', $query['openid.sreg.optional'] );
		}
		if ( array_key_exists( 'openid.sreg.policy_url', $query ) ) {
			$sreg['policy_url'] = $query['openid.sreg.policy_url'];
		}
		return $sreg;
	}

	/**
	 * @param User $user
	 * @param string $field
	 * @param string $value
	 * @return bool
	 */
	function SetUserField( $user, $field, $value ) {
		switch ( $field ) {
		case 'fullname':
			$user->setRealName( $value );
			return true;
		case 'email':
			if ( Sanitizer::validateEmail( $value ) ) {
				$user->setEmail( $value );
			} else {
				$user->setEmail( "" );
			}
			return true;
		case 'language':
			$this->userOptionsManager->setOption( $user, 'language', $value );
			return true;
		default:
			return false;
		}
	}

	/**
	 * @param User $user
	 * @param string $field
	 * @return string|null
	 */
	function GetUserField( $user, $field ) {
		switch ( $field ) {
		case 'nickname':
			return $user->getName();
		case 'fullname':
			return $user->getRealName();
		case 'email':
			return $user->getEmail();
		case 'language':
			return $this->userOptionsManager->getOption( $user, 'language' );
		default:
			return null;
		}
	}

	/**
	 * @param Auth_OpenID_Server $server
	 * @param Auth_OpenID_ServerResponse $response
	 */
	function Response( $server, $response ) {
		global $wgOut;

		assert( $server !== null );
		assert( $response !== null );

		$wgOut->disable();

		AtEase::suppressWarnings();
		$wr =& $server->encodeResponse( $response );
		AtEase::restoreWarnings();

		assert( $wr !== null );

		header( "Status: " . $wr->code );

		foreach ( $wr->headers as $k => $v ) {
			header( "$k: $v" );
		}

		print $wr->body;
	}

	function LoginForm( $request, $msg = null ) {
	// is this really used by someone ?
		global $wgOut;

		wfDebug( "OpenID: SpecialOpenIDServer::LoginForm. You should not pass this point.\n" );
		$wgOut->showErrorPage( 'openiderror', 'openiderrortext' );
	}

	function SaveValues( $request, $sreg ) {
		$this->getRequest()->getSession()->persist();

		$_SESSION['openid_server_request'] = $request;
		$_SESSION['openid_server_sreg'] = $sreg;

		return true;
	}

	function FetchValues() {
		return [ $_SESSION['openid_server_request'], $_SESSION['openid_server_sreg'] ];
	}

	function ClearValues() {
		if ( isset( $_SESSION ) ) {
			unset( $_SESSION['openid_server_request'] );
			unset( $_SESSION['openid_server_sreg'] );
		}
		return true;
	}

	function serverLogin( $request ) {
		global $wgUser;

		assert( isset( $request ) );

		if ( $this->getRequest()->getCheck( 'wpCancel' ) ) {
			return $request->answer( false );
		}

		$password = $this->getRequest()->getText( 'wpPassword' );

		if ( !isset( $password ) || strlen( $password ) == 0 ) {
			return wfMessage( 'wrongpasswordempty' )->text();
		}

		assert( isset( $password ) && strlen( $password ) > 0 );

		$url = $request->identity;

		assert( isset( $url ) && is_string( $url ) && strlen( $url ) > 0 );

		$name = $this->UrlToUserName( $url );

		assert( isset( $name ) && is_string( $name ) && strlen( $name ) > 0 );

		$user = User::newFromName( $name );

		assert( isset( $user ) );

		if ( !$this->checkPassword( $password ) ) {
			return wfMessage( 'wrongpassword' )->text();
		} else {
			$wgUser = $user;
			$this->getRequest()->getSession()->persist();
			$wgUser->SetCookies();
			Hooks::run( 'UserLoginComplete', [ &$wgUser ] );
			return false;
		}
	}

	function TrustForm( $request, $sreg, $msg = null ) {
		global $wgOut;

		$trust_root = $request->trust_root;
		$user = $this->getUser();

		$instructions = wfMessage( 'openidtrustinstructions', $trust_root )->text();
		$allow = wfMessage( 'openidallowtrust', $trust_root )->text();

		if ( isset( $msg ) ) {
			$wgOut->addHTML( "<p class='error'>{$msg}</p>" );
		}

		$ok = wfMessage( 'ok' )->text();
		$cancel = wfMessage( 'cancel' )->text();

		$sk = $wgOut->getSkin();

		$wgOut->addHTML( "<p>{$instructions}</p>" .
			'<form action="' . $sk->makeSpecialUrl( 'OpenIDServer/Trust' ) . '" method="POST">' .
			'<input name="wpAllowTrust" type="checkbox" value="on" checked="checked" id="wpAllowTrust">' .
			'<label for="wpAllowTrust">' . $allow . '</label><br />'
		);

		$fields = array_filter( array_unique( array_merge( $sreg['optional'], $sreg['required'] ) ),
							   [ $this, 'ValidField' ] );

		if ( count( $fields ) > 0 ) {
			$wgOut->addHTML( '<table>' );

			foreach ( $fields as $field ) {
				$wgOut->addHTML( "<tr>" );
				$wgOut->addHTML( "<th><label for='wpAllow{$field}'>" );
				$wgOut->addHTML( wfMessage( "openid$field" )->text() );
				$wgOut->addHTML( "</label></th>" );
				$value = $this->GetUserField( $user, $field );
				$wgOut->addHTML( '<td>' . htmlspecialchars( $value ) . '</td>' );
				$wgOut->addHTML( '<td>' . wfMessage( in_array( $field, $sreg['required'] )
						? 'openidrequired'
						: 'openidoptional' )->text() . '</td>' );
				$wgOut->addHTML( "<td><input name='wpAllow{$field}' id='wpAllow{$field}' type='checkbox'" );

				if ( $value !== null ) {
					$wgOut->addHTML( " value='on' checked='checked' />" );
				} else {
					$wgOut->addHTML( " disabled='disabled' />" );
				}
				$wgOut->addHTML( '</td>' );
				$wgOut->addHTML( "</tr>" );
			}

			$wgOut->addHTML( '</table>' );
		}
		$wgOut->addHTML( "<input type='submit' name='wpOK' value='{$ok}' /> <input type='submit' name='wpCancel' value='{$cancel}' />" .
			Html::Hidden( 'openidTrustFormToken', $user->getEditToken( 'openidTrustFormToken' ) ) . "\n" .
			"</form>"
		);
		return null;
	}

	/**
	 * @param Auth_OpenID_CheckIDRequest $request
	 * @param array $sreg
	 *
	 * @return Auth_OpenID_ServerResponse|null
	 */
	function Trust( $request, $sreg ) {
		assert( isset( $request ) );
		assert( isset( $sreg ) );

		if ( $this->getRequest()->getCheck( 'wpCancel' ) ) {
			return $request->answer( false );
		}

		$trust_root = $request->trust_root;
		$user = $this->getUser();

		assert( isset( $trust_root ) && strlen( $trust_root ) > 0 );

		# If they don't want us to allow trust, save that.

		if ( !$this->getRequest()->getCheck( 'wpAllowTrust' ) ) {
			$this->SetUserTrust( $user, $trust_root, false );
			# Set'em and sav'em
			$user->saveSettings();
		} else {
			$fields = array_filter( array_unique( array_merge( $sreg['optional'], $sreg['required'] ) ),
				[ $this, 'ValidField' ] );

			$allow = [];

			foreach ( $fields as $field ) {
				if ( $this->getRequest()->getCheck( 'wpAllow' . $field ) ) {
					$allow[] = $field;
				}
			}

			$this->SetUserTrust( $user, $trust_root, $allow );
			# Set'em and sav'em
			$user->saveSettings();
		}
	}

	/**
	 * Converts an URL /User:Name to a user name, if possible
	 *
	 * @param string $url
	 * @return null|string
	 */
	function UrlToUserName( $url ) {
		global $wgArticlePath, $wgServer;

		# URL must be a string

		if ( !isset( $url ) || !is_string( $url ) || strlen( $url ) == 0 ) {
			return null;
		}

		# it must start with our server, case doesn't matter

		// Remove the protocol if $wgServer is protocol-relative.
		if ( substr( $wgServer, 0, 2 ) == '//' ) {
			$url = substr( $url, strpos( $url, ':' ) + 1 );
		}

		if ( strpos( strtolower( $url ), strtolower( $wgServer ) ) !== 0 ) {
			return null;
		}

		$parts = parse_url( $url );

		$relative = $parts['path'];
		if ( array_key_exists( 'query', $parts ) && strlen( $parts['query'] ) > 0 ) {
			$relative .= '?' . $parts['query'];
		}

		# Use regexps to extract user name
		$pattern = str_replace( '$1', '(.*)', $wgArticlePath );
		$pattern = str_replace( '?', '\?', $pattern );

		/* remove "Special:OpenIDXRDS/" to allow construction of a valid user page name */
		$specialPagePrefix = SpecialPage::getTitleFor( 'OpenIDXRDS' );

		if ( $specialPagePrefix != "Special:OpenIDXRDS" ) {
			$specialPagePrefix = "( {$specialPagePrefix} | Special:OpenIDXRDS )";
		}

		$relative = preg_replace( "!" . preg_quote( $specialPagePrefix, "!" ) . "/!", "", $relative );

		# Can't have a pound-sign in the relative, since that's for fragments
		if ( !preg_match( "#$pattern#", $relative, $matches ) ) {
			return null;
		} else {
			$titletext = urldecode( $matches[1] );
			$nt = Title::newFromText( $titletext );
			if ( $nt === null || $nt->getNamespace() != NS_USER ) {
				return null;
			} else {
				return $nt->getText();
			}
		}
	}

	/**
	 * @return string
	 */
	function serverUrl() {
		return $this->getPageTitle()->getFullURL( '', false, PROTO_CANONICAL );
	}

	protected function getGroupName() {
		return 'openid';
	}
}

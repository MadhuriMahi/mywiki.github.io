<?php
/**
 * optionToTable.php -- Convert old user_options-based
 * Copyright 2006,2007 Internet Brands (http://www.internetbrands.com/)
 * By Evan Prodromou <evan@wikitravel.org>
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @file
 * @author Evan Prodromou <evan@wikitravel.org>
 * @ingroup Extensions
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class OpenIDOptionToTable extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Convert user_option-stored urls to the new openID table' );
	}

	public function execute() {
		$dbr = wfGetDB( DB_REPLICA );
		if ( !$dbr->tableExists( 'user_properties' ) ) {
			$this->error( "The OpenID extension requires at least MediaWiki 1.16.", true );
		}

		$this->output( "Checking for legacy user_property rows..." );
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select( [ 'user_properties' ], [ 'up_user' ],
			[ 'up_property' => 'openid_url' ], __METHOD__ );
		if ( $res->numRows() ) {
			foreach ( $res as $row ) {
				$user = User::newFromId( $row->up_user );
				$this->output( "\n\tFixing {$user->getName()}" );
				$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
				SpecialOpenID::addUserUrl( $user, $userOptionsLookup->getOption( $user, 'openid_url' ) );
			}
			$this->output( "done\n" );
		} else {
			$this->output( "none found\n" );
		}
	}
}

$maintClass = OpenIDOptionToTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;

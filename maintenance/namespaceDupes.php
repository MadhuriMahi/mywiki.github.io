<?php
/**
 * Check for articles to fix after adding/deleting namespaces
 *
 * Copyright © 2005-2007 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/Maintenance.php';

use MediaWiki\Deferred\LinksUpdate\LinksDeletionUpdate;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * Maintenance script that checks for articles to fix after
 * adding/deleting namespaces.
 *
 * @ingroup Maintenance
 */
class NamespaceDupes extends Maintenance {

	/**
	 * @var IMaintainableDatabase
	 */
	protected $db;

	/**
	 * Total number of pages that need fixing that are automatically resolveable
	 * @var int
	 */
	private $resolvablePages = 0;

	/**
	 * Total number of pages that need fixing
	 * @var int
	 */
	private $totalPages = 0;

	/**
	 * Total number of links that need fixing that are automatically resolveable
	 * @var int
	 */
	private $resolvableLinks = 0;

	/**
	 * Total number of erroneous links
	 * @var int
	 */
	private $totalLinks = 0;

	/**
	 * Total number of links deleted because they weren't automatically resolveable due to the
	 * target already existing
	 * @var int
	 */
	private $deletedLinks = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Find and fix pages affected by namespace addition/removal' );
		$this->addOption( 'fix', 'Attempt to automatically fix errors and delete broken links' );
		$this->addOption( 'merge', "Instead of renaming conflicts, do a history merge with " .
			"the correct title" );
		$this->addOption( 'add-suffix', "Dupes will be renamed with correct namespace with " .
			"<text> appended after the article name", false, true );
		$this->addOption( 'add-prefix', "Dupes will be renamed with correct namespace with " .
			"<text> prepended before the article name", false, true );
		$this->addOption( 'source-pseudo-namespace', "Move all pages with the given source " .
			"prefix (with an implied colon following it). If --dest-namespace is not specified, " .
			"the colon will be replaced with a hyphen.",
			false, true );
		$this->addOption( 'dest-namespace', "In combination with --source-pseudo-namespace, " .
			"specify the namespace ID of the destination.", false, true );
		$this->addOption( 'move-talk', "If this is specified, pages in the Talk namespace that " .
			"begin with a conflicting prefix will be renamed, for example " .
			"Talk:File:Foo -> File_Talk:Foo" );
	}

	public function execute() {
		$options = [
			'fix' => $this->hasOption( 'fix' ),
			'merge' => $this->hasOption( 'merge' ),
			'add-suffix' => $this->getOption( 'add-suffix', '' ),
			'add-prefix' => $this->getOption( 'add-prefix', '' ),
			'move-talk' => $this->hasOption( 'move-talk' ),
			'source-pseudo-namespace' => $this->getOption( 'source-pseudo-namespace', '' ),
			'dest-namespace' => intval( $this->getOption( 'dest-namespace', 0 ) )
		];

		if ( $options['source-pseudo-namespace'] !== '' ) {
			$retval = $this->checkPrefix( $options );
		} else {
			$retval = $this->checkAll( $options );
		}

		if ( $retval ) {
			$this->output( "\nLooks good!\n" );
		} else {
			$this->output( "\nOh noeees\n" );
		}
	}

	/**
	 * Check all namespaces
	 *
	 * @param array $options Associative array of validated command-line options
	 *
	 * @return bool
	 */
	private function checkAll( $options ) {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$spaces = [];

		// List interwikis first, so they'll be overridden
		// by any conflicting local namespaces.
		foreach ( $this->getInterwikiList() as $prefix ) {
			$name = $contLang->ucfirst( $prefix );
			$spaces[$name] = 0;
		}

		// Now pull in all canonical and alias namespaces...
		foreach (
			MediaWikiServices::getInstance()->getNamespaceInfo()->getCanonicalNamespaces()
			as $ns => $name
		) {
			// This includes $wgExtraNamespaces
			if ( $name !== '' ) {
				$spaces[$name] = $ns;
			}
		}
		foreach ( $contLang->getNamespaces() as $ns => $name ) {
			if ( $name !== '' ) {
				$spaces[$name] = $ns;
			}
		}
		foreach ( $contLang->getNamespaceAliases() as $name => $ns ) {
			$spaces[$name] = $ns;
		}

		// We'll need to check for lowercase keys as well,
		// since we're doing case-sensitive searches in the db.
		$capitalLinks = $this->getConfig()->get( MainConfigNames::CapitalLinks );
		foreach ( $spaces as $name => $ns ) {
			$moreNames = [];
			$moreNames[] = $contLang->uc( $name );
			$moreNames[] = $contLang->ucfirst( $contLang->lc( $name ) );
			$moreNames[] = $contLang->ucwords( $name );
			$moreNames[] = $contLang->ucwords( $contLang->lc( $name ) );
			$moreNames[] = $contLang->ucwordbreaks( $name );
			$moreNames[] = $contLang->ucwordbreaks( $contLang->lc( $name ) );
			if ( !$capitalLinks ) {
				foreach ( $moreNames as $altName ) {
					$moreNames[] = $contLang->lcfirst( $altName );
				}
				$moreNames[] = $contLang->lcfirst( $name );
			}
			foreach ( array_unique( $moreNames ) as $altName ) {
				if ( $altName !== $name ) {
					$spaces[$altName] = $ns;
				}
			}
		}

		// Sort by namespace index, and if there are two with the same index,
		// break the tie by sorting by name
		$origSpaces = $spaces;
		uksort( $spaces, static function ( $a, $b ) use ( $origSpaces ) {
			return $origSpaces[$a] <=> $origSpaces[$b]
				?: $a <=> $b;
		} );

		$ok = true;
		foreach ( $spaces as $name => $ns ) {
			$ok = $this->checkNamespace( $ns, $name, $options ) && $ok;
		}

		$this->output(
			"{$this->totalPages} pages to fix, " .
			"{$this->resolvablePages} were resolvable.\n\n"
		);

		foreach ( $spaces as $name => $ns ) {
			if ( $ns != 0 ) {
				/* Fix up link destinations for non-interwiki links only.
				 *
				 * For example if a page has [[Foo:Bar]] and then a Foo namespace
				 * is introduced, pagelinks needs to be updated to have
				 * page_namespace = NS_FOO.
				 *
				 * If instead an interwiki prefix was introduced called "Foo",
				 * the link should instead be moved to the iwlinks table. If a new
				 * language is introduced called "Foo", or if there is a pagelink
				 * [[fr:Bar]] when interlanguage magic links are turned on, the
				 * link would have to be moved to the langlinks table. Let's put
				 * those cases in the too-hard basket for now. The consequences are
				 * not especially severe.
				 * @fixme Handle interwiki links, and pagelinks to Category:, File:
				 * which probably need reparsing.
				 */

				$this->checkLinkTable( 'pagelinks', 'pl', $ns, $name, $options );
				$this->checkLinkTable( 'templatelinks', 'tl', $ns, $name, $options );

				// The redirect table has interwiki links randomly mixed in, we
				// need to filter those out. For example [[w:Foo:Bar]] would
				// have rd_interwiki=w and rd_namespace=0, which would match the
				// query for a conflicting namespace "Foo" if filtering wasn't done.
				$this->checkLinkTable( 'redirect', 'rd', $ns, $name, $options,
					[ 'rd_interwiki' => null ] );
				$this->checkLinkTable( 'redirect', 'rd', $ns, $name, $options,
					[ 'rd_interwiki' => '' ] );
			}
		}

		$this->output(
			"{$this->totalLinks} links to fix, " .
			"{$this->resolvableLinks} were resolvable, " .
			"{$this->deletedLinks} were deleted.\n"
		);

		return $ok;
	}

	/**
	 * @return string[]
	 */
	private function getInterwikiList() {
		$result = MediaWikiServices::getInstance()->getInterwikiLookup()->getAllPrefixes();
		return array_column( $result, 'iw_prefix' );
	}

	/**
	 * Check a given prefix and try to move it into the given destination namespace
	 *
	 * @param int $ns Destination namespace id
	 * @param string $name
	 * @param array $options Associative array of validated command-line options
	 * @return bool
	 */
	private function checkNamespace( $ns, $name, $options ) {
		$targets = $this->getTargetList( $ns, $name, $options );
		$count = $targets->numRows();
		$this->totalPages += $count;
		if ( $count == 0 ) {
			return true;
		}

		$dryRunNote = $options['fix'] ? '' : ' DRY RUN ONLY';

		$ok = true;
		foreach ( $targets as $row ) {
			// Find the new title and determine the action to take

			$newTitle = $this->getDestinationTitle(
				$ns, $name, $row->page_namespace, $row->page_title );
			$logStatus = false;
			if ( !$newTitle ) {
				if ( $options['add-prefix'] == '' && $options['add-suffix'] == '' ) {
					$logStatus = 'invalid title and --add-prefix not specified';
					$action = 'abort';
				} else {
					$action = 'alternate';
				}
			} elseif ( $newTitle->exists() ) {
				if ( $options['merge'] ) {
					if ( $this->canMerge( $row->page_id, $newTitle, $logStatus ) ) {
						$action = 'merge';
					} else {
						$action = 'abort';
					}
				} elseif ( $options['add-prefix'] == '' && $options['add-suffix'] == '' ) {
					$action = 'abort';
					$logStatus = 'dest title exists and --add-prefix not specified';
				} else {
					$action = 'alternate';
				}
			} else {
				$action = 'move';
				$logStatus = 'no conflict';
			}
			if ( $action === 'alternate' ) {
				[ $ns, $dbk ] = $this->getDestination( $ns, $name, $row->page_namespace,
					$row->page_title );
				$newTitle = $this->getAlternateTitle( $ns, $dbk, $options );
				if ( !$newTitle ) {
					$action = 'abort';
					$logStatus = 'alternate title is invalid';
				} elseif ( $newTitle->exists() ) {
					$action = 'abort';
					$logStatus = 'alternate title conflicts';
				} else {
					$action = 'move';
					$logStatus = 'alternate';
				}
			}

			// Take the action or log a dry run message

			$logTitle = "id={$row->page_id} ns={$row->page_namespace} dbk={$row->page_title}";
			$pageOK = true;

			switch ( $action ) {
				case 'abort':
					$this->output( "$logTitle *** $logStatus\n" );
					$pageOK = false;
					break;
				case 'move':
					$this->output( "$logTitle -> " .
						$newTitle->getPrefixedDBkey() . " ($logStatus)$dryRunNote\n" );

					if ( $options['fix'] ) {
						$pageOK = $this->movePage( $row->page_id, $newTitle );
					}
					break;
				case 'merge':
					$this->output( "$logTitle => " .
						$newTitle->getPrefixedDBkey() . " (merge)$dryRunNote\n" );

					if ( $options['fix'] ) {
						$pageOK = $this->mergePage( $row, $newTitle );
					}
					break;
			}

			if ( $pageOK ) {
				$this->resolvablePages++;
			} else {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Check and repair the destination fields in a link table
	 * @param string $table The link table name
	 * @param string $fieldPrefix The field prefix in the link table
	 * @param int $ns Destination namespace id
	 * @param string $name
	 * @param array $options Associative array of validated command-line options
	 * @param array $extraConds Extra conditions for the SQL query
	 */
	private function checkLinkTable( $table, $fieldPrefix, $ns, $name, $options,
		$extraConds = []
	) {
		$dbw = $this->getDB( DB_PRIMARY );

		$batchConds = [];
		$fromField = "{$fieldPrefix}_from";
		$batchSize = 500;
		$linksMigration = MediaWikiServices::getInstance()->getLinksMigration();
		if ( isset( $linksMigration::$mapping[$table] ) ) {
			$queryInfo = $linksMigration->getQueryInfo( $table );
			[ $namespaceField, $titleField ] = $linksMigration->getTitleFields( $table );
		} else {
			$queryInfo = [
				'tables' => [ $table ],
				'fields' => [
					"{$fieldPrefix}_namespace",
					"{$fieldPrefix}_title"
				],
				'joins' => []
			];
			$namespaceField = "{$fieldPrefix}_namespace";
			$titleField = "{$fieldPrefix}_title";
		}

		while ( true ) {
			$res = $dbw->select(
				$queryInfo['tables'],
				array_merge( [ $fromField ], $queryInfo['fields'] ),
				array_merge(
					$batchConds,
					$extraConds,
					[
						$namespaceField => 0,
						$titleField . $dbw->buildLike( "$name:", $dbw->anyString() )
					]
				),
				__METHOD__,
				[
					'ORDER BY' => [ $titleField, $fromField ],
					'LIMIT' => $batchSize
				],
				$queryInfo['joins']
			);

			if ( $res->numRows() == 0 ) {
				break;
			}

			$rowsToDeleteIfStillExists = [];

			foreach ( $res as $row ) {
				$logTitle = "from={$row->$fromField} ns={$row->$namespaceField} " .
					"dbk={$row->$titleField}";
				$destTitle = $this->getDestinationTitle(
					$ns, $name, $row->$namespaceField, $row->$titleField );
				$this->totalLinks++;
				if ( !$destTitle ) {
					$this->output( "$table $logTitle *** INVALID\n" );
					continue;
				}
				$this->resolvableLinks++;
				if ( !$options['fix'] ) {
					$this->output( "$table $logTitle -> " .
						$destTitle->getPrefixedDBkey() . " DRY RUN\n" );
					continue;
				}

				if ( isset( $linksMigration::$mapping[$table] ) ) {
					$setValue = $linksMigration->getLinksConditions( $table, $destTitle );
					$whereCondition = $linksMigration->getLinksConditions(
						$table,
						new TitleValue( 0, $row->$titleField )
					);
					$deleteCondition = $linksMigration->getLinksConditions(
						$table,
						new TitleValue( (int)$row->$namespaceField, $row->$titleField )
					);
				} else {
					$setValue = [
						$namespaceField => $destTitle->getNamespace(),
						$titleField => $destTitle->getDBkey()
					];
					$whereCondition = [
						$namespaceField => 0,
						$titleField => $row->$titleField
					];
					$deleteCondition = [
						$namespaceField => $row->$namespaceField,
						$titleField => $row->$titleField,
					];
				}

				$dbw->update( $table,
					// SET
					$setValue,
					// WHERE
					array_merge( [ $fromField => $row->$fromField ], $whereCondition ),
					__METHOD__,
					[ 'IGNORE' ]
				);

				$rowsToDeleteIfStillExists[] = $dbw->makeList(
					array_merge( [ $fromField => $row->$fromField ], $deleteCondition ),
					IDatabase::LIST_AND
				);

				$this->output( "$table $logTitle -> " .
					$destTitle->getPrefixedDBkey() . "\n"
				);
			}

			if ( $options['fix'] && count( $rowsToDeleteIfStillExists ) > 0 ) {
				$dbw->delete(
					$table,
					$dbw->makeList( $rowsToDeleteIfStillExists, IDatabase::LIST_OR ),
					__METHOD__
				);

				$this->deletedLinks += $dbw->affectedRows();
				$this->resolvableLinks -= $dbw->affectedRows();
			}

			$batchConds = [
				$dbw->buildComparison( '>', [
					// @phan-suppress-next-line PhanPossiblyUndeclaredVariable rows contains at least one item
					$titleField => $row->$titleField,
					// @phan-suppress-next-line PhanPossiblyUndeclaredVariable rows contains at least one item
					$fromField => $row->$fromField,
				] )
			];

			$this->waitForReplication();
		}
	}

	/**
	 * Move the given pseudo-namespace, either replacing the colon with a hyphen
	 * (useful for pseudo-namespaces that conflict with interwiki links) or move
	 * them to another namespace if specified.
	 * @param array $options Associative array of validated command-line options
	 * @return bool
	 */
	private function checkPrefix( $options ) {
		$prefix = $options['source-pseudo-namespace'];
		$ns = $options['dest-namespace'];
		$this->output( "Checking prefix \"$prefix\" vs namespace $ns\n" );

		return $this->checkNamespace( $ns, $prefix, $options );
	}

	/**
	 * Find pages in main and talk namespaces that have a prefix of the new
	 * namespace so we know titles that will need migrating
	 *
	 * @param int $ns Destination namespace id
	 * @param string $name Prefix that is being made a namespace
	 * @param array $options Associative array of validated command-line options
	 *
	 * @return IResultWrapper
	 */
	private function getTargetList( $ns, $name, $options ) {
		$dbw = $this->getDB( DB_PRIMARY );

		if (
			$options['move-talk'] &&
			MediaWikiServices::getInstance()->getNamespaceInfo()->isSubject( $ns )
		) {
			$checkNamespaces = [ NS_MAIN, NS_TALK ];
		} else {
			$checkNamespaces = NS_MAIN;
		}

		return $dbw->select( 'page',
			[
				'page_id',
				'page_title',
				'page_namespace',
			],
			[
				'page_namespace' => $checkNamespaces,
				'page_title' . $dbw->buildLike( "$name:", $dbw->anyString() ),
			],
			__METHOD__
		);
	}

	/**
	 * Get the preferred destination for a given target page.
	 * @param int $ns The destination namespace ID
	 * @param string $name The conflicting prefix
	 * @param int $sourceNs The source namespace
	 * @param string $sourceDbk The source DB key (i.e. page_title)
	 * @return array [ ns, dbkey ], not necessarily valid
	 */
	private function getDestination( $ns, $name, $sourceNs, $sourceDbk ) {
		$dbk = substr( $sourceDbk, strlen( "$name:" ) );
		if ( $ns == 0 ) {
			// An interwiki; try an alternate encoding with '-' for ':'
			$dbk = "$name-" . $dbk;
		}
		$destNS = $ns;
		$nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
		if ( $sourceNs == NS_TALK && $nsInfo->isSubject( $ns ) ) {
			// This is an associated talk page moved with the --move-talk feature.
			$destNS = $nsInfo->getTalk( $destNS );
		}
		return [ $destNS, $dbk ];
	}

	/**
	 * Get the preferred destination title for a given target page.
	 * @param int $ns The destination namespace ID
	 * @param string $name The conflicting prefix
	 * @param int $sourceNs The source namespace
	 * @param string $sourceDbk The source DB key (i.e. page_title)
	 * @return Title|false
	 */
	private function getDestinationTitle( $ns, $name, $sourceNs, $sourceDbk ) {
		[ $destNS, $dbk ] = $this->getDestination( $ns, $name, $sourceNs, $sourceDbk );
		$newTitle = Title::makeTitleSafe( $destNS, $dbk );
		if ( !$newTitle || !$newTitle->canExist() ) {
			return false;
		}
		return $newTitle;
	}

	/**
	 * Get an alternative title to move a page to. This is used if the
	 * preferred destination title already exists.
	 *
	 * @param int $ns The destination namespace ID
	 * @param string $dbk The source DB key (i.e. page_title)
	 * @param array $options Associative array of validated command-line options
	 * @return Title|false
	 */
	private function getAlternateTitle( $ns, $dbk, $options ) {
		$prefix = $options['add-prefix'];
		$suffix = $options['add-suffix'];
		if ( $prefix == '' && $suffix == '' ) {
			return false;
		}
		$newDbk = $prefix . $dbk . $suffix;
		return Title::makeTitleSafe( $ns, $newDbk );
	}

	/**
	 * Move a page
	 *
	 * @param int $id The page_id
	 * @param LinkTarget $newLinkTarget The new title link target
	 * @return bool
	 */
	private function movePage( $id, LinkTarget $newLinkTarget ) {
		$dbw = $this->getDB( DB_PRIMARY );

		$dbw->update( 'page',
			[
				"page_namespace" => $newLinkTarget->getNamespace(),
				"page_title" => $newLinkTarget->getDBkey(),
			],
			[
				"page_id" => $id,
			],
			__METHOD__
		);

		// Update *_from_namespace in links tables
		$fromNamespaceTables = [
			[ 'pagelinks', 'pl' ],
			[ 'templatelinks', 'tl' ],
			[ 'imagelinks', 'il' ]
		];
		foreach ( $fromNamespaceTables as [ $table, $fieldPrefix ] ) {
			$dbw->update( $table,
				// SET
				[ "{$fieldPrefix}_from_namespace" => $newLinkTarget->getNamespace() ],
				// WHERE
				[ "{$fieldPrefix}_from" => $id ],
				__METHOD__
			);
		}

		return true;
	}

	/**
	 * Determine if we can merge a page.
	 * We check if an inaccessible revision would become the latest and
	 * deny the merge if so -- it's theoretically possible to update the
	 * latest revision, but opens a can of worms -- search engine updates,
	 * recentchanges review, etc.
	 *
	 * @param int $id The page_id
	 * @param LinkTarget $linkTarget The new link target
	 * @param string &$logStatus This is set to the log status message on failure @phan-output-reference
	 * @return bool
	 */
	private function canMerge( $id, LinkTarget $linkTarget, &$logStatus ) {
		$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
		$latestDest = $revisionLookup->getRevisionByTitle( $linkTarget, 0,
			IDBAccessObject::READ_LATEST );
		$latestSource = $revisionLookup->getRevisionByPageId( $id, 0,
			IDBAccessObject::READ_LATEST );
		if ( $latestSource->getTimestamp() > $latestDest->getTimestamp() ) {
			$logStatus = 'cannot merge since source is later';
			return false;
		} else {
			return true;
		}
	}

	/**
	 * Merge page histories
	 *
	 * @param stdClass $row Page row
	 * @param Title $newTitle
	 * @return bool
	 */
	private function mergePage( $row, Title $newTitle ) {
		$dbw = $this->getDB( DB_PRIMARY );

		$id = $row->page_id;

		// Construct the WikiPage object we will need later, while the
		// page_id still exists. Note that this cannot use makeTitleSafe(),
		// we are deliberately constructing an invalid title.
		$sourceTitle = Title::makeTitle( $row->page_namespace, $row->page_title );
		$sourceTitle->resetArticleID( $id );
		$wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $sourceTitle );
		$wikiPage->loadPageData( WikiPage::READ_LATEST );

		$destId = $newTitle->getArticleID();
		$this->beginTransaction( $dbw, __METHOD__ );
		$dbw->update( 'revision',
			// SET
			[ 'rev_page' => $destId ],
			// WHERE
			[ 'rev_page' => $id ],
			__METHOD__
		);

		$dbw->delete( 'page', [ 'page_id' => $id ], __METHOD__ );

		$this->commitTransaction( $dbw, __METHOD__ );

		/* Call LinksDeletionUpdate to delete outgoing links from the old title,
		 * and update category counts.
		 *
		 * Calling external code with a fake broken Title is a fairly dubious
		 * idea. It's necessary because it's quite a lot of code to duplicate,
		 * but that also makes it fragile since it would be easy for someone to
		 * accidentally introduce an assumption of title validity to the code we
		 * are calling.
		 */
		DeferredUpdates::addUpdate( new LinksDeletionUpdate( $wikiPage ) );
		DeferredUpdates::doUpdates();

		return true;
	}
}

$maintClass = NamespaceDupes::class;
require_once RUN_MAINTENANCE_IF_MAIN;

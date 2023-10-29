<?php

namespace MediaWiki\Extension\SimpleBreadcrumb;

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use OutputPage;
use Parser;
use ParserOutput;

class Hooks {
	/**
	 * The generated breadcrumb elements
	 * @var array
	 */
	public static $breadcrumb = array();

	/**
	 * Register the parser function and global variables
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	public static function onParserFirstCallInit($parser) {		
		global $wgbcdelimiter, $wgbcMaxCount, $wgbcOverflowPrefix;
		/**
		 * Breadcrumb delimiter string
		 * @var string
		 */
		$wgbcdelimiter = ' &gt; ';

		/**
		 * Maximum elements in breadcrumb
		 * @var int
		 */
		$wgbcMaxCount = 5;

		/**
		 * Prefix when breadcrumb has more elements than fixed limit
		 * @var string
		 */
		$wgbcOverflowPrefix = '&hellip;';
		
		$parser->setFunctionHook('breadcrumb', [ self::class, 'buildBreadcrumb' ]);
		return true;
	}

	/**
	 * Parse the "breadcrumb" parser function.
	 *
	 * @param Parser $parser
	 * @param string $parentPageTitle
	 * @param string|null $alias
	 */
	public static function buildBreadcrumb($parser, $parentPageTitle = null, $alias = null) {		
		// Process the page title
		$parentPageTitle = trim($parentPageTitle);
		$pagedata = array();
		$page = $parser->getPage();
		#$pagedata['dbkey'] = $page->getDBkey();
		$pagedata['pageID'] = $page->getId();
		$pagedata['title'] = $page->getFullText();
		$pagedata['alias'] = self::parseWikiMarkup(self::sanitizeAlias($alias));
		if ($pagedata['title'] == $parentPageTitle) {// If the parent page and the current page are the same, set parent page to null
			$pagedata['parentTitle'] = null;
			$parentPageTitle = null;
		} else {
			$pagedata['parentTitle'] = $parentPageTitle;
		}
		$pagedata['link'] = self::getPageLink($page, $pagedata);
		
		// Add this page to cache
		self::saveToBreadcrumbCache($pagedata);
		
		//if no parent page is supplied, this is the top level and we don't want to display the breadcrumb.
		if (empty($parentPageTitle)) {
			return '';
		}
		$breadcrumbList = array();
		
		if (!empty($pagedata['alias']))
			$breadcrumbList[] = $pagedata['alias'];
		else
			$breadcrumbList[] = $pagedata['title'];
		
		$ancestorPages = array();
		//Add current page to ancestorPages array so we can watch out for loops
		$ancestorPages[$pagedata['title']] = $pagedata;
		// Get ancestor pages  
		$ancestorPages = self::getAncestorPages($parentPageTitle, $ancestorPages);
		
		//Remove current page from ancestorPages array (it's already in the $breadcrumbList)
		unset($ancestorPages[$pagedata['title']]);
		
		if (is_iterable($ancestorPages) && count($ancestorPages) > 0) {
			// Add the ancestor pages to the breadcrumbList array
			foreach ($ancestorPages as $ancestorPage) {
				$breadcrumbList[] = $ancestorPage['link'];
			}
		} else {
			return '';
		}
		// Reverse the order to get the deepest link first
		self::$breadcrumb = array_reverse($breadcrumbList, true);

		// Render the generated breadcrumb and save to the parser
		$parserOutput = $parser->getOutput();
		$outputString = self::render();
		$parserOutput->setExtensionData('simplebreadcrumb', $outputString);
		return '';
	}

	/**
	 * Render the breadcrumb trail.
	 *
	 * @return string
	 */
	public static function render() {
		global $wgbcdelimiter, $wgbcMaxCount;
		
		// Check if the breadcrumb count exceeds the maximum
		if (count(self::$breadcrumb) > $wgbcMaxCount) {
			// Truncate the breadcrumb trail while keeping the top page
			self::truncateBreadcrumb();
		}

		// Join the breadcrumb elements with the specified delimiter
		$breadcrumbHtmlstring = implode($wgbcdelimiter, self::$breadcrumb);

		// Return the generated breadcrumb HTML
		return '<div id="breadcrumb">' . $breadcrumbHtmlstring . '</div>';
	}
	
	/**
	 * Recursively retrieve ancestor pages for a given page.
	 *
	 * @param string $pageTitle The name of the parent page.
	 * @return array An array of ancestor pages.
	 */
	public static function getAncestorPages($pageTitle, $ancestorPages) {
		// Create a Title object for the page.
		$title = Title::newFromText($pageTitle);
		if (!empty($title)) {
			if (!$title->isKnown($pageTitle) || array_key_exists($pageTitle, $ancestorPages)) {
				return $ancestorPages; //This page doesn't exist or is already in the breadcrumb trail--we don't want an infinite loop
			}
		} else {
			return null;
		}
		// Find if parent is cached
		$pageData = self::loadFromBreadcrumbCache($pageTitle);
		if (!empty($pageData)) {
			$ancestorPages[$pageTitle] = $pageData;
			//Go one level deeper
			if (!empty($ancestorPages[$pageTitle]['parentTitle'])) {
				$parentTitle = $ancestorPages[$pageTitle]['parentTitle'];
				$ancestorPages = self::getAncestorPages($parentTitle, $ancestorPages);
			}
			return $ancestorPages;
		} else {
			$parentPageData = array();
			$parentPageData['title'] = $pageTitle;
			$parentPageData['alias'] = null;
			$parentPageData['parentTitle'] = null;
			$parentPageData['link'] = self::getPageLink($title, $parentPageData);
			$ancestorPages[$pageTitle] = $parentPageData;
			return $ancestorPages;
		}		
	}

	/**
	 * Return the code for the page link
	 *
	 * @param Title $title
	 * @param array $pagedata
	 * @return string html link
	 */
	public static function getPageLink($title, $pagedata) {
		// Invalid page
		if (empty($pagedata) || empty($pagedata['title'])) {
			return '';
		}
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();
		// Return link
		if (!empty($pagedata['alias'])) {
			$text = $pagedata['alias'];
			// Encode the opening and closing <i> and <b> tags to placeholders.
			$text = str_replace('<i>', '__open_i_tag__', $text);
			$text = str_replace('</i>', '__close_i_tag__', $text);
			$text = str_replace('<b>', '__open_b_tag__', $text);
			$text = str_replace('</b>', '__close_b_tag__', $text);
			
			// Encode ampersands (used in html entities) to placeholders--if not, all html entities will have "&#38;" instead of "&"
			$text = str_replace("&", '__ampersand___placeholder__', $text);			

			// Create the preloaded link with the encoded text.
			$linkString = $linkRenderer->makePreloadedLink($title, $text);

			// Decode the opening and closing <i> and <b> tags back to their original form.
			$linkString = str_replace('__open_i_tag__', '<i>', $linkString);
			$linkString = str_replace('__close_i_tag__', '</i>', $linkString);
			$linkString = str_replace('__open_b_tag__', '<b>', $linkString);
			$linkString = str_replace('__close_b_tag__', '</b>', $linkString);
			
			// Decode ampersands back to their original form.
			$linkString = str_replace('__ampersand___placeholder__', "&", $linkString);
			
			return $linkString;
		} else {
			return $linkRenderer->makePreloadedLink($title);
		}
	}

	/**
	 * Truncate the breadcrumb trail while keeping the top page.
	 *
	 * @return array
	 */
	private static function truncateBreadcrumb() {
		global $wgbcMaxCount, $wgbcOverflowPrefix;
		
		// Keep the very first page (top page).
		$truncatedBreadcrumb = array_slice(self::$breadcrumb, 0, 1);

		// Add the overflow prefix.
		$truncatedBreadcrumb[] = $wgbcOverflowPrefix;

		// Calculate the number of pages to keep from the end.
		$pagesToKeep = $wgbcMaxCount - 2;

		// Append the last pages while respecting the maximum count.
		$truncatedBreadcrumb = array_merge(
			$truncatedBreadcrumb,
			array_slice(self::$breadcrumb, -$pagesToKeep, $pagesToKeep)
		);

		self::$breadcrumb = $truncatedBreadcrumb;
	}

	/**
	 * Parse italics and bold wiki markup.
	 *
	 * @param String $text
	 * @return String
	 */
	private static function parseWikiMarkup($text) {
		//Have to use "&#39;" because the parser doesn't pass plain apostrophes.
		$boldpattern = '/&#39;&#39;&#39;(.*?)&#39;&#39;&#39;/';
		$boldreplacement = '<b>$1</b>';
		// Convert bold markup ('''text''') to HTML <b> tags
		$text = preg_replace($boldpattern, $boldreplacement, $text);

		$italicspattern = '/&#39;&#39;(.*?)&#39;&#39;/';
		$italicsreplacement = '<i>$1</i>';
		// Convert italicized markup (''text'') to HTML <i> tags
		$text = preg_replace($italicspattern, $italicsreplacement, $text);

		return $text;
	}

	/**
	 * Sanitize the user-input page alias string.
	 *
	 * @param string $alias
	 * @return string
	 */
	private static function sanitizeAlias($alias) {
		// Sanitize $alias and limit its length
		$maxLength = 255;
		$alias = trim($alias); // Remove leading/trailing whitespace
		$alias = mb_substr($alias, 0, $maxLength, 'utf-8'); // Limit to max length

		// Sanitize $alias using wfEscapeWikiText
		return wfEscapeWikiText($alias);
	}

	/**
	 * Inject the breadcrumb HTML into the page output
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @return bool
	 */
	public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ) {		
		$breadcrumbHtmlString = $parserOutput->getExtensionData( 'simplebreadcrumb' );
		if(!empty($breadcrumbHtmlString)) {//If there's no breadcrumb for this page, don't do anything
			// Add the breadcrumb html string
			$out->addSubtitle( $breadcrumbHtmlString );
		}
		return true;
	}

	/**
	 * @param DatabaseUpdater $updater
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater ) {
		$updater->addExtensionTable( 'simple_breadcrumb_cache',
			dirname( __DIR__ ) . '/sql/tables-generated.sql' );
	}

	/**
	 * Save breadcrumb data to the cache database table.
	 *
	 * @param array $pagedata Array with keys 'title', 'alias', 'parentTitle', and 'link'.
	 */
	public static function saveToBreadcrumbCache( $pagedata ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$tableName = $dbw->tableName( 'simple_breadcrumb_cache' );
		$dbw->upsert(
			$tableName,
			[
				'page_ID' => $pagedata['pageID'],
				'page_title' => $pagedata['title'],
				'link' => $pagedata['link'],
				'alias' => $pagedata['alias'],
				'parent_title' => $pagedata['parentTitle']
			],
			[ 'page_ID' ],
			[
				'link' => $pagedata['link'],
				'alias' => $pagedata['alias'],
				'parent_title' => $pagedata['parentTitle']
			]
		);

	}

	/**
	 * Load breadcrumb data from the cache database table.
	 *
	 * @param string $pageTitle
	 * @return array|false $pagedata Array with keys 'title', 'alias', 'parentTitle', and 'link', or false if not found.
	 */
	public static function loadFromBreadcrumbCache( $pageTitle ) {
		$db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );
		$tableName = $db->tableName( 'simple_breadcrumb_cache' );

		$row = $db->selectRow(
			$tableName,
			[ 'link', 'alias', 'parent_title' ],
			[ 'page_title' => $pageTitle ]
		);

		if ( $row ) {
			return [
				'title' => $pageTitle,
				'link' => $row->link,
				'alias' => $row->alias,
				'parentTitle' => $row->parent_title
			];
		}
		return false;
	}
}
?>

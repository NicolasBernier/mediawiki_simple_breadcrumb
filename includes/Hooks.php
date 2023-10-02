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
		self::loadBreadcrumbCache();
		self::$breadcrumbCache[$pagedata['title']] = $pagedata;
		self::saveBreadcrumbCache();
		
		//if no parent page is supplied, this is the top level and we don't want to display the breadcrumb.
		if (empty($parentPageTitle)) {
			return '';
		}
		$breadcrumbList = array();
		
		if (!empty($pagedata['alias']))
			$breadcrumbList[] = $pagedata['alias'];
		else
			$breadcrumbList[] = $pagedata['title'];
		
		// Get ancestor pages  
		$ancestorPages = self::getAncestorPages($parentPageTitle);
		
		if(is_iterable($ancestorPages)){
			// Add the ancestor pages to the breadcrumbList array
			foreach ($ancestorPages as $ancestorPage) {
				$breadcrumbList[] = $ancestorPage['link'];
			}
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
	public static function getAncestorPages($pageTitle, $ancestorPages = []) {
		// Create a Title object for the page.
		$title = Title::newFromText($pageTitle);
		if (!empty($title)) {
			if (!$title->isKnown($pageTitle))
				return null; //This page doesn't exist
		} else {
			return null;
		}
		// Find if parent is cached
		if (array_key_exists($pageTitle, self::$breadcrumbCache)) {

			$ancestorPages[$pageTitle] =  self::$breadcrumbCache[$pageTitle];
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
		if (!empty($pagedata['alias']))
			return $linkRenderer->makePreloadedLink($title, $pagedata['alias']);
		else
			return $linkRenderer->makePreloadedLink($title);
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
	 * Inject the breadcrumb HTML into the page output
	 *
	 * @param OutputPage $out
	 * @param ParserOutput $parserOutput
	 * @return bool
	 */
	public static function onOutputPageParserOutput( OutputPage $out, ParserOutput $parserOutput ) {
		$breadcrumbHtmlString = $parserOutput->getExtensionData( 'simplebreadcrumb' );
		if(!empty($breadcrumbHtmlString)) {//If there's no breadcrumb for this page, don't do anything (was previously adding a bunch of empty lines on Special:SpecialPages)
			// Add some style
			$out->addInlineStyle('#breadcrumb {position:relative; top:-15px; }');
			// Add the breadcrumb html string
			$out->addSubtitle( $breadcrumbHtmlString );
		}
		return true;
	}
		
	/**
	 * Occurs after the save page request has been processed.
	 *
	 * @param WikiPage $article
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param boolean $isMinor
	 * @param boolean $isWatch
	 * @param integer $flags
	 * @param Revision $revision
	 * @param Status $status
	 * @param integer $baseRevId
	 * @return boolean
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage, MediaWiki\User\UserIdentity $user, string $summary, int $flags, MediaWiki\Revision\RevisionRecord $revisionRecord, MediaWiki\Storage\EditResult $editResult ) {
		// Remove the saved page from the cache to force it to reload from cache next time it's invoked.
		self::loadBreadcrumbCache();
		
		// Determine the saved page's title.
		$pageTitle = $wikiPage->getTitle()->getFullText();

		// Unset the cached breadcrumb data for the saved page.
		unset(self::$breadcrumbCache[$pageTitle]);
		
		// Save the updated breadcrumb cache.
		self::saveBreadcrumbCache();
				
		// Return true to indicate successful processing.
		return true;
	}

	/**
	 * Breadcrumb cache
	 * @var array
	 */
	protected static $breadcrumbCache = array();

	/**
	 * Load breadcrumb cache data using MediaWiki's caching system.
	 */
	private static function loadBreadcrumbCache() {
		// Use MediaWiki's caching system to load the cached breadcrumb data.
		$cacheKey = 'breadcrumb_cache';
		$cache = MediaWikiServices::getInstance()->getMainObjectStash();

		$cachedData = $cache->get($cacheKey);

		if ($cachedData !== false) {
			self::$breadcrumbCache = $cachedData;
		}
	}

	/**
	 * Save breadcrumb cache data using MediaWiki's caching system.
	 */
	private static function saveBreadcrumbCache() {		
		// Use MediaWiki's caching system to save the breadcrumb cache data.
		$cacheKey = 'breadcrumb_cache';
		$cache = MediaWikiServices::getInstance()->getMainObjectStash();

		// Save the breadcrumb cache data
		$cache->set($cacheKey, self::$breadcrumbCache);
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
}
?>

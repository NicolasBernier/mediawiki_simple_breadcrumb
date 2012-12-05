<?php

/**
 * MediaWiki Simple hieracherical breadcrumb extension
 * For documentation, please see http://www.mediawiki.org/wiki/Extension:Simple_Breadcrumb
 *
 * @ingroup Extensions
 * @author Nicolas Bernier
 * @version 1.0
 */
define('SIMPLE_BREADCRUMB_VERSION', '1.0');

// Extension credits that show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
	'name'        => 'Simple Breadcrumb',
	'url'         => 'http://www.mediawiki.org/wiki/Extension:Simple_Breadcrumb',
	'version'     => SIMPLE_BREADCRUMB_VERSION,
	'author'      => '[http://techblog.bernier.re Nicolas Bernier]',
	'description' => 'Parser function implementing a hierarchical breadcumb.'
);

// Enable extension hooks
$wgHooks['ParserFirstCallInit'][]  = 'SimpleBreadCrumb::init';
$wgHooks['ParserBeforeTidy'][]     = 'SimpleBreadCrumb::onParserBeforeTidy';
$wgHooks['OutputPageBeforeHTML'][] = 'SimpleBreadCrumb::onOutputPageBeforeHtml';
$wgHooks['EditFormPreloadText'][]  = array('SimpleBreadCrumb::editFormSetParent');

if (version_compare($wgVersion, '1.21.0') >= 1)
	$wgHooks['PageContentSaveComplete'][] = 'SimpleBreadCrumb::onPageContentSaveComplete';
else
	$wgHooks['ArticleSaveComplete'][] = 'SimpleBreadCrumb::onPageContentSaveComplete';

$wgExtensionMessagesFiles['SimpleBreadcrumb'] = dirname( __FILE__ ) . '/SimpleBreadcrumb.i18n.php';

class SimpleBreadCrumb
{
	/**
	 * Empty breadcrumb tag
	 */
	const BREADCRUMB_TAG = '{{#breadcrumb: }}';

	/**
	 * Fill the breadcrumb tag when editing a new page
	 * @var boolean
	 */
	public static $fillTagOnNewPage = true;

	/**
	 * Enable link on the current active page in the breadcrumb
	 * @var boolean
	 */
	public static $selfLink = true;

	/**
	 * Breadcrumb delimiter string
	 * @var string
	 */
	public static $delimiter = ' &gt; ';

	/**
	 * Maximum elements in breadcrumb
	 * @var int
	 */
	public static $maxCount = 5;

	/**
	 * Prefix when breadcrumb has more elements than fixed limit
	 * @var string
	 */
	public static $overflowPrefix = '[&hellip;]';

	/**
	 * Callback function to override the SimpleBreadCrumb::onOutputPageBeforeHtml() hook
	 * @var function
	 */
	public static $onOutputPageBeforeHtmlCallback = null;

	/**
	 * Callback function to override the SimpleBreadCrumb::render() method
	 * @var type
	 */
	public static $renderCallback = null;

	/**
	 * The generated breadcrumb elements
	 * @var array
	 */
	public static $breadcrumb = array();

	/**
	 * Extension init
	 * @param Parser $parser
	 * @return type
	 */
	public static function init(Parser $parser)
	{
		$parser->setFunctionHook('breadcrumb', 'SimpleBreadCrumb::build');

		return true;
	}

	/**
	 * Add an empty breadcrumb tag when editing an empty page
	 * @param string $textbox
	 * @param string $title
	 * @return boolean
	 */
	public static function editFormSetParent(&$textbox, &$title)
	{
		if (!empty($textbox) || !self::$fillTagOnNewPage)
			return true;

		$textbox = SimpleBreadCrumb::BREADCRUMB_TAG;
		return true;
	}

	/**
	 * True when the SimpleBreadCrumb::build() is called by its own process
	 * @var boolean
	 */
	protected static $parsingParent = false;

	/**
	 * SimpleBreadCrumb::build() may be called by itself to determine parse the breadcrumb
	 * of the parent page. In this case we only need to know the parent, saved in this variable.
	 * @var boolean
	 */
	protected static $foundParentPage = null;

	/**
	 * Build the breadcrumb
	 * @param Parser $parser
	 * @param string $parentPageName
	 * @param string $parentPageDisplayName
	 * @return string
	 */
	public static function build(Parser $parser, $parentPageName = null, $parentPageDisplayName = null)
	{
		// Parsing invoked by getParentPage(): we only need to know the parent.
		if (self::$parsingParent)
		{
			// Only keep the first occurrence
			if (empty(self::$foundParentPage))
				self::$foundParentPage = self::getPageFromString($parentPageName, $parentPageDisplayName);

			return '';
		}

		$currentPage = self::getPageFromString($parser->getTitle()->getFullText());

		$breadcrumb = array();

		$parser->disableCache();

		// Add current page, with self link if activated
		if (self::$selfLink)
			$breadcrumb[$currentPage['full_name']] = self::getPageLink($currentPage);
		else
			$breadcrumb[$currentPage['full_name']] = $currentPage['display_name'];

		// A parent page has been specified
		if (!empty($parentPageName))
		{
			// Add the parent page to the breadcrumt
			$parentPage = self::getPageFromString($parentPageName, $parentPageDisplayName);
			$breadcrumb[$parentPage['full_name']] = self::getPageLink($parentPage);

			// Add other ancestor pages
			while ($parentOfParentPage = self::getParentPage($parser, $parentPage))
			{
				// Parent already added to breadcrumb: abort
				if (!empty($breadcrumb[$parentOfParentPage['full_name']]))
					break;

				// Add ancestors and carry on
				$breadcrumb[$parentOfParentPage['full_name']] = self::getPageLink($parentOfParentPage);
				$parentPage = $parentOfParentPage;
			}
		}

		// Reverse the order to get the deepest link first
		self::$breadcrumb = array_reverse($breadcrumb, true);

		// Render the breadcrumb in place
		return self::render();
	}

	/**
	 * Return the page data from string
	 * @param string $pageStr
	 * @param string $pageDisplayName
	 * @return array
	 */
	public static function getPageFromString($pageStr, $pageDisplayName = null)
	{
		if (empty($pageStr))
			return null;

		$pageData = array();

		$pageParts = explode('|', $pageStr);
		$pageParts[0] = str_replace('_', ' ', $pageParts[0]);

		if (!empty($pageDisplayName))
			$pageData['display_name'] = trim($pageDisplayName);
		else if (!empty($pageParts[1]))
			$pageData['display_name'] = trim($pageParts[1]);

		if (preg_match('/^:?(([^:]+):(.*))$/', trim($pageParts[0]), $matches))
		{
			// Get namespace and namespace ID
			$strNamespace = trim($matches[2]);
			$namespaceId = self::getNamespaceId($strNamespace);

			// Valid namespace
			if (!empty($namespaceId))
			{
				$namespace = new MWNamespace();
				$pageData['name']         = str_replace(' ', '_', trim($matches[3]));
				$pageData['namespace']    = $strNamespace;
				$pageData['namespace_id'] = $namespaceId;
				$pageData['full_name']    = $namespace->getCanonicalName($namespaceId) . ':' . trim($matches[3]); // Use canonical name since $strNamespace may be localized
			}
			// Invalid namespace ID: the colons are just part of the page name
			else
			{
				$pageData['name']         = str_replace(' ', '_', trim($matches[1]));
				$pageData['namespace']    = null;
				$pageData['namespace_id'] = 0;
				$pageData['full_name']    = trim($pageParts[0]);
			}
		}
		// No namespace
		else
		{
			$pageData['name']         = str_replace(' ', '_', trim($pageParts[0]));
			$pageData['namespace']    = null;
			$pageData['namespace_id'] = 0;
			$pageData['full_name']    = trim($pageParts[0]);
		}

		if (empty($pageData['display_name']))
			$pageData['display_name'] = str_replace('_', ' ', $pageData['name']);

		return $pageData;
	}

	/**
	 * Return the ID of the provided namespace name.
	 * Handles localized namespaces.
	 * @param string $namespaceName
	 * @return int
	 */
	public static function getNamespaceId($namespaceName)
	{
		global $wgContLang;

		if (!empty($wgContLang->mNamespaceIds))
			foreach($wgContLang->mNamespaceIds as $ns => $nsId)
				if (strtolower($ns) == strtolower($namespaceName))
					return $nsId;

		$namespace = new MWNamespace();
		return $namespace->getCanonicalIndex(strtolower($namespaceName));
	}

	/**
	 * Return the code for the page link
	 * @param array $page
	 * @return string
	 */
	public static function getPageLink($page)
	{
		// Invalid page
		if (empty($page) || empty($page['name']))
			return '';

		// Add prefixing semicolons for namespaced links
		$namespacePrefix = !empty($page['namespace_id'])?':':'';

		// Return link
		if ($page['full_name'] != str_replace(' ', '_', $page['display_name']))
			return '[[' . $namespacePrefix . $page['full_name'] . ' | ' . $page['display_name'] . ']]';
		else
			return '[[' . $namespacePrefix . $page['full_name'] . ']]';
	}

	/**
	 * Return the parent page's information, if exists.
	 * @param Parser $parser
	 * @param array  $page
	 * @return array
	 */
	public static function getParentPage(Parser $parser, $page)
	{
		// Find if parent is cached before to avoid expensive parsing of the parent page
		self::loadBreadcrumbCache();
		if (array_key_exists($page['full_name'], self::$breadcrumbCache))
			return self::$breadcrumbCache[$page['full_name']];

		// Load get parent page from DB
		$result = wfGetDB(DB_SLAVE)->select(
			array('revision', 'text', 'page'),
			'old_text',
			array(
				'page_title'     => $page['name'],
				'page_namespace' => $page['namespace_id']
			),
			__METHOD__,
			array('ORDER BY' => 'rev_id DESC LIMIT 1'),
			array(
				'text'     => array('LEFT JOIN', 'old_id = rev_text_id'),
				'revision' => array('LEFT JOIN', 'rev_page = page_id')
			)
		);

		// Page not found
		if (empty($result))
			return null;

		// Fetch page code
		$pageRow = wfGetDB(DB_SLAVE)->fetchRow($result);
		wfGetDB(DB_SLAVE)->freeResult($result);

		// Parse page code and get parent using static vars
		$parentParser = clone $parser;
		self::$parsingParent = true;
		self::$foundParentPage = null;
		$parentParser->parse($pageRow['old_text'], new Title(), new ParserOptions());
		self::$parsingParent = false;

		// Add parent page to cache
		self::$breadcrumbCache[$page['full_name']] = self::$foundParentPage;
		self::saveBreadcrumbCache();

		// Return found parent page
		return self::$foundParentPage;
	}

	/**
	 * The rendred HTML code of the breadcrumb
	 * @var string
	 */
	public static $breadcrumbHtml = null;

	/**
	 * Remove the processed breadcrumb HTML code and store it in self::$breadcrumbHtml
	 * for further use.
	 * @param Parser $parser
	 * @param string $text
	 * @return boolean
	 */
	public static function onParserBeforeTidy(Parser $parser, &$text)
	{
		// Breadcrumb tag found
		if (preg_match('@<div[^>]* id=[\'"]breadcrumb[\'"].+</div>@mUis', $text, $matches))
		{
			// Store it for further use
			self::$breadcrumbHtml = $matches[0];

			// Remove it from markup
			$text = str_replace($matches[0], '', $text);

			// Remove the resulting empty lines at the beginning and the end of the markup
			$text = preg_replace("@^(<p>(<br[^>]*>|[\r\n\t ]*)*</p>|(<br[^>]*>|[\r\n\t ]+))@i", '', $text);
			$text = preg_replace("@(<p>(<br[^>]*>|[\r\n\t ]*)*</p>|(<br[^>]*>|[\r\n\t ]+))$@i", '', $text);
		}

		return true;
	}

	/**
	 * Insert the previously rendered breadcrumb HTML code to the top of the page.
	 * @param OutputPage $out
	 * @param string     $text
	 * @return boolean
	 */
	public static function onOutputPageBeforeHtml(OutputPage $out, &$text )
	{
		// The method has been overriden
		if (!empty(self::$onOutputPageBeforeHtmlCallback) && call_user_func(self::$onOutputPageBeforeHtmlCallback, $out, $text))
			return true;

		// Add some style
		$out->addInlineStyle('#breadcrumb {position:relative; top:-15px; font-size:90%}');

		// Add breadcrumb code
		$out->prependHTML(self::$breadcrumbHtml);
		return true;
	}

	/**
	 * Occurs after the save page request has been processed.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 *
	 * @param WikiPage $article
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param boolean $isMinor
	 * @param boolean $isWatch
	 * @param $section Deprecated
	 * @param integer $flags
	 * @param {Revision|null} $revision
	 * @param Status $status
	 * @param integer $baseRevId
	 *
	 * @return boolean
	 */
	public static function onPageContentSaveComplete($article, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId)
	{
		// Remove the saved page from the cache to force it to reload from cache next time it's invoked
		self::loadBreadcrumbCache();
		$savedPage = self::getPageFromString($article->getTitle()->getPrefixedText());
		unset(self::$breadcrumbCache[$savedPage['full_name']]);
		self::saveBreadcrumbCache();
		return true;
	}

	/**
	 * Breadcrumb cache
	 * @var array
	 */
	protected static $breadcrumbCache = array();

	/**
	 * Load breadcrumb cache from file
	 * @global type $wgFileCacheDirectory
	 */
	protected static function loadBreadcrumbCache()
	{
		global $wgFileCacheDirectory;

		if (!empty(self::$breadcrumbCache))
			return;

		self::$breadcrumbCache = array();

		if (file_exists($wgFileCacheDirectory . '/BreadcrumbCache.php'))
			@include($wgFileCacheDirectory . '/BreadcrumbCache.php');

		if (!empty($breadcrumbCache) && is_array($breadcrumbCache))
			self::$breadcrumbCache = $breadcrumbCache;
	}

	/**
	 * Save breadcrumb cache file
	 * @global type $wgFileCacheDirectory
	 */
	protected static function saveBreadcrumbCache()
	{
		global $wgFileCacheDirectory;

		if (!file_exists($wgFileCacheDirectory))
			mkdir($wgFileCacheDirectory, 0775);

		file_put_contents($wgFileCacheDirectory . '/BreadcrumbCache.php', "<?php\n\$breadcrumbCache = " . var_export(self::$breadcrumbCache, true) . ";\n?>");
	}

	/**
	 * Render the breadcrumb using Wiki syntax
	 * should be a <div> tag having the id=breadcrumb
	 * @return string
	 */
	public static function render()
	{
		// The method has been overriden
		if (!empty(self::$renderCallback))
			return call_user_func(self::$renderCallback);

		// Amount of elements exceeded: add the overflow prefix
		if (self::$maxCount > 0 && count(self::$breadcrumb) > self::$maxCount)
		{
			if (self::$maxCount > 2)
				$breadcrumb = array_merge(
					array_slice(self::$breadcrumb, 0, 1),
					array(self::$overflowPrefix),
					array_slice(self::$breadcrumb, - self::$maxCount + 2, self::$maxCount - 2)
				);
			// No enough elements to use the prefix: just keep the last ones.
			else
				$breadcrumb = array_slice(self::$breadcrumb, - self::$maxCount, self::$maxCount);
		}
		else
			$breadcrumb = SimpleBreadCrumb::$breadcrumb;

		// Render breadcrumb code
		$breadcrumbCode = implode(SimpleBreadCrumb::$delimiter, $breadcrumb);
		return '<div id="breadcrumb">' . $breadcrumbCode . '</div>';
	}
}

?>
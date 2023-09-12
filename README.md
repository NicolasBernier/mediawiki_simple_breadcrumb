Mediawiki Simple Breadcrumb
===========================

A hieracherical breadcrumb extension for MediaWiki. Implements a {{#breadcrumb: }} tag to set a parent page for each page. The resulting breadcrumb is displayed under the page title.
Tested with MediaWiki version 1.39.4

Usage
-----

Just add a {{#breadcrumb: }} tag anywhere in your page to set its parent and its (the current page's) alias.

	{{#breadcrumb: Parent_Page | Alias }}

The tag can be used in templates and accepts variables.

	{{#breadcrumb: Category:Releases {{{product}}} | {{SUBPAGENAME}} }}

You should not add more than one breadcrumb tag in your page.


Installation
------------

Copy the files to a folder named "SimpleBreadcrumb" in your extensions directory. 
Edit LocalSettings.php to include the extension:

	// Simple Breadcrumb
	wfLoadExtension( 'SimpleBreadcrumb' );


Configuration
-------------

The extension offers these configuration variables that can be overriden in LocalSettings.php after the inclusion of the extension.

	$wgbcdelimiter            = ' &gt; ';     // Breadcrumb delimiter string
	$wgbcMaxCount             = 5;            // Maximum elements in breadcrumb
	$wgbcOverflowPrefix       = '&hellip;';   // Prefix when breadcrumb has more elements than fixed limit

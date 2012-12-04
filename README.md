Mediawiki Simple Breadcrumb
===========================

A hieracherical breadcrumb extension for MediaWiki. Implements a {{#breadcrumb: }} tag to set a parent page for each page. The resulting breadcrumb is displayed under the page title.
Tested with MediaWiki version 1.16.0

Usage
-----

Just add a {{#breadcrumb: }} tag anywhere in your page to set its parent.

	{{#breadcrumb: Parent_Page | Parent }}

The tag can be used in templates and accepts variables.

	{{#breadcrumb: Category:Releases {{{product}}} | {{{product}}} }}

You should not add more than one breadcrumb tag in your page.


Installation
------------

* Copy SimpleBreadcrumb.php and SimpleBreadcrumb.i18n.php in your extensions directory. 
* Edit LocalSettings.php to include the extension :

	// Simple Breadcrumb

	require_once('extensions/SimpleBreadcrumb.php');


Configuration
-------------

The extension offers a bunch of configuration variables that can be overriden in LocalSettings.php after the inclusion of the extension.

	SimpleBreadCrumb::$fillTagOnNewPage               = true;         // Fill the breadcrumb tag when editing a new page
	SimpleBreadCrumb::$selfLink                       = true;         // Enable link on the current active page in the breadcrumb
	SimpleBreadCrumb::$delimiter                      = ' &gt; ';     // Breadcrumb delimiter string
	SimpleBreadCrumb::$maxCount                       = 5;            // Maximum elements in breadcrumb
	SimpleBreadCrumb::$overflowPrefix                 = '[&hellip;]'; // Prefix when breadcrumb has more elements than fixed limit
	SimpleBreadCrumb::$onOutputPageBeforeHtmlCallback = null;         // Callback function to override the SimpleBreadCrumb::onOutputPageBeforeHtml() hook
	SimpleBreadCrumb::$renderCallback                 = null;         // Callback function to override the SimpleBreadCrumb::render() method

Do not call SimpleBreadCrumb::onOutputPageBeforeHtml() and SimpleBreadCrumb::render() within the callbacks to avoid infinite recursion. Refer to SimpleBreadcrumb.php to see how it's called.

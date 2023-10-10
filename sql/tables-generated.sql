-- Create the simple_breadcrumb_cache table
CREATE TABLE /*_*/simple_breadcrumb_cache (
    page_ID int(8) NOT NULL PRIMARY KEY,
	page_title TEXT NOT NULL,
    link TEXT,
    alias TEXT,
    parent_title TEXT
) /*$wgDBTableOptions*/;

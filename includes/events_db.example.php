<?php
/**
 * DCW Engage - credentials for the dcwwiki.org (MediaWiki / Cargo) database.
 *
 * SETUP: copy this file to includes/events_db.php and fill in the real values.
 * events_db.php must be listed in .gitignore. NEVER commit it.
 *
 * Used read-only by includes/dcw_events.php for the "What's on" section on the home page.
 * Give this login SELECT permission ONLY. On Hostinger (hPanel > Databases > MySQL Databases) create a NEW
 * user, attach it to the wiki database, and in "Change MySQL user permissions" tick Select and nothing else.
 * Create the view first: run docs/wiki_events_view.sql once on the wiki database.
 * If the database is not reachable, the "What's on" section is simply left out.
 */
return [
    'host'  => 'localhost',
    'name'  => 'your_wiki_database',
    'user'  => 'readonly_user',
    'pass'  => 'CHANGE_ME',
    // 'port'  => '3306',                 // optional
    // 'view'  => 'engage_events_v',      // optional: this is the default
];

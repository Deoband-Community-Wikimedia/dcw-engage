<?php
/**
 * DCW Engage - settings for the "What's on" events section.
 *
 * Events are read from the wiki's public API (https://dcwwiki.org/api.php, Cargo table "events").
 * NO DATABASE LOGIN is used and nothing on dcwwiki.org is changed, so there are no secrets in this file.
 *
 * Everything here is OPTIONAL. With the defaults, you do not need includes/events_db.php at all.
 * Copy this file to includes/events_db.php only if you need to change a value.
 * (Keeping events_db.php in .gitignore, like certs_db.php, is still a good habit.)
 *
 * If the wiki can't be reached, the "What's on" section is simply left out.
 */
return [
    // 'api'   => 'https://dcwwiki.org/api.php',   // use https://dcwwiki.org/w/api.php if your API lives under /w/
    // 'table' => 'events',                        // the Cargo table name, as listed on Special:CargoTables
];

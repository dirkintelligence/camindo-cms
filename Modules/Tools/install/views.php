<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
return [
    'index_server.php' => [
        'description' => 'Tools, tab Server: server and PHP information.',
    ],
    'index_data.php' => [
        'description' => 'Tools, tab Data: rebuild thumbnails, media protection and normalize trees - each tool runs only after its own button.',
    ],
    'index_database.php' => [
        'description' => 'Tools, tab Database: database information, empty the trash permanently, discard stale working copies - each tool runs only after its own button.',
    ],
    'index_trash_user.php' => [
        'description' => 'Tools, tab Trash of a user: permanently empty the trash of one website user (profile and his contents) - runs only after its own button.',
    ],
    'index_developer.php' => [
        'description' => 'Tools, tab Developer: rebuild caches (snippets, HTML templates, templates, handbook index) and session data - each tool runs only after its own button.',
    ],
    'tool_db_info.php' => [
        'description' => 'MySQL server information (version, variables, table sizes).',
    ],
    'tool_phpinfo.php' => [
        'description' => 'PHP information (phpinfo) of the server.',
    ],
];

<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'tools',
'label' => '#tools.module_label',
'description' => '#tools.module_description',
'icon' => 'build',
'listed' => true,
// Zusatzrechte (Backend-Benutzergruppen): interner Name => Snippet der Beschriftung,
// ein Recht je Reiter (ToolsBackend::$tabs). Siehe docs/developers/12-group-rights.md.
'access' => [
    'server' => 'tools.access_server',
    'database' => 'tools.access_database',
    'data' => 'tools.access_data',
    'developer' => 'tools.access_developer',
    // Papierkorb eines Frontend-Users sofort endgültig leeren (Datenschutz)
    'trash_user' => 'tools.access_trash_user',
],
// Einstiege des Moduls in Reiter-Reihenfolge: die Modulkachel der Startseite
// öffnet den ersten, den die Gruppe laut 'script_access' darf (StartBackend)
'entries' => ['index.php', 'index_database.php', 'index_data.php', 'index_developer.php', 'index_trash_user.php'],
// Welches Zusatzrecht ein Einstiegsscript verlangt (Access::script(), geprüft in
// Backend::backendPath())
'script_access' => [
    'index.php' => 'server',
    'index_server.php' => 'server',
    'tool_db_info.php' => 'server',
    'tool_phpinfo.php' => 'server',
    'phpinfo.php' => 'server',
    'index_database.php' => 'database',
    'tool_delete.php' => 'database',
    'tool_tmp_delete.php' => 'database',
    'index_data.php' => 'data',
    'tool_thumbnails.php' => 'data',
    'tool_tree_normalize.php' => 'data',
    'index_developer.php' => 'developer',
    'tool_snippets.php' => 'developer',
    'tool_html.php' => 'developer',
    'tool_instances.php' => 'developer',
    'tool_media.php' => 'developer',
    'tool_docs.php' => 'developer',
    'tool_templates.php' => 'developer',
    'tool_info.php' => 'developer',
    'index_trash_user.php' => 'trash_user',
    'tool_trash_user.php' => 'trash_user',
]

];
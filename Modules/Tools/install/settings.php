<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'tools',
'label' => '#tools.module_label',
'description' => '#tools.module_description',
'icon' => 'build',
'listed' => true,
 // Additional rights (backend user groups): internal name => snippet of the label,
 // one right per tab (ToolsBackend::$tabs). See developer docs, chapter 11 (Permissions).
'access' => [
    'server' => 'tools.access_server',
    'database' => 'tools.access_database',
    'data' => 'tools.access_data',
    'developer' => 'tools.access_developer',
    // Empty a frontend user's trash immediately and permanently (privacy)
    'trash_user' => 'tools.access_trash_user',
],
 // Entries of the module in tab order: the module tile on the start page
 // opens the first one that the group may open per 'script_access' (StartBackend)
'entries' => ['index.php', 'index_database.php', 'index_data.php', 'index_developer.php', 'index_trash_user.php'],
 // Which additional right an entry script requires (Access::script(), checked in
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
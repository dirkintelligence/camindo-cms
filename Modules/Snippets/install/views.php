<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'index.php' => [
        'description' => 'Snippets: the text sets of the instances (UI texts of the websites in all languages).',
    ],
    'set_table.php' => [
        'description' => 'All snippets of one instance as a table with a language selection; search, add, edit.',
        'params'      => ['instance_id' => 'int'],
    ],
    'snippet_edit.php' => [
        'description' => 'Edit one snippet of an instance in all its languages (key; without key: new snippet).',
        'params'      => ['instance_id' => 'int', 'key' => 'string'],
    ],
];

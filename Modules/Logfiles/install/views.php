<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'index.php' => [
        'description' => 'Log files in var/log: search entries by file name, date, category (e.g. security, php errors, database errors, mail) and text.',
        'params'      => ['name' => 'string', 'date' => 'string', 'category' => 'string', 'search' => 'string'],
    ],
];

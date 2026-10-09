<?php
 // Views of the module that a tool may open (Core\BackendViews, e.g. the
 // assistant of the module Ai with ui__open): script => description (English, for the
 // language model) and allowed parameters ('int', 'string' or list of allowed values).
 // Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'instance_list.php' => [
        'description' => 'Instances: the websites (domains, languages, settings) managed in this installation.',
    ],
    'instance_info.php' => [
        'description' => 'Detail view of an instance: domains, languages, start page, security headers, settings.',
        'params'      => ['id' => 'int'],
    ],
    'instance_edit.php' => [
        'description' => 'Edit form of an instance. function=create: new instance; copy / delete with id.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
];

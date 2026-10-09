<?php
// Views of the module that may open a tool (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'template_areas.php' => [
        'description' => 'Templates: tree of the template areas per instance (page templates, includes, e-mails, trigger templates …).',
    ],
    'template_list.php' => [
        'description' => 'Templates of a template area.',
        'params'      => ['area_id' => 'int'],
    ],
    'template_search.php' => [
        'description' => 'Search form for templates of an area.',
        'params'      => ['area_id' => 'int'],
    ],
    'template_global_search.php' => [
        'description' => 'Search in all templates (name and code).',
    ],
    'template_info.php' => [
        'description' => 'Detail view of a template: type, area, settings, where it is used.',
        'params'      => ['area_id' => 'int', 'id' => 'int'],
    ],
    'template_edit.php' => [
        'description' => 'Code editor of a template (CMS commands, HTML, PHP). function=create with area_id: new template; copy / delete with id.',
        'params'      => ['area_id' => 'int', 'id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
    'template_access.php' => [
        'description' => 'Which user groups may edit this template.',
        'params'      => ['id' => 'int'],
    ],
];

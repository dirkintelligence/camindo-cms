<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
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

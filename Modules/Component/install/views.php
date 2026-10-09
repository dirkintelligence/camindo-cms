<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of the module Ai with ui__open): script => description (in English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'component_list.php' => [
        'description' => 'Installed and available components: modules, processors (converters for images, video …) or extensions (field extensions such as editors), with version and status.',
        'params'      => ['type' => ['modules', 'processors', 'extensions']],
    ],
    'component_info.php' => [
        'description' => 'Detail view of a component: version, description, requirements, settings.',
        'params'      => ['type' => ['modules', 'processors', 'extensions'], 'name' => 'string'],
    ],
];

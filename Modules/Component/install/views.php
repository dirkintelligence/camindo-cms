<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
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

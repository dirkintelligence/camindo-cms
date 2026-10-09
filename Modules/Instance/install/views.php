<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews, z. B. der
// Assistent des Moduls Ai mit ui__open): Script => Beschreibung (englisch, für das
// Sprachmodell) und erlaubte Parameter ('int', 'string' oder Liste erlaubter Werte).
// Nur Scripte, die beim Öffnen nichts ändern - keine Aktionen, keine ajax-Scripte.
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

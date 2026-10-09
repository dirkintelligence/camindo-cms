<?php
// Ansichten des Moduls, die ein Werkzeug öffnen darf (Core\BackendViews): Script =>
// Beschreibung (englisch, für das Sprachmodell) und erlaubte Parameter.
// Nur Scripte, die beim Öffnen nichts ändern.
return [
    'policy_list.php' => [
        'description' => 'Privacy: delete policies of the content areas (what happens when a record is deleted, after how long).',
    ],
    'policy_info.php' => [
        'description' => 'Info page of a delete policy: label, instance, categories, areas using it, blocks.',
        'params'      => ['id' => 'int'],
    ],
    'policy_edit.php' => [
        'description' => 'Edit form of a delete policy: label, instance, blocks of trigger source, delay, action and category. Without id: new policy.',
        'params'      => ['id' => 'int'],
    ],
    'template_list.php' => [
        'description' => 'Privacy: templates of the personal data report, separately for website users and users, one per instance and language.',
    ],
    'template_edit.php' => [
        'description' => 'Edit form of a personal data report template (HTML with placeholders). Without id: choose kind (user = website users, admin = users) and instance; scope (profile area or user group, 0 = all) and language follow in the form.',
        'params'      => ['id' => 'int', 'kind' => ['user', 'admin'], 'instance_id' => 'int'],
    ],
    'export.php' => [
        'description' => 'Export of personal data of a website user (profile record) or a backend user. kind=user|admin, id selects the person.',
        'params'      => ['kind' => ['user', 'admin'], 'id' => 'int'],
    ],
];

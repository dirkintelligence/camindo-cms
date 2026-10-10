<?php
// Views of the module that may open a tool (Core\BackendViews): script =>
// description (English, for the language model) and allowed parameters.
// Only scripts that change nothing when opened.
return [
    'policy_list.php' => [
        'description' => 'Privacy: deletion policies of the content areas (what happens when a record is deleted, after how long).',
    ],
    'policy_info.php' => [
        'description' => 'Info page of a deletion policy: label, instance, categories, areas using it, blocks.',
        'params'      => ['id' => 'int'],
    ],
    'policy_edit.php' => [
        'description' => 'Edit form of a deletion policy: label, instance, blocks of trigger source, delay, action and category. Without id: new policy.',
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

<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'template',
'label' => '#template.module_label',
'description' => '#template.module_description',
'icon' => 'extension',
'listed' => true,
// API: read and create/change (TemplateApi)
'api' => ['TemplateApi'],
// Start page widget: parser messages of the templates and the note on CMS:NONCE
// (Camindo\Core\Widgets, TemplateBackend::widget())
'widget' => [
    'class'  => 'TemplateBackend',
    'method' => 'widget',
    'label'  => '#template.widget_label',
    'icon'   => 'data_object',
],
// Scripts that other modules may call as lookup without module access (Access::script())
'shared' => ['template_list.php', 'template_search.php'],
// Additional module rights:
// none
'access' => [
]

];
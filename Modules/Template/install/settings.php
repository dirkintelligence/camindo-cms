<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'template',
'label' => '#template.module_label',
'description' => '#template.module_description',
'icon' => 'extension',
'listed' => true,
// API: lesen und anlegen/ändern (TemplateApi)
'api' => ['TemplateApi'],
// Startseiten-Widget: Parser-Meldungen der Templates und der Hinweis auf CMS:NONCE
// (Camindo\Core\Widgets, TemplateBackend::widget())
'widget' => [
    'class'  => 'TemplateBackend',
    'method' => 'widget',
    'label'  => '#template.widget_label',
    'icon'   => 'data_object',
],
// Scripte, die andere Module ohne Modulzugriff als Lookup aufrufen dürfen (Access::script())
'shared' => ['template_list.php', 'template_search.php'],
// Zusätzliche Modulrechte:
// keine
'access' => [
]

];
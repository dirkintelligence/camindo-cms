<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'area',
'label' => '#area.module_label',
'description' => '#area.module_description',
'icon' => 'transition_dissolve',
'listed' => true,
// Additional module rights:
// none (superuser-only module)
// API classes (Camindo\Core\Api\Api, relative to the module namespace)
'api' => ['AreaApi', 'AreaAdminApi', 'FieldApi'],
'access' => [
]

];
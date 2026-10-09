<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'area',
'label' => '#area.module_label',
'description' => '#area.module_description',
'icon' => 'transition_dissolve',
'listed' => true,
// Zusätzliche Modulrechte:
// keine (reines Superuser-Modul)
// API-Klassen (Camindo\Core\Api\Api, relativ zum Modul-Namespace)
'api' => ['AreaApi', 'AreaAdminApi', 'FieldApi'],
'access' => [
]

];
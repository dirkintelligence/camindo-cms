<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'instance',
'label' => '#instance.module_label',
'description' => '#instance.module_description',
'icon' => 'language',
'listed' => true,
// API: lesen und anlegen/ändern (InstanceApi)
'api' => ['InstanceApi'],
// Zusätzliche Modulrechte:
// keine (reines Superuser-Modul)
'access' => [
]

];
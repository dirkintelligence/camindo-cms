<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'status',
'label' => '#status.module_label',
'description' => '#status.module_description',
'icon' => 'check',
'listed' => true,
// API: lesen und anlegen/ändern (StatusApi)
'api' => ['StatusApi'],
// Zusätzliche Modulrechte:
// keine (reines Superuser-Modul)
'access' => [
]

];
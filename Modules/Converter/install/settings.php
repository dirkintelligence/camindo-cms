<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'converter',
'label' => '#converter.module_label',
'description' => '#converter.module_description',
'icon' => 'manufacturing',
'listed' => true,
// API: lesen und anlegen/ändern (ConverterApi)
'api' => ['ConverterApi'],
// Zusätzliche Modulrechte:
// keine (reines Superuser-Modul)
'access' => [
]

];
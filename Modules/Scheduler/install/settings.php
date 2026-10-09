<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'scheduler',
'label' => '#scheduler.module_label',
'description' => '#scheduler.module_description',
'icon' => 'schedule',
'listed' => true,
// API: lesen und anlegen/ändern (SchedulerApi)
'api' => ['SchedulerApi'],
// Zusätzliche Modulrechte:
// keine (reines Superuser-Modul)
'access' => [
]

];

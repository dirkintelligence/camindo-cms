<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'scheduler',
'label' => '#scheduler.module_label',
'description' => '#scheduler.module_description',
'icon' => 'schedule',
'listed' => true,
 // API: read and create/change (SchedulerApi)
'api' => ['SchedulerApi'],
 // Additional module rights:
 // none (superuser-only module)
'access' => [
]

];

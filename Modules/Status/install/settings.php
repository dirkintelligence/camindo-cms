<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'status',
'label' => '#status.module_label',
'description' => '#status.module_description',
'icon' => 'check',
'listed' => true,
// API: read and create/change (StatusApi)
'api' => ['StatusApi'],
// Additional module permissions:
// none (purely superuser module)
'access' => [
]

];
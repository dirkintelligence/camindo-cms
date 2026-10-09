<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'instance',
'label' => '#instance.module_label',
'description' => '#instance.module_description',
'icon' => 'language',
'listed' => true,
 // API: read and create/change (InstanceApi)
'api' => ['InstanceApi'],
 // Additional module rights:
 // none (superuser-only module)
'access' => [
]

];
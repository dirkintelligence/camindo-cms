<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'converter',
'label' => '#converter.module_label',
'description' => '#converter.module_description',
'icon' => 'manufacturing',
'listed' => true,
// API: read and create/change (ConverterApi)
'api' => ['ConverterApi'],
// Additional module rights:
// none (superuser-only module)
'access' => [
]

];
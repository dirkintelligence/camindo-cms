<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'language',
'label' => '#language.module_label',
'description' => '#language.module_description',
'icon' => 'flag',
'listed' => true,
// Only superusers: structure of the whole system (Access::module())
'superuser' => true,
// API: read and create/change languages (LanguageApi)
'api' => ['LanguageApi'],
// Additional module permissions:
// none (purely superuser module)
'access' => [
]

];
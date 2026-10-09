<?php
// Core extension Password: password policy as a tooltip and strength bar
// (red/orange/green) below a password field. The rules come from
// security.password_policy, can be overridden per field; additionally checked on the
// server (Camindo\Core\PasswordPolicy, developer docs, chapter 13 (Security: authentication)).
// password.ext.js/.css also run without the backend - inlined in the frontend template
// (see the manual for template programmers).
return [
    'version'          => '9.0.0',
    'type'             => 'validator',
    'label'            => '#ext.password.label',
    'description'      => '#ext.password.description',
    'display_types'    => ['password'],
    'field_types'      => ['password'],
    'options_provider' => 'Camindo\\Core\\PasswordPolicy',
    'assets' => [
        'js'  => ['js/password.ext.js'],
        'css' => ['css/password.ext.css'],
    ],
    'requires' => ['core' => '^9.0'],
];

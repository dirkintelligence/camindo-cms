<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of module Ai with ui__open): script => description (English, for the
// language model) and allowed parameters ('int', 'string' or a list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'admin_list.php' => [
        'description' => 'List of all backend administrators with user group and status; starting point to create or open an administrator.',
    ],
    'admin_search.php' => [
        'description' => 'Search form for administrators.',
    ],
    'admin_info.php' => [
        'description' => 'Detail view of an administrator: account data, user group, second factor, devices and sessions.',
        'params'      => ['id' => 'int'],
    ],
    'admin_edit.php' => [
        'description' => 'Edit form of an administrator. function=create: empty form for a new administrator; copy: form prefilled from id; delete: confirmation to delete id.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
    'admin_audit.php' => [
        'description' => 'Changes (audit log) concerning one administrator account.',
        'params'      => ['id' => 'int'],
    ],
    'group_list.php' => [
        'description' => 'List of user groups (Superuser, Editor …); rights are managed per group.',
    ],
    'group_info.php' => [
        'description' => 'Detail view of a user group.',
        'params'      => ['id' => 'int'],
    ],
    'group_edit.php' => [
        'description' => 'Edit form of a user group (name, settings). function=create/copy/delete as in admin_edit.',
        'params'      => ['id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
    'group_access.php' => [
        'description' => 'Rights of a user group: which modules and additional rights it has, which instances, areas and languages it may read or write. function=edit opens the rights form.',
        'params'      => ['id' => 'int', 'function' => ['edit']],
    ],
    'settings.php' => [
        'description' => 'Personal settings of the logged-in administrator: backend language, date/number format, time zone, preferred content language, front-end editing, start page widgets, second factor. Superuser with id: settings of another administrator; function=messages_delete_form deletes all internal messages of that administrator after confirmation.',
        'params'      => ['id' => 'int'],
    ],
    'password.php' => [
        'description' => 'Form to change the own password.',
    ],
    'totp.php' => [
        'description' => 'Two-factor sign-in of the own account: authenticator app, passkeys, recovery codes. function=setup starts setting up the app.',
        'params'      => ['function' => ['setup']],
    ],
    'devices.php' => [
        'description' => 'Known devices and active sessions of the own account (superuser with id: of another administrator); sign out devices.',
        'params'      => ['id' => 'int'],
    ],
];

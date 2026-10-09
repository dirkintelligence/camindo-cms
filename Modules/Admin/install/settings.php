<?php
// module settings
return [

'version' => '9.0.0',
'type' => 'admin',
'label' => '#admin.module_label',
'description' => '#admin.module_description',
'icon' => 'person',
'listed' => true,
// Scripts that other modules may call as lookups without module access (Access::script())
'shared' => ['admin_list.php', 'admin_search.php', 'ajax_lookup_preview.php'],
// Scripts that every admin may call without module access: own settings,
// password, two-factor login, devices and sessions (Access::script(); foreign
// accounts only as superuser)
'personal' => ['settings.php', 'totp.php', 'password.php', 'devices.php'],
// Additional module rights:
//     Admin names are always readable, e.g. record created by ... on ...
//     Whether the admin details (contact data) may be read is decided by admin_read*
//     Create (admin_create) and change/block (admin_change) apply to admins of the
//     own group; the group cannot be changed in the process. The password of another
//     admin is set only by a superuser (or the initial password when creating).
//     The rest is reserved for the superuser.
'access' => [
    'admin_read_group' => 'admin.access_admin_read_group',    // from own group
    'admin_read_other' => 'admin.access_admin_read_other',    // from other group
    'admin_create' => 'admin.access_admin_create',            // anlegen, kopieren (eigene Gruppe)
    'admin_change' => 'admin.access_admin_change',            // change, block (own group)
]

];
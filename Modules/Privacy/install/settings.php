<?php
// Core module Privacy (data protection): manage deletion policies of the content areas and
// export personal data of website users and users, deleted
// Benutzer anonymisieren.
// Without an additional right the group only sees the policies; superusers may do everything.
return [

'version' => '9.1.1',
'type' => 'privacy',
'label' => '#privacy.module_label',
'description' => '#privacy.module_description',
'icon' => 'shield',
'listed' => true,
// Additional rights (backend user groups): internal name => snippet of the label.
'access' => [
    // Create, change, delete policies - only for instances the group may edit
    // may; policies for all instances (default policies) only the superuser
    'policy_edit' => 'privacy.access_policy_edit',
    // Data export of personal data: website users from readable profile areas,
    // users from groups whose users the group may see in the module Users
    'export' => 'privacy.access_export',
    // Create, change, delete templates of the data disclosure - only for instances that
    // the group may edit
    'template_edit' => 'privacy.access_template_edit',
    // Anonymize deleted users (tab "Deleted users", AdminAnonymizer) -
    // only users from groups that the group may see in the module Users
    'admin_anonymize' => 'privacy.access_admin_anonymize',
],
// Tabs that require an additional right (Access::script())
'script_access' => [
    'export.php' => 'export',
    'export_download.php' => 'export',
    'admin_anonymize.php' => 'admin_anonymize',
],

];

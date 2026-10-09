<?php
// Views of the module that a tool may open (Core\BackendViews, e.g. the
// assistant of module Ai with ui__open): script => description (English, for the
// language model) and allowed parameters ('int', 'string' or list of allowed values).
// Only scripts that change nothing when opened - no actions, no ajax scripts.
return [
    'area_list.php' => [
        'description' => 'Structure: tree of all content areas and folders per instance (website), with area type. Starting point of area management: create areas, open an area, its fields and rights.',
    ],
    'area_search.php' => [
        'description' => 'Search form for areas.',
    ],
    'area_create.php' => [
        'description' => 'First step of creating a new area: choice of the area type (records, profile, user content …) and of blueprints (prepared areas with fields).',
    ],
    'area_info.php' => [
        'description' => 'Detail view of an area: type, instance, languages, default and sort field, tree, slug prefix, trigger template, links to fields and rights. tab=audit: changes.',
        'params'      => ['id' => 'int', 'tab' => ['audit']],
    ],
    'area_edit.php' => [
        'description' => 'Edit form of the settings of an area. function=copy: new area prefilled from id; delete: confirmation to delete the area. (A new area starts with area_create.php.)',
        'params'      => ['id' => 'int', 'function' => ['copy', 'delete']],
    ],
    'area_access.php' => [
        'description' => 'Which user groups may read, create, change and delete records in this area. function=edit opens the form.',
        'params'      => ['id' => 'int', 'function' => ['edit']],
    ],
    'field_list.php' => [
        'description' => 'Input fields of an area in their order, with name, label and type; add, sort and open fields here.',
        'params'      => ['area_id' => 'int'],
    ],
    'field_create.php' => [
        'description' => 'First step of a new input field in an area: choice of the field type.',
        'params'      => ['area_id' => 'int'],
    ],
    'field_info.php' => [
        'description' => 'Detail view of an input field: type, name, label, multilingual, required, limits, options.',
        'params'      => ['id' => 'int'],
    ],
    'field_edit.php' => [
        'description' => 'Edit form of an input field. function=create with area_id: new field; copy / delete with id.',
        'params'      => ['id' => 'int', 'area_id' => 'int', 'function' => ['create', 'copy', 'delete']],
    ],
    'field_convert.php' => [
        'description' => 'Change the type or the multilingualism of an existing input field: choice of the possible targets (with a warning where content may be lost), then a confirmation.',
        'params'      => ['id' => 'int'],
    ],
];

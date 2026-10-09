<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Record\RecordBackendFolderList;

$recordBackendFolderList = $app->get(RecordBackendFolderList::class);
$recordBackendFolderList->folderList();

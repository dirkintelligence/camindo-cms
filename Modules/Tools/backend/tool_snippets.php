<?php
/** 
 * We are inside the IIFE of module.php
 * @var Camindo\Core\Config $config
 * @var Camindo\Core\Database $db
 * @var Camindo\Core\System $system
 * @var Camindo\Core\Crypt $crypt
 * @var Camindo\Core\Cms $cms
 * @var Camindo\Core\Backend $backend
 * @var Camindo\Core\Session $session
 */

use Camindo\Core\Modules\Tools\ToolSnippets;

// Rebuilds files - POST only (developer docs, chapter 14 (Security: web))
if (!$app->get(Camindo\Core\Backend::class)->requirePost()) $app->get(Camindo\Core\Access::class)->denied();

$tool = $app->get(ToolSnippets::class);
$tool->buildSnippetCache();

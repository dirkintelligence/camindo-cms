<?php
/** 
 * Wir befinden uns innerhalb der IIFE der module.php
 * @var Camindo\Core\Config $config
 * @var Camindo\Core\Database $db
 * @var Camindo\Core\System $system
 * @var Camindo\Core\Crypt $crypt
 * @var Camindo\Core\Cms $cms
 * @var Camindo\Core\Backend $backend
 * @var Camindo\Core\Session $session
 */

use Camindo\Core\Modules\Tools\ToolSnippets;

$tool = $app->get(ToolSnippets::class);
$tool->buildSnippetCache();

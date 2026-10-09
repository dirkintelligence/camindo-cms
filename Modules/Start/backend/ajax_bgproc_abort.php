<?php
// @var Camindo\Core\ContainerInterface $app
use Camindo\Core\Modules\Start\StartBgproc;

$startBgproc = $app->get(StartBgproc::class);
$startBgproc->bgprocAbort();

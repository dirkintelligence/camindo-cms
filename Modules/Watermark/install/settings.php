<?php
// Core module Watermark: check images in the backend for the invisible watermark of this
// installation (Camindo\Core\Watermark, key of this installation). Embedding itself is
// part of the media processing (instance code, field switch). Anyone whose group has the
// module may check images; instance names are only shown for instances the user has
// access to. The page only reads the uploaded file and runs no other program than
// paths.watermark, so no 'superuser' lock is needed.
return [

'version' => '9.2.0',
'type' => 'watermark',
'label' => '#watermark.module_label',
'description' => '#watermark.module_description',
'icon' => 'fingerprint',
'listed' => true,
'access' => [
]

];

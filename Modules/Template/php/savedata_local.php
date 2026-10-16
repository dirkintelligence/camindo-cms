<?php
if (is_string($_r) && $_r !== '' && $_r !== 'this' && $_r !== 'GLOBALS' && $_r[0] !== '_') { ${$_r} = &$GLOBALS[$_r]; }

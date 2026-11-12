<?php
// @var Camindo\Core\ContainerInterface $app
// phpinfo() with masked secrets: the cookie header and the session id would let a
// script in the browser read the HttpOnly session cookie (fetch on this page), and
// the environment may carry passwords and keys (CAMINDO_*, database, SMTP, API).
use Camindo\Core\Session;

ob_start();
phpinfo();
$html = (string)ob_get_clean();

$secret = '/(COOKIE|SESSID|PASS|SECRET|KEY|TOKEN|AUTH|CAMINDO_)/i';
$html = (string)preg_replace_callback(
    '~(<tr><td class="e">)([^<]*)(</td><td class="v">)(.*?)(</td>)~s',
    fn($m) => preg_match($secret, $m[2]) ? $m[1] . $m[2] . $m[3] . '<i>***</i>' . $m[5] : $m[0],
    $html
);
$sessionId = (string)$app->get(Session::class)->get('session_id');
if ($sessionId !== '') $html = str_replace($sessionId, '***', $html);
echo $html;

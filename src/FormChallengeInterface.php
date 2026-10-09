<?php
/**
 * External captcha (Cloudflare Turnstile, Friendly Captcha, hCaptcha …) for
 * forms with formguard=true - configured via security.form_challenge.
 *
 * The widget includes the script itself (the provider's script; with an active
 * script policy via CMS:CSP script=…). The core only checks the response:
 *
 *     class Turnstile implements \Camindo\Core\FormChallengeInterface {
 *         public function verify(array $request): bool {
 *             $token = (string)($request['cf-turnstile-response'] ?? '');
 *             // POST to https://challenges.cloudflare.com/turnstile/v0/siteverify …
 *         }
 *     }
 *
 * @see Camindo\Core\FormGuard
 */
namespace Camindo\Core;

interface FormChallengeInterface
{
    /**
     * @param array $request  $_POST of the submitted form
     * @return bool           true = human
     */
    public function verify(array $request): bool;
}

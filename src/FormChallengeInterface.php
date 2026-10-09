<?php
/**
 * Externes Captcha (Cloudflare Turnstile, Friendly Captcha, hCaptcha …) für
 * Formulare mit formguard=true - eingestellt über security.form_challenge.
 *
 * Das Widget bindet das Template selbst ein (Script des Anbieters, bei aktiver
 * Script-Policy über CMS:CSP script=…); der Core prüft nur die Antwort:
 *
 *     class Turnstile implements \Camindo\Core\FormChallengeInterface {
 *         public function verify(array $request): bool {
 *             $token = (string)($request['cf-turnstile-response'] ?? '');
 *             // POST an https://challenges.cloudflare.com/turnstile/v0/siteverify …
 *         }
 *     }
 *
 * @see Camindo\Core\FormGuard
 */
namespace Camindo\Core;

interface FormChallengeInterface
{
    /**
     * @param array $request  $_POST des abgeschickten Formulars
     * @return bool           true = Mensch
     */
    public function verify(array $request): bool;
}

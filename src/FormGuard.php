<?php
/**
 * Form protection without an external service: decoy field and signed timestamp.
 * Developer docs, chapter 14 (Security: web) (form guard).
 *
 * (CMS:USER_TOKEN formguard=true) outputs next to the CSRF token:
 *
 *     _ft      "{Unix time}.{HMAC}" - HMAC over the time and the random value of the cookie
 *              'ucsrf' (UserSession::csrfSeed()); a robot can neither forge it
 *              nor take it over from another browser
 *     c9_url   a text field that humans do not see (off-screen,
 *              aria-hidden, tabindex -1) and therefore leave empty; robots that fill
 *              every field give themselves away
 *
 * Commands with formguard=true (USER_REGISTER, USER_PW, CMS:EMAIL) then require:
 * decoy field empty, timestamp genuine, older than user.formguard_min_seconds and
 * younger than user.formguard_max_seconds. formguard=N (number) sets the minimum time
 * for this form to N seconds (0 = protection off). If this fails, they respond as with
 * throttling - a robot should not learn what the cause was.
 *
 * Anyone who knows the source code can get past this (camindo is open source). It is
 * meant against robots that blindly fill every form; for more, a captcha can be
 * connected via security.form_challenge (FormChallengeInterface).
 *
 * @see Camindo\Core\Modules\Record\RecordUser::cmsToken()
 */
namespace Camindo\Core;

class FormGuard
{
    public const TIME_FIELD = '_ft';
    public const TRAP_FIELD = 'c9_url';

    public function __construct(
        private Config $config,
        private Crypt $crypt,
        private UserSession $userSession,
        private System $system,
        private ContainerInterface $container,
    ) {}

    /**
     * The two fields as HTML - '' without cookie (call after the first output
     * without a preceding USER_TOKEN).
     */
    public function fields(): string
    {
        $seed = $this->userSession->csrfSeed(true);
        if ($seed === '') return '';
        $time = (string)time();
        $value = $time . '.' . $this->sign($time, $seed);
        return '<input type="hidden" name="' . self::TIME_FIELD . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '">'
            . '<div class="c9-hp" aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">'
            . '<label>Website <input type="text" name="' . self::TRAP_FIELD . '" value="" tabindex="-1" autocomplete="off"></label></div>';
    }

    /**
     * Was the submitted form sent by a human? Also checks the captcha from
     * security.form_challenge, if one is configured.
     */
    public function verify(?int $minSeconds = null): bool
    {
        $reason = $this->failure($minSeconds);
        if ($reason !== '') {
            $this->system->log('security', 'Form guard rejected request (' . $reason . '): ' . (string)($_SERVER['REQUEST_URI'] ?? ''));
            return false;
        }
        return true;
    }

    /**
     * formguard parameter of a command: false = off, true = defaults, number =
     * minimum time in seconds.
     *
     * @return int|false|null  false = off, null = default, otherwise seconds
     */
    public static function parameter(mixed $value): int|false|null
    {
        $value = trim((string)$value);
        if ($value === '' || in_array(strtolower($value), ['false', '0', 'no', 'off'], true)) return false;
        if (ctype_digit($value)) return (int)$value;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? null : false;
    }

    private function failure(?int $minSeconds): string
    {
        $trap = $_POST[self::TRAP_FIELD] ?? '';
        if (!is_string($trap) || $trap !== '') return 'trap';

        $value = $_POST[self::TIME_FIELD] ?? '';
        if (!is_string($value) || !preg_match('/^(\d{1,12})\.([A-Za-z0-9_\-]{20,})$/', $value, $m)) return 'time missing';
        $seed = $this->userSession->csrfSeed();
        if ($seed === '' || !hash_equals($this->sign($m[1], $seed), $m[2])) return 'time forged';
        $age = time() - (int)$m[1];
        $min = max(0, $minSeconds ?? (int)$this->config->get('user.formguard_min_seconds'));
        $max = (int)$this->config->get('user.formguard_max_seconds');
        if ($age < $min) return 'too fast';
        if ($max > 0 && $age > $max) return 'expired';

        $class = trim((string)$this->config->get('security.form_challenge'));
        if ($class !== '') {
            $challenge = $this->container->get($class);
            if (!$challenge instanceof FormChallengeInterface) return 'challenge misconfigured';
            if (!$challenge->verify($_POST)) return 'challenge';
        }
        return '';
    }

    private function sign(string $time, string $seed): string
    {
        return $this->crypt->hash('formguard|' . $time . '|' . $seed);
    }
}

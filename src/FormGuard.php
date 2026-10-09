<?php
/**
 * Formularschutz ohne fremden Dienst: Köderfeld und signierter Zeitstempel.
 * developer_docs/20-frontend-schutz.md A.5.
 *
 * (CMS:USER_TOKEN formguard=true) gibt neben dem CSRF-Token aus:
 *
 *     _ft      "{Unix-Zeit}.{HMAC}" - HMAC über Zeit und den Zufallswert des Cookies
 *              'ucsrf' (UserSession::csrfSeed()); ein Robot kann ihn weder fälschen
 *              noch aus einem fremden Browser übernehmen
 *     c9_url   ein Textfeld, das Menschen nicht sehen (außerhalb des Bildschirms,
 *              aria-hidden, tabindex -1) und deshalb leer lassen; Robots, die jedes
 *              Feld füllen, verraten sich
 *
 * Befehle mit formguard=true (USER_REGISTER, USER_PW, CMS:EMAIL) verlangen dann:
 * Köderfeld leer, Zeitstempel echt, zwischen user.formguard_min_seconds und
 * user.formguard_max_seconds alt. formguard=N (Zahl) setzt die Mindestzeit für
 * dieses Formular auf N Sekunden (0 = Schutz aus). Scheitert das, antworten sie wie bei der
 * Drosselung - ein Robot soll nicht lernen, woran es lag.
 *
 * Wer den Quelltext kennt, überlistet das (camindo wird Open Source). Gedacht ist es
 * gegen Robots, die blind jedes Formular füllen; für mehr lässt sich über
 * security.form_challenge ein Captcha anbinden (FormChallengeInterface).
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
     * Die beiden Felder als HTML - '' ohne Cookie (Aufruf nach der ersten Ausgabe
     * ohne vorheriges USER_TOKEN).
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
     * Stammt das abgeschickte Formular von einem Menschen? Prüft zusätzlich das
     * Captcha aus security.form_challenge, wenn eines eingestellt ist.
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
     * Parameter formguard eines Befehls: false = aus, true = Vorgaben, Zahl =
     * Mindestzeit in Sekunden.
     *
     * @return int|false|null  false = aus, null = Vorgabe, sonst Sekunden
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

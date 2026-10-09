<?php
/**
 * Grafischer Vergleich eines Audit-Log-Eintrags: vorher / nachher je Feld.
 * Instanzname im Code: $auditDiff.
 *
 * TEXTE WERDEN JE ABSATZ VERGLICHEN
 * --------------------
 * Ein Text zerfällt an Zeilenumbrüchen (\r\n, \n, \r) und nach schließenden
 * Block-Tags (</p>, </li>, </h1> …, <br>) in Absätze. paragraphs() gleicht beide
 * Folgen ab (längste gemeinsame Teilfolge): Unveränderte Absätze stehen grau in
 * beiden Spalten, entfernte rot links, hinzugekommene grün rechts. Steht einem
 * entfernten Absatz ein hinzugekommener gegenüber, gilt er als geändert: dann
 * markiert words() innerhalb des Absatzes die einzelnen Wörter - sofern beide
 * Fassungen genug gemeinsam haben (MIN_WORD_SIMILARITY), sonst den ganzen Absatz.
 *
 * Bei sehr langen Texten (mehr als MAX_CELLS Vergleiche) wird nicht gerechnet,
 * sondern beide Stände ganz gezeigt - die Seite soll nicht hängen.
 *
 * ALLES IST FREMDER TEXT
 * --------------------
 * Inhalte werden als Text gezeigt (htmlspecialchars), auch HTML aus Editoren: Der
 * Vergleich zeigt, was gespeichert wurde, nicht, wie es aussieht.
 *
 * HTML AUS VORLAGEN
 * --------------------
 * Das Markup steht in backend/html/audit_*.html. Backend wird erst beim Rendern
 * geholt (LazyServicesTrait) - die APIs brauchen nur die Text-Methoden
 * (actorLabel(), plainChanges()) und laden Backend gar nicht erst.
 *
 * @see Camindo\Core\AuditLog
 *
 * @property-read Backend $backend
 */
namespace Camindo\Core;

class AuditDiff {

    use LazyServicesTrait;

    /** Obergrenze für die Vergleichstabelle (Absätze bzw. Wörter: n × m) */
    public const MAX_CELLS = 250000;

    /** Anteil gleicher Wörter, ab dem ein Absatz wortweise statt ganz markiert wird */
    public const MIN_WORD_SIMILARITY = 0.4;

    /** Feldtypen, die absatzweise verglichen werden */
    private const TEXT_TYPES = ['text', 'chars', 'json', 'html', 'textarea', 'code'];

    public function __construct(
        private Cms $cms,
        private AuditLog $auditLog,
        protected ContainerInterface $container,
    )
    {
        // Zuweisung statt Startwert am Property - wie in Backend (LazyServicesTrait)
        $this->lazyServices = ['backend' => Backend::class];
    }

    /** IP-Adresse eines Eintrags zum Anzeigen: gekürzte Stellen als "xxx" (ClientIp::display()) */
    public function displayIp(string $ip): string
    {
        return ClientIp::display($ip, ClientIp::v4Octets($this->container->get(Config::class)));
    }

    /**
     * Die Änderungen eines Eintrags als Tabelle Feld | vorher | nachher.
     *
     * @param array $changes      Liste aus AuditLog::diff()
     * @param bool  $showSecrets  false: verschlüsselte Werte nicht entschlüsseln
     * @return string             HTML ('' ohne Änderungen)
     */
    public function table(array $changes, bool $showSecrets = true): string
    {
        if (!$changes) return '';
        $rows = '';
        foreach ($changes as $change) {
            $label = $this->backend->escape($this->cms->textOrSnippet((string)($change['l'] ?? $change['k'] ?? '')));
            $lang = (string)($change['g'] ?? '');
            if ($lang !== '') $label .= $this->backend->template('audit_lang_badge', ['{lang}' => $this->backend->escape($lang)]);
            [$before, $after] = $this->cells($change, $showSecrets);
            $rows .= $this->backend->template('audit_table_row', [
                '{key}' => $this->backend->escape((string)($change['k'] ?? '')),
                '{label}' => $label,
                '{before}' => $before,
                '{after}' => $after,
            ]);
        }
        return $this->backend->template('audit_table', ['{rows}' => $rows]);
    }

    /**
     * Ein Eintrag als Karte: Kopfzeile (Zeitpunkt, Aktion, wer, woher) und die
     * Vergleichstabelle.
     *
     * @param array  $row          Zeile aus AuditLog::search()
     * @param bool   $superuser    IP-Adresse zeigen
     * @param string $objectLink   HTML hinter der Kopfzeile (z. B. Verweis aufs Objekt)
     */
    public function entry(array $row, bool $superuser, string $objectLink = ''): string
    {
        $action = (string)$row['action'];
        $level = match ($action) {
            'create', 'restore', 'login' => 'success',
            'delete', 'purge', 'login_failed' => 'danger',
            default => 'primary',
        };
        $head = $this->backend->template('audit_entry_head', [
            '{level}' => $level,
            '{action}' => $this->backend->escape($this->actionLabel($action)),
            '{time}' => $this->backend->escape($this->localTime((string)$row['created_at'])),
            '{actor}' => $this->backend->escape($this->actorLabel($row)),
            '{origin}' => $this->backend->escape((string)$row['origin'] . ($superuser && (string)$row['ip'] !== '' ? ', ' . $this->displayIp((string)$row['ip']) : '')),
            '{object_link}' => $this->backend->protect($objectLink),
        ]);
        $changes = (array)($row['changes'] ?? []);
        if ($changes) {
            $body = $this->table($changes);
        }
        elseif (in_array($action, ['create', 'update'], true)) {
            $body = $this->backend->template('audit_no_changes');
        }
        else {
            $body = '';
        }
        return $this->backend->template('audit_entry', [
            '{head}' => $head,
            '{body}' => $body !== '' ? $this->backend->template('audit_entry_body', ['{body}' => $body]) : '',
        ]);
    }

    /**
     * Beschriftung einer Aktion (Snippet audit_action_{action}).
     */
    public function actionLabel(string $action): string
    {
        return $this->cms->snippet('audit_action_' . $action);
    }

    /** admin_id => Name, je Request (actorLabel()) */
    private array $adminNames = [];

    /**
     * Wer hat gehandelt - Benutzername, Website-User #Id, Besucher oder System.
     *
     * Benutzer stehen im Audit-Log nur als Id (AuditLog, Klassenkommentar): der Name
     * kommt aus `admin` - der heutige, bei einem anonymisierten Konto also admin_{id}.
     * Ältere Einträge mit Namen: der gespeicherte, wenn es das Konto nicht mehr gibt.
     */
    public function actorLabel(array $row): string
    {
        return match ((string)$row['actor_type']) {
            'admin' => $this->adminName((int)$row['admin_id'])
                ?? ((string)($row['actor_name'] ?? '') !== '' ? (string)$row['actor_name'] : '#' . (int)$row['admin_id']),
            'user' => $this->cms->snippet('audit_actor_user') . ((int)$row['user_id'] > 0 ? ' #' . (int)$row['user_id'] : ''),
            'visitor' => $this->cms->snippet('audit_actor_visitor'),
            default => (string)($row['actor_name'] ?? '') !== '' ? (string)$row['actor_name'] : $this->cms->snippet('audit_actor_system'),
        };
    }

    /** Name eines Benutzers (auch gelöscht), null = gibt es nicht */
    private function adminName(int $adminId): ?string
    {
        if ($adminId < 1) return null;
        if (!array_key_exists($adminId, $this->adminNames)) {
            $db = $this->container->get(Database::class);
            $db->pushResult();
            try {
                $db->query("SELECT `name` FROM `admin` WHERE `admin_id` = ?", 'i', [$adminId]);
                $name = $db->fetchNum()[0] ?? null;
                $this->adminNames[$adminId] = $name === null ? null : (string)$name;
            }
            finally {
                $db->popResult();
            }
        }
        return $this->adminNames[$adminId];
    }

    /**
     * UTC-Zeitpunkt aus der Datenbank in Zeitzone und Format des Admins.
     */
    public function localTime(string $utc): string
    {
        if ($utc === '') return '';
        $local = $this->cms->userTZ !== '' ? $this->cms->utcToUserTZ(substr($utc, 0, 19)) : substr($utc, 0, 19);
        return $this->cms->_formatDate($local) ?: $local;
    }

    /**
     * Die Änderungen eines Eintrags als Daten (API: record.info history, audit.history):
     * je Feld field, label, language, before, after. Passwörter nur als ***,
     * verschlüsselte Werte entschlüsselt.
     *
     * @param int $truncate  Werte ab so vielen Zeichen kürzen (0 = nie); gekürzte
     *                       Änderungen tragen truncated = true
     */
    public function plainChanges(array $changes, int $truncate = 0): array
    {
        $list = [];
        foreach ($changes as $change) {
            $type = (string)($change['t'] ?? 'text');
            $before = (string)($change['b'] ?? '');
            $after = (string)($change['a'] ?? '');
            if ($type === 'password') {
                $before = $before === '' ? '' : '***';
                $after = $after === '' ? '' : '***';
            }
            elseif ($type === 'encrypted') {
                $before = $this->auditLog->decryptValue($before);
                $after = $this->auditLog->decryptValue($after);
            }
            $entry = [
                'field'    => (string)($change['k'] ?? ''),
                'label'    => trim(html_entity_decode(strip_tags($this->cms->textOrSnippet((string)($change['l'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'language' => ((string)($change['g'] ?? '')) ?: null,
                'before'   => $before,
                'after'    => $after,
            ];
            if ($truncate > 0 && (mb_strlen($before) > $truncate || mb_strlen($after) > $truncate)) {
                $entry['before'] = mb_strlen($before) > $truncate ? mb_substr($before, 0, $truncate) . ' …' : $before;
                $entry['after'] = mb_strlen($after) > $truncate ? mb_substr($after, 0, $truncate) . ' …' : $after;
                $entry['truncated'] = true;
            }
            $list[] = $entry;
        }
        return $list;
    }

    /**
     * Vorher- und Nachher-Zelle einer Änderung.
     *
     * @return string[]  [html vorher, html nachher]
     */
    public function cells(array $change, bool $showSecrets = true): array
    {
        $type = (string)($change['t'] ?? 'text');
        $before = (string)($change['b'] ?? '');
        $after = (string)($change['a'] ?? '');

        if ($type === 'password') {
            return [
                $before === '' ? $this->empty() : $this->secondary('••••••'),
                $after === '' ? $this->empty() : $this->secondary('••••••') . ' '
                    . $this->backend->escape($this->cms->snippet('audit_password_changed')),
            ];
        }
        if ($type === 'encrypted') {
            if (!$showSecrets) {
                $hidden = $this->secondary($this->cms->snippet('audit_encrypted'));
                return [$before === '' ? $this->empty() : $hidden, $after === '' ? $this->empty() : $hidden];
            }
            $before = $this->auditLog->decryptValue($before);
            $after = $this->auditLog->decryptValue($after);
        }
        if ($type === 'bit') {
            return [
                $before === '' ? $this->empty() : $this->bit($before),
                $after === '' ? $this->empty() : $this->bit($after),
            ];
        }
        if (in_array($type, self::TEXT_TYPES, true) || $type === 'encrypted') {
            return $this->paragraphs($before, $after);
        }
        // Einzelwerte: ganz ersetzt
        return [
            $before === '' ? $this->empty() : $this->mark($before, 'del'),
            $after === '' ? $this->empty() : $this->mark($after, 'ins'),
        ];
    }

    /**
     * Zwei Texte absatzweise vergleichen (siehe Klassenkommentar).
     *
     * @return string[]  [html vorher, html nachher]
     */
    public function paragraphs(string $before, string $after): array
    {
        if ($before === '' && $after === '') return [$this->empty(), $this->empty()];
        $a = $this->split($before);
        $b = $this->split($after);
        if ($before === '') return [$this->empty(), $this->join(array_map(fn($p) => $this->mark($p, 'ins'), $b))];
        if ($after === '') return [$this->join(array_map(fn($p) => $this->mark($p, 'del'), $a)), $this->empty()];

        $ops = $this->lcs($a, $b);
        if ($ops === null) {
            // zu groß zum Rechnen: beide ganz
            return [
                $this->join(array_map(fn($p) => $this->mark($p, 'del'), $a)),
                $this->join(array_map(fn($p) => $this->mark($p, 'ins'), $b)),
            ];
        }

        $left = [];
        $right = [];
        $count = count($ops);
        for ($i = 0; $i < $count; $i++) {
            [$op, $text] = $ops[$i];
            if ($op === '=') {
                $left[] = $this->same($text);
                $right[] = $this->same($text);
                continue;
            }
            // Block aus Entfernen/Hinzufügen sammeln
            $dels = [];
            $inss = [];
            while ($i < $count && $ops[$i][0] !== '=') {
                if ($ops[$i][0] === '-') $dels[] = $ops[$i][1];
                else $inss[] = $ops[$i][1];
                $i++;
            }
            $i--;
            // Paarweise gegenüberstellen: geänderte Absätze wortweise markieren
            $pairs = max(count($dels), count($inss));
            for ($k = 0; $k < $pairs; $k++) {
                $d = $dels[$k] ?? null;
                $n = $inss[$k] ?? null;
                if ($d !== null && $n !== null) {
                    [$l, $r] = $this->words($d, $n);
                    $left[] = $l;
                    $right[] = $r;
                }
                elseif ($d !== null) {
                    $left[] = $this->mark($d, 'del');
                }
                else {
                    $right[] = $this->mark((string)$n, 'ins');
                }
            }
        }
        return [$this->join($left), $this->join($right)];
    }

    /**
     * Zwei Absätze wortweise vergleichen.
     *
     * @return string[]  [html vorher, html nachher] - je ein Absatz
     */
    public function words(string $before, string $after): array
    {
        $a = preg_split('/(\s+)/u', $before, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('/(\s+)/u', $after, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $ops = $this->lcs($a, $b);
        if ($ops === null) return [$this->mark($before, 'del'), $this->mark($after, 'ins')];

        // Kaum Gemeinsames: der Absatz ist neu geschrieben - ganz markieren
        $same = 0;
        foreach ($ops as [$op, $token]) {
            if ($op === '=' && trim($token) !== '') $same++;
        }
        $words = max(count(array_filter($a, 'trim')), count(array_filter($b, 'trim')), 1);
        if ($same / $words < self::MIN_WORD_SIMILARITY) {
            return [$this->mark($before, 'del'), $this->mark($after, 'ins')];
        }

        $left = '';
        $right = '';
        foreach ($ops as [$op, $token]) {
            $html = $this->backend->escape($token);
            if ($op === '=') {
                $left .= $html;
                $right .= $html;
            }
            elseif ($op === '-') {
                $left .= trim($token) === '' ? $html : $this->backend->template('audit_word_del', ['{text}' => $html]);
            }
            else {
                $right .= trim($token) === '' ? $html : $this->backend->template('audit_word_ins', ['{text}' => $html]);
            }
        }
        // Benachbarte Markierungen (nur durch Leerraum getrennt) zusammenfassen
        $left = preg_replace('~</del>(\s+)<del\b[^>]*>~u', '$1', $left) ?? $left;
        $right = preg_replace('~</ins>(\s+)<ins\b[^>]*>~u', '$1', $right) ?? $right;
        return [
            $this->backend->template('audit_words_del', ['{html}' => $left]),
            $this->backend->template('audit_words_ins', ['{html}' => $right]),
        ];
    }

    // ====================

    /**
     * Text in Absätze zerlegen: Zeilenumbrüche und schließende Block-Tags.
     *
     * @return string[]
     */
    private function split(string $text): array
    {
        $text = preg_replace('~(</(?:p|div|li|h[1-6]|blockquote|tr|table|ul|ol)>|<br\s*/?>)~i', "$1\n", $text) ?? $text;
        $parts = preg_split('/\r\n|\r|\n/', $text) ?: [];
        return array_values(array_filter(array_map('rtrim', $parts), fn(string $p) => trim($p) !== ''));
    }

    /**
     * Längste gemeinsame Teilfolge als Operationsliste.
     *
     * @return array|null  Liste aus [op, element] mit op '=', '-', '+'; null wenn zu groß
     */
    private function lcs(array $a, array $b): ?array
    {
        // Gemeinsamen Anfang und gemeinsames Ende abtrennen - spart fast immer das meiste
        $head = [];
        while ($a && $b && $a[0] === $b[0]) {
            $head[] = ['=', array_shift($a)];
            array_shift($b);
        }
        $tail = [];
        while ($a && $b && end($a) === end($b)) {
            array_unshift($tail, ['=', array_pop($a)]);
            array_pop($b);
        }
        $n = count($a);
        $m = count($b);
        if ($n * $m > self::MAX_CELLS) return null;

        // Tabelle der Längen (von hinten)
        $len = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $len[$i][$j] = $a[$i] === $b[$j]
                    ? $len[$i + 1][$j + 1] + 1
                    : max($len[$i + 1][$j], $len[$i][$j + 1]);
            }
        }
        $ops = $head;
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['=', $a[$i]];
                $i++;
                $j++;
            }
            elseif ($len[$i + 1][$j] >= $len[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            }
            else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) $ops[] = ['-', $a[$i++]];
        while ($j < $m) $ops[] = ['+', $b[$j++]];
        return array_merge($ops, $tail);
    }

    private function mark(string $text, string $kind): string
    {
        return $this->backend->template($kind === 'del' ? 'audit_par_del' : 'audit_par_ins', [
            '{html}' => nl2br($this->backend->escape($text)),
        ]);
    }

    private function same(string $text): string
    {
        return $this->backend->template('audit_par_same', ['{html}' => $this->backend->escape($text)]);
    }

    private function join(array $html): string
    {
        return $html ? implode('', $html) : $this->empty();
    }

    private function bit(string $value): string
    {
        return $this->backend->template($value === '1' ? 'audit_bit_on' : 'audit_bit_off');
    }

    /** Grauer Text (Vorlage text-body-secondary) */
    private function secondary(string $text): string
    {
        return $this->backend->template('text-body-secondary', ['{text}' => $this->backend->escape($text)]);
    }

    private function empty(): string
    {
        return $this->secondary('–');
    }
}

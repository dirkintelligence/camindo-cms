<?php
/**
 * Dataset-Kindklasse für die Tabelle `language` - die Sprachen, in denen das Frontend
 * ausgeliefert wird.
 *
 * Nicht zu verwechseln mit den Sprach-Snippets des Backends: die liegen als
 * PHP-Dateien in den lang/-Verzeichnissen und werden über Cms::snippet() geholt.
 * Diese Tabelle beschreibt ausschließlich die Sprachen der Website-Inhalte.
 *
 * Der `code` landet als erstes URL-Segment im Frontend (/de/..., /en/...); die
 * Rewrite-Regel in public/.htaccess (Docroot) trennt ihn ab und übergibt ihn als
 * eigenen Parameter an Page::resolveRequest(). `endonym` ist die Eigenbezeichnung
 * der Sprache ("Deutsch" statt "German") - das gehört in einen Sprachumschalter.
 *
 * ZWEI ANGABEN FÜR DIE LAUFZEIT
 * --------------------
 *     backend_code   Backend-Sprache (Snippets) zu dieser Inhaltssprache, z. B.
 *                    de_AT -> de_DE. Die Meldungen der CMS-Befehle kommen aus
 *                    Cms::snippet() - so erscheinen sie im Frontend in der Sprache
 *                    der Seite. Auswahl aus Cms::backendLanguages(), Pflicht.
 *     timezone       Zeitzone, in der Datums- und Zeitangaben dieser Sprachversion
 *                    ausgegeben werden; leer = system.timezone aus der Konfiguration.
 * Beides stellt Cms::setLanguage() ein, sobald Page die Sprache des Aufrufs kennt;
 * die Werte liegen dafür im Instanz-Dateicache (InstanceCache).
 *
 * @see Camindo\Core\Modules\Language\LanguageBackend
 * @see Camindo\Core\Dataset
 */
namespace Camindo\Core\Modules\Language;

use Camindo\Core\Cms;
use Camindo\Core\Collation;
use Camindo\Core\Dataset;
use Camindo\Core\InstanceCache;
use Camindo\Core\LanguageData;

class Language extends Dataset {
    
    protected function hookDefinition(): void
    {
        $this->tableName  = 'language';
        $this->moduleName = 'Language';
        $this->defaultSortOrder = 'position ASC';
        $this->schema = [
            'language_id' => [
                'type'  => 'integer',
                'label' => '#language.field_language_id',
            ],
            'code' => [
                'type'  => 'chars',
                'label' => '#language.field_code',
                'note' => '#language.note_field_code',
                'max'   => 5,
                'flags' => ['trim', 'not_empty'],
            ],
            'backend_code' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#language.field_backend_code',
                'tooltip' => '#language.tooltip_field_backend_code',
                'max'   => 8,
                'flags' => ['trim', 'not_empty'],
                'default' => Cms::FALLBACK_LANG,
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#language.field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
                'flags' => ['trim', 'not_empty'],
            ],
            'endonym' => [
                'type'  => 'chars',
                'label' => '#language.field_endonym',
                'max'   => 64,
                'flags' => ['trim', 'not_empty'],
            ],
            'timezone' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#language.field_timezone',
                'tooltip' => '#language.tooltip_field_timezone',
                'max'   => 64,
                'flags' => ['trim'],
            ],
            'position' => [
                'type'  => 'integer',
                'label' => '#language.field_position',
                'display_type' => 'select',
            ],
        ];
    }

    /**
     * Vor dem Erstellen: neue Sprache ans Ende der Sortierung stellen.
     * Die Position bestimmt u. a. die Reihenfolge im Sprachumschalter.
     */
    protected function hookOnCreate(array &$data): void
    {
        $sql = "SELECT COALESCE(MAX(`position`), 0) + 1 FROM `language`";
        $this->db->query($sql);
        $data['position'] = $this->db->fetchNum()[0] ?? 0;
    }

    /**
     * Vor dem Speichern: backend_code und timezone gegen die tatsächlichen
     * Möglichkeiten prüfen - eine Auswahlliste ist kein Schutz vor einem
     * manipulierten Formular, und beide Werte gehen später ungeprüft an
     * date_default_timezone_set() bzw. an den Snippet-Cache.
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        if (empty($fields) || in_array('backend_code', $fields, true)) {
            $code = trim((string)($data['backend_code'] ?? ''));
            // Frühere zweistellige Kürzel ("de") auf das heutige ("de_DE") heben
            if (preg_match('/^[a-z]{2,3}$/', $code)) {
                $code = Cms::matchLanguage($code, $this->cms->backendLanguages()) ?? $code;
                $data['backend_code'] = $code;
            }
            if ($code !== '' && !in_array($code, $this->cms->backendLanguages(), true)) {
                $this->createFieldError('error_invalid_format', 'backend_code', $this->schema['backend_code']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
        if (empty($fields) || in_array('timezone', $fields, true)) {
            $timezone = trim((string)($data['timezone'] ?? ''));
            if ($timezone !== '' && !$this->cms->isValidTimezone($timezone)) {
                $this->createFieldError('error_invalid_format', 'timezone', $this->schema['timezone']['label']);
                throw new \Exception('#' . $this->cms->errorMsgLast());
            }
        }
    }

    /**
     * Nach dem Speichern: Cache-Dateien der Instanzen erneuern.
     *
     * Die Dateien instance-{id}.php führen zu jeder Sprache code, label und endonym
     * (siehe InstanceCache) - eine Änderung hier muss dort ankommen.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $this->instanceCache()->rebuild();

        // Anderes Kürzel mit anderen Regeln: der Volltext der Sprache ist nach den alten
        // gefaltet (Collation::fold()) - in jeder Instanz, die sie führt, als veraltet markieren
        $languageId = (int)($data['language_id'] ?? $previousData['language_id'] ?? 0);
        $oldCode = (string)($previousData['code'] ?? '');
        $newCode = (string)($data['code'] ?? $oldCode);
        if ($languageId > 0 && $oldCode !== '' && !Collation::sameRules($oldCode, $newCode)) {
            foreach ($this->instancesWith($languageId) as $instanceId) {
                $this->container->get(LanguageData::class)->markFulltextStale($instanceId);
            }
        }
    }

    /** Instanzen, die eine Sprache führen (als Haupt- oder weitere Sprache) */
    private function instancesWith(int $languageId): array
    {
        $this->db->query(
            "SELECT `instance_id` FROM `instance` WHERE `primary_language_id` = ?"
            . " UNION SELECT `instance_id` FROM `rel_instance_language` WHERE `language_id` = ?",
            'ii', [$languageId, $languageId]
        );
        return array_map('intval', $this->db->fetchAll());
    }

    protected function hookOnLoaded(array &$data): void
    {
    }

    /**
     * Instanzen, die eine Sprache als Hauptsprache bzw. als weitere Sprache führen -
     * für die Warnung vor dem Löschen.
     *
     * @return array  ['primary' => [instance_id => label], 'additional' => [...]]
     */
    public function usage(int $languageId): array
    {
        $usage = ['primary' => [], 'additional' => []];
        $this->db->pushResult();
        try {
            $this->db->query(
                "SELECT `instance_id`, `label` FROM `instance` WHERE `primary_language_id` = ? ORDER BY `position`",
                'i', [$languageId]
            );
            foreach ($this->db->fetchPairs() as $id => $label) {
                $usage['primary'][(int)$id] = $this->cms->textOrSnippet((string)$label);
            }
            $this->db->query(
                "SELECT `i`.`instance_id`, `i`.`label` FROM `rel_instance_language` `r`"
                . " INNER JOIN `instance` `i` ON `i`.`instance_id` = `r`.`instance_id`"
                . " WHERE `r`.`language_id` = ? ORDER BY `i`.`position`",
                'i', [$languageId]
            );
            foreach ($this->db->fetchPairs() as $id => $label) {
                $usage['additional'][(int)$id] = $this->cms->textOrSnippet((string)$label);
            }
        }
        finally {
            $this->db->popResult();
        }
        return $usage;
    }

    /**
     * Vor dem Löschen: die Inhalte der Sprache in allen Instanzen löschen.
     *
     * Läuft in der Transaktion von Dataset::delete() - wirft ein Schritt, bleibt die
     * Sprache stehen (schon gelöschte Dateien bleiben dann weg).
     *
     * Hauptsprache einer Instanz darf sie nicht sein: `instance`.`primary_language_id`
     * hat keinen Fremdschlüssel, die Instanz zeigte sonst ins Leere - erst dort eine
     * andere Hauptsprache wählen. Sonst LanguageData::deleteLanguage(): Texte, Zahlen,
     * Dateien, Volltext, Inhalts-Cache und Datenschutz-Vorlagen der
     * Sprache verschwinden aus allen Instanzen.
     */
    protected function hookOnDelete(array $data): void
    {
        $languageId = (int)($data['language_id'] ?? 0);

        $primary = $this->usage($languageId)['primary'];
        if (!empty($primary)) {
            $errorMsg = str_replace(
                '{instances}',
                implode(', ', $primary),
                $this->cms->snippet('language.error_primary_language_in_use')
            );
            $this->cms->error($errorMsg);
            throw new \Exception('#' . $errorMsg);
        }

        $this->container->get(LanguageData::class)->deleteLanguage($languageId);
    }

    /**
     * Nach dem Löschen: Cache-Dateien der Instanzen erneuern.
     */
    protected function hookOnDeleted(array $data): void
    {
        $this->instanceCache()->rebuild();
    }

    // Sonderfunktionen für Language
    // --------------------

    /**
     * Dateicache der Instanzen (siehe Camindo\Core\InstanceCache).
     */
    private function instanceCache(): InstanceCache
    {
        return $this->container->get(InstanceCache::class);
    }

}
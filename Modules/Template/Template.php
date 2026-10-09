<?php
/**
 * Dataset-Kindklasse für die Tabelle `template` - die Frontend-Templates.
 *
 * Ein Template wird in der CMS-Befehlssprache geschrieben und beim Speichern zu PHP
 * kompiliert. Zur Laufzeit kommt kein Parser mehr zum Einsatz, das Frontend bindet
 * fertiges PHP ein.
 *
 * DIE DATEI FÜHRT
 * --------------------
 * Der Quelltext liegt als Datei unter var/templates/{instance_id}/{bereich}/{name}.cms.php
 * (TemplateFiles). Sie ist die einzige Quelle:
 *
 *     Datei         Quelltext in CMS-Syntax - das, was der Redakteur bearbeitet.
 *                   load() liest ihn von dort (hookOnLoaded()), save() schreibt ihn
 *                   dorthin zurück (hookOnSaved()).
 *     code          Kopie des Quelltexts in der Datenbank - NUR für die Suche im
 *                   Backend nach Code-Stellen
 *     styled_code   HTML mit Syntax-Highlighting - NUR für TemplateBackend::info()
 *     error_text    Meldungen des Parsers, mit Zeilennummern
 *     php_code      daraus kompiliertes PHP - erzeugt in hookOnSave(), nur als
 *                   Datei nach var/php/{area_id}/{name}.php geschrieben
 *                   (hookOnSaved()), nie in die Datenbank
 *
 * Aus der Datenbank gelangt nie Code ins Dateisystem: kompiliert wird immer aus dem
 * Quelltext, der gerade gespeichert wird (Formular oder Datei), und rebuildFiles()
 * liest die Dateien. Sicherheitsaspekt - wer in die Datenbank schreiben kann, darf
 * damit keinen PHP-Code ausführen können. Manuell geänderte, angelegte oder
 * gelöschte Dateien übernimmt TemplateFiles::sync().
 *
 * Von var/php bindet Page::resolveRequest() die kompilierte Datei ein.
 *
 * NAMEN SIND NUR JE BEREICH EINDEUTIG
 * --------------------
 * `name` trägt das Flag 'unique_in_area': derselbe Name darf in mehreren
 * Template-Bereichen vorkommen. Angesprochen wird ein Template deshalb wahlweise
 * als "name" oder als "bereichsname.name" (Cms::templateFile()). Ein bloßer Name,
 * der in mehreren Bereichen vorkommt, ist im Frontend ein Fehler (Exception) -
 * der Verzeichnisanteil {area_id} des Dateipfads hält die Dateien auseinander.
 *
 * Syntaxfehler verhindern das Speichern NICHT. Sie landen in error_text und werden im
 * Backend angezeigt; der fehlerhafte Befehl bleibt im erzeugten PHP als Rohtext stehen.
 * So geht ein halbfertiges Template beim Zwischenspeichern nicht verloren.
 *
 * ZUWEISUNG AN INHALTSBEREICHE
 * --------------------
 * Welches Template für welchen Bereich gilt, steht nicht hier, sondern in
 * `rel_area_template` und `area`.`default_template_id` - gepflegt über saveAccess().
 * Diese Verknüpfungen (wie auch `area`.`list_template_id`) gibt es nur im Backend,
 * das Dateisystem kennt sie nicht.
 *
 * @see Camindo\Core\Modules\Template\TemplateFiles         Quelltext-Dateien, Abgleich
 * @see Camindo\Core\Modules\Template\TemplateParserTrait   Parser und Code-Erzeugung
 * @see Camindo\Core\Modules\Template\TemplateBackend       Backend-Oberfläche
 * @see Camindo\Core\Page                                    Auslieferung im Frontend
 */
namespace Camindo\Core\Modules\Template;

use Camindo\Core\Dataset;
use Camindo\Core\Modules\Template\TemplateParserTrait;

class Template extends Dataset {

    /** Verwendungen (`type`): '' = Seite/Baustein, 'scheduler' = zeitgesteuert */
    public const TYPES = ['', 'scheduler'];

    // Audit-Log: styled_code entsteht beim Speichern aus `code` (parse()) - der
    // Vergleich von `code` sagt alles; error_text bleibt (zeigt, was ein Speichern
    // an Meldungen gebracht hat)
    protected array $auditIgnore = ['styled_code'];

    // Bringt parse() mit: CMS-Syntax -> PHP + styledCode + Fehlerliste.
    // Als Trait und nicht als eigene Klasse, weil der Parser direkten Zugriff auf das
    // Dataset braucht (u. a. $this->config für die Snippet-Verzeichnisse).
    use TemplateParserTrait;

    protected function hookDefinition(): void
    {
        $this->tableName  = 'template';
        $this->moduleName = 'Template';
        // Templates enthalten Script und Event-Handler als Inhalt - keine Bereinigung
        $this->sanitizeHtml = false;
        $this->defaultSortOrder = 'name ASC';
        $this->schema = [
            'template_id' => [
                'type'  => 'integer',
                'label' => 'template_id'
            ],
            'area_id' => [
                'type'  => 'integer', // kein foreign key
                'label' => 'area_id',
                'min'   => 1
            ],
            // Website, zu der das Template gehört. Kein Eingabefeld: der Wert kommt
            // aus dem Container (`area`.`instance_id`) und wird von hookOnSave() bei
            // jedem Speichern von dort geholt. Die Kopie in `template` trägt den
            // Fremdschlüssel auf `instance` und erspart Cms::select() den JOIN über
            // den Bereich (siehe Cms::$sqlAccess).
            'instance_id' => [
                'type'  => 'integer', // FK auf `instance`, NOT NULL -> nicht 'id'
                'label' => '#template.field_instance_id',
                'min'   => 0
            ],
            // Verwendung: '' = Seite/Baustein, 'scheduler' = zeitgesteuert (Modul
            // Scheduler, Aufgabentyp template) - nie per URL aufrufbar (Page)
            'type' => [
                'type'  => 'chars',
                'display_type' => 'select',
                'label' => '#template.field_type',
                'tooltip' => '#template.tooltip_field_type',
                'max'   => 12
            ],
            'name' => [
                'type'  => 'chars',
                'label' => '#template.field_name',
                'tooltip' => '#template.tooltip_field_name',
                'max'   => 32,
                'flags' => ['not_empty', 'trim', 'name', 'unique_in_area'],
            ],
            'label' => [
                'type'  => 'chars',
                'label' => '#template.field_label',
                'note' => '#template.note_field_label',
                'tooltip' => '#template.tooltip_field_label',
                'max'   => 64,
                'extension' => 'Snippets',   // Core-Extension: Snippet auswählen (#prefix.key)
            ],
            'code' => [
                'type'  => 'text',
                'display_type' => 'textarea',
                'label' => '#template.field_code',
            ],
            'styled_code' => [
                'type'  => 'text',
                'label' => '#template.field_styled_code',
            ],
            'error_text' => [
                'type'  => 'text',
                'label' => '#template.field_error_text',
            ],
            'created_by' => [
                'type'  => 'id',
                'label' => '#record.created_by',
                'table' => 'admin'
            ],
            'changed_by' => [
                'type'  => 'id',
                'label' => '#record.changed_by',
                'table' => 'admin'
            ],
            'created_at' => [
                'type'  => 'timestampl',
                'label' => '#record.field_created_at',
                'default' => 'now'
            ],
            'changed_at' => [
                'type'  => 'timestampl',
                'label' => '#record.field_changed_at',
                'default' => 'now'
            ],
            'standalone' => [
                'type' => 'bit',
                'display_type' => 'switch',
                'label' => '#template.field_standalone',
                'tooltip' => '#template.tooltip_field_standalone'
            ]
        ];
    }

    protected function hookOnCreate(array &$data): void
    {
    }

    /**
     * Vor dem Speichern: CMS-Quelltext übersetzen.
     *
     * Befüllt die drei abgeleiteten Spalten aus `code`. Sie sind damit immer konsistent
     * zum Quelltext - php_code, styled_code und error_text sollten nie von Hand
     * geschrieben werden.
     *
     * Hinweis: $data['code'] und $data['area_id'] werden vorausgesetzt. Wird save() mit
     * einer $fields-Liste aufgerufen, die 'code' nicht enthält, muss der Aufrufer die
     * Werte trotzdem in $data mitgeben - und zwar aus dem Formular oder der Datei,
     * nie aus der Spalte `code` (load() liefert ohnehin den Dateiinhalt).
     */
    protected function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // instance_id
        // --------------------
        // Kein Eingabefeld: das Template gehört zu der Website, zu der sein Container
        // gehört. Bei JEDEM Speichern von dort geholt - so wandert ein Template, das
        // den Container wechselt, gleich mit. (Area::hookOnSaved() deckt den
        // umgekehrten Fall ab: der Container wechselt die Instanz.)
        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        if ($areaId > 0) {
            $data['instance_id'] = $this->areaInstanceId($areaId);
            if (!in_array('instance_id', $fields, true)) $fields[] = 'instance_id';
        }

        // Template-Code parsen
        $result               = $this->parse($data['code'] ?? '', (int)$data['area_id']);
        $data['php_code']     = $result['phpCode'];
        $data['styled_code']  = $result['styledCode'];
        $data['error_text']   = implode('<br>', (array)$result['errors']);
    }

    /**
     * Nach dem Speichern: kompiliertes PHP als ausführbare Datei ablegen.
     *
     * Ziel ist var/php/{area_id}/{name}.php - genau der Pfad, den
     * Cms::templateFile() liefert und aus dem Page bzw. der include-Schnipsel der
     * Templates laden. Geschrieben wird mit Dateisperre, damit ein gleichzeitiger
     * Frontend-Request keine halb geschriebene Datei einbindet.
     *
     * Wechselt Name oder Bereich (Umbenennen, Verschieben), wird die alte Datei
     * entfernt.
     */
    protected function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
        $templateDir = $this->templateDir();

        $areaId = (int)($data['area_id'] ?? $previousData['area_id'] ?? 0);
        $name = (string)($data['name'] ?? $previousData['name'] ?? '');
        $this->system->filePutContents($templateDir, self::fileName($areaId, $name), $data['php_code'], lock:true);

        $oldAreaId = (int)($previousData['area_id'] ?? 0);
        $oldName = (string)($previousData['name'] ?? '');
        $renamed = ($oldName !== '' && ($oldName !== $name || $oldAreaId !== $areaId));
        if ($renamed) {
            $oldFile = $templateDir . '/' . self::fileName($oldAreaId, $oldName);
            if (is_file($oldFile)) unlink($oldFile);
        }

        // Quelltext als Datei (var/templates/{instance_id}/{bereich}/{name}.cms.php,
        // siehe TemplateFiles) - das Backend überschreibt die Datei ohne Rücksicht
        // auf ihren Zeitstempel; beim Abgleich aus der Datei ist der Inhalt ohnehin
        // derselbe und die Datei bleibt unangetastet
        $files = $this->templateFiles();
        if ($renamed) $files->removeTemplate($oldAreaId, $oldName);
        $files->writeTemplate($areaId, $name, (string)($data['code'] ?? $previousData['code'] ?? ''));
    }

    /**
     * Nach dem Laden: Quelltext aus der Datei.
     *
     * Die Spalte `code` ist nur die Suchkopie (siehe Klassenkommentar). Fehlt die
     * Datei, ist der Quelltext leer - bewusst kein Rückgriff auf die Spalte, sonst
     * käme über "Bearbeiten -> Speichern" doch Code aus der Datenbank ins
     * Dateisystem. TemplateFiles::sync() löscht ein Template ohne Datei ohnehin
     * beim nächsten Lauf.
     */
    protected function hookOnLoaded(array &$data): void
    {
        $data['code'] = $this->templateFiles()->readTemplate(
            (int)($data['area_id'] ?? 0), (string)($data['name'] ?? '')
        ) ?? '';
    }

    /**
     * Vor dem (finalen) Löschen des Datenbank-Eintrags
     */
    protected function hookOnDelete(array $data): void
    {
        // Delete php file
        // Wenn die Datei nicht gelöscht werden kann -> Exception -> Rollback
        // Manuelles Eingreifen nötig (als Sicherheitsaspekt gewollt).
        $file = $this->templateDir() . '/' . self::fileName((int)$data['area_id'], (string)$data['name']);
        if (is_file($file)) unlink($file);

        // Quelltext-Datei (var/templates/...)
        $this->templateFiles()->removeTemplate((int)$data['area_id'], (string)$data['name']);
    }

    private function templateFiles(): TemplateFiles
    {
        return $this->container->get(TemplateFiles::class);
    }

    /**
     * Relativer Pfad der kompilierten Datei unterhalb von var/php:
     * "{area_id}/{name}.php". Dieselbe Regel gilt in Cms::templateFile().
     */
    public static function fileName(int $areaId, string $name): string
    {
        return $areaId . '/' . $name . '.php';
    }

    private function templateDir(): string
    {
        return $this->config->get('paths.var_dir') . '/php';
    }

    /**
     * Alle kompilierten Dateien neu schreiben (Werkzeuge > Entwickler).
     *
     * Kompiliert jedes Template aus seiner Quelltext-Datei (var/templates, nie aus
     * der Spalte `code`) neu nach {area_id}/{name}.php, schreibt dazu styled_code
     * und error_text (Parser-Meldungen, auch neue Hinweise nach einer Änderung am
     * Compiler - Widget TemplateBackend::widget()) und räumt anschließend
     * Dateien aus dem Wurzelverzeichnis von var/php weg - dort lagen die Dateien
     * vor der Umstellung auf Bereichsverzeichnisse. Templates ohne Datei werden
     * übergangen (TemplateFiles::sync() löscht sie).
     *
     * @return int  Anzahl geschriebener Dateien
     */
    public function rebuildFiles(): int
    {
        $templateDir = $this->templateDir();
        $files = $this->templateFiles();
        $this->db->pushResult();
        $this->db->query("SELECT `template_id`, `area_id`, `name` FROM `template`");
        $rows = $this->db->fetchAll();
        $this->db->popResult();

        $count = 0;
        foreach ($rows as $row) {
            $code = $files->readTemplate((int)$row['area_id'], (string)$row['name']);
            if ($code === null) continue;
            $result = $this->parse($code, (int)$row['area_id']);
            $this->system->filePutContents(
                $templateDir,
                self::fileName((int)$row['area_id'], (string)$row['name']),
                $result['phpCode'],
                lock:true
            );
            $this->db->pushResult();
            $this->db->query(
                "UPDATE `template` SET `styled_code` = ?, `error_text` = ? WHERE `template_id` = ?",
                'ssi',
                [$result['styledCode'], implode('<br>', (array)$result['errors']), (int)$row['template_id']]
            );
            $this->db->popResult();
            $count++;
        }

        foreach ((array)glob($templateDir . '/*.php') as $file) {
            if (is_file($file)) unlink($file);
        }
        return $count;
    }

    /**
     * Template in einen anderen Template-Bereich verschieben.
     *
     * Läuft über save(): load() bringt den Quelltext aus der Datei mit, hookOnSave()
     * holt die instance_id des neuen Bereichs und kompiliert neu, hookOnSaved() legt
     * die Dateien im neuen Bereichsverzeichnis ab und entfernt die alten. Die Eindeutigkeitsprüfung des Namens (unique_in_area)
     * greift dabei für den Zielbereich; ein Fehler steht in Cms::errors.
     *
     * Die Zuweisungen an Inhaltsbereiche (`rel_area_template`,
     * `area`.`default_template_id`) bleiben unberührt - sie hängen an der
     * template_id, nicht am Bereich, in dem das Template liegt.
     *
     * @param int $id            template_id
     * @param int $targetAreaId  Zielbereich (Typ 'template')
     * @return bool
     */
    public function moveToArea(int $id, int $targetAreaId): bool
    {
        if (!$data = $this->load($id)) return false;
        if ((int)$data['area_id'] === $targetAreaId) return true;
        // Rechte der Benutzergruppe: Schreiben in Quelle und Ziel
        if (!$this->accessCheck('write', (int)$data['area_id']) || !$this->accessCheck('write', $targetAreaId)) return false;

        $this->db->query(
            "SELECT 1 FROM `area` WHERE `area_id` = ? AND `type` = 'template' AND `deleted` = 0",
            'i', [$targetAreaId]
        );
        if ($this->db->num_rows < 1) {
            $this->cms->error($this->cms->snippet('area.error_area_not_found'));
            return false;
        }

        $data['area_id'] = $targetAreaId;
        $fields = ['area_id', 'name', 'styled_code', 'error_text'];
        return $this->save($fields, $data) !== null;
    }

    /**
     * Template-Zuweisungen (access) aktualisieren
     *
     * Schreibt in einem Durchgang beide Orte, an denen die Zuweisung hängt:
     *   - `area`.`default_template_id`  (höchstens ein Default-Template je Bereich)
     *   - `rel_area_template`           (selectable / override je Bereich)
     * Bereiche, für die weder selectable noch override gesetzt ist, bekommen keine Zeile
     * in rel_area_template - die Tabelle enthält also nur echte Zuweisungen.
     *
     * Das Default-Template wird immer zusätzlich als "selectable" markiert; das
     * vereinfacht die Zugriffsprüfung in Page::resolveRequest().
     * 
     * VERBESSERUNGSBEDÜRFTIG:
     * Wenn die Zuweisung zwischen Content-Bereich und Template entfernt wird,
     * bleibt das Template dennoch in record.template_id bestehen.
     * Der Aufruf des Datensatzes schlägt NICHT fehl, obwohl dies erwartbar wäre.
     * Die Record-Daten überleben dafür ein Zuweisung-entfernen und -wiederherstellen.
     * 
     * TODO:
     * Hinweis ausgeben, in wie viele Datensätzen das Template explizit gewählt ist,
     * das von der Zweisung entfernt werden soll -> Alternativ-Template / (oder "Default") wählen
     *
     * @param int $id   Template-ID
     * @param array $data Berechtigungen - key:area_id, val:
     *                  default = true: Template ist Default-Template im Bereich
     *                  selectable = true: Template ist wählbar im Bereich
     *                  override = true: Templateangabe in der Url darf zugewiesenes Template im Bereich überschreiben
     * @param bool|int $standalone
     *                  true: Template darf ohne Datensatzinhalte verwendet werden
     * @param ?string $type  Verwendung (self::TYPES), null = unverändert; ein
     *                  zeitgesteuertes Template ist nie standalone
     * @return bool     false bei Fehlern
     */
    public function saveAccess(int $id, array $data, bool|int $standalone, ?string $type = null): bool
    {
        // Rechte der Benutzergruppe: Schreiben im Template-Bereich, Lesen der
        // Inhaltsbereiche, denen das Template zugewiesen wird (12-group-rights.md)
        $access = $this->container->get(\Camindo\Core\Access::class);
        if ($access->isLoaded() && !$access->isSuperuser()) {
            $template = $this->load($id);
            $ok = $template !== null && $access->templateWrite((int)$template['area_id']);
            foreach (array_keys($data) as $areaId) {
                if (!$access->areaRead((int)$areaId)) $ok = false;
            }
            if (!$ok) {
                $this->cms->error($this->cms->snippet('error_access_denied'));
                return false;
            }
        }

        // Lade aktuelle Default-Templates aus den (in $data) angegebenen Inhaltsbreichen.
        // Format: area_id => default_template_id
        $areaIds = $this->system->sanitizeIds(array_keys($data), true);
        $areaIdString = implode(',', $areaIds) ?: '0';
        $this->db->query("SELECT `area_id`,`default_template_id` FROM `area` WHERE `area_id` IN ($areaIdString) AND `deleted`=0");
        $defaultTemplate = $this->db->fetchPairs();

        // Sammle Daten für `rel_area_template`
        $i = 0;
        $sqlValues = [];
        // Arbeite $data ab...
        foreach ($data as $areaId => $row) {
            $isCurrentlyDefault = ((int)($defaultTemplate[$areaId] ?? 0) === $id);
            $becomesDefault = (bool)($row['default'] ?? false);

            if ($isCurrentlyDefault != $becomesDefault) {
                // Default-Template des Bereichs aktualisieren
                if (!$becomesDefault) {
                    $sql = "UPDATE `area` SET `default_template_id`= NULL WHERE `area_id` = ?";
                    $this->db->query($sql, 'i', [$areaId]);
                }
                else {
                    $sql = "UPDATE `area` SET `default_template_id`= ? WHERE `area_id` = ?";
                    $this->db->query($sql, 'ii', [$id, $areaId]);
                }
            }

            // Das Default-Template immer auch als "wählbar" markieren
            // (erleichtert die Zugriffsprüfung in class Page)
            if ($becomesDefault) {
                $row['selectable'] = 1;
            }

            // Daten für rel_area_template
            $selectable = (int)($row['selectable'] ?? 0);
            $override = (int)($row['override'] ?? 0);
            if ($selectable || $override) {
                array_push($sqlValues, 
                    (int)$areaId, 
                    (int)$id, 
                    $selectable, 
                    $override
                );
                $i++;
            }
        }

        // Lösche alte Daten aus `rel_area_template`
        $this->db->query("DELETE FROM `rel_area_template` WHERE `template_id` = ?", 'i', [$id]);

        // Trage neue Daten ein 
        if ($i > 0) {
            $sqlCols = implode(', ', array_fill(0, $i, '(?, ?, ?, ?)'));
            $sql = "INSERT INTO `rel_area_template` (`area_id`, `template_id`, `selectable`, `override`)"
            ." VALUES " . $sqlCols;
            $sqlTypes = str_repeat('iiii', $i);
            $this->db->query($sql, $sqlTypes, $sqlValues);
        }

        // Aktualisiere standalone-Flag und Verwendung
        if ($type !== null) {
            $type = in_array($type, self::TYPES, true) ? $type : '';
            if ($type === 'scheduler') $standalone = 0;
            $this->db->query("UPDATE `template` SET `type` = ? WHERE `template_id` = ?", 'si', [$type, $id]);
        }
        $sql = "UPDATE `template` SET `standalone` = ? WHERE `template_id` = ?";
        $this->db->query($sql, 'ii', [$standalone, $id]);
        return true;
    }
}
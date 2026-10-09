<?php
/**
 * Backend-Oberfläche des Moduls User.
 *
 * Das Modul User ist für die Frontend-Community gedacht (Besucher-Anmeldung,
 * Registrierung, Warenkorb)
 * Auf der Startseite werden bewusst alle Inhalte, gruppiert nach Content-Bereichen
 * gelistet, die von Usern erstellt / verändert wurden. Dies wird anhand des
 * dafür explizit reservierten Bit-Feldes "needs_review" ermittelt.
 * Der reguläre Workflow wird über das Feld status_id abgebildet.
 *
 * DAS MODUL IST EIN WIDGET, KEINE KACHEL
 * --------------------
 * Es gibt nichts zu verwalten, nur etwas zu sehen - deshalb trägt das Modul in
 * seiner settings.php 'button' => false und stattdessen ein 'widget'. Auf der
 * Startseite steht es dort, wo der Admin es in seinen Einstellungen hinlegt
 * (Camindo\Core\Widgets); widget() liefert genau den Inhalt, den list() zeigt.
 * list() selbst bleibt als ganze Seite bestehen (backend/index.php) - das Widget
 * ist ein Ausschnitt davon, keine zweite Darstellung.
 *
 * @see Camindo\Core\Widgets                      Startseiten-Widgets der Module
 * @see Camindo\Core\Modules\Record\RecordUser   Frontend-Befehle CMS:USER_* (Anmeldung, Beiträge, Warenkorb)
 * @see docs/developers/11-community.md
 */
namespace Camindo\Core\Modules\User;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\System;
use Camindo\Core\Cms;
use Camindo\Core\Crypt;
use Camindo\Core\Modules\Area\Area;

class UserBackend {
    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';

    public function __construct(
        private Backend $backend,
        private Database $db,
        private System $system,
        private Cms $cms,
        private Crypt $crypt,
        private Area $area,
    )
    {}

    /**
     * Übersicht der gegenzulesenden Inhalte ausgeben.
     *
     * Je Inhaltsbereich eine Schaltfläche mit Bereichsname, Icon und Anzahl offener
     * Datensätze; gibt es nichts zu tun, erscheint stattdessen eine Erfolgsmeldung.
     *
     * (Der bisherige Kommentar "Render Folder-Area-Tree" beschrieb etwas anderes.)
     */
    public function list()
    {
        // Bread crum navi
        $this->backend->setNaviLabel('#headline_unreviewed');

        // Container header
        echo $this->backend->template('container_header');

        $unreviewedIcon = $this->backend->template('user.icon_unreviewed');
        echo $this->backend->template('headline1', [
            '{text}' => $unreviewedIcon. ' {#user.headline_unreviewed}'
        ]);
        echo $this->backend->template('row_start');
        echo $this->backend->template('col_start_50');

        echo $this->_unreviewedList();

        echo $this->backend->template('col_end');
        echo $this->backend->template('row_end');

        // Container footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Widget für die Startseite (settings.php 'widget').
     *
     * Derselbe Inhalt wie list(), ohne Seitenrahmen und Überschrift - die kommen
     * von der Widget-Karte.
     */
    public function widget(): string
    {
        return $this->_unreviewedList();
    }

    /**
     * Die Liste der gegenzulesenden Inhalte: je Inhaltsbereich eine Schaltfläche,
     * oder die Erfolgsmeldung, wenn nichts offen ist.
     *
     * Bereichsname und Icon kommen aus `area` und damit aus dem Backend - trotzdem
     * maskiert: ein Bereichsname ist ein Eingabefeld, kein Bezeichner.
     */
    private function _unreviewedList(): string
    {
        $unreviewedByArea = $this->_unreviewedByArea();
        if (!$unreviewedByArea) {
            return $this->backend->template('alert_success', [
                '{text}' => '{#user.message_no_new_content}'
            ]);
        }

        $items = [];
        foreach ($unreviewedByArea as $item) {
            $items[] = $this->backend->template('user.area_review_button', [
                '{area_id}' => (string)(int)$item['area_id'],
                '{area_label}' => htmlspecialchars($this->cms->textOrSnippet((string)$item['label'])),
                '{area_icon}' => htmlspecialchars((string)$item['icon']),
                '{number}' => (string)(int)$item['number']
            ]);
        }
        return $this->backend->template('ul_unstyled', [
            '{items}' => '<li>' . implode("</li>\n<li>", $items). '</li>'
        ]);
    }

    /**
     * Offene Gegenlese-Aufgaben je Inhaltsbereich zählen.
     *
     * LEFT JOIN auf `area`, damit Datensätze mit inzwischen gelöschtem Bereich nicht
     * unter den Tisch fallen - dort bleiben label und icon dann leer.
     *
     * @return ?array  Zeilen mit area_id, label, icon, number - leer, wenn nichts offen ist
     */
    private function _unreviewedByArea(): ?array
    {
        $query = "SELECT `r`.`area_id`, `a`.`label`, `a`.`icon`, COUNT(`r`.`record_id`) `number`"
            ." FROM `record` `r` LEFT JOIN `area` `a`"
            ." ON `r`.`area_id` = `a`.`area_id`"
            ." WHERE `r`.`needs_review`=1"
            ." GROUP BY `a`.`area_id`"
            ." ORDER BY `a`.`position` ASC";
        $this->db->query($query);
        return $this->db->fetchAll();
    }
}
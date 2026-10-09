<?php
/**
 * Backend-Oberfläche für die CMS-internen Nachrichten (Tabelle `message`).
 *
 * Einstiegspunkte (Scripte in backend/):
 *
 *     message_list.php    -> list()    Posteingang (tab=outbox: Postausgang) des
 *                                      angemeldeten Admins
 *     message_info.php    -> info()    Nachricht lesen (markiert sie als gelesen)
 *     message_edit.php    -> edit()    neu / bearbeiten / weiterleiten / löschen
 *     message_action.php  -> action()  gelesen / ungelesen (Ajax, ohne Seite)
 *     ajax_unread.php     -> unread()  Zähler für das Abzeichen im Backend-Kopf
 *
 * Erreichbar über das Brief-Symbol rechts oben (cApp.mail.open()); das Abzeichen
 * darauf zeigt die ungelesenen Nachrichten (höchstens "99"), gesetzt beim Laden des
 * Backends (index.php), nach jeder Aktion hier und alle 30 Sekunden vom Timer
 * (cApp.polling -> cApp.mail.refreshBadge() -> ajax_unread.php).
 *
 * Liste und Nachricht liegen als Layer übereinander wie in jedem anderen Modul -
 * kein zweispaltiges Mailprogramm. Die Liste zeigt die Nachrichten AN den
 * angemeldeten Admin (Eingang) bzw. VON ihm (Ausgang); die Bedingung kommt als 'sql'
 * in select(), nicht als Filter, damit sie nicht im Filtertext auftaucht und nicht
 * über den Request zu ändern ist. Das Brief-Symbol öffnet die Liste nicht ein
 * zweites Mal, solange sie oben liegt (cApp.layers.addOnce()).
 *
 * EMPFÄNGER
 * --------------------
 * Das Feld "An" ist ein Lookup-Feld auf die Admin-Liste (Vorlage
 * edit_form_lookup_foreign_table): AdminBackend::list() mit src_field=to_admin_id
 * zeigt die Checkbox-Spalte, AdminBackend::lookupPreviewCreate() liefert den
 * Vorschaublock mit dem versteckten Feld data[to_admin_id]. Geschrieben wird erst
 * beim Speichern des Formulars.
 *
 * WEITERLEITEN
 * --------------------
 * function=forward lädt die Nachricht wie zum Bearbeiten, verwirft aber Id und
 * Empfänger und stellt "FWD: " vor den Betreff. Text, HTML-Teil und Datensatzbezug
 * bleiben - gespeichert wird eine NEUE Nachricht mit dem Weiterleitenden als Absender.
 *
 * DATENSATZBEZUG
 * --------------------
 * Der Mail-Button auf der Info-Seite eines Datensatzes (RecordBackendInfo) ruft
 * message_edit.php?function=create&record_id={id}. Im Formular steht der Datensatz
 * als unverlinkter Teaser unter dem Text, gespeichert wird nur seine Id
 * (Message::record_id). Der Empfänger sieht denselben Teaser verlinkt und kommt
 * damit auf die Info-Seite des Datensatzes - der Titel ist dabei immer der aktuelle.
 *
 * @see Camindo\Core\Modules\Messaging\Message  Dataset/Model
 * @see Camindo\Core\Modules\Admin\AdminBackend  Lookup-Ziel für den Empfänger
 * @see Camindo\Core\Backend
 */
namespace Camindo\Core\Modules\Messaging;

use Camindo\Core\Backend;
use Camindo\Core\Database;
use Camindo\Core\Cms;
use Camindo\Core\Session;
use Camindo\Core\Modules\Admin\AdminBackend;
use Camindo\Core\Modules\Messaging\Message;

class MessagingBackend {

    /** Höchster Wert, den das Abzeichen im Backend-Kopf anzeigt */
    public const BADGE_MAX = 99;

    protected string $iconMessage = '';

    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';
    /** admin_id aus der Session - Empfänger der gelisteten, Absender neuer Nachrichten */
    protected int $adminId = 0;

    public function __construct(
        private Backend $backend,
        private Database $db,
        private Cms $cms,
        private Session $session,
        private Message $message,
        private AdminBackend $adminBackend
    )
    {
        $this->iconMessage = $this->backend->template('messaging.icon_message');
        $this->adminId = (int)$this->session->get('admin_id');
    }

    /**
     * Posteingang / Postausgang (backend/message_list.php).
     *
     * Zwei Reiter auf derselben Liste: ohne tab die empfangenen Nachrichten
     * (to_admin_id = Session-Admin, Spalte "Von"), mit tab=outbox die gesendeten
     * (created_by = Session-Admin, Spalte "An"). Ungelesene Zeilen sind im Eingang
     * fett; das Symbol zeigt in beiden Reitern, ob der Empfänger gelesen hat.
     * Sortierung und Blättern kommen aus dem Request; die Bedingung des Reiters ist
     * fest (siehe Klassenkommentar). Das Kontextmenü führt Gelesen/Ungelesen nur im
     * Eingang.
     *
     * Request: tab ('' | outbox), sort, order, skip
     */
    public function list(): void
    {
        $isInbox = ((string)($_REQUEST['tab'] ?? '') !== 'outbox');

        // Bread crum navi
        $this->backend->setNaviLabel('#messaging.module_label');

        // Container header
        echo $this->backend->template('container_header');

        // H1
        echo $this->backend->headline('#messaging.headline_message_list');

        // Tabs
        // --------------------
        $this->backend->tabBarReset();
        $this->backend->tabAdd('#messaging.tab_inbox', "cApp.layers.replace('Messaging/message_list.php');", $isInbox);
        $this->backend->tabAdd('#messaging.tab_outbox', "cApp.layers.replace('Messaging/message_list.php?tab=outbox');", !$isInbox);
        echo $this->backend->tabBar();

        // Buttons
        // --------------------
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_create_primary', "cApp.layers.add('Messaging/message_edit.php?function=create');");
        $this->backend->buttonAdd('button_refresh', "cApp.layers.refresh();");
        echo $this->backend->buttonBar();

        // Liste
        // --------------------
        $f = $this->cms->select([
            'request' => 'skip,limit,sort,order',
            'limit' => $this->cms->list_rows,
            'count' => 'true',
            'filtertext' => 'true',
            'sql' => $isInbox
                ? "`message`.`to_admin_id` = {$this->adminId} AND `message`.`recipient_deleted` = 0"
                : "`message`.`created_by` = {$this->adminId} AND `message`.`sender_deleted` = 0",
        ], $this->message);

        // Geänderte Sortierung als lesbarer Text
        echo $this->backend->filterText($f);

        // Pagination top (zeigt bei 0 Treffern "Keine Nachrichten")
        echo $this->backend->paginationTop('messaging.listed_records');

        if ($this->db->num_rows > 0) {
            // Table head: Von (Eingang) bzw. An (Ausgang)
            $schema = $this->message->getSchema();
            $partnerField = $isInbox ? 'created_by' : 'to_admin_id';
            $headColumns = $this->backend->template('messaging.message_table_head_columns', [
                '{label_partner}' => $this->backend->tableHeadColumn($schema[$partnerField], $f, $partnerField),
                '{label_created_at}' => $this->backend->tableHeadColumn($schema['created_at'], $f, 'created_at'),
                '{label_subject}' => $this->backend->tableHeadColumn($schema['subject'], $f, 'subject'),
            ]);

            // Table rows (Zeilenklick öffnet die Nachricht in dieser Sicht)
            $tplTableRow = $this->backend->template('messaging.message_table_row', [
                '{box}' => $isInbox ? 'inbox' : 'outbox',
            ]);
            $tableRows = '';
            while ($data = $this->message->fetch()) {
                $isRead = (int)($data['is_read'] ?? 0) === 1;
                $tableRows .= strtr($tplTableRow, [
                    '{data_id}' => $data['message_id'],
                    '{class}' => ($isInbox && !$isRead) ? ' fw-bold' : '',
                    '{icon}' => $this->_stateIcons($data),
                    '{data_partner}' => $this->backend->teaser('admin', (int)$data[$partnerField], linked: false, icon: false, label: true)
                        ?: $this->backend->emptyPlaceholder,
                    '{data_date}' => $this->backend->backendValue($data['created_at'], $schema['created_at']),
                    '{data_subject}' => htmlspecialchars((string)$data['subject']),
                ]) . "\n";
            }

            // Render table
            echo $this->backend->template('messaging.message_table_with_head', [
                '{head_columns}' => $headColumns,
                '{table_rows}' => $tableRows,
                '{box}' => $isInbox ? 'inbox' : 'outbox',
            ]);

            // Context menu (samt Zielcontainer für die Ajax-Aktionen gelesen/ungelesen);
            // Löschen nennt die Sicht - es nimmt die Nachricht nur aus DIESER Liste
            echo $this->backend->template('messaging.message_context_menu', [
                '{read_items}' => $isInbox ? $this->backend->template('messaging.message_context_menu_read') : '',
                '{box}' => $isInbox ? 'inbox' : 'outbox',
            ]);

            // Pagination bottom
            echo $this->backend->paginationBottom();
        }

        // Abzeichen mitziehen: die Liste ist der Ort, an dem der Zähler stimmen muss
        echo $this->_badgeScript();

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Nachricht lesen (backend/message_info.php).
     *
     * Öffnet der Empfänger die Nachricht, gilt sie als gelesen (Message::markRead()).
     * Der Text ist Klartext und wird als solcher ausgegeben (htmlspecialchars + nl2br);
     * der vom CMS erzeugte HTML-Teil steht roh darunter, der Datensatzbezug als
     * verlinkter Teaser.
     */
    public function info(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        // Sicht: aus der Liste mitgegeben; noread=1 nach "Ungelesen markieren" - die
        // Seite erscheint erneut, ohne die Nachricht gleich wieder zu lesen
        $box = (string)($_REQUEST['box'] ?? '');
        $noRead = !empty($_REQUEST['noread']);

        // Bread crum navi
        $this->backend->setNaviLabel('#button_info');

        // Container header
        echo $this->backend->template('container_header');

        if (!$this->data = $this->_loadAccessible($this->id)) {
            $this->_editErrors();
            echo $this->backend->template('container_footer');
            return;
        }
        $data = $this->data;
        $box = $this->message->boxOf($data, $this->adminId, $box);
        $isRecipient = ((int)$data['to_admin_id'] === $this->adminId);
        $isRead = (int)($data['is_read'] ?? 0) === 1;
        $isImportant = (int)($data['is_important'] ?? 0) === 1;
        $listChanged = false;

        // Empfänger liest: als gelesen markieren - die Liste darunter zieht nach
        if ($isRecipient && !$isRead && !$noRead) {
            $this->message->markRead($this->id, true);
            $data['is_read'] = 1;
            $isRead = true;
            $listChanged = true;
        }

        // H1: Briefumschlag (geschlossen/geöffnet, ggf. mit Warnung) + Betreff
        echo $this->backend->headline($this->_stateIcons($data) . ' ' . htmlspecialchars((string)$data['subject']));

        // Buttons
        $boxParam = '&box=' . $box;
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Messaging/message_edit.php?id={$this->id}{$boxParam}');");
        if ($isRecipient) {
            // Markieren: Pulldown - die Seite erscheint danach erneut (message_action
            // mit back=info), nur das Symbol vor dem Betreff wechselt
            $actionUrl = "Messaging/message_action.php?id={$this->id}&back=info{$boxParam}";
            $this->backend->buttonAdd('messaging.button_mark_dropdown', '', '');
            $this->backend->buttonBarReplace('{item_read}', $this->backend->template('messaging.button_mark_item', [
                '{onclick}' => "cApp.layers.replacePost('{$actionUrl}&function=" . ($isRead ? 'unread' : 'read') . "');",
                '{icon}' => $isRead ? 'mail' : 'drafts',
                '{label}' => $isRead ? '{#messaging.button_mark_unread}' : '{#messaging.button_mark_read}',
            ]));
            $this->backend->buttonBarReplace('{item_important}', $this->backend->template('messaging.button_mark_item', [
                '{onclick}' => "cApp.layers.replacePost('{$actionUrl}&function=" . ($isImportant ? 'unimportant' : 'important') . "');",
                '{icon}' => $isImportant ? 'warning_off' : 'warning',
                '{label}' => $isImportant ? '{#messaging.button_mark_unimportant}' : '{#messaging.button_mark_important}',
            ]));
        }
        $this->backend->buttonAdd('messaging.button_forward', "cApp.layers.replace('Messaging/message_edit.php?id={$this->id}&function=forward');");
        $this->backend->buttonAdd('button_delete', "cApp.layers.replace('Messaging/message_edit.php?id={$this->id}&function=delete{$boxParam}');");
        if (!empty($_REQUEST['back'])) {
            $this->backend->buttonAdd('button_back', "history.back()");
        }
        else {
            $this->backend->buttonAdd('button_close', "cApp.layers.close()");
        }
        echo $this->backend->buttonBar();

        // Kopfdaten (grau) und Nachricht (weiß): Betreff, Klartext, HTML-Teil,
        // Datensatz (verlinkt)
        $schema = $this->message->getSchema();
        echo $this->backend->template('messaging.message_view', [
            '{from}' => $this->backend->teaser('admin', (int)$data['created_by']) ?: $this->backend->emptyPlaceholder,
            '{to}' => $this->backend->teaser('admin', (int)$data['to_admin_id']) ?: $this->backend->emptyPlaceholder,
            '{date}' => $this->backend->backendValue($data['created_at'], $schema['created_at']),
            '{subject}' => htmlspecialchars((string)$data['subject']),
            '{body}' => $this->_bodyHtml(
                (string)$data['body'],
                (string)($data['html'] ?? ''),
                (int)($data['record_id'] ?? 0),
                linked: true
            ),
        ]);

        // Abzeichen mitziehen und - wurde soeben gelesen - die Liste im Layer darunter
        if ($listChanged) $this->backend->script("cApp.mail.refreshList();");
        echo $this->_badgeScript();

        echo $this->backend->template('container_footer');
    }

    /**
     * Symbole zum Zustand einer Nachricht: geschlossener (ungelesen) oder geöffneter
     * (gelesen) Briefumschlag, bei wichtigen Nachrichten daneben ein Warn-Symbol.
     */
    private function _stateIcons(array $data): string
    {
        $isRead = (int)($data['is_read'] ?? 0) === 1;
        $html = $this->backend->template($isRead ? 'messaging.icon_message_read' : 'messaging.icon_message_unread');
        if ((int)($data['is_important'] ?? 0) === 1) {
            $html .= $this->backend->template('messaging.icon_message_important');
        }
        return $html;
    }

    /**
     * Markieren (backend/message_action.php) - Ajax, liefert nur Javascript.
     *
     *     function = read | unread | important | unimportant
     *
     * Aus dem Kontextmenü der Liste geladen in den versteckten Container
     * #c9-message-action (data-target): danach wird die Liste neu geladen. Aus der
     * Detailansicht per layers.replace() mit back=info: danach erscheint die
     * Detailansicht erneut (nach "ungelesen" mit noread=1, sonst läse sie die
     * Nachricht gleich wieder) - nur das Symbol vor dem Betreff wechselt; die Liste
     * im Layer darunter zieht nach (cApp.mail.refreshList()).
     *
     * Request: id, function, back = info (optional), box (optional)
     */
    public function action(): void
    {
        // Setzt Kennzeichen der Nachricht - nur per POST (17-csrf.md §2.7)
        if (!$this->backend->requirePost()) {
            echo $this->backend->access()->deniedHtml();
            return;
        }
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $function = (string)($_REQUEST['function'] ?? '');
        $backToInfo = ((string)($_REQUEST['back'] ?? '') === 'info');
        $box = preg_replace('/[^a-z]/', '', (string)($_REQUEST['box'] ?? ''));

        if (!$data = $this->_loadAccessible($this->id)) {
            $this->_editErrors();
            return;
        }
        // Markieren darf nur der Empfänger - die Kennzeichen sind seine
        $isRecipient = ((int)$data['to_admin_id'] === $this->adminId);
        if ($isRecipient) {
            match ($function) {
                'read'        => $this->message->markRead($this->id, true),
                'unread'      => $this->message->markRead($this->id, false),
                'important'   => $this->message->markImportant($this->id, true),
                'unimportant' => $this->message->markImportant($this->id, false),
                default       => null,
            };
        }

        if ($backToInfo) {
            $url = "Messaging/message_info.php?id={$this->id}&box={$box}" . ($function === 'unread' ? '&noread=1' : '');
            $script = "cApp.layers.replace('{$url}');\ncApp.mail.refreshList();\n";
        }
        else {
            $script = "cApp.mail.refreshList();\n";
        }
        $script .= $this->_badgeJs();
        $this->backend->script($script);
    }

    /**
     * Zähler für das Abzeichen (backend/ajax_unread.php) - nur Javascript, vom Timer
     * alle 30 Sekunden geholt (cApp.mail.refreshBadge()). Muss klein bleiben.
     */
    public function unread(): void
    {
        echo $this->_badgeScript();
    }

    /**
     * Anzahl ungelesener Nachrichten des angemeldeten Admins, gedeckelt auf
     * BADGE_MAX - mehr passt nicht ins Abzeichen.
     */
    public function badgeCount(): int
    {
        return min($this->message->unreadCount($this->adminId), self::BADGE_MAX);
    }

    /**
     * Javascript, das das Abzeichen setzt (ohne <script>-Klammer)
     */
    private function _badgeJs(): string
    {
        return "cApp.mail.setBadge(" . $this->badgeCount() . ");";
    }

    /**
     * <script>-Block, der das Abzeichen setzt - ans Ende einer Seite gehängt
     */
    private function _badgeScript(): string
    {
        return '<script' . $this->backend->nonceAttr() . '>' . $this->_badgeJs() . "</script>\n";
    }

    /**
     * Nachricht laden und Zugriff prüfen (Empfänger oder Absender).
     * Fehlermeldung in Cms::errors, Rückgabe null bei Misserfolg.
     */
    private function _loadAccessible(int $id): ?array
    {
        if (!$data = $this->message->load($id)) return null;
        if (!$this->message->canAccess($data, $this->adminId)) {
            $this->cms->error($this->cms->snippet('messaging.error_access_denied'));
            return null;
        }
        return $data;
    }

    /**
     * Den Nachrichtenkörper rendern: Klartext, darunter der HTML-Teil, darunter der
     * Datensatz-Teaser.
     *
     * @param string $body      Klartext
     * @param string $html      vom CMS erzeugter HTML-Code ('' = keiner)
     * @param int    $recordId  Datensatzbezug (0 = keiner)
     * @param bool   $linked    Teaser verlinkt (Info-Seite) oder nicht (Formular)
     */
    private function _bodyHtml(string $body, string $html, int $recordId, bool $linked): string
    {
        $recordHtml = '';
        if ($recordId > 0) {
            $teaser = $this->backend->teaser('record', $recordId, linked: $linked, icon: true);
            if ($teaser !== '') {
                $recordHtml = $this->backend->template('messaging.message_record', ['{teaser}' => $teaser]);
            }
        }
        return $this->backend->template('messaging.message_body', [
            '{text}' => nl2br(htmlspecialchars($body)),
            '{html}' => $html,
            '{record}' => $recordHtml,
        ]);
    }

    /**
     * Neu / bearbeiten / weiterleiten / löschen (backend/message_edit.php).
     *
     * Zustandsautomat über $_REQUEST['function'] wie in AdminBackend::edit()
     * (docs/developers/03-backend-entwicklung.md); zusätzlich 'forward'.
     *
     * Request: id, function, data[...], record_id (nur create: Datensatzbezug)
     */
    public function edit(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        $this->function = (string)($_REQUEST['function'] ?? '');
        if ($this->function == '') $this->function = 'edit';
        $this->data = (array)($_REQUEST['data'] ?? []);
        $this->data['message_id'] = $this->id;

        // Bread crum navi
        $naviLabel = ($this->function === 'forward') ? '#messaging.button_forward' : '#button_' . $this->function;
        $this->backend->setNaviLabel($naviLabel);

        // Container header
        echo $this->backend->template('container_header');

        // Create
        if ($this->function == 'create') {
            $this->_editCreate();
        }

        // Load
        if (in_array($this->function, ['edit', 'forward', 'delete'], true)) {
            $this->_editLoad();
        }

        // H1
        echo $this->_editHeadline();

        // Save
        if ($this->function == 'save') {
            $this->_editSave();
        }

        // Delete confirm
        if ($this->function == 'delete_confirm') {
            $this->_editDeleteConfirm();
        }

        // Errors
        if (in_array($this->function, ['errors', 'skip'], true)) {
            $this->_editErrors();
        }

        // Edit form
        if (in_array($this->function, ['create', 'edit', 'forward', 'errors'], true)) {
            $this->_editForm();
        }

        // Delete form
        if ($this->function == 'delete') {
            $this->_editDeleteForm();
        }

        echo $this->backend->template('container_footer');
    }


    // ====================
    // edit() Hilfsfunktionen

    /**
     * function=create: leeres Formular. Ein record_id im Request (Mail-Button auf der
     * Info-Seite eines Datensatzes) wird als Datensatzbezug übernommen.
     */
    private function _editCreate(): void
    {
        $this->data = $this->message->init();
        $this->data['record_id'] = (int)($_REQUEST['record_id'] ?? 0);
        $this->id = 0;
    }

    /**
     * function=edit|forward|delete: Nachricht laden (nur Empfänger oder Absender).
     *
     * Weiterleiten: Id und Empfänger fallen weg, der Betreff bekommt den Präfix
     * "FWD: " (nur einmal) - gespeichert wird eine neue Nachricht.
     */
    private function _editLoad(): void
    {
        $this->data = $this->_loadAccessible($this->id);
        if (!$this->data) {
            $this->function = 'skip';
            return;
        }
        if ($this->function == 'forward') {
            $this->id = 0;
            $this->data['message_id'] = 0;
            $this->data['to_admin_id'] = 0;
            $prefix = $this->cms->snippet('messaging.prefix_forward');
            if (!str_starts_with((string)$this->data['subject'], $prefix)) {
                $this->data['subject'] = $prefix . $this->data['subject'];
            }
        }
    }

    /**
     * Überschrift des Formulars: beim Löschen der Betreff, sonst je nach Funktion.
     */
    private function _editHeadline(): string
    {
        if ($this->function == 'delete') {
            $label = htmlspecialchars((string)($this->data['subject'] ?? ''));
        }
        elseif ($this->id > 0) {
            $label = $this->cms->snippet('messaging.headline_message_edit');
        }
        else {
            $label = $this->cms->snippet('messaging.headline_message_create');
        }
        return $this->backend->template('headline1', [
            '{text}' => $this->iconMessage . ' ' . $label,
        ]);
    }

    /**
     * function=save: speichern.
     *
     * Feldliste ausdrücklich: Empfänger, Betreff, Text - Datensatzbezug und HTML-Teil
     * nur, wenn das Formular sie mitbringt (versteckte Felder; der HTML-Teil nur bei
     * der Weiterleitung). Nicht übermittelte Spalten bleiben beim Ändern unverändert.
     * Absender und Datum setzt Dataset::save() (created_by/created_at) beim Anlegen.
     */
    private function _editSave(): void
    {
        // Bestehende Nachricht: nur Empfänger oder Absender
        if ($this->id > 0 && !$this->_loadAccessible($this->id)) {
            $this->function = 'errors';
            return;
        }

        $fields = ['to_admin_id', 'subject', 'body'];
        foreach (['record_id', 'html'] as $name) {
            if (array_key_exists($name, $this->data)) $fields[] = $name;
        }

        if ($this->message->save($fields, $this->data)) {
            $this->backend->balloon('success', $this->cms->snippet('messaging.message_message_saved'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
                cApp.mail.refreshList();
                " . $this->_badgeJs() . "
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * function=delete_confirm: aus der eigenen Sicht löschen - endgültig, sobald beide
     * Seiten gelöscht haben (Message::deleteFor(), kein Papierkorb).
     */
    private function _editDeleteConfirm(): void
    {
        // Nur aus der eigenen Sicht (Ein- oder Ausgang) entfernen - die andere Seite
        // behält die Nachricht (Message::deleteFor())
        $box = preg_replace('/[^a-z]/', '', (string)($_REQUEST['box'] ?? ''));
        $data = $this->_loadAccessible($this->id);
        $box = $data ? $this->message->boxOf($data, $this->adminId, $box) : '';
        if ($data && $box !== '' && $this->message->deleteFor($this->id, $box)) {
            $this->backend->balloon('success', $this->cms->snippet('messaging.message_message_deleted'));
            $this->backend->script("
                cApp.layers.invalidate();
                cApp.layers.close();
                cApp.mail.refreshList();
                " . $this->_badgeJs() . "
            ");
        }
        else {
            $this->function = 'errors';
        }
    }

    /**
     * Fehlermeldungen aus Cms::errors als roten Kasten ausgeben.
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger', [
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * Das Eingabeformular: Empfänger (Lookup), Betreff, Text; darunter - falls
     * vorhanden - HTML-Teil und Datensatz als unverlinkter Teaser (beides nur zur
     * Ansicht, mitgeführt in versteckten Feldern).
     */
    private function _editForm(): void
    {
        $changed = ($this->function == 'errors') ? ' data-changed="true"' : '';
        echo '<form action="Messaging/message_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('messaging.message_edit_form');

        // to_admin_id - Lookup auf die Admin-Liste
        // --------------------
        $toAdminId = (int)($this->data['to_admin_id'] ?? 0);
        $this->backend->formField($this->message, [
            'name' => 'to_admin_id',
            'value' => $toAdminId,
            'id' => $this->id,
            'template' => 'edit_form_lookup_foreign_table',
            // Das Kontextmenü hinter einem Lookup-Feld gehört zu den Verknüpfungs-
            // feldern der Datensätze (field_id) - hier gibt es keins.
            'contextMenu' => '',
        ]);
        $this->backend->formReplace('{src_id}', (string)$this->id);
        $this->backend->formReplace('{src_field}', 'to_admin_id');
        $this->backend->formReplace('{search_url}', 'Admin/admin_list.php?src_field=to_admin_id');
        $this->backend->formReplace('{update_url}', 'Admin/ajax_lookup_preview.php?src_field=to_admin_id');
        $this->backend->formReplace('{data_ids}', $toAdminId > 0 ? (string)$toAdminId : '');
        $this->backend->formReplace('{max}', '1');
        $this->backend->formReplace(
            '{preview}',
            $this->adminBackend->lookupPreviewCreate('to_admin_id', [$toAdminId], 'data', 1)
        );

        // subject, body
        // --------------------
        foreach (['subject', 'body'] as $key) {
            $this->backend->formField($this->message, [
                'name' => $key,
                'value' => $this->data[$key] ?? '',
                'id' => $this->id
            ]);
        }

        // HTML-Teil und Datensatzbezug (Ansicht + versteckte Felder)
        // --------------------
        $html = (string)($this->data['html'] ?? '');
        $recordId = (int)($this->data['record_id'] ?? 0);
        if ($html !== '' || $recordId > 0) {
            $this->backend->formReplace('{data_attachment}', $this->backend->template('edit_form_static', [
                '{label}' => $this->cms->snippet('messaging.label_attachment'),
                '{html}' => $this->backend->template('messaging.attachment_box', [
                    '{html}' => $this->backend->protect($this->_bodyHtml('', $html, $recordId, linked: false)),
                ]),
            ]));
        }
        if ($html !== '') $this->backend->formHidden('data[html]', $html);
        $this->backend->formHidden('data[record_id]', $recordId);

        // function
        $this->backend->formHidden('function', 'save');
        $this->backend->formHidden('id', $this->id);

        // Print form
        echo $this->backend->form('form_save_cancel_note', [], removePlaceholders: true);

        // JavaScript Anweisung für growing textarea, nach Ajax-Load
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");
        echo '</form>';
    }

    /**
     * Wirklich löschen? Formular
     */
    private function _editDeleteForm(): void
    {
        echo '<form action="Messaging/message_edit.php" method="post">';

        $this->backend->formInit('messaging.message_delete_form');

        $this->backend->formHidden('id', $this->id);
        $this->backend->formHidden('function', 'delete_confirm');
        $this->backend->formHidden('box', preg_replace('/[^a-z]/', '', (string)($_REQUEST['box'] ?? '')));

        // Print form
        echo $this->backend->form('form_delete_cancel');

        echo '</form>';
    }
}

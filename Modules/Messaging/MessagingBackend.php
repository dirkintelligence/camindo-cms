<?php
/**
 * Backend UI for the CMS-internal messages (table `message`).
 *
 * Entry points (scripts in backend/):
 *
 *     message_list.php    -> list()    inbox (tab=outbox: outbox) of the
 *                                      logged-in admin
 *     message_info.php    -> info()    read message (marks it as read)
 *     message_edit.php    -> edit()    new / edit / forward / delete
 *     message_action.php  -> action()  read / unread (Ajax, without a page)
 *     ajax_unread.php     -> unread()  counter for the badge in the backend header
 *
 * Reachable via the envelope symbol at the top right (cApp.mail.open()); the badge
 * on it shows the unread messages (at most "99"), set when the backend is loaded
 * (index.php), after each action here and every 30 seconds by the timer
 * (cApp.polling -> cApp.mail.refreshBadge() -> ajax_unread.php).
 *
 * List and message lie on top of each other as layers, as in every other module -
 * not a two-column mail program. The list shows the messages TO the
 * logged-in admin (inbox) resp. FROM them (outbox); the condition comes as 'sql'
 * in select(), not as a filter, so that it does not appear in the filter text and
 * cannot be changed via the request. The envelope symbol does not open the list
 * a second time while it is on top (cApp.layers.addOnce()).
 *
 * RECIPIENT
 * --------------------
 * The field "To" is a lookup field on the admin list (template
 * edit_form_lookup_foreign_table): AdminBackend::list() with src_field=to_admin_id
 * shows the checkbox column, AdminBackend::lookupPreviewCreate() returns the
 * preview block with the hidden field data[to_admin_id]. Writing happens only
 * when the form is saved.
 *
 * FORWARDING
 * --------------------
 * function=forward loads the message as for editing, but discards ID and
 * recipient and puts "FWD: " in front of the subject. Text, HTML part and record reference
 * remain - a NEW message is saved with the forwarder as sender.
 *
 * RECORD REFERENCE
 * --------------------
 * The mail button on the info page of a record (RecordBackendInfo) calls
 * message_edit.php?function=create&record_id={id}. In the form the record stands
 * as an unlinked teaser below the text; only its ID is saved
 * (Message::record_id). The recipient sees the same teaser linked and thus
 * reaches the info page of the record - the title is always the current one.
 *
 * @see Camindo\Core\Modules\Messaging\Message  Dataset/Model
 * @see Camindo\Core\Modules\Admin\AdminBackend  lookup target for the recipient
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

    /** Highest value the badge in the backend header shows */
    public const BADGE_MAX = 99;

    protected string $iconMessage = '';

    protected int $id = 0;
    protected ?array $data = [];
    protected string $function = '';
    /** admin_id from the session - recipient of the listed, sender of new messages */
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
     * Inbox / outbox (backend/message_list.php).
     *
     * Two tabs on the same list: without tab the received messages
     * (to_admin_id = session admin, column "From"), with tab=outbox the sent ones
     * (created_by = session admin, column "To"). Unread rows are bold in the inbox;
     * the symbol shows in both tabs whether the recipient has read it.
     * Sorting and paging come from the request; the condition of the tab is
     * fixed (see class comment). The context menu offers Read/Unread only in the
     * inbox.
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

        // List
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

        // Changed sorting as readable text
        echo $this->backend->filterText($f);

        // Pagination top (shows "No messages" for 0 hits)
        echo $this->backend->paginationTop('messaging.listed_records');

        if ($this->db->num_rows > 0) {
            // Table head: From (inbox) resp. To (outbox)
            $schema = $this->message->getSchema();
            $partnerField = $isInbox ? 'created_by' : 'to_admin_id';
            $headColumns = $this->backend->template('messaging.message_table_head_columns', [
                '{label_partner}' => $this->backend->tableHeadColumn($schema[$partnerField], $f, $partnerField),
                '{label_created_at}' => $this->backend->tableHeadColumn($schema['created_at'], $f, 'created_at'),
                '{label_subject}' => $this->backend->tableHeadColumn($schema['subject'], $f, 'subject'),
            ]);

            // Table rows (clicking a row opens the message in this view)
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

            // Context menu (incl. target container for the Ajax actions read/unread);
            // Delete names the view - it removes the message only from THIS list
            echo $this->backend->template('messaging.message_context_menu', [
                '{read_items}' => $isInbox ? $this->backend->template('messaging.message_context_menu_read') : '',
                '{box}' => $isInbox ? 'inbox' : 'outbox',
            ]);

            // Pagination bottom
            echo $this->backend->paginationBottom();
        }

        // Carry the badge along: the list is the place where the counter must match
        echo $this->_badgeScript();

        // Container Footer
        echo $this->backend->template('container_footer');
    }

    /**
     * Read message (backend/message_info.php).
     *
     * If the recipient opens the message, it counts as read (Message::markRead()).
     * The text is plain text and is output as such (htmlspecialchars + nl2br);
     * the HTML part generated by the CMS stands raw below it, the record reference as
     * a linked teaser.
     */
    public function info(): void
    {
        $this->id = (int)($_REQUEST['id'] ?? 0);
        // View: passed on from the list; noread=1 after "Mark as unread" - the
        // page appears again without reading the message right away
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

        // Recipient reads: mark as read - the list below follows
        if ($isRecipient && !$isRead && !$noRead) {
            $this->message->markRead($this->id, true);
            $data['is_read'] = 1;
            $isRead = true;
            $listChanged = true;
        }

        // H1: envelope (closed/open, with warning if applicable) + subject
        echo $this->backend->headline($this->_stateIcons($data) . ' ' . htmlspecialchars((string)$data['subject']));

        // Buttons
        $boxParam = '&box=' . $box;
        $this->backend->buttonBarReset();
        $this->backend->buttonAdd('button_edit_primary', "cApp.layers.replace('Messaging/message_edit.php?id={$this->id}{$boxParam}');");
        if ($isRecipient) {
            // Mark: pulldown - the page appears again afterwards (message_action
            // with back=info), only the symbol before the subject changes
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

        // Header data (gray) and message (white): subject, plain text, HTML part,
        // record (linked)
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

        // Carry the badge along and - if just read - the list in the layer below
        if ($listChanged) $this->backend->script("cApp.mail.refreshList();");
        echo $this->_badgeScript();

        echo $this->backend->template('container_footer');
    }

    /**
     * Symbols for the state of a message: closed (unread) or open (read)
     * envelope; for important messages a warning symbol next to it.
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
     * Mark (backend/message_action.php) - Ajax, returns only JavaScript.
     *
     *     function = read | unread | important | unimportant
     *
     * Loaded from the context menu of the list into the hidden container
     * #c9-message-action (data-target): afterwards the list is reloaded. From the
     * detail view via layers.replace() with back=info: afterwards the detail
     * view appears again (after "unread" with noread=1, otherwise it would read the
     * message right away again) - only the symbol before the subject changes; the list
     * in the layer below follows (cApp.mail.refreshList()).
     *
     * Request: id, function, back = info (optional), box (optional)
     */
    public function action(): void
    {
        // Sets flags of the message - only via POST (developer docs, chapter 14 (Security: web))
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
        // Only the recipient may mark - the flags are theirs
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
     * Counter for the badge (backend/ajax_unread.php) - only JavaScript, fetched by the timer
     * every 30 seconds (cApp.mail.refreshBadge()). Must stay small.
     */
    public function unread(): void
    {
        echo $this->_badgeScript();
    }

    /**
     * Number of unread messages of the logged-in admin, capped at
     * BADGE_MAX - more does not fit into the badge.
     */
    public function badgeCount(): int
    {
        return min($this->message->unreadCount($this->adminId), self::BADGE_MAX);
    }

    /**
     * JavaScript that sets the badge (without <script> brackets)
     */
    private function _badgeJs(): string
    {
        return "cApp.mail.setBadge(" . $this->badgeCount() . ");";
    }

    /**
     * <script> block that sets the badge - appended at the end of a page
     */
    private function _badgeScript(): string
    {
        return '<script' . $this->backend->nonceAttr() . '>' . $this->_badgeJs() . "</script>\n";
    }

    /**
     * Load message and check access (recipient or sender).
     * Error message in Cms::errors, return null on failure.
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
     * Render the message body: plain text, below it the HTML part, below that the
     * record teaser.
     *
     * @param string $body      plain text
     * @param string $html      HTML code generated by the CMS ('' = none)
     * @param int    $recordId  record reference (0 = none)
     * @param bool   $linked    teaser linked (info page) or not (form)
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
     * New / edit / forward / delete (backend/message_edit.php).
     *
     * State machine via $_REQUEST['function'] as in AdminBackend::edit()
     * (developer docs, chapter 05 (Backend development)); additionally 'forward'.
     *
     * Request: id, function, data[...], record_id (only create: record reference)
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
    // edit() helper functions

    /**
     * function=create: empty form. A record_id in the request (mail button on the
     * info page of a record) is taken over as record reference.
     */
    private function _editCreate(): void
    {
        $this->data = $this->message->init();
        $this->data['record_id'] = (int)($_REQUEST['record_id'] ?? 0);
        $this->id = 0;
    }

    /**
     * function=edit|forward|delete: load message (only recipient or sender).
     *
     * Forward: ID and recipient fall away, the subject gets the prefix
     * "FWD: " (only once) - a new message is saved.
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
     * Heading of the form: when deleting the subject, otherwise depending on the function.
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
     * function=save: save.
     *
     * Explicit field list: recipient, subject, text - record reference and HTML part
     * only if the form carries them (hidden fields; the HTML part only with
     * forwarding). Columns not transmitted stay unchanged when editing.
     * Sender and date are set by Dataset::save() (created_by/created_at) when creating.
     */
    private function _editSave(): void
    {
        // Existing message: only recipient or sender
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
     * function=delete_confirm: remove from own view - finally, as soon as both
     * sides have deleted (Message::deleteFor(), no trash).
     */
    private function _editDeleteConfirm(): void
    {
        // Remove only from own view (inbox or outbox) - the other side
        // keeps the message (Message::deleteFor())
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
     * Output error messages from Cms::errors as a red box.
     */
    private function _editErrors(): void
    {
        echo $this->backend->template('alert_danger', [
            '{text}' => $this->cms->errorMsg()
        ]);
    }

    /**
     * The input form: recipient (lookup), subject, text; below - if
     * present - HTML part and record as unlinked teaser (both only for
     * display, carried along in hidden fields).
     */
    private function _editForm(): void
    {
        $changed = ($this->function == 'errors') ? ' data-changed="true"' : '';
        echo '<form action="Messaging/message_edit.php" method="post"' . $changed . '>';

        $this->backend->formInit('messaging.message_edit_form');

        // to_admin_id - lookup on the admin list
        // --------------------
        $toAdminId = (int)($this->data['to_admin_id'] ?? 0);
        $this->backend->formField($this->message, [
            'name' => 'to_admin_id',
            'value' => $toAdminId,
            'id' => $this->id,
            'template' => 'edit_form_lookup_foreign_table',
            // The context menu behind a lookup field belongs to the link
            // fields of the records (field_id) - there are none here.
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

        // HTML part and record reference (display + hidden fields)
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

        // JavaScript statement for growing textarea, after Ajax load
        $this->backend->script("cApp.ui._initTextareaGrow(\$('.c9-layer:last'));");
        echo '</form>';
    }

    /**
     * Really delete? Form
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

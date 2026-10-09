<?php
/**
 * options_provider of the MarkdownVisual extension (Core/Extensions/MarkdownVisual):
 * adds the UI texts (tooltips, menus, dialogs) in the backend language to the field
 * options - snippets ext.markdownvisual.ui_{key}. The script knows the same keys with
 * English defaults (TEXTS in markdownvisual.ext.js).
 */
namespace Camindo\Core;

class MarkdownVisualOptions {

    /** Snippet prefix of the texts (HtmlVisualOptions: ext.htmlvisual.ui_) */
    protected const PREFIX = 'ext.markdownvisual.ui_';

    public const TEXT_KEYS = [
        'editor', 'mod', 'bold', 'italic', 'underline', 'code', 'heading', 'paragraph', 'heading_n',
        'ul', 'ol', 'indent', 'outdent', 'quote', 'codeblock', 'link', 'hr', 'br', 'special',
        'align_left', 'align_center', 'align_right', 'dir', 'dir_ltr', 'dir_rtl', 'table', 'undo', 'redo', 'source',
        'link_url', 'link_text', 'link_apply', 'link_remove', 'link_invalid', 'cancel',
        'table_cols', 'table_rows', 'table_insert', 'table_note', 'col_left', 'col_right', 'col_delete',
        'row_above', 'row_below', 'row_delete', 'table_delete',
        'group_currency', 'group_symbols', 'group_quotes', 'group_dashes', 'group_spaces',
        'ch_ndash', 'ch_mdash', 'ch_nbhy', 'ch_minus', 'ch_nbsp', 'ch_nnbsp', 'ch_ensp', 'ch_thinsp', 'ch_shy',
    ];

    public function __construct(
        private Cms $cms,
    )
    {
    }

    public function clientOptions(array $options): array
    {
        $texts = [];
        foreach (self::TEXT_KEYS as $key) {
            // a missing snippet returns its index - then the script's default applies
            $index = static::PREFIX . $key;
            $text = $this->cms->snippet($index);
            if ($text !== '' && $text !== $index) $texts[$key] = $text;
        }
        $options['texts'] = $texts;
        return $options;
    }
}

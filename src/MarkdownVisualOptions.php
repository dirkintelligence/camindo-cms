<?php
/**
 * options_provider der Extension MarkdownVisual (Core/Extensions/MarkdownVisual):
 * ergänzt die Feldoptionen um die Texte der Oberfläche (Tooltips, Menüs, Dialoge) in
 * der Backend-Sprache - Snippets ext.markdownvisual.ui_{schlüssel}. Das Script kennt
 * dieselben Schlüssel mit englischen Vorgaben (TEXTS in markdownvisual.ext.js).
 */
namespace Camindo\Core;

class MarkdownVisualOptions {

    /** Snippet-Präfix der Texte (HtmlVisualOptions: ext.htmlvisual.ui_) */
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
            // ein fehlendes Snippet liefert seinen Index - dann gilt die Vorgabe des Scripts
            $index = static::PREFIX . $key;
            $text = $this->cms->snippet($index);
            if ($text !== '' && $text !== $index) $texts[$key] = $text;
        }
        $options['texts'] = $texts;
        return $options;
    }
}

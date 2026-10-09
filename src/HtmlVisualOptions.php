<?php
/**
 * options_provider of the HTMLVisual extension (Core/Extensions/HTMLVisual): the same
 * UI texts as MarkdownVisual, from the snippets ext.htmlvisual.ui_{key}.
 * The script knows the same keys with English defaults (TEXTS in htmlvisual.ext.js).
 */
namespace Camindo\Core;

class HtmlVisualOptions extends MarkdownVisualOptions {

    protected const PREFIX = 'ext.htmlvisual.ui_';
}

<?php
/**
 * options_provider der Extension HTMLVisual (Core/Extensions/HTMLVisual): dieselben
 * Texte der Oberfläche wie MarkdownVisual, aus den Snippets ext.htmlvisual.ui_{schlüssel}.
 * Das Script kennt dieselben Schlüssel mit englischen Vorgaben (TEXTS in htmlvisual.ext.js).
 */
namespace Camindo\Core;

class HtmlVisualOptions extends MarkdownVisualOptions {

    protected const PREFIX = 'ext.htmlvisual.ui_';
}

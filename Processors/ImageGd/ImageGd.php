<?php
namespace Camindo\Core\Processors\ImageGd;

use Camindo\Core\MediaProcessor;

class ImageGd extends MediaProcessor {

    // Backend
    // ====================

    /**
     * Tabellenstruktur für den Konverter
     * Darauf basierend kann mit $this->backend->formField() ein Eingabefeld erzeugt werden
     * 
     * type:
     *     integer -> BIGINT
     *     chars -> VARCHAR(255)
     *     bit -> BIT(1)
     * display_type:
     *     text:   einfaches Eingabefeld
     *     select: Auswahl, Optionen werden am besten dynamisch erzeugt
     *     label:  mehrsprachiges Snippet #proc.{processorname}.{snippetname} 
     *     prefix,
     *     suffix: erscheint vor / nach dem Eingabefeld
     *     pattern: Javascript Pattern für Eingabevalidierung
     *     min, max: Mindest- / Maximalwert, bei Text die Zeichenanzahl
     */
    protected function hookDefinition(): void
    {
        // Defaultwerte für Eingabefelder des Konverters
        $this->defaults['media_types_incl'] = implode("\n", [
            "image/jpeg",
            "image/png",
            "image/gif",
            "image/webp",
            "image/bmp"
        ]);

        // Zusätzliche Eingabefelder für den Konverter
        $this->schema = [
            'details.width' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_width',
                'default' => '300',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.height' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_height',
                'default' => '',
                'min' => 0,
                'suffix' => 'px'
            ],
            'details.crop' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_crop',
                'tooltip' => '#proc.imagegd.tooltip_field_crop',
                'display_type' => 'switch',
                'default' => 0
            ],
            // Größe: Bedingung, Einheit, Höchstzahl Pixel - wie bei Magick
            // (dort die Kennzeichen > < % @ der Geometrie), hier in targetGeometry()
            'details.resize_when' => [
                'type'  => 'chars',
                'label' => '#proc.imagegd.field_resize_when',
                'tooltip' => '#proc.imagegd.tooltip_field_resize_when',
                'display_type' => 'select',
                'options' => [
                    'always'  => '#proc.imagegd.option_resize_always',
                    'shrink'  => '#proc.imagegd.option_resize_shrink',
                    'enlarge' => '#proc.imagegd.option_resize_enlarge',
                ],
                'default' => 'always',
                'max' => 10
            ],
            'details.unit' => [
                'type'  => 'chars',
                'label' => '#proc.imagegd.field_unit',
                'tooltip' => '#proc.imagegd.tooltip_field_unit',
                'display_type' => 'select',
                'options' => [
                    'px'      => '#proc.imagegd.option_unit_px',
                    'percent' => '#proc.imagegd.option_unit_percent',
                ],
                'default' => 'px',
                'max' => 10
            ],
            'details.max_pixels' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_max_pixels',
                'tooltip' => '#proc.imagegd.tooltip_field_max_pixels',
                'note' => '#proc.imagegd.note_max_pixels',
                'min' => 0
            ],
            'details.quality' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_quality',
                'default' => '80',
                'suffix' => '%',
                'note' => '#proc.imagegd.note_quality',
                'tooltip' => '#proc.imagegd.tooltip_field_quality',
                'min' => 0,
            ],
            'details.indexed_colors' => [
                'type'  => 'integer',
                'label' => '#proc.imagegd.field_indexed_colors',
                'default' => 'TrueColor',
                'note' => '#proc.imagegd.note_indexed_colors',
                'tooltip' => '#proc.imagegd.tooltip_field_indexed_colors',
                'min' => 0,
                'max' => 256
            ],
            'details.alpha' => [
                'type'  => 'bit',
                'label' => '#proc.imagegd.field_alpha',
                'tooltip' => '#proc.imagegd.tooltip_field_alpha',
                'display_type' => 'switch',
            ],
            'details.bgcolor' => [
                'type'  => 'chars',
                'label' => '#proc.imagegd.field_bgcolor',
                'note' => '#proc.imagegd.note_field_bgcolor',
                'tooltip' => '#proc.imagegd.tooltip_field_bgcolor',
                'default' => '#ffffff',
                'pattern' => '#?([0-9a-fA-F]{3}|[0-9a-zA-Z]{6})',
                'max' => 7
            ],
        ];
    }

    /**
     * before creating converter (defaults)
     */
    public function hookOnCreate(array &$data): void
    {
    }

    /**
     * after loading converter
     */
    public function hookOnLoaded(array &$data): void
    {
        if (!empty($data['details']['bgcolor'])) $data['details']['bgcolor'] = '#' . $data['details']['bgcolor'];
    }

    /**
     * before saving converter (check data)
     */
    public function hookOnSave(array &$fields, array &$data, array &$previousData): void
    {
        // Auswahlfelder: nur die Werte aus dem Schema (auch über die API)
        foreach (['resize_when', 'unit'] as $key) {
            if (!isset($data['details'][$key]) || $data['details'][$key] === '') continue;
            $allowed = array_map('strval', array_keys($this->schema['details.' . $key]['options']));
            if (!in_array((string)$data['details'][$key], $allowed, true)) {
                throw new \Exception(strtr($this->cms->snippet('proc.imagegd.error_option'), [
                    '{field}' => $this->cms->textOrSnippet($this->schema['details.' . $key]['label']),
                    '{values}' => implode(', ', $allowed),
                ]));
            }
        }

        // bgcolor, stored without '#'
        $data['details']['bgcolor'] = ltrim($data['details']['bgcolor'] ?? '', '#');
        $indexedColors = $data['details']['indexed_colors'] ?? '';
        if ($indexedColors === 'TrueColor') $data['details']['indexed_colors'] = '';
    }

    /**
     * after saving converter
     */
    public function hookOnSaved(array &$fields, array &$data, array &$previousData): void
    {
    }

    /**
     * before deleting converter
     */
    public function hookOnDelete(array $data): void
    {
    }

    // Engine
    // ====================

    /**
     * Bildformate, die GD lesen/schreiben kann - abhängig vom GD-Build (imagetypes()).
     * Schlüssel: Extension, Wert: [IMG_*-Bit, Mime-Typ]. WebP und AVIF fehlen in
     * manchen Builds komplett (dann existieren auch imagecreatefromwebp() & Co. nicht).
     */
    protected const FORMATS = [
        'jpg'  => [IMG_JPG,  'image/jpeg'],
        'jpeg' => [IMG_JPG,  'image/jpeg'],
        'jpe'  => [IMG_JPG,  'image/jpeg'],
        'png'  => [IMG_PNG,  'image/png'],
        'gif'  => [IMG_GIF,  'image/gif'],
        'webp' => [IMG_WEBP, 'image/webp'],
        'bmp'  => [IMG_BMP,  'image/bmp'],
        'dib'  => [IMG_BMP,  'image/bmp'],
        'avif' => [IMG_AVIF, 'image/avif'],
    ];

    /**
     * Ist das Format in diesem GD-Build enthalten?
     * @param string $extension  Extension ohne Punkt (Kleinschreibung egal)
     */
    protected function formatAvailable(string $extension): bool
    {
        $format = static::FORMATS[strtolower($extension)] ?? null;
        return $format !== null && (imagetypes() & $format[0]) !== 0;
    }

    /**
     * Quell- UND Zielformat müssen zu den Formaten dieses GD-Builds gehören,
     * sonst soll writeThumbnails() den nächsten Konverter nehmen.
     * Die Quelle wird über den Mime-Typ geprüft (serverseitig ermittelt, verlässlicher
     * als die Extension), ersatzweise über die Extension.
     */
    public function supports(string $mime, string $srcExtension, string $destExtension): bool
    {
        $srcExt = '';
        foreach (static::FORMATS as $extension => [, $formatMime]) {
            if ($formatMime === strtolower($mime)) { $srcExt = $extension; break; }
        }
        if ($srcExt === '') $srcExt = $srcExtension;
        return $this->formatAvailable($srcExt) && $this->formatAvailable($destExtension);
    }

    /**
     * Erzeugt eine Dateiversion basierend auf den übergebenen Parametern
     *
     * @param string $srcPath   Pfad zur Quelldatei, relativ zu media_dir
     * @param array  $srcData   Daten der Quell-Datei
     * @param string $destPath  Pfad zur Zieldatei, relativ zu media_dir
     * @param array  $details   Konfigurations-Array
     * @return array            Daten der Zieldatei, bzw ['size'=>0, 'errors'=>'...']
     */
    public function convert(string $srcPath, array $srcData, string $destPath, array $details, bool $forceBgproc): array
    {
        $mediaDir = $this->config->get('paths.media_dir');
        $srcFile = $mediaDir . DIRECTORY_SEPARATOR . ltrim($srcPath, '/\\');
        $destFile =  $mediaDir . DIRECTORY_SEPARATOR . ltrim($destPath, '/\\');
        // Zielformat: Extension aus dem Konverter (writeThumbnails() legt sie in
        // $details), ersatzweise die der Zieldatei
        $ext = strtolower((string)($details['extension'] ?: pathinfo($destPath, PATHINFO_EXTENSION)));
        
        $fileData = [
            'width'     => 0,
            'height'    => 0,
            'mime_type' => '',
            'size'      => 0,
            'filename'  => '',
            'extension' => '',
        ];
        
        // Prüfen, ob die Quelldatei existiert und lesbar ist
        if (!file_exists($srcFile) || !is_readable($srcFile)) {
            return $fileData + ['errors' => 'error_file_not_found'];
        }

        // Zielformat muss GD schreiben können - sonst nächsten Konverter versuchen
        if (!$this->formatAvailable($ext)) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported', 'unsupported' => true];
        }

        // Bildinformationen auslesen
        // (kein Bild, das GD kennt - z. B. PDF, SVG: nächsten Konverter versuchen)
        $info = @getimagesize($srcFile);
        if ($info === false) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_dimensions', 'unsupported' => true];
        }

        [$srcW, $srcH, $type] = $info;

        // Originalbild laden
        // (Format nicht in diesem GD-Build? Die imagecreatefrom*()-Funktion fehlt dann
        //  ebenfalls - vorher prüfen, sonst Fatal Error)
        $readers = [
            IMAGETYPE_JPEG => ['jpg',  'imagecreatefromjpeg'],
            IMAGETYPE_PNG  => ['png',  'imagecreatefrompng'],
            IMAGETYPE_GIF  => ['gif',  'imagecreatefromgif'],
            IMAGETYPE_WEBP => ['webp', 'imagecreatefromwebp'],
            IMAGETYPE_BMP  => ['bmp',  'imagecreatefrombmp'],
            IMAGETYPE_AVIF => ['avif', 'imagecreatefromavif'],
        ];
        [$srcFormat, $reader] = $readers[$type] ?? ['', ''];
        if ($srcFormat === '' || !$this->formatAvailable($srcFormat) || !function_exists($reader)) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported', 'unsupported' => true];
        }
        $srcImg = @$reader($srcFile);
        if (!$srcImg) {
            return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported'];
        }

        // Ziel-Dimensionen und Quellausschnitt berechnen
        [$srcX, $srcY, $copyW, $copyH, $dstW, $dstH] = $this->targetGeometry($details, $srcW, $srcH);

        // Ziel-Canvas erstellen (TrueColor RGB)
        $dstImg = imagecreatetruecolor($dstW, $dstH);

        // Hintergrundfarbe evaluieren (bgcolor)
        $bgColorHex = isset($details['bgcolor']) ? trim($details['bgcolor']) : '';
        $bgColorHex = ltrim($bgColorHex, '#'); // Führende Raute entfernen, falls vorhanden

        // Prüfung mit regulärem Ausdruck auf 3 oder 6 gültige Hex-Zeichen
        if (!preg_match('/^([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $bgColorHex)) {
            $bgColorHex = 'FFF'; // Fallback bei ungültiger oder leerer Eingabe
        }

        // Hex-Wert in RGB-Werte (0-255) umwandeln
        if (strlen($bgColorHex) === 3) {
            $r = hexdec(str_repeat($bgColorHex[0], 2));
            $g = hexdec(str_repeat($bgColorHex[1], 2));
            $b = hexdec(str_repeat($bgColorHex[2], 2));
        } else {
            $r = hexdec(substr($bgColorHex, 0, 2));
            $g = hexdec(substr($bgColorHex, 2, 2));
            $b = hexdec(substr($bgColorHex, 4, 2));
        }

        // Alpha-Channel (Transparenz) oder Hintergrundfarbe anwenden
        $keepAlpha = !empty($details['alpha']);
        if ($keepAlpha) {
            // Transparenz erhalten
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 255, 255, 255, 127);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
        } else {
            // Hintergrund mit berechneter RGB-Farbe füllen
            $bgAllocated = imagecolorallocate($dstImg, $r, $g, $b);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $bgAllocated);
        }

        // Bild neu berechnen und kopieren
        imagecopyresampled($dstImg, $srcImg, 0, 0, $srcX, $srcY, $dstW, $dstH, $copyW, $copyH);

        // Farbtiefe anpassen (falls ein Wert kleiner als 24 gefordert ist)
        $bit = isset($details['indexed_colors']) ? (int)$details['indexed_colors'] : 24;

        if ($bit < 24) {
            $colors = 0;
            
            switch ($bit) {
                case 8: $colors = 256; break;
                case 4: $colors = 16;  break;
                case 1: $colors = 2;   break;
            }

            if ($colors > 0) {
                // Parameter 2 (true) aktiviert Dithering. 
                // Das mildert harte Farbübergänge bei der Reduzierung deutlich ab.
                imagetruecolortopalette($dstImg, true, $colors);
            }
        }

        // Zielbild speichern
        // --------------------

        // Komprimierung
        $quality = isset($details['quality']) ? (int)$details['quality'] : 80;
        $success = false;
        
        // MimeType
        $mimeType = '';
        switch ($ext) {
            case 'jpeg':
            case 'jpg':
            case 'jpe':
                $success = imagejpeg($dstImg, $destFile, $quality);
                $mimeType = 'image/jpeg';
                break;
            case 'png':
                // PNG-Kompression (0-9) ehrlich aus Qualität (0-100) errechnen
                $pngCompression = (int)round(9 - ($quality * 9 / 100));
                $pngCompression = max(0, min(9, $pngCompression));
                $success = imagepng($dstImg, $destFile, $pngCompression);
                $mimeType = 'image/png';
                break;
            case 'webp':
                $success = imagewebp($dstImg, $destFile, $quality);
                $mimeType = 'image/webp';
                break;
            case 'gif':
                $success = imagegif($dstImg, $destFile);
                $mimeType = 'image/gif';
                break;
            case 'bmp':
            case 'dib':
                $success = imagebmp($dstImg, $destFile);
                $mimeType = 'image/bmp';
                break;
            case 'avif':
                $success = imageavif($dstImg, $destFile, $quality);
                $mimeType = 'image/avif';
                break;
            default:
                // (formatAvailable() oben hat unbekannte Extensions bereits abgefangen)
                return $fileData + ['errors' => 'proc.imagegd.error_file_not_supported', 'unsupported' => true];
        }
        
        // Prüfen ob das Speichern erfolgreich war
        if (!$success) {
            return $fileData + ['errors' => 'error_file_conversion'];
        }

        /**
         * Die folgende Rückgabestruktur ist bei allen Medien-Konvertern gleich.
         */
        $fileData = [
            'width'     => $dstW,
            'height'    => $dstH,
            'mime_type' => $mimeType,
            'size'      => filesize($destFile),
            'filename'  => (string)($srcData['filename'] ?? ''),
            'extension' => $ext,
            // Zusätzliche Infos zur Quell-Datei wurden ermittelt:
            'source_width' => $srcW,
            'source_height' => $srcH,
            // keine source_data
        ];
        return $fileData;
    }

    /**
     * Zielgröße und Quellausschnitt aus Zielbreite/-höhe und den Größen-Optionen,
     * gleichbedeutend mit den Geometrie-Kennzeichen von Magick::geometryArguments():
     *
     *     crop         0 einpassen, 1 ausfüllen und mittig beschneiden
     *     resize_when  shrink  nur verkleinern (>), enlarge nur vergrößern (<)
     *     unit         percent Breite/Höhe in Prozent der Quelle (%) - nur Einpassen;
     *                          ein Wert gilt für beide Seiten, zwei Werte je Seite
     *     max_pixels   Höchstzahl Pixel (@), danach, mit derselben Bedingung
     *
     * Ist die Änderung durch die Bedingung ausgeschlossen, behält das Bild beim
     * Einpassen seine Größe; beim Beschneiden wird nur mittig auf das Zielformat
     * zugeschnitten, soweit das Bild reicht (ohne Rand in der Hintergrundfarbe).
     * Beschneiden braucht beide Maße; fehlt eines, wird eingepasst.
     *
     * @return int[]  [srcX, srcY, copyW, copyH, dstW, dstH]
     */
    protected function targetGeometry(array $details, int $srcW, int $srcH): array
    {
        $reqW = max(0, (int)($details['width'] ?? 0));
        $reqH = max(0, (int)($details['height'] ?? 0));
        $percent = ($details['unit'] ?? 'px') === 'percent';
        $when = (string)($details['resize_when'] ?? 'always');
        $isCrop = !empty($details['crop']) && $reqW > 0 && $reqH > 0 && !$percent;

        // Ändert ein Faktor die Größe in der erlaubten Richtung?
        $allowed = fn(float $scale): bool => match ($when) {
            'shrink'  => $scale < 1,
            'enlarge' => $scale > 1,
            default   => true,
        };

        // Ohne Zielmaße: Originalgröße (nur Format, Qualität ... ändern sich)
        $srcX = $srcY = 0;
        $copyW = $dstW = $srcW;
        $copyH = $dstH = $srcH;

        if ($percent && ($reqW > 0 || $reqH > 0)) {
            // 50 (beide Seiten) bzw. 50 x 30 (je Seite)
            $scaleW = ($reqW ?: $reqH) / 100;
            $scaleH = ($reqH ?: $reqW) / 100;
            // wie ImageMagick: verkleinern, wenn eine Seite kleiner wird, vergrößern,
            // wenn eine Seite größer wird
            $change = match ($when) {
                'shrink'  => $scaleW < 1 || $scaleH < 1,
                'enlarge' => $scaleW > 1 || $scaleH > 1,
                default   => true,
            };
            if ($change) {
                $dstW = (int)round($srcW * $scaleW);
                $dstH = (int)round($srcH * $scaleH);
            }
        }
        elseif ($isCrop) {
            // Ausfüllen und mittig beschneiden
            $scale = max($reqW / $srcW, $reqH / $srcH);
            if ($allowed($scale)) {
                $copyW = (int)round($reqW / $scale);
                $copyH = (int)round($reqH / $scale);
                $dstW = $reqW;
                $dstH = $reqH;
            }
            else {
                // keine Größenänderung erlaubt: nur zuschneiden, soweit das Bild reicht
                $copyW = $dstW = min($reqW, $srcW);
                $copyH = $dstH = min($reqH, $srcH);
            }
            $srcX = (int)round(($srcW - $copyW) / 2);
            $srcY = (int)round(($srcH - $copyH) / 2);
        }
        elseif ($reqW > 0 || $reqH > 0) {
            // Einpassen: das ganze Bild passt in die Box
            $scale = min($reqW > 0 ? $reqW / $srcW : INF, $reqH > 0 ? $reqH / $srcH : INF);
            if ($allowed($scale)) {
                $dstW = (int)round($srcW * $scale);
                $dstH = (int)round($srcH * $scale);
            }
        }

        // Höchstzahl Pixel: proportional auf die Fläche, mit derselben Bedingung
        $maxPixels = (int)($details['max_pixels'] ?? 0);
        if ($maxPixels > 0 && $dstW > 0 && $dstH > 0) {
            $scale = sqrt($maxPixels / ($dstW * $dstH));
            if ($allowed($scale)) {
                $dstW = (int)floor($dstW * $scale);
                $dstH = (int)floor($dstH * $scale);
            }
        }

        return [$srcX, $srcY, $copyW, $copyH, max(1, $dstW), max(1, $dstH)];
    }

}

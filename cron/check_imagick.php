<?php
/**
 * check_imagick.php - self-test for the engine Imagick of the processor Magick
 *
 *     php Core/cron/check_imagick.php
 *
 * Checks:
 *   - ImagickCommand::parse()/unsupported(): translatable and rejected parameters
 *   - ImagickCommand::geometry()/resizeSize(): the geometry flags (fit, ^, !, %, @, >, <)
 *   - Magick::convert() with both engines on generated images (JPEG with EXIF
 *     orientation, PNG with alpha channel, animated GIF): same dimensions and nearly the
 *     same mean color for the form fields (size, crop, fill, distort, percent,
 *     max pixels, shrink only, alpha, colors) and expert parameters
 *   - SVG is refused with Imagick ('unsupported')
 *   - fallback without a program (image editing of the info page, rotation on upload):
 *     imageInfo(), renderEdit() and autoOrient() with Imagick, compared with the program
 * Without the extension Imagick the test is skipped; without the program under paths.magick
 * only the Imagick results are checked. The files lie under paths.media_dir/zz_selftest_imagick
 * and are removed afterwards. Exit code 1 on deviations.
 */
use Camindo\Core\ProcessorFactory;
use Camindo\Core\Processors\Magick\ImagickCommand;
use Camindo\Core\Processors\Magick\Magick;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

if (!ImagickCommand::available()) {
    echo "skipped: PHP extension imagick not loaded\n";
    exit(0);
}

$failed = 0;
$count = 0;
$check = function (string $label, mixed $got, mixed $expected) use (&$failed, &$count): void {
    $count++;
    $ok = ($got === $expected);
    if (!$ok) $failed++;
    printf("%s  %-58s %s\n", $ok ? 'ok  ' : 'FAIL', $label,
        $ok ? '' : 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($got, true));
};

// Parameters
// ====================
foreach ([
    ['-strip -colorspace sRGB', null],
    ['-limit memory 128MiB -define jpeg:size=800x800 -density 72', null],
    ['-sharpen 0x1 -unsharp 0x0.75+0.75+0.008 -quality 85 -interlace Plane', null],
    ['-gravity north -crop 100x100+0+0 +repage -resize 50%', null],
    ['-sampling-factor 4:2:0 -filter Lanczos -modulate 110,90', null],
    ['-coalesce -layers optimize', null],
    ['+profile "*"', null],
    ['-resize', '-resize'],
    ['-swirl 90', '-swirl'],
    ['-colorspace Foo', '-colorspace Foo'],
    ['-define delegate:bimodal=true', '-define delegate:bimodal=true'],
    ['-gravity sideways', '-gravity sideways'],
    ['-resize 100x100 extra', 'extra'],
    ['-crop 10x10@', '-crop 10x10@'],
] as [$params, $expected]) {
    $tokens = str_getcsv($params, ' ', '"', '\\');
    $check('unsupported(' . $params . ')', ImagickCommand::unsupported($tokens), $expected);
}

// Geometry
// ====================
$size = fn(int $w, int $h, string $g) => ImagickCommand::resizeSize($w, $h, ImagickCommand::geometry($g));
foreach ([
    [1200, 800, '300x', [300, 200]],
    [1200, 800, 'x200', [300, 200]],
    [1200, 800, '300x300', [300, 200]],
    [1200, 800, '300x300^', [450, 300]],
    [1200, 800, '300x300!', [300, 300]],
    [1200, 800, '50%', [600, 400]],
    [1200, 800, '50x25%', [600, 200]],
    [1200, 800, '240000@', [600, 400]],
    [1200, 800, '40000@', [244, 163]],
    [1200, 800, '4000000@>', null],
    [1200, 800, '2000x2000>', null],
    [1200, 800, '300x300>', [300, 200]],
    [1200, 800, '300x300<', null],
    [200, 100, '400x400<', [400, 200]],
] as [$w, $h, $g, $expected]) {
    $check("resizeSize({$w}x{$h}, {$g})", $size($w, $h, $g), $expected);
}

// Conversion with both engines
// ====================
/** @var Magick $magick */
$magick = $app->get(ProcessorFactory::class)->create('Magick');
$config = $app->get(\Camindo\Core\Config::class);
$mediaDir = rtrim((string)$config->get('paths.media_dir'), '/');
$dir = 'zz_selftest_imagick';
@mkdir("{$mediaDir}/{$dir}", 0775, true);
$cli = $magick->getVersion() !== null;
if (!$cli) echo "note: no ImageMagick under paths.magick - only Imagick is checked\n";

// Source images
$img = new Imagick();
$img->newPseudoImage(1200, 800, 'gradient:#d03030-#3050d0');
$img->setImageFormat('jpeg');
$jpeg = $img->getImageBlob();
$img->clear();
// EXIF segment with orientation 6 (RightTop): shown 800x1200
$tiff = "MM\x00\x2a\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00" . "\x00\x00\x00\x00";
$app1 = "Exif\x00\x00" . $tiff;
file_put_contents("{$mediaDir}/{$dir}/photo.jpg", substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));

$img = new Imagick();
$img->newImage(400, 300, new ImagickPixel('transparent'));
$draw = new ImagickDraw();
$draw->setFillColor('#20a040');
$draw->rectangle(50, 50, 349, 249);
$img->drawImage($draw);
$img->setImageFormat('png');
$img->writeImage("png:{$mediaDir}/{$dir}/alpha.png");
$img->clear();

$img = new Imagick();
foreach (['#ff0000', '#00ff00', '#0000ff'] as $color) {
    $frame = new Imagick();
    $frame->newImage(120, 80, new ImagickPixel($color));
    $frame->setImageFormat('gif');
    $frame->setImageDelay(20);
    $img->addImage($frame);
}
$img->writeImages("gif:{$mediaDir}/{$dir}/anim.gif", true);
$img->clear();

$sources = [
    'photo' => ['photo.jpg', ['mime_type' => 'image/jpeg', 'extension' => 'jpg', 'filename' => 'photo']],
    'alpha' => ['alpha.png', ['mime_type' => 'image/png', 'extension' => 'png', 'filename' => 'alpha']],
    'anim'  => ['anim.gif', ['mime_type' => 'image/gif', 'extension' => 'gif', 'filename' => 'anim']],
];

/** mean color of a file, rounded to 0..255 per channel */
$meanColor = function (string $file): array {
    $img = new Imagick($file);
    $img->setIteratorIndex(0);
    $img->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
    $img->scaleImage(1, 1);
    $rgb = $img->getImagePixelColor(0, 0)->getColor();
    $img->clear();
    return [$rgb['r'], $rgb['g'], $rgb['b']];
};

$base = ['width' => 300, 'height' => '', 'crop' => 0, 'resize_when' => 'always', 'unit' => 'px',
    'max_pixels' => '', 'quality' => 80, 'indexed_colors' => '', 'alpha' => 0, 'bgcolor' => 'ffffff',
    'parameters1' => '-strip -colorspace sRGB', 'parameters2' => ''];
$cases = [
    ['photo', 'jpg', 'fit 300 (EXIF rotated)', []],
    ['photo', 'jpg', 'fit 300x300', ['height' => 300]],
    ['photo', 'jpg', 'crop 300x300', ['height' => 300, 'crop' => Magick::CROP]],
    ['photo', 'jpg', 'fill 300x300', ['height' => 300, 'crop' => Magick::FILL]],
    ['photo', 'jpg', 'distort 300x300', ['height' => 300, 'crop' => Magick::DISTORT]],
    ['photo', 'webp', 'percent 25 to webp', ['width' => 25, 'unit' => 'percent']],
    ['photo', 'jpg', 'max pixels 40000', ['width' => '', 'max_pixels' => 40000]],
    ['photo', 'jpg', 'shrink only 2000', ['width' => 2000, 'resize_when' => 'shrink']],
    ['photo', 'jpg', 'crop shrink only 2000x300', ['width' => 2000, 'height' => 300, 'crop' => Magick::CROP, 'resize_when' => 'shrink']],
    ['photo', 'jpg', 'manual crop', ['height' => 200, 'crop' => Magick::CROP, 'manual_crop' => ['x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.3]]],
    ['photo', 'png', 'expert: -resize 120x120! -sharpen 0x1', ['parameters2' => '-resize 120x120! -sharpen 0x1']],
    ['photo', 'jpg', 'expert: -gravity north -crop 400x200+0+0', ['parameters2' => '-gravity north -crop 400x200+0+0 +repage -resize 50%']],
    ['alpha', 'png', 'alpha kept', ['width' => 200, 'alpha' => 1]],
    ['alpha', 'jpg', 'alpha removed (bg #ff8800)', ['width' => 200, 'bgcolor' => 'ff8800']],
    ['alpha', 'png', 'colors 8', ['width' => 200, 'indexed_colors' => 8]],
    ['alpha', 'png', 'crop 100x100 + extent', ['width' => 100, 'height' => 100, 'crop' => Magick::CROP]],
    ['anim', 'gif', 'animated gif first frame', ['width' => 60]],
];

foreach ($cases as $i => [$src, $ext, $label, $override]) {
    $details = array_replace($base, $override, ['extension' => $ext]);
    $results = [];
    foreach ($cli ? [Magick::ENGINE_CLI, Magick::ENGINE_IMAGICK] : [Magick::ENGINE_IMAGICK] as $engine) {
        $dest = "{$dir}/out{$i}_{$engine}.{$ext}";
        $data = $magick->convert("{$dir}/" . $sources[$src][0], $sources[$src][1], $dest, ['engine' => $engine] + $details, false);
        if (!empty($data['errors'])) {
            $check("{$label} [{$engine}]", $data['errors'] . ' ' . ($data['error_details'] ?? ''), 'no error');
            continue 2;
        }
        $results[$engine] = $data + ['file' => "{$mediaDir}/{$dest}"];
    }
    $im = $results[Magick::ENGINE_IMAGICK];
    if (!$cli) {
        $check("{$label}: size > 0", $im['size'] > 0, true);
        continue;
    }
    $cl = $results[Magick::ENGINE_CLI];
    $check("{$label}: {$cl['width']}x{$cl['height']}", [$im['width'], $im['height']], [$cl['width'], $cl['height']]);
    $a = $meanColor($cl['file']);
    $b = $meanColor($im['file']);
    $diff = max(abs($a[0] - $b[0]), abs($a[1] - $b[1]), abs($a[2] - $b[2]));
    $check("{$label}: mean color", $diff <= 6 ? 'close' : implode(',', $a) . ' vs ' . implode(',', $b), 'close');
    if ($ext !== 'webp') $check("{$label}: mime", $im['mime_type'], $cl['mime_type']);
}

// SVG is not processed with Imagick
file_put_contents("{$mediaDir}/{$dir}/x.svg", '<svg xmlns="http://www.w3.org/2000/svg" width="8" height="8"/>');
$data = $magick->convert("{$dir}/x.svg", ['mime_type' => 'image/svg+xml', 'extension' => 'svg'], "{$dir}/x.png",
    ['engine' => Magick::ENGINE_IMAGICK, 'extension' => 'png'] + $base, false);
$check('svg refused with Imagick', [$data['errors'] ?? '', !empty($data['unsupported'])], ['proc.magick.error_format_imagick', true]);

// Parameter that cannot be translated
$data = $magick->convert("{$dir}/photo.jpg", $sources['photo'][1], "{$dir}/y.jpg",
    ['engine' => Magick::ENGINE_IMAGICK, 'extension' => 'jpg', 'parameters2' => '-swirl 90'] + $base, false);
$check('untranslatable parameter', $data['errors'] ?? '', 'proc.magick.error_imagick_parameter');

// Fallback without program: image editing, rotation on upload
// ====================
// Magick without a configured program - the methods of the image editing then use Imagick
$fallback = new class(
    $config, $app->get(\Camindo\Core\Cms::class), $app->get(\Camindo\Core\Crypt::class),
    $app->get(\Camindo\Core\Execute::class), $app->get(\Camindo\Core\BackgroundProcess::class),
    $app->get(\Camindo\Core\Processors\Magick\MagickPolicy::class)
) extends Magick {
    public function cliAvailable(): bool { return false; }
};
$check('fallback: available()', $fallback->available(), true);
$check('fallback: canEdit(jpeg)', $fallback->canEdit('image/jpeg', 'jpg'), true);
$check('fallback: canEdit(svg)', $fallback->canEdit('image/svg+xml', 'svg'), false);
$info = $fallback->imageInfo("{$mediaDir}/{$dir}/photo.jpg", 'image/jpeg', 'jpg');
$check('fallback: imageInfo (as shown)', [$info['width'] ?? 0, $info['height'] ?? 0, $info['orientation'] ?? ''], [800, 1200, 'RightTop']);

$edits = [
    'rotate 90 + crop + size + gamma' => [['rotate' => 90, 'gamma' => 20, 'crop' => ['x' => 0.1, 'y' => 0.2, 'w' => 0.5, 'h' => 0.4], 'width' => 240, 'height' => 120], 0, 85],
    'preview 300 px'                  => [['rotate' => 180, 'gamma' => -30], 300, 70],
    'crop only'                       => [['crop' => ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5]], 0, 0],
];
foreach ($edits as $label => [$edit, $maxSide, $quality]) {
    $out = [];
    foreach (array_filter(['imagick' => $fallback, 'cli' => $cli ? $magick : null]) as $engine => $processor) {
        $file = "{$mediaDir}/{$dir}/edit_{$engine}.jpg";
        $result = $processor->renderEdit("{$mediaDir}/{$dir}/photo.jpg", 'image/jpeg', 'jpg', $file, 'jpg', $edit, $maxSide, $quality, $error);
        $out[$engine] = $result === null ? 'error: ' . $error : [$result['width'], $result['height'], $meanColor($file)];
    }
    if (!isset($out['cli'])) {
        $check("fallback renderEdit {$label}", is_array($out['imagick']), true);
        continue;
    }
    $check("fallback renderEdit {$label}: size", is_array($out['imagick']) ? array_slice($out['imagick'], 0, 2) : $out['imagick'],
        is_array($out['cli']) ? array_slice($out['cli'], 0, 2) : $out['cli']);
    if (is_array($out['imagick']) && is_array($out['cli'])) {
        $diff = max(array_map(fn($a, $b) => abs($a - $b), $out['imagick'][2], $out['cli'][2]));
        $check("fallback renderEdit {$label}: mean color", $diff <= 6 ? 'close' : implode(',', $out['imagick'][2]) . ' vs ' . implode(',', $out['cli'][2]), 'close');
    }
}

copy("{$mediaDir}/{$dir}/photo.jpg", "{$mediaDir}/{$dir}/orient.jpg");
$check('fallback: autoOrient()', $fallback->autoOrient("{$mediaDir}/{$dir}/orient.jpg", 'image/jpeg', 'jpg'), true);
$after = new Imagick("{$mediaDir}/{$dir}/orient.jpg");
$check('fallback: autoOrient pixels upright', [$after->getImageWidth(), $after->getImageHeight(),
    in_array($after->getImageOrientation(), [Imagick::ORIENTATION_UNDEFINED, Imagick::ORIENTATION_TOPLEFT], true)], [800, 1200, true]);
$after->clear();
$check('fallback: autoOrient again (nothing to do)', $fallback->autoOrient("{$mediaDir}/{$dir}/orient.jpg", 'image/jpeg', 'jpg'), false);

// Clean up
foreach (glob("{$mediaDir}/{$dir}/*") ?: [] as $file) @unlink($file);
@rmdir("{$mediaDir}/{$dir}");

printf("\n%d checks, %d failed\n", $count, $failed);
exit($failed > 0 ? 1 : 0);

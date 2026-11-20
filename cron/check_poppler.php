<?php
/**
 * check_poppler.php - self-test for the processor Poppler (PDF pages with pdftoppm)
 *
 *     php Core/cron/check_poppler.php
 *
 * Checks:
 *   - Poppler::targetSize(): fitting with and without page size (pdfinfo)
 *   - Poppler::isPdf(), supports()
 *   - convert() on a generated three-page PDF: size, page selection (also beyond the
 *     end), page rotation, crop box against media box, PNG/JPEG/TIFF, color of the page
 *   - files that are not PDF ('unsupported') and broken PDF (error, no leftovers)
 *   - without pdfinfo: fitted by the longer side
 * Without pdftoppm under paths.pdftoppm the test is skipped. The files lie under
 * paths.media_dir/zz_selftest_poppler and are removed afterwards. Exit code 1 on deviations.
 */
use Camindo\Core\ProcessorFactory;
use Camindo\Core\Processors\Poppler\Poppler;

// Reachable only on the command line, not via the web server
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

define('CAMINDO_STARTED', true);
define('CAMINDO_CLI', true);

// Load bootstrap
require_once dirname(__DIR__, 2) . '/load_camindo.php';

$failed = 0;
$count = 0;
$check = function (string $label, mixed $got, mixed $expected) use (&$failed, &$count): void {
    $count++;
    $ok = ($got === $expected);
    if (!$ok) $failed++;
    printf("%s  %-58s %s\n", $ok ? 'ok  ' : 'FAIL', $label,
        $ok ? '' : 'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($got, true));
};

// Without a program
// ====================
foreach ([
    [300, 0, 595.0, 842.0, ['-scale-to', '424']],
    [0, 200, 595.0, 842.0, ['-scale-to', '200']],
    [300, 300, 842.0, 595.0, ['-scale-to', '300']],
    [99999, 0, 100.0, 100.0, ['-scale-to', '10000']],
    [300, 0, 0.0, 0.0, ['-scale-to', '300']],
    [0, 200, 0.0, 0.0, ['-scale-to', '200']],
    [300, 200, 0.0, 0.0, ['-scale-to', '200']],
    [0, 0, 595.0, 842.0, null],
] as [$w, $h, $pw, $ph, $expected]) {
    $check("targetSize({$w}, {$h}, {$pw}x{$ph})", Poppler::targetSize($w, $h, $pw, $ph), $expected);
}

/** @var Poppler $poppler */
$poppler = $app->get(ProcessorFactory::class)->create('Poppler');
$check('supports(pdf -> png)', $poppler->supports('application/pdf', 'pdf', 'png'), true);
$check('supports(pdf -> webp)', $poppler->supports('application/pdf', 'pdf', 'webp'), false);
$check('supports(jpeg -> png)', $poppler->supports('image/jpeg', 'jpg', 'png'), false);

if ($poppler->binary() === null) {
    printf("\nskipped conversion: no pdftoppm under paths.pdftoppm\n%d checks, %d failed\n", $count, $failed);
    exit($failed > 0 ? 1 : 0);
}

// Test files
// ====================
$config = $app->get(\Camindo\Core\Config::class);
$mediaDir = rtrim((string)$config->get('paths.media_dir'), '/');
$dir = 'zz_selftest_poppler';
@mkdir("{$mediaDir}/{$dir}", 0775, true);

/**
 * A small PDF: per page [media box, crop box or null, rotation, content stream].
 */
$makePdf = function (array $pages): string {
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
    $kids = [];
    $next = 3;
    foreach ($pages as [$media, $crop, $rotate, $content]) {
        $pageId = $next++;
        $contentId = $next++;
        $kids[] = "{$pageId} 0 R";
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [' . implode(' ', $media) . ']'
            . ($crop ? ' /CropBox [' . implode(' ', $crop) . ']' : '')
            . ($rotate ? " /Rotate {$rotate}" : '') . " /Contents {$contentId} 0 R >>";
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) $pdf .= sprintf("%010d 00000 n \n", $offset);
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    return $pdf;
};
file_put_contents("{$mediaDir}/{$dir}/doc.pdf", $makePdf([
    [[0, 0, 595, 842], null, 0, '1 0 0 rg 0 0 595 842 re f'],                          // A4, red
    [[0, 0, 595, 842], null, 90, '0 0 1 rg 0 0 595 842 re f'],                         // A4 turned, blue
    [[0, 0, 600, 800], [100, 100, 400, 400], 0, '0 0 1 rg 0 0 600 800 re f 0 1 0 rg 100 100 300 300 re f'],
]));
file_put_contents("{$mediaDir}/{$dir}/fake.pdf", base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
file_put_contents("{$mediaDir}/{$dir}/broken.pdf", "%PDF-1.4\nthis is not a document\n");

$check('isPdf(doc.pdf)', Poppler::isPdf("{$mediaDir}/{$dir}/doc.pdf"), true);
$check('isPdf(fake.pdf = PNG)', Poppler::isPdf("{$mediaDir}/{$dir}/fake.pdf"), false);
$info = $poppler->pageInfo("{$mediaDir}/{$dir}/doc.pdf", 2);
if ($info !== null) {
    $check('pageInfo(page 2, turned)', [$info['pages'], $info['width'], $info['height']], [3, 842.0, 595.0]);
}

/** dominant channel of the image: 'r', 'g' or 'b' */
$color = function (string $file): string {
    $img = @imagecreatefromstring((string)file_get_contents($file));
    if (!$img) {
        // TIFF: GD cannot read it - nothing to compare
        return '?';
    }
    $sum = [0, 0, 0];
    $w = imagesx($img);
    $h = imagesy($img);
    for ($x = 0; $x < $w; $x += max(1, intdiv($w, 20))) {
        for ($y = 0; $y < $h; $y += max(1, intdiv($h, 20))) {
            $rgb = imagecolorat($img, $x, $y);
            $sum[0] += ($rgb >> 16) & 255;
            $sum[1] += ($rgb >> 8) & 255;
            $sum[2] += $rgb & 255;
        }
    }
    return ['r', 'g', 'b'][array_search(max($sum), $sum, true)];
};

// Conversion
// ====================
$src = ['mime_type' => 'application/pdf', 'extension' => 'pdf', 'filename' => 'doc'];
$base = ['page' => 1, 'width' => 300, 'height' => 0, 'box' => 'crop', 'annotations' => 1, 'quality' => 85];
$cases = [
    ['page 1, width 300',          'png',  [],                                [300, 424], 'image/png',  'r'],
    ['page 1, height 200',         'jpg',  ['width' => 0, 'height' => 200],   [142, 200], 'image/jpeg', 'r'],
    ['page 1, box 300x300',        'png',  ['height' => 300],                 [212, 300], 'image/png',  'r'],
    ['page 2 (turned 90)',         'png',  ['page' => 2],                     [300, 212], 'image/png',  'b'],
    ['page 9 -> last page',        'png',  ['page' => 9],                     [300, 300], 'image/png',  'g'],
    ['page 3, crop box',           'png',  ['page' => 3],                     [300, 300], 'image/png',  'g'],
    ['page 3, media box',          'png',  ['page' => 3, 'box' => 'media'],   [300, 400], 'image/png',  'b'],
    ['TIFF',                       'tif',  [],                                [300, 424], 'image/tiff', '?'],
];
foreach ($cases as $i => [$label, $ext, $override, $size, $mime, $dominant]) {
    $dest = "{$dir}/out{$i}.{$ext}";
    $data = $poppler->convert("{$dir}/doc.pdf", $src, $dest, array_replace($base, $override, ['extension' => $ext]), false);
    if (!empty($data['errors'])) {
        $check($label, $data['errors'] . ' ' . ($data['error_details'] ?? ''), 'no error');
        continue;
    }
    $check("{$label}: size", [$data['width'], $data['height']], $size);
    $check("{$label}: mime", $data['mime_type'], $mime);
    if ($ext !== 'tif') $check("{$label}: color", $color("{$mediaDir}/{$dest}"), $dominant);
}

$data = $poppler->convert("{$dir}/fake.pdf", $src, "{$dir}/fake.png", $base + ['extension' => 'png'], false);
$check('PNG named .pdf: unsupported', [$data['errors'] ?? '', !empty($data['unsupported'])], ['proc.poppler.error_not_pdf', true]);
$data = $poppler->convert("{$dir}/broken.pdf", $src, "{$dir}/broken.png", $base + ['extension' => 'png'], false);
$check('broken PDF: error', $data['errors'] ?? '', 'error_file_conversion');
$check('broken PDF: no leftovers', count(glob("{$mediaDir}/{$dir}/.poppler-*") ?: []) + (int)is_file("{$mediaDir}/{$dir}/broken.png"), 0);
$data = $poppler->convert("{$dir}/doc.pdf", $src, "{$dir}/x.webp", $base + ['extension' => 'webp'], false);
$check('target webp: unsupported', [$data['errors'] ?? '', !empty($data['unsupported'])], ['proc.poppler.error_extension', true]);

// Without pdfinfo
// ====================
$noInfo = new class(
    $config, $app->get(\Camindo\Core\Cms::class), $app->get(\Camindo\Core\Crypt::class),
    $app->get(\Camindo\Core\Execute::class), $app->get(\Camindo\Core\BackgroundProcess::class)
) extends Poppler {
    public function infoBinary(): ?string { return null; }
};
$data = $noInfo->convert("{$dir}/doc.pdf", $src, "{$dir}/ni1.png", ['extension' => 'png'] + $base, false);
$check('without pdfinfo: width 300 (longer side)', [$data['width'] ?? 0, $data['height'] ?? 0], [212, 300]);
$data = $noInfo->convert("{$dir}/doc.pdf", $src, "{$dir}/ni2.png", ['extension' => 'png', 'height' => 300] + $base, false);
$check('without pdfinfo: box 300x300 (-scale-to)', [$data['width'] ?? 0, $data['height'] ?? 0], [212, 300]);

// Clean up
foreach (array_merge(glob("{$mediaDir}/{$dir}/*") ?: [], glob("{$mediaDir}/{$dir}/.poppler-*") ?: []) as $file) @unlink($file);
@rmdir("{$mediaDir}/{$dir}");

printf("\n%d checks, %d failed\n", $count, $failed);
exit($failed > 0 ? 1 : 0);

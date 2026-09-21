<?php

declare(strict_types=1);

use App\Modules\Media\Domain\FileInspection;
use App\Modules\Media\Support\FileInspector;

require_once __DIR__.'/UnitConfig.php';

const INSPECTED_DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

beforeEach(fn () => bootMediaConfig());

afterEach(function (): void {
    resetMediaConfig();
    array_map(unlink(...), array_filter(InspectedFiles::$paths, is_file(...)));
    InspectedFiles::$paths = [];
});

final class InspectedFiles
{
    /** @var list<string> */
    public static array $paths = [];
}

function inspectBytes(string $bytes, string $extension): FileInspection
{
    $path = (string) tempnam(sys_get_temp_dir(), 'inspect-');
    InspectedFiles::$paths[] = $path;
    file_put_contents($path, $bytes);

    return (new FileInspector)->inspect($path, $extension);
}

function png(int $width = 8, int $height = 6): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** @param array<string, string> $entries */
function archive(array $entries): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'archive-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/** What Word writes: the content types part first, then the document part. */
function docx(): string
{
    return archive([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>',
        'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>',
    ]);
}

it('accepts an image and reports its checksum and dimensions', function (): void {
    $bytes = png(8, 6);

    $result = inspectBytes($bytes, 'png');

    expect($result->accepted())->toBeTrue()
        ->and($result->rejection)->toBeNull()
        ->and($result->detectedMime)->toBe('image/png')
        ->and($result->checksumSha256)->toBe(hash('sha256', $bytes))
        ->and([$result->width, $result->height])->toBe([8, 6]);
});

it('accepts a real DOCX whichever way libmagic names it', function (): void {
    $result = inspectBytes(docx(), 'docx');

    expect($result->accepted())->toBeTrue()
        ->and($result->detectedMime)->toBeIn([INSPECTED_DOCX_MIME, 'application/zip', 'application/octet-stream'])
        ->and($result->width)->toBeNull()
        ->and($result->checksumSha256)->toHaveLength(64);
});

it('accepts a ZIP made on Windows: the declared x-zip-compressed never reaches the inspector', function (): void {
    $result = inspectBytes(archive(['logs/app.log' => "line\r\n", 'readme.txt' => 'x']), 'zip');

    expect($result->accepted())->toBeTrue()->and($result->detectedMime)->toBe('application/zip');
});

it('accepts text, csv and log files as text', function (string $extension, string $bytes): void {
    expect(inspectBytes($bytes, $extension)->accepted())->toBeTrue();
})->with([
    ['txt', "plain notes\n"],
    ['log', "2026-09-20 10:00:00 ERROR something failed\n"],
    ['csv', "name,email\nPriya,priya@example.test\n"],
    ['txt', "नमस्ते संसार\n"],
]);

it('rejects bytes that are not what the extension says', function (string $extension, string $bytes, string $reason): void {
    $result = inspectBytes($bytes, $extension);

    expect($result->accepted())->toBeFalse()
        ->and($result->rejection)->toBe($reason)
        ->and($result->checksumSha256)->toBeNull();
})->with([
    'php as png' => ['png', '<?php system($_GET["c"]);', FileInspector::TYPE_MISMATCH],
    'html as txt' => ['txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', FileInspector::TYPE_MISMATCH],
    'svg as png' => ['png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', FileInspector::TYPE_MISMATCH],
    'png as jpg' => ['jpg', png(), FileInspector::TYPE_MISMATCH],
    'pdf as docx' => ['docx', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n", FileInspector::TYPE_MISMATCH],
    'unknown extension' => ['exe', "MZ\x90\x00", FileInspector::TYPE_MISMATCH],
    'truncated png' => ['png', substr(png(), 0, 20), FileInspector::UNDECODABLE_IMAGE],
    'plain zip as docx' => ['docx', archive(['a.txt' => 'x']), FileInspector::INVALID_DOCUMENT],
    'docx without content types' => ['docx', archive(['word/document.xml' => '<w:document/>']), FileInspector::INVALID_DOCUMENT],
]);

it('rejects a DOCX renamed to XLSX, by type or by its missing workbook', function (): void {
    // libmagic builds that know OOXML call it a type mismatch; the others leave it to the entry check.
    expect(inspectBytes(docx(), 'xlsx')->rejection)->toBeIn([FileInspector::TYPE_MISMATCH, FileInspector::INVALID_DOCUMENT]);
});

<?php

declare(strict_types=1);

use App\Modules\Media\Support\AllowedMedia;

require_once __DIR__.'/UnitConfig.php';

const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

beforeEach(fn () => bootMediaConfig());
afterEach(fn () => resetMediaConfig());

it('maps allow-listed extensions to the stored type', function (string $filename, string $extension, string $mime): void {
    expect(AllowedMedia::extensionFor($filename))->toBe($extension)
        ->and(AllowedMedia::mimeFor($extension))->toBe($mime);
})->with([
    ['photo.PNG', 'png', 'image/png'],
    ['photo.jpg', 'jpg', 'image/jpeg'],
    ['photo.jpeg', 'jpeg', 'image/jpeg'],
    ['anim.gif', 'gif', 'image/gif'],
    ['pic.webp', 'webp', 'image/webp'],
    ['doc.pdf', 'pdf', 'application/pdf'],
    ['notes.txt', 'txt', 'text/plain'],
    ['server.log', 'log', 'text/plain'],
    ['people.csv', 'csv', 'text/csv'],
    ['logs.zip', 'zip', 'application/zip'],
    ['report.docx', 'docx', DOCX_MIME],
    ['sheet.xlsx', 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ['deck.pptx', 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
]);

it('has no row for active content, whatever the configuration says', function (string $filename): void {
    bootMediaConfig(['allowed_mime' => ['image/svg+xml', 'text/html', 'application/x-php', 'application/javascript', 'text/plain']]);

    expect(AllowedMedia::extensionFor($filename))->toBeNull();
})->with(['logo.svg', 'page.html', 'page.htm', 'shell.php', 'shell.phtml', 'app.js', 'run.exe', 'run.sh', 'README', 'photo.png.php']);

it('lets configuration switch a type off', function (): void {
    bootMediaConfig(['allowed_mime' => ['image/png']]);

    expect(AllowedMedia::extensionFor('a.png'))->toBe('png')
        ->and(AllowedMedia::extensionFor('a.pdf'))->toBeNull()
        ->and(AllowedMedia::extensionFor('a.log'))->toBeNull();
});

it('accepts what browsers really declare', function (string $extension, string $declared): void {
    expect(AllowedMedia::acceptsDeclared($extension, $declared))->toBeTrue();
})->with([
    'docx, Office installed' => ['docx', DOCX_MIME],
    'docx, unknown to the browser' => ['docx', ''],
    'docx, generic' => ['docx', 'application/octet-stream'],
    'docx, seen as a zip' => ['docx', 'application/x-zip-compressed'],
    'zip on Windows' => ['zip', 'application/x-zip-compressed'],
    'zip, legacy' => ['zip', 'application/x-zip'],
    'zip' => ['zip', 'application/zip'],
    'csv with Excel installed' => ['csv', 'application/vnd.ms-excel'],
    'log without a type' => ['log', ''],
    'old IE jpeg' => ['jpg', 'image/pjpeg'],
    'parameters and case' => ['txt', 'Text/Plain; charset=utf-8'],
]);

it('rejects a declared type of another family', function (string $extension, string $declared): void {
    expect(AllowedMedia::acceptsDeclared($extension, $declared))->toBeFalse();
})->with([
    ['png', 'application/pdf'],
    ['png', ''],                      // browsers always know images
    ['png', 'image/svg+xml'],
    ['pdf', 'text/html'],
    ['zip', 'application/x-msdownload'],
    ['svg', 'image/svg+xml'],
]);

it('decides on the detected type', function (string $extension, string $detected, bool $accepted): void {
    expect(AllowedMedia::acceptsDetected($extension, $detected))->toBe($accepted);
})->with([
    ['png', 'image/png', true],
    ['png', 'text/x-php', false],
    ['jpg', 'image/png', false],
    ['txt', 'text/plain', true],
    ['txt', 'text/html', false],
    ['csv', 'text/plain', true],
    ['zip', 'application/zip', true],
    ['zip', 'application/x-dosexec', false],
    ['docx', DOCX_MIME, true],
    ['docx', 'application/zip', true],            // libmagic builds that do not know OOXML
    ['docx', 'application/octet-stream', true],
    ['docx', 'application/pdf', false],
    ['exe', 'application/x-dosexec', false],
]);

it('names the entry an OOXML container must hold', function (): void {
    expect(AllowedMedia::requiredArchiveEntry('docx'))->toBe('word/document.xml')
        ->and(AllowedMedia::requiredArchiveEntry('xlsx'))->toBe('xl/workbook.xml')
        ->and(AllowedMedia::requiredArchiveEntry('pptx'))->toBe('ppt/presentation.xml')
        ->and(AllowedMedia::requiredArchiveEntry('zip'))->toBeNull()
        ->and(AllowedMedia::requiredArchiveEntry('nope'))->toBeNull();
});

it('knows which extensions are images', function (): void {
    expect(array_filter(['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'zip', 'nope'], AllowedMedia::isImage(...)))
        ->toBe(['png', 'jpg', 'jpeg', 'gif', 'webp']);
});

it('follows the configured size limit up to the database ceiling', function (mixed $configured, int $expected): void {
    bootMediaConfig(['max_file_bytes' => $configured]);

    expect(AllowedMedia::maxBytes())->toBe($expected);
})->with([
    'default' => [25 * 1024 * 1024, AllowedMedia::HARD_MAX_BYTES],
    'lowered' => [1000, 1000],
    'raised above the CHECK constraint' => [100 * 1024 * 1024, AllowedMedia::HARD_MAX_BYTES],
    'nonsense' => [0, 1],
]);

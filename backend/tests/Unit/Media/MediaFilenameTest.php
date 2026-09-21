<?php

declare(strict_types=1);

use App\Modules\Media\Support\MediaFilename;

describe('sanitise', function (): void {
    it('keeps an ordinary name as it is', function (string $name): void {
        expect(MediaFilename::sanitise($name))->toBe($name);
    })->with(['report.pdf', 'Q3 report (final) v2.docx', 'रिपोर्ट.pdf', "o'brien & sons.txt", 'archive.tar.gz']);

    it('cleans what breaks paths, headers and filesystems', function (string $input, string $expected): void {
        expect(MediaFilename::sanitise($input))->toBe($expected);
    })->with([
        'unix path' => ['/etc/passwd', 'passwd'],
        'traversal' => ['../../secret.txt', 'secret.txt'],
        'windows path' => ['C:\\Users\\me\\photo.png', 'photo.png'],
        'quotes' => ['Screen "shot".PNG', 'Screen _shot_.PNG'],
        'header injection' => ["a\r\nContent-Type: x.txt", 'a_Content-Type_ x.txt'],
        'null byte' => ["shell.php\0.png", 'shell.php_.png'],
        'reserved characters' => ['a<b>c|d?e*f;g%h.txt', 'a_b_c_d_e_f_g_h.txt'],
        'a run becomes one underscore' => ['a<>:"|?*b.txt', 'a_b.txt'],
        'whitespace runs' => ["a  \u{00A0}  b.txt", 'a b.txt'],
        'tab' => ["a\tb.txt", 'a_b.txt'],
        'leading and trailing dots and spaces' => [' ..hidden.txt. ', 'hidden.txt'],
        'bidi override hiding an extension' => ["invoice\u{202E}gnp.exe", 'invoicegnp.exe'],
        'invalid utf-8' => ["bad\xC3\x28name.txt", 'bad_(name.txt'],
    ]);

    it('returns an empty string when nothing usable is left', function (string $input): void {
        expect(MediaFilename::sanitise($input))->toBe('');
    })->with(['', '   ', '...', '/', "\u{202E}"]);

    it('cuts long names to 255 characters and keeps the extension', function (): void {
        $name = MediaFilename::sanitise(str_repeat('é', 300).'.pdf');

        expect(mb_strlen($name))->toBe(255)
            ->and($name)->toEndWith('.pdf')
            ->and(mb_strlen(MediaFilename::sanitise(str_repeat('a', 300))))->toBe(255);
    });

    it('is idempotent', function (string $input): void {
        $once = MediaFilename::sanitise($input);

        expect(MediaFilename::sanitise($once))->toBe($once);
    })->with(["a\r\n\"b\".txt", '../x/..y..', str_repeat('long ', 80).'.csv']);
});

describe('extension', function (): void {
    it('returns the lower-case extension', function (string $name, string $extension): void {
        expect(MediaFilename::extension($name))->toBe($extension);
    })->with([['photo.PNG', 'png'], ['archive.tar.gz', 'gz'], ['README', ''], ['.env', 'env'], ['trailing.', '']]);
});

describe('contentDisposition', function (): void {
    it('builds an attachment header with an ASCII fallback and an RFC 5987 name', function (string $name, string $header): void {
        expect(MediaFilename::contentDisposition($name))->toBe($header);
    })->with([
        'ascii' => ['report.pdf', "attachment; filename=\"report.pdf\"; filename*=UTF-8''report.pdf"],
        'spaces' => ['Q3 report.pdf', "attachment; filename=\"Q3 report.pdf\"; filename*=UTF-8''Q3%20report.pdf"],
        'accents' => ['résumé.pdf', "attachment; filename=\"resume.pdf\"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf"],
        'empty' => ['', "attachment; filename=\"download\"; filename*=UTF-8''download"],
    ]);

    it('never lets a stored name break out of the header', function (): void {
        $header = MediaFilename::contentDisposition("x\".txt\r\nSet-Cookie: a=b; filename=\"evil.html");

        expect($header)->not->toContain("\r")->not->toContain("\n")
            ->and(substr_count($header, '"'))->toBe(2)
            ->and(substr_count($header, ';'))->toBe(2)
            ->and($header)->toStartWith('attachment; filename="');
    });

    it('falls back to download.ext when the name has no ASCII form', function (): void {
        $header = MediaFilename::contentDisposition('日本.pdf');

        expect($header)->toContain("filename*=UTF-8''%E6%97%A5%E6%9C%AC.pdf")
            ->and($header)->toStartWith('attachment; filename="download.pdf";');
    });
});

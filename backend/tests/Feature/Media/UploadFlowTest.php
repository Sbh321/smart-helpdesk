<?php

declare(strict_types=1);

use App\Modules\Media\Jobs\GenerateImageVariants;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

require_once __DIR__.'/MediaTestSupport.php';

beforeEach(function (): void {
    useMediaTestDisk();
    $this->acme = createTenant('acme');
    $this->user = actingAsRole($this->acme, 'agent');
});

afterEach(fn () => cleanMediaTestDisk());

function usedBytes(Tenant $tenant): int
{
    return (int) DB::table('tenant_counters')->where('tenant_id', $tenant->getKey())->value('storage_used_bytes');
}

describe('intent', function (): void {
    it('reserves a pending item and signs a PUT to a staging key, never the media key', function (): void {
        $bytes = pngBytes();

        $response = requestIntent('Screen "shot".PNG', $bytes, 'image/png')->assertCreated();

        $id = $response->json('data.media_id');
        $item = $this->acme->run(fn () => MediaItem::query()->findOrFail($id));
        expect($item->state)->toBe('pending')
            ->and($item->name)->toBe('Screen _shot_.PNG')
            ->and($item->mime_type)->toBe('image/png')
            ->and($item->storage_key)->toBe("media/{$id}/original.png")
            ->and($item->uploaded_by_user_id)->toBe($this->user->id)
            ->and($response->json('data.url'))->toContain("uploads/{$id}.png")
            ->and($response->json('data.url'))->not->toContain('original')
            ->and($response->json('data.headers'))->toBe(['Content-Type' => 'image/png']);

        $this->getJson('/v1/media/usage')->assertOk()
            ->assertJsonPath('data.used_bytes', 0)
            ->assertJsonPath('data.pending_bytes', strlen($bytes));
    });

    it('starts a branding upload in the Branding folder, for workspace administrators only', function (): void {
        $payload = ['filename' => 'logo.png', 'size' => strlen(pngBytes()), 'mime' => 'image/png', 'purpose' => 'branding'];

        $this->postJson('/v1/media/intent', $payload)->assertStatus(422)->assertJsonStructure(['errors' => ['purpose']]);
        $this->postJson('/v1/media/intent', [...$payload, 'purpose' => 'avatar'])->assertStatus(422);

        actingAsRole($this->acme, 'admin');
        $id = $this->postJson('/v1/media/intent', $payload)->assertCreated()->json('data.media_id');

        $folder = $this->acme->run(fn () => MediaItem::query()->findOrFail($id)->folder);
        expect($folder->system_key)->toBe('branding');
    });

    it('rejects files over the limit, empty files and types off the allow-list', function (array $payload, string $field): void {
        $this->postJson('/v1/media/intent', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => [$field]]);

        expect($this->acme->run(fn () => MediaItem::query()->count()))->toBe(0);
    })->with([
        'too large' => [['filename' => 'big.pdf', 'size' => 25 * 1024 * 1024 + 1, 'mime' => 'application/pdf'], 'size'],
        'empty' => [['filename' => 'empty.txt', 'size' => 0, 'mime' => 'text/plain'], 'size'],
        'svg' => [['filename' => 'logo.svg', 'size' => 100, 'mime' => 'image/svg+xml'], 'filename'],
        'html' => [['filename' => 'page.html', 'size' => 100, 'mime' => 'text/html'], 'filename'],
        'php' => [['filename' => 'shell.php', 'size' => 100, 'mime' => 'text/plain'], 'filename'],
        'no extension' => [['filename' => 'README', 'size' => 100, 'mime' => 'text/plain'], 'filename'],
        'declared type of another family' => [['filename' => 'photo.png', 'size' => 100, 'mime' => 'application/pdf'], 'mime'],
    ]);

    it('accepts what browsers really declare for Office files and Windows zips', function (string $filename, string $mime): void {
        requestIntent($filename, 'x', $mime, 1000)->assertCreated();
    })->with([
        ['report.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ['report.docx', ''],
        ['logs.zip', 'application/x-zip-compressed'],
        ['data.csv', 'application/vnd.ms-excel'],
        ['server.log', ''],
    ]);

    it('follows the configured limit and allow-list', function (): void {
        config(['helpdesk.media.max_file_bytes' => 1000, 'helpdesk.media.allowed_mime' => ['image/png']]);

        requestIntent('a.png', 'x', 'image/png', 1001)->assertStatus(422)->assertJsonStructure(['errors' => ['size']]);
        requestIntent('a.pdf', 'x', 'application/pdf', 10)->assertStatus(422)->assertJsonStructure(['errors' => ['filename']]);
        requestIntent('a.png', 'x', 'image/png', 1000)->assertCreated();
    });

    it('answers 422 quota_exceeded when used plus reserved plus new bytes pass the quota', function (): void {
        DB::table('tenants')->where('id', $this->acme->getKey())->update(['storage_quota_bytes' => 1000]);
        mediaItemIn($this->acme, 'ready', ['size_bytes' => 300]);
        DB::table('tenant_counters')->upsert(['tenant_id' => $this->acme->getKey(), 'storage_used_bytes' => 300], 'tenant_id');

        requestIntent('a.txt', 'x', 'text/plain', 400)->assertCreated();   // 300 used + 400 reserved
        requestIntent('b.txt', 'x', 'text/plain', 301)
            ->assertStatus(422)->assertJsonPath('code', 'quota_exceeded');
        requestIntent('c.txt', 'x', 'text/plain', 300)->assertCreated();   // exactly full

        $this->getJson('/v1/media/usage')->assertOk()
            ->assertJsonPath('data.used_bytes', 300)
            ->assertJsonPath('data.pending_bytes', 700)
            ->assertJsonPath('data.quota_bytes', 1000);
    });

    it('throttles intents at 30 a minute per user', function (): void {
        RateLimiter::clear('media-intent:'.$this->user->id);
        foreach (range(1, 30) as $i) {
            requestIntent("f{$i}.txt", 'x', 'text/plain', 10)->assertCreated();
        }

        requestIntent('f31.txt', 'x', 'text/plain', 10)
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    });
});

describe('complete', function (): void {
    it('verifies, moves the object to its media key, and makes the item ready', function (): void {
        $clock = new FrozenClock(CarbonImmutable::parse('2026-09-20 10:00:00'));
        app()->instance(Clock::class, $clock);
        $bytes = pngBytes(8, 6);
        $id = stageUpload($this->acme, 'shot.png', $bytes, 'image/png');

        $this->postJson("/v1/media/{$id}/complete")->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.checksum_sha256', hash('sha256', $bytes))
            ->assertJsonPath('data.width', 8)
            ->assertJsonPath('data.height', 6)
            ->assertJsonPath('data.size_bytes', strlen($bytes))
            ->assertJsonPath('data.completed_at', '2026-09-20T10:00:00.000000Z')
            ->assertJsonMissingPath('data.storage_key');

        expect(mediaObjectPath($this->acme, "media/{$id}/original.png"))->toBeFile()
            ->and(file_exists(mediaObjectPath($this->acme, "uploads/{$id}.png")))->toBeFalse()
            ->and(usedBytes($this->acme))->toBe(strlen($bytes));

        $this->getJson('/v1/media/usage')->assertJsonPath('data.pending_bytes', 0);
    });

    it('accepts a real docx, a zip, a csv and a text file by their detected type', function (string $filename, string $bytes, string $mime, string $stored): void {
        $id = stageUpload($this->acme, $filename, $bytes, $mime);

        $this->postJson("/v1/media/{$id}/complete")->assertOk()
            ->assertJsonPath('data.state', 'ready')
            ->assertJsonPath('data.mime_type', $stored)
            ->assertJsonPath('data.width', null);
    })->with([
        'docx' => fn () => ['report.docx', docxBytes(), '', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'windows zip' => fn () => ['logs.zip', zipBytes(['a.log' => 'line']), 'application/x-zip-compressed', 'application/zip'],
        'csv' => fn () => ['people.csv', "name,email\nPriya,priya@example.test\n", 'application/vnd.ms-excel', 'text/csv'],
        'txt' => fn () => ['notes.txt', "plain notes\n", 'text/plain', 'text/plain'],
    ]);

    it('is idempotent: a retry returns the ready item and counts the bytes once', function (): void {
        Queue::fake();
        $bytes = pngBytes();
        $id = stageUpload($this->acme, 'shot.png', $bytes, 'image/png');

        $first = $this->postJson("/v1/media/{$id}/complete")->assertOk()->json('data');
        $second = $this->postJson("/v1/media/{$id}/complete")->assertOk()->json('data');

        expect($second)->toBe($first)
            ->and(usedBytes($this->acme))->toBe(strlen($bytes));
        Queue::assertPushed(GenerateImageVariants::class, 1);
    });

    it('finishes a retry after a crash between the move and the commit', function (): void {
        $bytes = "moved already\n";
        $id = (string) requestIntent('notes.txt', $bytes, 'text/plain')->json('data.media_id');
        putMediaObject($this->acme, MediaKeys::original($id, 'txt'), $bytes);

        $this->postJson("/v1/media/{$id}/complete")->assertOk()->assertJsonPath('data.state', 'ready');
        expect(usedBytes($this->acme))->toBe(strlen($bytes));
    });

    it('ignores a replay of the signed PUT after completion', function (): void {
        $item = uploadReady($this->acme, 'notes.txt', "the verified bytes\n");

        // The URL is still valid for minutes; it can only ever write the staging key.
        putMediaObject($this->acme, MediaKeys::staging($item->id, 'txt'), '<?php evil();');
        $this->postJson("/v1/media/{$item->id}/complete")->assertOk()
            ->assertJsonPath('data.checksum_sha256', hash('sha256', "the verified bytes\n"));

        expect(file_get_contents(mediaObjectPath($this->acme, $item->storage_key)))->toBe("the verified bytes\n");
    });

    it('fails the item, releases the reservation and deletes the object when verification fails', function (Closure $stage, string $reason): void {
        // The dataset wraps each stager in a closure so nothing runs at collection time; depending on
        // how Pest resolves it, the test receives the wrapper or the stager itself.
        $staged = $stage($this->acme);
        $id = $staged instanceof Closure ? $staged($this->acme) : $staged;

        $this->postJson("/v1/media/{$id}/complete")
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('meta.reason', $reason);

        expect($this->acme->run(fn () => MediaItem::query()->findOrFail($id)->state))->toBe('failed')
            ->and(glob(mediaObjectPath($this->acme, 'uploads/*')))->toBe([])
            ->and(file_exists(mediaObjectPath($this->acme, "media/{$id}")))->toBeFalse()
            ->and(usedBytes($this->acme))->toBe(0);
        $this->getJson('/v1/media/usage')->assertJsonPath('data.pending_bytes', 0);

        // A failed item is final: the client asks for a new intent.
        $this->postJson("/v1/media/{$id}/complete")->assertStatus(409)
            ->assertJsonPath('code', 'conflict')->assertJsonPath('meta.state', 'failed');
    })->with([
        'object never arrived' => [fn () => fn (Tenant $t) => (string) requestIntent('a.txt', 'abc', 'text/plain')->json('data.media_id'), 'missing_object'],
        'larger than declared' => [fn () => fn (Tenant $t) => stageUpload($t, 'a.txt', str_repeat('a', 500), 'text/plain', 100), 'size_mismatch'],
        'smaller than declared' => [fn () => fn (Tenant $t) => stageUpload($t, 'a.txt', 'abc', 'text/plain', 100), 'size_mismatch'],
        'php renamed to png' => [fn () => fn (Tenant $t) => stageUpload($t, 'shell.png', '<?php system($_GET["c"]);', 'image/png'), 'type_mismatch'],
        'html renamed to txt' => [fn () => fn (Tenant $t) => stageUpload($t, 'page.txt', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'text/plain'), 'type_mismatch'],
        'plain zip renamed to docx' => [fn () => fn (Tenant $t) => stageUpload($t, 'fake.docx', zipBytes(['a.txt' => 'x']), ''), 'invalid_document'],
        'truncated png' => [fn () => fn (Tenant $t) => stageUpload($t, 'cut.png', substr(pngBytes(), 0, 20), 'image/png'), 'undecodable_image'],
    ]);

    it('lets only the uploader or a media manager complete an upload', function (): void {
        $id = stageUpload($this->acme, 'a.txt', "mine\n", 'text/plain');

        actingAsRole($this->acme, 'agent');
        $this->postJson("/v1/media/{$id}/complete")->assertForbidden();

        actingAsRole($this->acme, 'admin');
        $this->postJson("/v1/media/{$id}/complete")->assertOk();
    });

    it('answers 404 for ids that are not UUIDs or do not exist', function (): void {
        $this->postJson('/v1/media/not-a-uuid/complete')->assertNotFound();
        $this->postJson('/v1/media/01920000-0000-7000-8000-000000000000/complete')->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

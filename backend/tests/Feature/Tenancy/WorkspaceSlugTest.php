<?php

declare(strict_types=1);

use App\Modules\Tenancy\Rules\WorkspaceSlug;
use Illuminate\Support\Facades\Validator;

it('accepts valid workspace slugs', function (string $slug): void {
    expect(Validator::make(['workspace' => $slug], ['workspace' => [new WorkspaceSlug]])->passes())->toBeTrue();
})->with(['acme', 'acme-corp', 'a', 'x1', str_repeat('a', 63)]);

it('rejects malformed and reserved slugs', function (mixed $slug, string $message): void {
    $validator = Validator::make(['workspace' => $slug], ['workspace' => [new WorkspaceSlug]]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('workspace'))->toContain($message);
})->with([
    'upper case' => ['Acme', 'may only contain'],
    'leading hyphen' => ['-acme', 'may only contain'],
    'double hyphen' => ['ac--me', 'may only contain'],
    'too long' => [str_repeat('a', 64), 'may only contain'],
    'underscore' => ['acme_corp', 'may only contain'],
    'not a string' => [['acme'], 'may only contain'],
    'reserved api' => ['api', 'reserved'],
    'reserved admin' => ['admin', 'reserved'],
    'reserved select-workspace' => ['select-workspace', 'reserved'],
    'reserved platform-docs' => ['platform-docs', 'reserved'],
]);

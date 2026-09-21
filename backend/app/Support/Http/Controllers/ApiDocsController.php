<?php

declare(strict_types=1);

namespace App\Support\Http\Controllers;

use Dedoc\Scramble\CacheableGenerator;
use Dedoc\Scramble\Scramble;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * The API reference (Stoplight Elements) and its OpenAPI document (docs/07-api/documentation.md §Access).
 * Registered twice: on the docs host for workspace users and under /platform-api on the admin host for
 * Platform Super Admins. The `viewApiDocs` gate in the route middleware decides who reads it.
 */
final class ApiDocsController
{
    public function ui(CacheableGenerator $generator): View
    {
        $config = Scramble::getGeneratorConfig(Scramble::DEFAULT_API);
        $result = $generator->generate($config);

        return view($config->renderer()->view, [
            'spec' => $result->spec(),
            'config' => $config,
            'result' => $result,
        ]);
    }

    public function document(CacheableGenerator $generator): JsonResponse
    {
        return response()->json($generator(Scramble::getGeneratorConfig(Scramble::DEFAULT_API)), options: JSON_PRETTY_PRINT);
    }
}

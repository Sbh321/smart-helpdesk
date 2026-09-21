<?php

declare(strict_types=1);

namespace App\Support\ApiDocs;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\RedirectResponse;

/**
 * Documents a controller that returns `RedirectResponse` as `302` with a `Location` header and no body.
 * Scramble has no extension for redirects and falls back to `200 {}` (docs/07-api/documentation.md).
 */
final class RedirectResponseToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(RedirectResponse::class);
    }

    public function toResponse(Type $type): Response
    {
        return Response::make(302)
            ->setDescription('Redirect to a short-lived signed URL.')
            ->addHeader('Location', new Header(
                description: 'Signed URL of the file on the files host; valid for a few minutes.',
                required: true,
                schema: Schema::fromType((new StringType)->format('uri')),
            ));
    }
}

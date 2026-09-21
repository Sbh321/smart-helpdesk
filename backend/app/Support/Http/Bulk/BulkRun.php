<?php

declare(strict_types=1);

namespace App\Support\Http\Bulk;

use App\Support\Errors\DomainException;
use App\Support\Errors\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one action per id and records what happened to each (docs/07-api/conventions.md §Bulk). Every
 * id is its own unit of work: a failure is recorded with the same problem code the single-ticket
 * endpoint would answer, and the next id still runs. Unexpected errors are reported and recorded as
 * `internal_error`.
 */
final class BulkRun
{
    /** @var list<BulkRow> */
    private array $rows = [];

    /**
     * @param  list<string>  $ids
     * @param  callable(string): array<string, mixed>  $action  returns what the row should show on success
     * @param  array<string, int>  $numbers  ticket numbers by id, so failed rows can name their ticket too
     */
    public static function over(array $ids, callable $action, array $numbers = []): self
    {
        $run = new self;
        foreach ($ids as $id) {
            $row = $run->attempt($id, $action);
            if (! $row->ok && isset($numbers[$id])) {
                $row = new BulkRow($row->id, false, $row->code, $row->detail, ['number' => $numbers[$id], ...$row->details]);
            }
            $run->rows[] = $row;
        }

        return $run;
    }

    /** @return list<BulkRow> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function succeeded(): int
    {
        return count(array_filter($this->rows, fn (BulkRow $row): bool => $row->ok));
    }

    /** @param callable(string): array<string, mixed> $action */
    private function attempt(string $id, callable $action): BulkRow
    {
        try {
            return new BulkRow($id, true, null, null, $action($id));
        } catch (DomainException $exception) {
            return new BulkRow($id, false, $exception->code(), $exception->getMessage(), $exception->meta());
        } catch (AuthorizationException) {
            return new BulkRow($id, false, ErrorCode::Forbidden, 'You may not do this to this ticket.');
        } catch (ModelNotFoundException) {
            return new BulkRow($id, false, ErrorCode::NotFound, 'No such ticket in this workspace.');
        } catch (ValidationException $exception) {
            return new BulkRow($id, false, ErrorCode::ValidationFailed, (string) collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            report($exception);

            return new BulkRow($id, false, ErrorCode::InternalError, 'Something went wrong with this ticket.');
        }
    }
}

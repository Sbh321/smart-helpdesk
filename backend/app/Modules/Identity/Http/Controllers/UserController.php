<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Actions\ChangeUserRoles;
use App\Modules\Identity\Actions\InviteUser;
use App\Modules\Identity\Actions\ResendInvitation;
use App\Modules\Identity\Actions\SetUserActive;
use App\Modules\Identity\Http\Requests\IndexUsersRequest;
use App\Modules\Identity\Http\Requests\InviteUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\WorkspaceUserResource;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Users (docs/07-api/conventions.md §Users): the workspace's people, their roles and
 * whether they can sign in. Role changes follow docs/03-architecture/security.md §Role assignment.
 */
#[Group('Users')]
final class UserController
{
    /** List workspace users. */
    #[QueryParameter('search', 'Name or email contains.', type: 'string')]
    #[QueryParameter('filter[status]', '`active`, `invited` or `disabled`, comma separated.', type: 'string')]
    #[QueryParameter('filter[role]', 'Role names, comma separated.', type: 'string')]
    #[QueryParameter('sort', 'name, email, created_at or last_login_at, optionally prefixed by -.', type: 'string')]
    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        $query = User::query()->with(['roles', 'latestInvitation']);
        $query->when($request->search(), function (Builder $q, string $search): void {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $q->where(fn (Builder $inner) => $inner->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like));
        });
        $query->when($request->filterValues('status'), function (Builder $q, array $statuses): void {
            $q->where(function (Builder $any) use ($statuses): void {
                if (in_array('disabled', $statuses, true)) {
                    $any->orWhere('is_active', false);
                }
                if (in_array('invited', $statuses, true)) {
                    $any->orWhere(fn (Builder $invited) => $invited->where('is_active', true)->whereNull('password'));
                }
                if (in_array('active', $statuses, true)) {
                    $any->orWhere(fn (Builder $active) => $active->where('is_active', true)->whereNotNull('password'));
                }
            });
        });
        $query->when($request->filterValues('role'), function (Builder $q, array $roles): void {
            // An invited user's roles are still on the invitation.
            $q->where(fn (Builder $either) => $either
                ->whereHas('roles', fn (Builder $role) => $role->whereIn('name', $roles))
                ->orWhere(fn (Builder $invited) => $invited->whereNull('password')->whereHas(
                    'latestInvitation', fn (Builder $invitation) => $invitation->where(function (Builder $names) use ($roles): void {
                        foreach ($roles as $role) {
                            $names->orWhereJsonContains('role_names', $role);
                        }
                    }),
                )));
        });
        foreach ($request->sortColumns() as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        return WorkspaceUserResource::collection($query->orderBy('id')->paginate($request->perPage())->withQueryString());
    }

    /** Get a workspace user. */
    public function show(User $user): WorkspaceUserResource
    {
        return new WorkspaceUserResource($user->load(['roles', 'latestInvitation']));
    }

    /**
     * Invite a user.
     *
     * Creates the user and mails a 48-hour invitation; the roles apply once it is accepted.
     */
    #[Response(status: 201, type: WorkspaceUserResource::class)]
    public function invite(InviteUserRequest $request, InviteUser $invite): JsonResponse
    {
        /** @var array{name: string, email: string, roles: list<string>} $data */
        $data = $request->validated();
        /** @var User $actor */
        $actor = $request->user();
        $user = $invite($actor, $data['name'], $data['email'], $data['roles']);

        return (new WorkspaceUserResource($user->load(['roles', 'latestInvitation'])))->response()->setStatusCode(201);
    }

    /**
     * Resend an invitation.
     *
     * A new invitation link; the previous one stops working. 409 `conflict` once accepted.
     */
    public function resendInvitation(Request $request, User $user, ResendInvitation $resend): WorkspaceUserResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $resend($actor, $user);

        return new WorkspaceUserResource($user->load(['roles', 'latestInvitation']));
    }

    /**
     * Update a user.
     *
     * Name and roles. 403 `forbidden` (`meta.roles`) for a role beyond the actor's reach; 422 `last_owner`
     * when the last active owner would lose the role.
     */
    public function update(UpdateUserRequest $request, User $user, ChangeUserRoles $roles): WorkspaceUserResource
    {
        /** @var array{name?: string, roles?: list<string>} $data */
        $data = $request->validated();
        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($data, $user, $actor, $roles): void {
            if (isset($data['name'])) {
                $user->forceFill(['name' => trim($data['name'])])->save();
            }
            if (isset($data['roles'])) {
                $roles($actor, $user, $data['roles']);
            }
        });

        return new WorkspaceUserResource($user->refresh()->load(['roles', 'latestInvitation']));
    }

    /**
     * Disable a user.
     *
     * 409 `conflict` (`self`) for the own account; 422 `last_owner` for the last active owner.
     */
    public function disable(Request $request, User $user, SetUserActive $set): WorkspaceUserResource
    {
        return $this->setActive($request, $user, $set, false);
    }

    /** Enable a user. */
    public function enable(Request $request, User $user, SetUserActive $set): WorkspaceUserResource
    {
        return $this->setActive($request, $user, $set, true);
    }

    private function setActive(Request $request, User $user, SetUserActive $set, bool $active): WorkspaceUserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new WorkspaceUserResource($set($actor, $user, $active)->load(['roles', 'latestInvitation']));
    }
}

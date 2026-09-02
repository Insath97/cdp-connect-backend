<?php

namespace App\Http\Controllers\V1;

use App\Traits\ActivityLogTrait;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReassignChildrenRequest;
use App\Models\User;
use App\Models\UserHierarchyChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class UserHierarchyController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Hierarchy Reassign', only: ['reassignChildren']),
            new Middleware('permission:User Hierarchy View', only: ['history', 'allHistory']),
            new Middleware('permission:User Hierarchy Tree', only: ['tree']),
        ];
    }

    /**
     * Reassign children from one user to another.
     * When a user leaves, their children are moved to a new parent.
     */
    public function reassignChildren(ReassignChildrenRequest $request, string $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        $newParentId = $request->new_parent_user_id;

        // Cannot reassign to self
        if ($userId == $newParentId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot reassign children to the same user'
            ], 422);
        }

        $newParent = User::find($newParentId);

        if (!$newParent) {
            return response()->json([
                'status' => 'error',
                'message' => 'New parent user not found'
            ], 404);
        }

        // Prevent circular reference: new parent cannot be a descendant of the user
        $descendantIds = $user->getAllDescendantIds();
        if (in_array($newParentId, $descendantIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot reassign children to a descendant of this user'
            ], 422);
        }

        // Get current children
        $children = User::where('parent_user_id', $userId)->get();

        if ($children->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This user has no children to reassign'
            ], 422);
        }

        $oldParentId = $user->parent_user_id;

        try {
            DB::beginTransaction();

            // Log each child reassignment
            foreach ($children as $child) {
                UserHierarchyChange::create([
                    'user_id' => $child->id,
                    'old_parent_user_id' => $userId,
                    'new_parent_user_id' => $newParentId,
                    'changed_by' => Auth::id(),
                    'reason' => $request->reason,
                    'changed_at' => now(),
                ]);
            }

            // Update children's parent_user_id
            User::where('parent_user_id', $userId)
                ->update(['parent_user_id' => $newParentId]);

            DB::commit();

            $this->logActivity('Update', 'UserHierarchy', 'Children reassigned', [
                'departing_user_id' => $userId,
                'old_parent_id' => $oldParentId,
                'new_parent_id' => $newParentId,
                'children_count' => $children->count(),
                'reason' => $request->reason,
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Children reassigned successfully',
                'data' => [
                    'departing_user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                    ],
                    'new_parent' => [
                        'id' => $newParent->id,
                        'name' => $newParent->name,
                    ],
                    'children_moved' => $children->count(),
                    'children' => $children->map(fn ($child) => [
                        'id' => $child->id,
                        'name' => $child->name,
                        'old_parent_id' => (int) $userId,
                    ]),
                ]
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reassign children',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    /**
     * Get hierarchy change history for a user.
     */
    public function history(Request $request, string $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        $perPage = $request->get('per_page', 15);

        $changes = UserHierarchyChange::where('user_id', $userId)
            ->with(['oldParent:id,name', 'newParent:id,name', 'changedByUser:id,name'])
            ->orderBy('changed_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Hierarchy change history retrieved successfully',
            'data' => $changes
        ], 200);
    }

    /**
     * Get all hierarchy changes (admin view).
     */
    public function allHistory(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        $query = UserHierarchyChange::with(['user:id,name', 'oldParent:id,name', 'newParent:id,name', 'changedByUser:id,name']);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('changed_by')) {
            $query->where('changed_by', $request->changed_by);
        }

        if ($request->filled('from_date') && $request->filled('to_date')) {
            $query->whereBetween('changed_at', [$request->from_date, $request->to_date]);
        } elseif ($request->filled('from_date')) {
            $query->where('changed_at', '>=', $request->from_date);
        } elseif ($request->filled('to_date')) {
            $query->where('changed_at', '<=', $request->to_date);
        }

        $changes = $query->orderBy('changed_at', 'desc')->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'message' => 'Hierarchy change history retrieved successfully',
            'data' => $changes
        ], 200);
    }

    /**
     * Get the current hierarchy tree for a user and their descendants.
     */
    public function tree(Request $request, string $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found'
            ], 404);
        }

        $tree = $this->buildTree($user->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Hierarchy tree retrieved successfully',
            'data' => $tree
        ], 200);
    }

    /**
     * Recursively build the hierarchy tree.
     */
    private function buildTree(int $userId): array
    {
        $user = User::with(['level:id,level_name'])->find($userId);

        if (!$user) {
            return [];
        }

        $children = User::where('parent_user_id', $userId)
            ->with(['level:id,level_name'])
            ->get();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'level' => $user->level ? $user->level->level_name : null,
            'is_active' => $user->is_active,
            'children' => $children->map(function ($child) {
                return $this->buildTree($child->id);
            })->toArray(),
        ];
    }
}

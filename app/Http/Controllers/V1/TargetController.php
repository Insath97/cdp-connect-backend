<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTargetRequest;
use App\Http\Requests\UpdateTargetRequest;
use App\Models\Target;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TargetController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $user = Auth::user();
            $query = Target::with(['user', 'assigner']);

            // Hierarchy visibility logic
            if (!$user->hasRole('Super Admin')) {
                $descendantIds = $user->getAllDescendantIds();
                $accessibleUserIds = array_merge([$user->id], $descendantIds);

                $query->where(function ($q) use ($user, $accessibleUserIds) {
                    $q->whereIn('user_id', $accessibleUserIds)
                        ->orWhere('assigned_by', $user->id);
                });
            }

            // Filter logic
            if ($request->has('user_id')) {
                $targetUserId = $request->user_id;
                // If not admin, ensure requested user_id is in allowed scope
                if (!$user->hasRole('Super Admin')) {
                    $allowedIds = array_merge([$user->id], $user->getAllDescendantIds());
                    if (!in_array($targetUserId, $allowedIds)) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Unauthorized access to target history.'
                        ], 403);
                    }
                }
                $query->where('user_id', $targetUserId);
            }

            if ($request->has('period_type')) {
                $query->where('period_type', $request->period_type);
            }

            if ($request->has('period_key')) {
                $query->where('period_key', $request->period_key);
            }

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $targets = $query->orderBy('created_at', 'desc')->paginate($perPage);

            Log::info('Targets index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['user_id', 'period_type', 'period_key']),
                'count' => $targets->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Targets retrieved successfully',
                'data' => $targets
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve targets',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateTargetRequest $request)
    {
        try {
            $currentUser = Auth::user();
            $data = $request->validated();

            // Validate hierarchy splitting logic
            // 1. Check if the target user is a child of current user (or if strict hierarchy is enforced)
            // For now, we assume 'assigned_by' tracks who set it.

            $data['assigned_by'] = $currentUser->id;
            $targetUser = User::with('level')->findOrFail($data['user_id']);

            // 1. Admin Restriction: Only assign to Level 1 (GM)
            if ($currentUser->hasRole('Super Admin')) {
                if ($targetUser->level->tire_level !== 1) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Admins can only assign targets to top-level users (GM). Sub-targets must be assigned by their respective managers.'
                    ], 422);
                }
            } else {
                // 2. Hierarchy Restriction: Only assign to direct children
                if ($targetUser->parent_user_id !== $currentUser->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You can only assign sub-targets to your direct subordinates.'
                    ], 422);
                }

                // 3. Unallocated Target Check
                $parentTarget = Target::where('user_id', $currentUser->id)
                    ->where('period_type', $data['period_type'])
                    ->where('period_key', $data['period_key'])
                    ->first();

                if (!$parentTarget) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You do not have a target set for this period, so you cannot assign sub-targets.'
                    ], 422);
                }

                $alreadyAssigned = Target::where('assigned_by', $currentUser->id)
                    ->where('period_type', $data['period_type'])
                    ->where('period_key', $data['period_key'])
                    ->sum('target_amount');

                if (($alreadyAssigned + $data['target_amount']) > $parentTarget->target_amount) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Insufficient target amount. You cannot assign more than your own target limit.',
                        'data' => [
                            'your_limit' => $parentTarget->target_amount,
                            'already_assigned' => $alreadyAssigned,
                            'attempted' => $data['target_amount'],
                            'remaining' => $parentTarget->target_amount - $alreadyAssigned
                        ]
                    ], 422);
                }
            }

            $target = Target::create($data);

            Log::info('Target created', [
                'creator_id' => $currentUser->id,
                'target_id' => $target->id,
                'target_user_id' => $target->user_id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Target created successfully',
                'data' => $target
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create target',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $target = Target::with(['user', 'assigner'])->find($id);

            if (!$target) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Target not found'
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Target retrieved successfully',
                'data' => $target
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve target',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateTargetRequest $request, string $id)
    {
        try {
            $target = Target::find($id);

            if (!$target) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Target not found'
                ], 404);
            }

            $data = $request->validated();
            $currentUser = Auth::user();

            // Permission Check: Only assigner or Super Admin
            if ($currentUser->id !== $target->assigned_by && !$currentUser->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized. You can only update targets you assigned.'
                ], 403);
            }

            // Unallocated Target Check on Amount Increase
            if (isset($data['target_amount']) && !$currentUser->hasRole('Super Admin')) {
                $parentTarget = Target::where('user_id', $currentUser->id)
                    ->where('period_type', $target->period_type)
                    ->where('period_key', $target->period_key)
                    ->first();

                $otherAssignedSum = Target::where('assigned_by', $currentUser->id)
                    ->where('period_type', $target->period_type)
                    ->where('period_key', $target->period_key)
                    ->where('id', '!=', $target->id)
                    ->sum('target_amount');

                if (($otherAssignedSum + $data['target_amount']) > $parentTarget->target_amount) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Insufficient target amount limit.',
                        'data' => [
                            'your_limit' => $parentTarget->target_amount,
                            'other_assignments' => $otherAssignedSum,
                            'requested' => $data['target_amount'],
                            'max_allowed' => $parentTarget->target_amount - $otherAssignedSum
                        ]
                    ], 422);
                }
            }

            $target->update($data);

            Log::info('Target updated', [
                'updater_id' => Auth::id(),
                'target_id' => $target->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Target updated successfully',
                'data' => $target
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update target',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $target = Target::find($id);

            if (!$target) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Target not found'
                ], 404);
            }

            // Permission check: Only assigner or Super Admin
            if (Auth::id() !== $target->assigned_by && !Auth::guard('api')->user()->hasRole('Super Admin')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You can only delete targets you assigned'
                ], 403);
            }

            $target->delete();

            Log::info('Target deleted', [
                'deleter_id' => Auth::id(),
                'target_id' => $id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Target deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete target',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function myTargets(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Target::where('user_id', Auth::id())
                ->with(['assigner'])
                ->orderBy('created_at', 'desc');

            $targets = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'message' => 'My targets retrieved successfully',
                'data' => $targets
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve my targets',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}

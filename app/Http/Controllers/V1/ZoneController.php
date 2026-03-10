<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ZoneController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Zone Index', only: ['index', 'show']),
            new Middleware('permission:Zone Create', only: ['store']),
            new Middleware('permission:Zone Update', only: ['update']),
            new Middleware('permission:Zone Delete', only: ['destroy']),
            new Middleware('permission:Zone Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Zone::with('province.country');

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->active() : $query->where('is_active', false);
            }

            if ($request->has('province_id')) {
                $query->where('province_id', $request->province_id);
            }

            $zones = $query->paginate($perPage);

            Log::info('Zones index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page', 'province_id']),
                'count' => $zones->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zones retrieved successfully',
                'data' => $zones
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve zones',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function store(CreateZoneRequest $request)
    {
        try {
            $data = $request->validated();
            $zone = Zone::create($data);

            Log::info('Zone created', [
                'user_id' => Auth::id(),
                'zone_id' => $zone->id,
                'zone_name' => $zone->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zone created successfully',
                'data' => $zone
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create zone',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $zone = Zone::with('province.country')->find($id);

            if (!$zone) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zone not found'
                ], 404);
            }

            Log::info('Zone viewed', [
                'user_id' => Auth::id(),
                'zone_id' => $zone->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zone retrieved successfully',
                'data' => $zone
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve zone',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function update(UpdateZoneRequest $request, string $id)
    {
        try {
            $zone = Zone::find($id);

            if (!$zone) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zone not found'
                ], 404);
            }

            $data = $request->validated();
            $zone->update($data);

            Log::info('Zone updated', [
                'user_id' => Auth::id(),
                'zone_id' => $zone->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zone updated successfully',
                'data' => $zone
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update zone',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $zone = Zone::find($id);

            if (!$zone) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zone not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized zone deletion attempt', [
                    'user_id' => Auth::id(),
                    'zone_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete zones'
                ], 403);
            }

            $zone->delete();

            Log::info('Zone deleted', [
                'user_id' => Auth::id(),
                'zone_id' => $id,
                'zone_name' => $zone->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zone deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete zone',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $zone = Zone::find($id);

            if (!$zone) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Zone not found'
                ], 404);
            }

            $zone->is_active = !$zone->is_active;
            $zone->save();

            Log::info('Zone status toggled', [
                'user_id' => Auth::id(),
                'zone_id' => $zone->id,
                'new_status' => $zone->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Zone status updated successfully',
                'data' => [
                    'id' => $zone->id,
                    'is_active' => $zone->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle zone status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}

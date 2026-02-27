<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCountryRequest;
use App\Http\Requests\UpdateCountryRequest;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CountryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:Country Index', only: ['index', 'show']),
            new Middleware('permission:Country Create', only: ['store']),
            new Middleware('permission:Country Update', only: ['update']),
            new Middleware('permission:Country Delete', only: ['destroy']),
            new Middleware('permission:Country Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = Country::query();

            if ($request->has('search')) {
                $query->search($request->search);
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->active() : $query->where('is_active', false);
            }

            $countries = $query->paginate($perPage);

            Log::info('Countries index accessed', [
                'user_id' => Auth::id(),
                'filters' => $request->only(['search', 'is_active', 'per_page']),
                'count' => $countries->count()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Countries retrieved successfully',
                'data' => $countries
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve countries',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    public function store(CreateCountryRequest $request)
    {
        try {
            $data = $request->validated();
            $country = Country::create($data);

            Log::info('Country created', [
                'user_id' => Auth::id(),
                'country_id' => $country->id,
                'country_name' => $country->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Country created successfully',
                'data' => $country
            ], 201);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create country',
                'error' => $th->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $country = Country::find($id);

            if (!$country) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Country not found'
                ], 404);
            }

            Log::info('Country viewed', [
                'user_id' => Auth::id(),
                'country_id' => $country->id
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Country retrieved successfully',
                'data' => $country
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve country',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    public function update(UpdateCountryRequest $request, string $id)
    {
        try {
            $country = Country::find($id);

            if (!$country) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Country not found'
                ], 404);
            }

            $data = $request->validated();
            $country->update($data);

            Log::info('Country updated', [
                'user_id' => Auth::id(),
                'country_id' => $country->id,
                'updated_fields' => array_keys($data)
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Country updated successfully',
                'data' => $country
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update country',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    public function destroy(string $id)
    {
        try {
            $country = Country::find($id);

            if (!$country) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Country not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                Log::warning('Unauthorized country deletion attempt', [
                    'user_id' => Auth::id(),
                    'country_id' => $id
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete countries'
                ], 403);
            }

            $country->delete();

            Log::info('Country deleted', [
                'user_id' => Auth::id(),
                'country_id' => $id,
                'country_name' => $country->name
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Country deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete country',
                'error' => $th->getMessage()
            ], 500);
        }
    }
    public function toggleStatus(string $id)
    {
        try {
            $country = Country::find($id);

            if (!$country) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Country not found'
                ], 404);
            }

            $country->is_active = !$country->is_active;
            $country->save();

            Log::info('Country status toggled', [
                'user_id' => Auth::id(),
                'country_id' => $country->id,
                'new_status' => $country->is_active
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Country status updated successfully',
                'data' => [
                    'id' => $country->id,
                    'is_active' => $country->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle country status',
                'error' => $th->getMessage()
            ], 500);
        }
    }
}

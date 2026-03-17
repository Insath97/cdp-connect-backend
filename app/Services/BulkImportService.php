<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Country;
use App\Models\InvestmentProduct;
use App\Models\Level;
use App\Models\Province;
use App\Models\Region;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class BulkImportService
{
    /**
     * Import data from CSV file for a specific table.
     */
    public function import(UploadedFile $file, string $table): array
    {
        $results = [
            'total' => 0,
            'imported' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        if (!$headers) {
            fclose($handle);
            return array_merge($results, ['errors' => ['Empty or invalid CSV file.']]);
        }

        $rowNumber = 1;
        while (($rowData = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $results['total']++;
            
            $data = array_combine($headers, $rowData);
            
            DB::beginTransaction();
            try {
                $this->processRow($table, $data);
                DB::commit();
                $results['imported']++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => $e->getMessage()
                ];
                Log::error("Import failed for table $table at row $rowNumber: " . $e->getMessage());
            }
        }

        fclose($handle);
        return $results;
    }

    /**
     * Route the row processing to the specific model handler.
     */
    protected function processRow(string $table, array $data): void
    {
        switch ($table) {
            case 'countries':
                Country::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'provinces':
                $country = Country::where('code', $data['country_code'])->firstOrFail();
                $data['country_id'] = $country->id;
                unset($data['country_code']);
                Province::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'zones':
                $province = Province::where('code', $data['province_code'])->firstOrFail();
                $data['province_id'] = $province->id;
                unset($data['province_code']);
                Zone::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'regions':
                $zone = Zone::where('code', $data['zone_code'])->firstOrFail();
                $data['zone_id'] = $zone->id;
                unset($data['zone_code']);
                Region::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'branches':
                $this->resolveGeographicalIds($data);
                Branch::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'levels':
                Level::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'investment_products':
                InvestmentProduct::updateOrCreate(['code' => $data['code']], $data);
                break;

            case 'system_settings':
                SystemSetting::updateOrCreate(['key' => $data['key']], $data);
                break;

            case 'users':
                $this->processUserRow($data);
                break;

            default:
                throw new \Exception("Unsupported table: $table");
        }
    }

    /**
     * Special handling for User creation including hierarchy and roles.
     */
    protected function processUserRow(array $data): void
    {
        // Resolve Hierarchy
        if (!empty($data['parent_username'])) {
            $parent = User::where('username', $data['parent_username'])->firstOrFail();
            $data['parent_user_id'] = $parent->id;
        }
        unset($data['parent_username']);

        // Resolve Level
        if (!empty($data['level_code'])) {
            $level = Level::where('code', $data['level_code'])->firstOrFail();
            $data['level_id'] = $level->id;
        }
        unset($data['level_code']);

        // Resolve Geography and Branch
        $this->resolveGeographicalIds($data);
        if (!empty($data['branch_code'])) {
            $branch = Branch::where('code', $data['branch_code'])->firstOrFail();
            $data['branch_id'] = $branch->id;
        }
        unset($data['branch_code']);

        $roleName = $data['role'] ?? null;
        unset($data['role']);

        // Password Hashing
        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user = User::updateOrCreate(['email' => $data['email']], $data);

        // Assign Role
        if ($roleName) {
            $user->assignRole($roleName);
        }
    }

    /**
     * Helper to resolve Province, Zone, and Region IDs by their codes.
     */
    protected function resolveGeographicalIds(array &$data): void
    {
        if (!empty($data['province_code'])) {
            $data['province_id'] = Province::where('code', $data['province_code'])->firstOrFail()->id;
            unset($data['province_code']);
        }
        if (!empty($data['zone_code'])) {
            $data['zone_id'] = Zone::where('code', $data['zone_code'])->firstOrFail()->id;
            unset($data['zone_code']);
        }
        if (!empty($data['region_code'])) {
            $data['region_id'] = Region::where('code', $data['region_code'])->firstOrFail()->id;
            unset($data['region_code']);
        }
    }
}

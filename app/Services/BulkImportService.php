<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\Level;
use App\Models\Province;
use App\Models\Region;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Zone;
use App\Models\Beneficiary;
use App\Models\Receipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class BulkImportService
{
    /**
     * Get configuration for all importable tables.
     */
    public function getImportableConfig(): array
    {
        return [
            'countries' => [
                'model' => Country::class,
                'unique_key' => 'code',
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'provinces' => [
                'model' => Province::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'country_code' => ['model' => Country::class, 'field' => 'code', 'foreign_key' => 'country_id']
                ],
                'fillable' => ['name', 'code', 'is_active', 'contact_info'],
            ],
            'zones' => [
                'model' => Zone::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id']
                ],
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'regions' => [
                'model' => Region::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'zone_code' => ['model' => Zone::class, 'field' => 'code', 'foreign_key' => 'zone_id']
                ],
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'branches' => [
                'model' => Branch::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                    'zone_code' => ['model' => Zone::class, 'field' => 'code', 'foreign_key' => 'zone_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                ],
                'fillable' => ['name', 'code', 'address_line1', 'address_line2', 'city', 'postal_code', 'phone_primary', 'phone_secondary', 'email', 'fax', 'opening_date', 'branch_type', 'latitude', 'longitude', 'is_active', 'is_head_office'],
            ],
            'levels' => [
                'model' => Level::class,
                'unique_key' => 'code',
                'fillable' => ['level_name', 'slug', 'code', 'tire_level', 'category', 'isActive', 'is_single_user'],
            ],
            'investment_products' => [
                'model' => InvestmentProduct::class,
                'unique_key' => 'code',
                'fillable' => ['name', 'code', 'duration_months', 'roi_percentage', 'is_variable_roi', 'unit_head_commission_pct', 'parent_commission_pct', 'is_active'],
            ],
            'system_settings' => [
                'model' => SystemSetting::class,
                'unique_key' => 'key',
                'fillable' => ['key', 'value', 'type', 'group', 'description'],
            ],
            'users' => [
                'model' => User::class,
                'unique_key' => 'email',
                'dependencies' => [
                    'parent_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'parent_user_id'],
                    'level_code' => ['model' => Level::class, 'field' => 'code', 'foreign_key' => 'level_id'],
                    'branch_code' => ['model' => Branch::class, 'field' => 'code', 'foreign_key' => 'branch_id'],
                    'zone_code' => ['model' => Zone::class, 'field' => 'code', 'foreign_key' => 'zone_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                ],
                'special_fields' => ['password', 'role'],
                'fillable' => ['name', 'username', 'email', 'user_type', 'is_active', 'can_login', 'id_type', 'id_number'],
            ],
            'customers' => [
                'model' => Customer::class,
                'unique_key' => 'customer_code',
                'dependencies' => [
                    'agent_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'customer_id'] // agent is stored in customer_id
                ],
                'fillable' => ['full_name', 'name_with_initials', 'customer_code', 'id_type', 'id_number', 'address_line_1', 'address_line_2', 'landmark', 'city', 'state', 'country', 'postal_code', 'date_of_birth', 'phone_primary', 'phone_secondary', 'email', 'have_whatsapp', 'whatsapp_number', 'preferred_language', 'employment_status', 'occupation', 'employer_name', 'employer_address_line1', 'employer_address_line2', 'employer_city', 'employer_state', 'employer_country', 'employer_postal_code', 'employer_phone', 'employer_email', 'business_name', 'business_registration_number', 'business_nature', 'business_address_line1', 'business_address_line2', 'business_city', 'business_state', 'business_country', 'business_postal_code', 'business_phone', 'business_email', 'is_active'],
            ],
            'investments' => [
                'model' => Investment::class,
                'unique_key' => 'application_number',
                'dependencies' => [
                    'customer_code' => ['model' => Customer::class, 'field' => 'customer_code', 'foreign_key' => 'customer_id'],
                    'branch_code' => ['model' => Branch::class, 'field' => 'code', 'foreign_key' => 'branch_id'],
                    'product_code' => ['model' => InvestmentProduct::class, 'field' => 'code', 'foreign_key' => 'investment_product_id'],
                    'created_by_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'created_by'],
                    'unit_head_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'unit_head_id'],
                ],
                'fillable' => ['policy_number', 'application_number', 'sales_code', 'reservation_date', 'target_period_key', 'investment_amount', 'bank', 'payment_type', 'payment_description', 'initial_payment', 'initial_payment_date', 'monthly_payment_amount', 'monthly_payment_date', 'payment_proof', 'status', 'notes'],
            ],
        ];
    }

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

        $config = $this->getImportableConfig()[$table] ?? null;
        if (!$config) {
            return array_merge($results, ['errors' => ["Unsupported table: $table"]]);
        }

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
            
            // Handle mismatched column counts
            if (count($headers) !== count($rowData)) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => "Column count mismatch. Expected " . count($headers) . ", got " . count($rowData)
                ];
                continue;
            }

            $data = array_combine($headers, $rowData);
            
            DB::beginTransaction();
            try {
                $this->processGenericRow($config, $data);
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
     * Generic row processing using config.
     */
    protected function processGenericRow(array $config, array $data): void
    {
        $modelClass = $config['model'];
        $uniqueKeyField = $config['unique_key'];
        
        // Resolve Dependencies
        if (isset($config['dependencies'])) {
            foreach ($config['dependencies'] as $csvCol => $dep) {
                if (!empty($data[$csvCol])) {
                    $resolved = $dep['model']::where($dep['field'], $data[$csvCol])->first();
                    if (!$resolved) {
                        throw new \Exception("Could not resolve {$csvCol} with value '{$data[$csvCol]}'");
                    }
                    $data[$dep['foreign_key']] = $resolved->id;
                }
                unset($data[$csvCol]);
            }
        }

        // Special handling for Users
        if ($modelClass === User::class) {
            if (!empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }
            $roleName = $data['role'] ?? null;
            unset($data['role']);

            $user = User::updateOrCreate([$uniqueKeyField => $data[$uniqueKeyField]], $data);
            
            if ($roleName) {
                $user->syncRoles([$roleName]);
            }
            return;
        }

        // Default updateOrCreate
        $modelClass::updateOrCreate(
            [$uniqueKeyField => $data[$uniqueKeyField]],
            $data
        );
    }

    /**
     * Get list of importable tables for dynamic selection.
     */
    public function getImportableTables(): array
    {
        $configs = $this->getImportableConfig();
        $list = [];

        foreach ($configs as $table => $config) {
            $headers = $config['fillable'];
            if (isset($config['dependencies'])) {
                $headers = array_merge($headers, array_keys($config['dependencies']));
            }
            if (isset($config['special_fields'])) {
                $headers = array_merge($headers, $config['special_fields']);
            }
            
            $list[] = [
                'table' => $table,
                'headers' => $headers,
                'unique_key' => $config['unique_key']
            ];
        }

        return $list;
    }
}

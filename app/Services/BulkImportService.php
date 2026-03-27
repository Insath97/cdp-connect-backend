<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Branch;
use App\Models\Commission;
use App\Models\CommissionSetting;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\InvestmentProductRate;
use App\Models\Level;
use App\Models\Province;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Region;
use App\Models\SystemSetting;
use App\Models\Target;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Carbon\Carbon;

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
                    'agent_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'customer_id']
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
                    'checked_by_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'checked_by'],
                    'approved_by_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'approved_by'],
                ],
                'fillable' => ['policy_number', 'application_number', 'sales_code', 'reservation_date', 'target_period_key', 'investment_amount', 'bank', 'payment_type', 'payment_description', 'initial_payment', 'initial_payment_date', 'monthly_payment_amount', 'monthly_payment_date', 'payment_proof', 'status', 'checked_at', 'approved_at', 'notes'],
            ],
            'beneficiaries' => [
                'model' => Beneficiary::class,
                'unique_key' => 'id_number',
                'dependencies' => [
                    'customer_code' => ['model' => Customer::class, 'field' => 'customer_code', 'foreign_key' => 'customer_id'],
                ],
                'fillable' => ['full_name', 'id_type', 'id_number', 'phone_primary', 'relationship', 'share_percentage'],
            ],
            'receipts' => [
                'model' => Receipt::class,
                'unique_key' => 'receipt_number',
                'dependencies' => [
                    'application_number' => ['model' => Investment::class, 'field' => 'application_number', 'foreign_key' => 'investment_id'],
                ],
                'fillable' => ['receipt_number', 'amount', 'printed_at'],
            ],
            'customer_bank_details' => [
                'model' => CustomerBankDetail::class,
                'unique_key' => 'account_number',
                'dependencies' => [
                    'customer_code' => ['model' => Customer::class, 'field' => 'customer_code', 'foreign_key' => 'customer_id'],
                ],
                'fillable' => ['bank_name', 'branch_name', 'account_number', 'payment_method'],
            ],
            'commissions' => [
                'model' => Commission::class,
                'unique_key' => 'id',
                'dependencies' => [
                    'application_number' => ['model' => Investment::class, 'field' => 'application_number', 'foreign_key' => 'investment_id'],
                    'username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'user_id'],
                ],
                'fillable' => ['investment_amount', 'commission_amount', 'commission_percentage', 'tier', 'period_key', 'status'],
            ],
            'commission_settings' => [
                'model' => CommissionSetting::class,
                'unique_key' => 'key',
                'fillable' => ['key', 'value', 'description'],
            ],
            'investment_product_rates' => [
                'model' => InvestmentProductRate::class,
                'unique_key' => 'id',
                'dependencies' => [
                    'product_code' => ['model' => InvestmentProduct::class, 'field' => 'code', 'foreign_key' => 'investment_product_id'],
                ],
                'fillable' => ['year', 'roi_percentage'],
            ],
            'quotations' => [
                'model' => Quotation::class,
                'unique_key' => 'quotation_number',
                'dependencies' => [
                    'customer_code' => ['model' => Customer::class, 'field' => 'customer_code', 'foreign_key' => 'customer_id'],
                    'branch_code' => ['model' => Branch::class, 'field' => 'code', 'foreign_key' => 'branch_id'],
                    'product_code' => ['model' => InvestmentProduct::class, 'field' => 'code', 'foreign_key' => 'investment_product_id'],
                    'created_by_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'created_by'],
                ],
                'fillable' => ['quotation_number', 'f_name', 'l_name', 'full_name', 'name_with_initials', 'id_type', 'id_number', 'phone_primary', 'email', 'address', 'investment_amount', 'monthly_return', 'annual_return', 'maturity_amount', 'month_6_breakdown', 'year_1_breakdown', 'year_2_breakdown', 'year_3_breakdown', 'year_4_breakdown', 'year_5_breakdown', 'status', 'is_active', 'valid_until', 'notes'],
            ],
            'targets' => [
                'model' => Target::class,
                'unique_key' => 'id',
                'dependencies' => [
                    'username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'user_id'],
                    'assigned_by_username' => ['model' => User::class, 'field' => 'username', 'foreign_key' => 'assigned_by'],
                ],
                'fillable' => ['period_type', 'period_key', 'target_amount', 'current_amount', 'achieved_amount', 'achievement_percentage', 'status', 'achieved_at'],
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

        // Read and clean headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return array_merge($results, ['errors' => ['Empty or invalid CSV file.']]);
        }

        // Clean headers (remove BOM, trim whitespace)
        $headers = array_map(function($header) {
            return trim($header, "\xEF\xBB\xBF");
        }, $headers);
        $headers = array_map('trim', $headers);

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

            // Clean all data (remove empty strings, trim)
            $data = $this->cleanData($data);

            // Special preprocessing for customers table
            if ($table === 'customers') {
                $data = $this->preprocessCustomerData($data);

                // Skip if date_of_birth is invalid
                if (isset($data['date_of_birth']) && $data['date_of_birth'] === null) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'row' => $rowNumber,
                        'error' => "Invalid date format in date_of_birth. Expected dd/mm/yyyy"
                    ];
                    continue;
                }
            }

            // Special preprocessing for investments table
            if ($table === 'investments') {
                $data = $this->preprocessInvestmentData($data);
            }

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
     * Clean and sanitize data
     */
    protected function cleanData(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                $cleaned[$key] = null;
            } else {
                $cleaned[$key] = trim($value);
            }
        }
        return $cleaned;
    }

    /**
     * Preprocess customer data (date format, boolean fields, etc.)
     */
    protected function preprocessCustomerData(array $data): array
    {
        // 1. Handle date_of_birth conversion (dd/mm/yyyy -> Y-m-d)
        if (!empty($data['date_of_birth'])) {
            $parsedDate = $this->parseDate($data['date_of_birth']);
            if ($parsedDate) {
                $data['date_of_birth'] = $parsedDate;
            } else {
                $data['date_of_birth'] = null; // Will be caught by validation
            }
        }

        // 2. Handle have_whatsapp boolean field
        if (isset($data['have_whatsapp'])) {
            $value = strtolower(trim($data['have_whatsapp']));
            if (in_array($value, ['1', 'true', 'yes', 'y', 'on'])) {
                $data['have_whatsapp'] = true;
            } elseif (in_array($value, ['0', 'false', 'no', 'n', 'off', ''])) {
                $data['have_whatsapp'] = false;
            } else {
                $data['have_whatsapp'] = false; // Default to false
            }
        } else {
            $data['have_whatsapp'] = false;
        }

        // 3. Handle phone numbers - clean formatting
        if (!empty($data['phone_primary'])) {
            $data['phone_primary'] = $this->cleanPhoneNumber($data['phone_primary']);
        }

        if (!empty($data['phone_secondary'])) {
            $data['phone_secondary'] = $this->cleanPhoneNumber($data['phone_secondary']);
        }

        if (!empty($data['whatsapp_number'])) {
            $data['whatsapp_number'] = $this->cleanPhoneNumber($data['whatsapp_number']);
        }

        // 4. Handle id_number - remove spaces
        if (!empty($data['id_number'])) {
            $data['id_number'] = str_replace(' ', '', trim($data['id_number']));
        }

        // 5. Set default values for required fields if missing
        if (empty($data['country'])) {
            $data['country'] = 'Sri Lanka';
        }

        if (empty($data['preferred_language'])) {
            $data['preferred_language'] = 'english';
        }

        if (empty($data['employment_status'])) {
            $data['employment_status'] = 'unemployed';
        }

        if (!isset($data['is_active']) || $data['is_active'] === null) {
            $data['is_active'] = true;
        }

        return $data;
    }

    /**
     * Preprocess investment data
     */
    protected function preprocessInvestmentData(array $data): array
    {
        // Parse dates
        $dateFields = ['reservation_date', 'initial_payment_date', 'monthly_payment_date'];
        foreach ($dateFields as $field) {
            if (!empty($data[$field])) {
                $parsedDate = $this->parseDate($data[$field]);
                if ($parsedDate) {
                    $data[$field] = $parsedDate;
                } else {
                    $data[$field] = null;
                }
            }
        }

        // Handle numeric fields
        $numericFields = ['investment_amount', 'initial_payment', 'monthly_payment_amount'];
        foreach ($numericFields as $field) {
            if (!empty($data[$field])) {
                $data[$field] = floatval(str_replace(',', '', $data[$field]));
            }
        }

        return $data;
    }

    /**
     * Parse date from dd/mm/yyyy to Y-m-d
     */
    protected function parseDate($date): ?string
    {
        if (empty($date)) return null;

        $date = trim($date);

        // Handle dd/mm/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // Handle dd-mm-YYYY format
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // Handle YYYY-mm-dd format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // Try Carbon as last resort
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clean phone number
     */
    protected function cleanPhoneNumber($phone): ?string
    {
        if (empty($phone)) return null;

        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading zeros
        $phone = ltrim($phone, '0');

        // Add 94 if it's a 9-digit number (Sri Lankan mobile)
        if (strlen($phone) === 9 && preg_match('/^7[0-9]{8}$/', $phone)) {
            $phone = '94' . $phone;
        }

        return $phone;
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

        // Prepare allowed fields: fillable + dependencies + unique key
        $allowedFields = $config['fillable'];
        if (isset($config['dependencies'])) {
            foreach ($config['dependencies'] as $dep) {
                $allowedFields[] = $dep['foreign_key'];
            }
        }
        if (!in_array($uniqueKeyField, $allowedFields)) {
            $allowedFields[] = $uniqueKeyField;
        }

        // Remove any fields that aren't in the allowed list
        $filteredData = array_intersect_key($data, array_flip($allowedFields));

        // Default updateOrCreate
        $modelClass::updateOrCreate(
            [$uniqueKeyField => $filteredData[$uniqueKeyField]],
            $filteredData
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

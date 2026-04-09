// contents for a tinker script
use App\Models\Investment;
use App\Models\InvestmentProduct;
use App\Models\User;
use App\Models\Commission;
use App\Models\Branch;
use App\Http\Controllers\V1\InvestmentController;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    // 1. Setup
    // Use first available branch/customer to avoid strict constraint issues
    $branch = Branch::first() ?: Branch::create(['name' => 'Test Branch', 'code' => 'TB', 'is_active' => true]);
    $parent = User::factory()->create(['name' => 'Test Parent', 'email' => 'parent_test@example.com']);
    $unitHead = User::factory()->create(['name' => 'Test UH', 'parent_user_id' => $parent->id, 'email' => 'uh_test@example.com']);
    
    $product = InvestmentProduct::create([
        'name' => 'Test Product Verify',
        'code' => 'TP-99-V',
        'unit_head_commission_pct' => 10.00,
        'parent_commission_pct' => 20.00,
        'duration_months' => 12,
        'roi_percentage' => 10,
        'is_active' => true
    ]);
    
    $investment = Investment::create([
        'investment_amount' => 100000,
        'unit_head_id' => $unitHead->id,
        'investment_product_id' => $product->id,
        'target_period_key' => '2024-04',
        'status' => 'pending',
        'application_number' => 'V-' . time(),
        'sales_code' => 'V-' . time(),
        'branch_id' => $branch->id,
        'customer_id' => 1 // Assuming 1 exists or is not heavily constrained
    ]);

    // 2. Logic call - Using Reflection to access protected method
    $reflection = new ReflectionClass(InvestmentController::class);
    $method = $reflection->getMethod('processCommissions');
    $method->setAccessible(true);
    
    $controller = new InvestmentController();
    $method->invokeArgs($controller, [$investment]);

    // 3. Assertion
    $uh = Commission::where('investment_id', $investment->id)->where('tier', 'unit_head')->first();
    $pc = Commission::where('investment_id', $investment->id)->where('tier', 'parent')->first();

    echo "--- RESULTS ---\n";
    echo "UH Comm Amount: " . ($uh->commission_amount ?? 'N/A') . " (Expected: 10000)\n";
    echo "Parent Comm Amount: " . ($pc->commission_amount ?? 'N/A') . " (Expected: 2000)\n";
    echo "---------------\n";
    
    if ($uh && $pc && (float)$uh->commission_amount == 10000 && (float)$pc->commission_amount == 2000) {
        echo "VERIFICATION STATUS: PASSED\n";
    } else {
        echo "VERIFICATION STATUS: FAILED\n";
    }

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
} finally {
    DB::rollBack(); // Don't pollute DB
}

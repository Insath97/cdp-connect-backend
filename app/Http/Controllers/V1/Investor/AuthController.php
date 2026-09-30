<?php

namespace App\Http\Controllers\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestOtpRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Requests\SetPasswordRequest;
use App\Http\Requests\InvestorLoginRequest;
use App\Models\Customer;
use App\Models\CustomerOtp;
use App\Models\User;
use App\Services\SmsService;
use App\Traits\ActivityLogTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    use ActivityLogTrait;

    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Request an OTP for customer login/verification.
     *
     * @param RequestOtpRequest $request
     * @return JsonResponse
     */
    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        try {
            $idNumber = trim((string) $request->input('id_number'));
            $idType = $request->input('id_type');

            // 1. Find customer by id_number and optional id_type (case-insensitive)
            $customerQuery = Customer::query();

            if (!empty($idType)) {
                $customerQuery->where('id_type', strtolower(trim((string) $idType)));
            }

            $customer = $customerQuery->where(function ($q) use ($idNumber) {
                $cleanNumber = preg_replace('/[^0-9a-zA-Z]/', '', $idNumber);
                $q->whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($idNumber)])
                  ->orWhere('phone_primary', $idNumber)
                  ->orWhere('phone_primary', 'like', '%' . $cleanNumber);
            })->first();

            if (!$customer) {
                Log::warning("OTP Request Failed: No customer found for ID/phone: {$idNumber}");
                $this->logActivity('OTP Request Failed', 'Investor Portal', "No customer found for ID: {$idNumber}", [
                    'id_number' => $idNumber,
                    'id_type' => $idType,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'No customer found matching the provided ID number.',
                ], 404);
            }

            // 2. Check if customer is active
            if (!$customer->is_active) {
                Log::warning("OTP Request Denied: Inactive customer tried to request OTP for ID: {$idNumber}, customer_id: {$customer->id}");
                $this->logActivity('OTP Request Denied', 'Investor Portal', "Inactive customer tried to request OTP for ID: {$idNumber}", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Your account is inactive. Please contact customer support.',
                ], 403);
            }

            // 3. Ensure primary phone is present
            $phone = trim($customer->phone_primary ?? '');
            if (empty($phone)) {
                Log::warning("OTP Request Warning: No primary mobile number found on file for customer ID: {$customer->id} (ID: {$idNumber})");
                $this->logActivity('Warning', 'Investor Portal', "No primary mobile number found on file for customer: {$customer->full_name} (ID: {$idNumber})", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'No primary mobile number found on file for this account. Please contact customer support.',
                ], 422);
            }

            // 4. Rate-limiting: Cooldown check (60 seconds)
            $recentOtp = CustomerOtp::where('id_number', $customer->id_number)
                ->active()
                ->latest('created_at')
                ->first();

            if ($recentOtp && $recentOtp->created_at->gt(Carbon::now()->subSeconds(60))) {
                $secondsRemaining = 60 - (int) Carbon::now()->diffInSeconds($recentOtp->created_at);

                Log::info("OTP request cooldown active for customer {$customer->id_number} ({$secondsRemaining}s remaining)");
                $this->logActivity('Warning', 'Investor Portal', "OTP request cooldown active for customer: {$customer->full_name} (ID: {$idNumber}, {$secondsRemaining}s remaining)", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                    'seconds_remaining' => $secondsRemaining,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => "Please wait {$secondsRemaining} seconds before requesting a new OTP.",
                    'data' => [
                        'retry_after_seconds' => $secondsRemaining,
                    ],
                ], 429);
            }

            // Rate-limiting: Maximum 5 OTP requests per hour
            $recentOtpsCount = CustomerOtp::where('id_number', $customer->id_number)
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($recentOtpsCount >= 5) {
                Log::warning("OTP request hourly limit reached (5/hr) for customer: {$customer->id_number}");
                $this->logActivity('Warning', 'Investor Portal', "Hourly OTP request limit reached for customer: {$customer->full_name} (ID: {$customer->id_number})", [
                    'customer_id' => $customer->id,
                    'id_number' => $customer->id_number,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Maximum OTP request limit reached for this hour. Please try again later or contact support.',
                ], 429);
            }

            // 5. Invalidate existing active unverified OTPs
            CustomerOtp::where('id_number', $customer->id_number)
                ->whereNull('verified_at')
                ->update(['expires_at' => Carbon::now()]);

            // 6. Generate secure 6-digit OTP
            $otpCode = (string) random_int(100000, 999999);

            // 7. Store OTP record (valid for 10 minutes)
            $otpRecord = CustomerOtp::create([
                'customer_id' => $customer->id,
                'id_number' => $customer->id_number,
                'otp' => $otpCode,
                'expires_at' => Carbon::now()->addMinutes(10),
                'attempts' => 0,
            ]);

            // 8. Mask phone number for response
            $maskedPhone = $this->maskPhoneNumber($phone);

            // 9. Send SMS
            $smsMessage = "Your CDP CORE verification code is: {$otpCode}. Valid for 10 minutes. Do not share this code.";
            $smsSent = false;
            try {
                $smsSent = $this->smsService->sendSms($phone, $smsMessage);
            } catch (\Throwable $e) {
                Log::error("Failed to send OTP SMS to {$phone}: " . $e->getMessage(), [
                    'customer_id' => $customer->id,
                    'exception' => $e,
                ]);
                $this->logActivity('Error', 'Investor Portal', "Failed to send OTP SMS to {$phone}: " . $e->getMessage(), [
                    'customer_id' => $customer->id,
                    'phone' => $phone,
                ]);
            }

            // 10. Log activity & info
            Log::info("OTP requested successfully for customer {$customer->id_number} (Customer ID: {$customer->id}, SMS: " . ($smsSent ? 'sent' : 'failed') . ")");
            $this->logActivity('OTP Requested', 'Investor Portal', "OTP requested for customer: {$customer->full_name} (ID: {$customer->id_number})", [
                'customer_id' => $customer->id,
                'id_number' => $customer->id_number,
                'otp_id' => $otpRecord->id,
                'sms_sent' => $smsSent,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'OTP has been sent successfully to your registered mobile number.',
                'data' => [
                    'id_type' => $customer->id_type,
                    'id_number' => $customer->id_number,
                    'phone_masked' => $maskedPhone,
                    'expires_in_seconds' => 600,
                    'resend_available_in_seconds' => 60,
                    'otp' => config('app.debug') ? $otpCode : null,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OTP request error: ' . $th->getMessage(), [
                'exception' => $th,
                'request' => $request->only('id_number'),
            ]);

            $this->logActivity('Error', 'Investor Portal', 'OTP request error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
                'id_number' => $request->input('id_number'),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while processing your OTP request. Please try again later.',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Resend an OTP to the customer.
     *
     * @param ResendOtpRequest $request
     * @return JsonResponse
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        try {
            $idNumber = trim((string) $request->input('id_number'));
            $idType = $request->input('id_type');

            // 1. Find customer by id_number and optional id_type
            $customerQuery = Customer::query();

            if (!empty($idType)) {
                $customerQuery->where('id_type', strtolower(trim((string) $idType)));
            }

            $customer = $customerQuery->where(function ($q) use ($idNumber) {
                $cleanNumber = preg_replace('/[^0-9a-zA-Z]/', '', $idNumber);
                $q->whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($idNumber)])
                  ->orWhere('phone_primary', $idNumber)
                  ->orWhere('phone_primary', 'like', '%' . $cleanNumber);
            })->first();

            if (!$customer) {
                Log::warning("OTP Resend Failed: No customer found for ID/phone: {$idNumber}");
                $this->logActivity('OTP Resend Failed', 'Investor Portal', "No customer found for ID: {$idNumber}", [
                    'id_number' => $idNumber,
                    'id_type' => $idType,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'No customer found matching the provided ID number.',
                ], 404);
            }

            // 2. Check customer active status
            if (!$customer->is_active) {
                Log::warning("OTP Resend Denied: Inactive customer tried to resend OTP for ID: {$idNumber}, customer_id: {$customer->id}");
                $this->logActivity('OTP Resend Denied', 'Investor Portal', "Inactive customer tried to resend OTP for ID: {$idNumber}", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Your account is inactive. Please contact customer support.',
                ], 403);
            }

            // 3. Ensure primary phone is present
            $phone = trim($customer->phone_primary ?? '');
            if (empty($phone)) {
                Log::warning("OTP Resend Warning: No primary mobile number found on file for customer ID: {$customer->id} (ID: {$idNumber})");
                $this->logActivity('Warning', 'Investor Portal', "No primary mobile number found on file for customer: {$customer->full_name} (ID: {$idNumber})", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'No primary mobile number found on file for this account. Please contact customer support.',
                ], 422);
            }

            // 4. Rate-limiting: Cooldown check (60 seconds)
            $latestOtp = CustomerOtp::where('id_number', $customer->id_number)
                ->latest('created_at')
                ->first();

            if ($latestOtp && $latestOtp->created_at->gt(Carbon::now()->subSeconds(60))) {
                $secondsRemaining = 60 - (int) Carbon::now()->diffInSeconds($latestOtp->created_at);

                Log::info("OTP resend cooldown active for customer {$customer->id_number} ({$secondsRemaining}s remaining)");
                $this->logActivity('Warning', 'Investor Portal', "OTP resend cooldown active for customer: {$customer->full_name} (ID: {$idNumber}, {$secondsRemaining}s remaining)", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                    'seconds_remaining' => $secondsRemaining,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => "Please wait {$secondsRemaining} seconds before requesting a new OTP.",
                    'data' => [
                        'retry_after_seconds' => $secondsRemaining,
                    ],
                ], 429);
            }

            // 5. Rate limit: Maximum 5 OTP requests per hour
            $recentOtpsCount = CustomerOtp::where('id_number', $customer->id_number)
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($recentOtpsCount >= 5) {
                Log::warning("OTP resend hourly limit reached (5/hr) for customer: {$customer->id_number}");
                $this->logActivity('Warning', 'Investor Portal', "Hourly OTP resend limit reached for customer: {$customer->full_name} (ID: {$idNumber})", [
                    'customer_id' => $customer->id,
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Maximum OTP resend limit reached for this hour. Please try again later or contact support.',
                ], 429);
            }

            // 6. Invalidate previous active unverified OTPs
            CustomerOtp::where('id_number', $customer->id_number)
                ->whereNull('verified_at')
                ->update(['expires_at' => Carbon::now()]);

            // 7. Generate new 6-digit OTP
            $otpCode = (string) random_int(100000, 999999);

            // 8. Store new OTP record
            $otpRecord = CustomerOtp::create([
                'customer_id' => $customer->id,
                'id_number' => $customer->id_number,
                'otp' => $otpCode,
                'expires_at' => Carbon::now()->addMinutes(10),
                'attempts' => 0,
            ]);

            // 9. Mask phone
            $maskedPhone = $this->maskPhoneNumber($phone);

            // 10. Send SMS
            $smsMessage = "Your CDP Connect verification code is: {$otpCode}. Valid for 10 minutes. Do not share this code.";
            $smsSent = false;
            try {
                $smsSent = $this->smsService->sendSms($phone, $smsMessage);
            } catch (\Throwable $e) {
                Log::error("Failed to resend OTP SMS to {$phone}: " . $e->getMessage(), [
                    'customer_id' => $customer->id,
                    'exception' => $e,
                ]);
                $this->logActivity('Error', 'Investor Portal', "Failed to resend OTP SMS to {$phone}: " . $e->getMessage(), [
                    'customer_id' => $customer->id,
                    'phone' => $phone,
                ]);
            }

            // 11. Log activity & info
            Log::info("OTP resent successfully for customer {$customer->id_number} (Customer ID: {$customer->id}, SMS: " . ($smsSent ? 'sent' : 'failed') . ")");
            $this->logActivity('OTP Resent', 'Investor Portal', "OTP resent for customer: {$customer->full_name} (ID: {$customer->id_number})", [
                'customer_id' => $customer->id,
                'id_number' => $customer->id_number,
                'otp_id' => $otpRecord->id,
                'sms_sent' => $smsSent,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'A new OTP has been resent to your registered mobile number.',
                'data' => [
                    'id_type' => $customer->id_type,
                    'id_number' => $customer->id_number,
                    'phone_masked' => $maskedPhone,
                    'expires_in_seconds' => 600,
                    'resend_available_in_seconds' => 60,
                    'otp' => config('app.debug') ? $otpCode : null,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OTP resend error: ' . $th->getMessage(), [
                'exception' => $th,
                'request' => $request->only('id_number'),
            ]);

            $this->logActivity('Error', 'Investor Portal', 'OTP resend error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
                'id_number' => $request->input('id_number'),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while resending the OTP. Please try again later.',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Verify OTP code and return customer identity with reset token.
     *
     * @param VerifyOtpRequest $request
     * @return JsonResponse
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $otp = trim((string) $request->input('otp'));
            $idNumber = trim((string) $request->input('id_number', ''));

            // Find active unverified OTP matching the code
            $otpQuery = CustomerOtp::with('customer')
                ->active()
                ->where('otp', $otp);

            if (!empty($idNumber)) {
                $otpQuery->where('id_number', $idNumber);
            }

            $otpRecord = $otpQuery->latest('created_at')->first();

            if (!$otpRecord) {
                // If id_number was provided, track failed attempt on any pending OTP
                if (!empty($idNumber)) {
                    $pendingOtp = CustomerOtp::where('id_number', $idNumber)->active()->latest('created_at')->first();
                    if ($pendingOtp) {
                        $pendingOtp->incrementAttempts();
                        $remaining = max(0, 5 - $pendingOtp->attempts);

                        if ($pendingOtp->hasMaxAttemptsReached(5)) {
                            Log::warning("OTP verification max attempts exceeded for ID: {$idNumber}, OTP ID: {$pendingOtp->id}");
                            $this->logActivity('Warning', 'Investor Portal', "OTP verification max attempts exceeded for ID: {$idNumber}", [
                                'customer_id' => $pendingOtp->customer_id,
                                'id_number' => $idNumber,
                                'attempts' => $pendingOtp->attempts,
                            ]);

                            return response()->json([
                                'status' => 'error',
                                'message' => 'Maximum verification attempts exceeded. Please request a new OTP.',
                            ], 422);
                        }

                        Log::warning("Invalid OTP code attempt for ID: {$idNumber}. Remaining: {$remaining}");
                        $this->logActivity('Warning', 'Investor Portal', "Invalid OTP code entered for ID: {$idNumber} ({$remaining} attempts remaining)", [
                            'customer_id' => $pendingOtp->customer_id,
                            'id_number' => $idNumber,
                            'attempts_used' => $pendingOtp->attempts,
                            'attempts_remaining' => $remaining,
                        ]);

                        return response()->json([
                            'status' => 'error',
                            'message' => "Invalid OTP code. {$remaining} attempts remaining.",
                            'data' => [
                                'attempts_remaining' => $remaining,
                            ],
                        ], 422);
                    }
                }

                Log::warning("Invalid or expired OTP verification attempt (id_number: '{$idNumber}')");
                $this->logActivity('Warning', 'Investor Portal', "Invalid or expired OTP verification attempt", [
                    'id_number' => $idNumber ?: null,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired OTP code. Please request a new OTP.',
                ], 422);
            }

            // Check if maximum attempts already reached
            if ($otpRecord->hasMaxAttemptsReached(5)) {
                Log::warning("OTP verification blocked: max attempts exceeded for customer {$otpRecord->id_number}, OTP ID: {$otpRecord->id}");
                $this->logActivity('Warning', 'Investor Portal', "OTP verification blocked: max attempts exceeded for ID: {$otpRecord->id_number}", [
                    'customer_id' => $otpRecord->customer_id,
                    'id_number' => $otpRecord->id_number,
                    'otp_id' => $otpRecord->id,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Maximum verification attempts exceeded. Please request a new OTP.',
                ], 422);
            }

            // Mark OTP as verified
            $otpRecord->markAsVerified();

            // Retrieve customer from database
            $customer = $otpRecord->customer;
            if (!$customer) {
                $customer = Customer::whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($otpRecord->id_number)])->first();
            }

            if (!$customer) {
                Log::error("Associated customer record not found for verified OTP ID {$otpRecord->id} (id_number: {$otpRecord->id_number})");
                $this->logActivity('Error', 'Investor Portal', "Associated customer record not found for verified OTP (ID: {$otpRecord->id_number})", [
                    'otp_id' => $otpRecord->id,
                    'id_number' => $otpRecord->id_number,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Associated customer record not found.',
                ], 404);
            }

            // Generate secure short-lived reset token (15 minutes)
            $resetToken = bin2hex(random_bytes(32));
            Cache::put("investor_reset_token_{$customer->id_number}", $resetToken, Carbon::now()->addMinutes(15));

            Log::info("OTP verified successfully for customer: {$customer->full_name} (ID: {$customer->id_number})");
            $this->logActivity('OTP Verified', 'Investor Portal', "OTP verified for customer: {$customer->full_name} (ID: {$customer->id_number})", [
                'customer_id' => $customer->id,
                'id_number' => $customer->id_number,
                'otp_id' => $otpRecord->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'OTP verified successfully.',
                'data' => [
                    'id_type' => $customer->id_type,
                    'id_number' => $customer->id_number,
                    'reset_token' => $resetToken,
                    'expires_in_seconds' => 900,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('OTP verification error: ' . $th->getMessage(), [
                'exception' => $th,
                'request' => $request->only(['otp', 'id_number']),
            ]);

            $this->logActivity('Error', 'Investor Portal', 'OTP verification error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
                'id_number' => $request->input('id_number'),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while verifying the OTP. Please try again later.',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Set password for verified investor.
     *
     * @param SetPasswordRequest $request
     * @return JsonResponse
     */
    public function setPassword(SetPasswordRequest $request): JsonResponse
    {
        try {
            $idNumber = trim((string) $request->input('id_number'));
            $resetToken = trim((string) $request->input('reset_token'));

            // 1. Verify reset_token against Cache
            $cachedToken = Cache::get("investor_reset_token_{$idNumber}");
            if (!$cachedToken || !hash_equals($cachedToken, $resetToken)) {
                Log::warning("Set password failed: Invalid or expired reset token for ID: {$idNumber}");
                $this->logActivity('Warning', 'Investor Portal', "Set password attempted with invalid or expired reset token for ID: {$idNumber}", [
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired reset token. Please verify your OTP again.',
                ], 403);
            }

            // 2. Find customer by id_number
            $customer = Customer::whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($idNumber)])->first();
            if (!$customer) {
                Log::warning("Set password failed: Customer not found for ID: {$idNumber}");
                $this->logActivity('Warning', 'Investor Portal', "Set password failed: Customer not found for ID: {$idNumber}", [
                    'id_number' => $idNumber,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Customer account not found.',
                ], 404);
            }

            // 3. Resolve or create user account
            $user = null;
            if ($customer->customer_id) {
                $user = User::find($customer->customer_id);
            }

            if (!$user) {
                $user = User::where('id_number', $customer->id_number)
                    ->orWhere(function ($q) use ($customer) {
                        if (!empty($customer->email)) {
                            $q->where('email', $customer->email);
                        }
                    })->first();
            }

            if ($user) {
                $user->password = Hash::make($request->input('password'));
                $user->is_active = true;
                $user->can_login = true;
                $user->save();
            } else {
                $email = !empty($customer->email) ? $customer->email : ($customer->customer_code . '@customer.cdpempire.com');
                $username = !empty($customer->customer_code) ? $customer->customer_code : ('cust_' . $customer->id);

                if (User::where('username', $username)->exists()) {
                    $username = $username . '_' . rand(100, 999);
                }

                $user = User::create([
                    'name' => $customer->full_name,
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make($request->input('password')),
                    'id_type' => $customer->id_type,
                    'id_number' => $customer->id_number,
                    'user_type' => 'customer',
                    'is_active' => true,
                    'can_login' => true,
                ]);
            }

            // Link customer to user record
            $customer->update(['customer_id' => $user->id]);

            // Invalidate the reset token
            Cache::forget("investor_reset_token_{$idNumber}");

            Log::info("Password set successfully for customer: {$customer->full_name} (ID: {$customer->id_number}, User ID: {$user->id})");
            $this->logActivity('Password Set', 'Investor Portal', "Password set successfully for customer: {$customer->full_name} (ID: {$customer->id_number})", [
                'customer_id' => $customer->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Password has been set successfully. Please log in with your new password.',
                'data' => [
                    'id_type' => $customer->id_type,
                    'id_number' => $customer->id_number,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Set password error: ' . $th->getMessage(), [
                'exception' => $th,
                'request' => $request->only(['id_number']),
            ]);

            $this->logActivity('Error', 'Investor Portal', 'Set password error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
                'id_number' => $request->input('id_number'),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while setting the password. Please try again later.',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Mask phone number for secure display.
     * E.g. "0771234567" becomes "077****567".
     *
     * @param string $phone
     * @return string
     */
    protected function maskPhoneNumber(string $phone): string
    {
        $phone = trim($phone);
        $len = strlen($phone);

        if ($len <= 5) {
            return str_repeat('*', $len);
        }

        $visibleStart = 3;
        $visibleEnd = 3;
        $maskedLength = max(3, $len - $visibleStart - $visibleEnd);

        return substr($phone, 0, $visibleStart) . str_repeat('*', $maskedLength) . substr($phone, -$visibleEnd);
    }

    /**
     * Investor Login.
     *
     * @param InvestorLoginRequest $request
     * @return JsonResponse
     */
    public function login(InvestorLoginRequest $request): JsonResponse
    {
        try {
            $loginValue = trim((string) (
                $request->input('login')
                ?? $request->input('id_number')
                ?? $request->input('username')
                ?? $request->input('email')
            ));

            // 1. Search Customer table by id_number, email, or primary phone
            $cleanNumber = preg_replace('/[^0-9a-zA-Z]/', '', $loginValue);
            $customer = Customer::where(function ($q) use ($loginValue, $cleanNumber) {
                $q->whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($loginValue)])
                  ->orWhereRaw('LOWER(TRIM(email)) = ?', [strtolower($loginValue)])
                  ->orWhere('phone_primary', $loginValue)
                  ->orWhere('phone_primary', 'like', '%' . $cleanNumber);
            })->first();

            // 2. Resolve User record
            $user = null;
            if ($customer && $customer->customer_id) {
                $user = User::find($customer->customer_id);
            }

            if (!$user) {
                $user = User::where(function ($query) use ($loginValue, $customer) {
                    $query->whereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($loginValue)])
                          ->orWhereRaw('LOWER(TRIM(email)) = ?', [strtolower($loginValue)])
                          ->orWhere('username', $loginValue);

                    if ($customer) {
                        $query->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($customer->id_number)]);
                        if (!empty($customer->email)) {
                            $query->orWhereRaw('LOWER(TRIM(email)) = ?', [strtolower($customer->email)]);
                        }
                    }
                })->first();
            }

            // 3. If User was found first, resolve linked Customer if not yet found
            if (!$customer && $user) {
                $customer = Customer::where('customer_id', $user->id)
                    ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($user->id_number)])
                    ->orWhere(function ($q) use ($user) {
                        if (!empty($user->email)) {
                            $q->whereRaw('LOWER(TRIM(email)) = ?', [strtolower($user->email)]);
                        }
                    })
                    ->first();
            }

            // If customer exists and user exists, ensure customer_id is linked
            if ($customer && $user && !$customer->customer_id) {
                $customer->update(['customer_id' => $user->id]);
            }

            // 4. Validate user exists and is an investor (has linked customer profile or user_type is customer)
            $isInvestor = $user && ($user->user_type === 'customer' || $customer !== null);

            if (!$isInvestor) {
                Log::warning("Investor Login Failed: Invalid credentials or not an investor account for login: {$loginValue}");
                $this->logActivity('Login Failed', 'Investor Portal', "Invalid login credentials for: {$loginValue}", [
                    'login' => $loginValue,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid credentials',
                ], 401);
            }

            // 4. Attempt JWT authentication via api guard
            $credentials = [
                'id' => $user->id,
                'password' => $request->input('password'),
            ];

            if (!$token = Auth::guard('api')->attempt($credentials)) {
                Log::warning("Investor Login Failed: Invalid password for user ID: {$user->id} ({$user->id_number})");
                $this->logActivity('Login Failed', 'Investor Portal', "Invalid password for user: {$user->name} (ID: {$user->id_number})", [
                    'user_id' => $user->id,
                    'id_number' => $user->id_number,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid credentials',
                ], 401);
            }

            $user = auth('api')->user();

            // 5. Verify user can login and is active
            if (!$user->canLogin()) {
                Auth::guard('api')->logout();
                Log::warning("Investor Login Denied: User account deactivated: ID {$user->id}");
                $this->logActivity('Login Denied', 'Investor Portal', "Account deactivated for user: {$user->name} (ID: {$user->id_number})", [
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Account is deactivated',
                ], 401);
            }

            // 6. Verify linked customer profile active status
            if ($customer && !$customer->is_active) {
                Auth::guard('api')->logout();
                Log::warning("Investor Login Denied: Linked customer profile is inactive for customer ID {$customer->id}");
                $this->logActivity('Login Denied', 'Investor Portal', "Customer profile is inactive for customer: {$customer->full_name} (ID: {$customer->id_number})", [
                    'customer_id' => $customer->id,
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Your customer account is inactive. Please contact customer support.',
                ], 403);
            }

            // 6. Update last login
            $user->updateLastLogin($request->ip());

            // 7. Create cookie (matching existing pattern)
            $cookie = cookie(
                'auth_token',
                $token,
                60 * 24 * 7,
                '/',
                null,
                true,  // Secure
                true,  // HttpOnly
                false,
                'lax'
            );

            // 8. Attach customer relation if present
            if ($customer) {
                $user->setRelation('customer', $customer);
            }

            // 9. Logging & Response
            Log::info("Investor Login Successful: User ID {$user->id} ({$user->id_number}), Customer ID: " . ($customer->id ?? 'N/A'));
            $this->logActivity('Login', 'Investor Portal', "Investor logged in successfully: {$user->name} (ID: {$user->id_number})", [
                'user_id' => $user->id,
                'customer_id' => $customer->id ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => $user,
                    'customer' => $customer,
                    'auth_token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                ],
            ], 200)->cookie($cookie);

        } catch (\Throwable $th) {
            Log::error('Investor login error: ' . $th->getMessage(), [
                'exception' => $th,
                'request' => $request->except('password'),
            ]);

            $this->logActivity('Error', 'Investor Portal', 'Login error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to login',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Investor Logout.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if ($user) {
                Log::info("Investor Logged Out: User ID {$user->id} ({$user->id_number})");
                $this->logActivity('Logout', 'Investor Portal', "Investor logged out: {$user->name} (ID: {$user->id_number})", [
                    'user_id' => $user->id,
                ]);
            }

            // Invalidate the JWT token
            Auth::guard('api')->logout();

            // Expire the cookie
            $cookie = Cookie::forget('auth_token');

            return response()->json([
                'status' => 'success',
                'message' => 'Logout successful',
            ], 200)->withCookie($cookie);

        } catch (\Throwable $th) {
            Log::error('Investor logout error: ' . $th->getMessage(), [
                'exception' => $th,
            ]);

            $this->logActivity('Error', 'Investor Portal', 'Logout error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to logout',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Get authenticated investor profile and details.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            // Load customer details
            $customer = Customer::where('customer_id', $user->id)
                ->orWhereRaw('LOWER(TRIM(id_number)) = ?', [strtolower($user->id_number)])
                ->first();

            if ($customer) {
                $user->setRelation('customer', $customer);
            }

            Log::info("Investor profile viewed: User ID {$user->id} ({$user->id_number})");
            $this->logActivity('View Profile', 'Investor Portal', "Investor profile viewed: {$user->name} (ID: {$user->id_number})", [
                'user_id' => $user->id,
                'customer_id' => $customer->id ?? null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'User details fetched successfully',
                'data' => [
                    'user' => $user,
                    'customer' => $customer,
                ],
            ], 200);

        } catch (\Throwable $th) {
            Log::error('Investor profile fetch error: ' . $th->getMessage(), [
                'exception' => $th,
            ]);

            $this->logActivity('Error', 'Investor Portal', 'Fetch profile error: ' . $th->getMessage(), [
                'exception' => $th->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch user details',
                'error' => config('app.debug') ? $th->getMessage() : null,
            ], 500);
        }
    }
}

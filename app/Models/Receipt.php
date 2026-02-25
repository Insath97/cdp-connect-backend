<?php

namespace App\Models;

use App\Utilities\NumberToWords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Receipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'receipt_number',
        'investment_id',
        'printed_by',
        'printed_at',
        'amount',
        'amount_in_words'
    ];

    protected $casts = [
        'printed_at' => 'datetime',
        'amount' => 'decimal:2'
    ];

    public function investment()
    {
        return $this->belongsTo(Investment::class);
    }

    public function printedBy()
    {
        return $this->belongsTo(User::class, 'printed_by');
    }

    public static function generateReceiptNumber()
    {
        return DB::transaction(function () {
            // Get the count of existing receipts to determine the sequence.
            // Assuming sequence starts from 12 as per user example (712).
            $count = self::count();
            $sequence = 12 + $count;

            $prefix = "267";
            $fixedSeven = "7";

            if ($sequence < 100) {
                // 3 digits: 7 + XX (e.g., 712)
                $suffix = $fixedSeven . str_pad($sequence, 2, '0', STR_PAD_LEFT);
            } else {
                // 4 digits: 7 + XXX (e.g., 7000)
                // If sequence is 100, we want 000.
                $suffix = $fixedSeven . str_pad($sequence - 100, 3, '0', STR_PAD_LEFT);
            }

            return $prefix . $suffix;
        });
    }

    protected static function booted()
    {
        static::creating(function ($receipt) {
            if (empty($receipt->receipt_number)) {
                $receipt->receipt_number = self::generateReceiptNumber();
            }

            if (empty($receipt->amount_in_words) && !empty($receipt->amount)) {
                $receipt->amount_in_words = NumberToWords::convert($receipt->amount);
            }

            if (empty($receipt->printed_at)) {
                $receipt->printed_at = now();
            }
        });
    }
}

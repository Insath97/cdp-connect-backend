<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'full_name',
        'name_with_initials',
        'customer_code',
        'id_type',
        'id_number',
        'address_line_1',
        'address_line_2',
        'landmark',
        'city',
        'state',
        'country',
        'postal_code',
        'date_of_birth',
        'phone_primary',
        'phone_secondary',
        'have_whatsapp',
        'whatsapp_number',
        'preferred_language',
        'employment_status',
        'occupation',
        'employer_name',
        'employer_address_line1',
        'employer_address_line2',
        'employer_city',
        'employer_state',
        'employer_country',
        'employer_postal_code',
        'employer_phone',
        'employer_email',
        'business_name',
        'business_registration_number',
        'business_nature',
        'business_address_line1',
        'business_address_line2',
        'business_city',
        'business_state',
        'business_country',
        'business_postal_code',
        'business_phone',
        'business_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'date_of_birth' => 'date',
        'have_whatsapp' => 'boolean',
    ];

    /* Relationships */

    public function user()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}

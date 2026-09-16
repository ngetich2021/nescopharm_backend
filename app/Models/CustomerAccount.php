<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAccount extends Model
{

    // Ensure proper casting for PostgreSQL booleans
    protected $casts = [
        'currently_defaulted' => 'boolean',
    ];

    protected $appends = [
        'approval_status',
        'is_approved',
        'is_rejected',
        'approval_stats',
        'has_pending_credit_change',
        'pending_credit_info',
        'has_rejections',
        'has_multiple_approvals',
        'approved_by_users',
        'rejected_by_users'
    ];

    /**
     * Handle currently_defaulted boolean conversion for PostgreSQL
     */
    public function setCurrentlyDefaultedAttribute($value)
    {
        if ($value === null) {
            $this->attributes['currently_defaulted'] = 'true';
        } else {
            $this->attributes['currently_defaulted'] = $value ? 'true' : 'false';
        }
    }

    /**
     * Get currently_defaulted as boolean when retrieving
     */
    public function getCurrentlyDefaultedAttribute($value)
    {
        return $value === 'true' || $value === true || $value === 1;
    }

    public function documents()
    {
        return $this->morphMany(Document::class, 'documentable');
    }
    protected $table = 'customer_accounts';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'customer_id',
        'company_id',
        'account_number',
        'registered_business_name',
        'nature_of_business',
        'certificate_of_incorporation_number',
        'company_type',
        'pin_number',
        'annual_turnover',
        'credit_required',
        'credit_period_required',
        'pending_credit_limit',
        'currently_defaulted',
        'credit_terms',
        'notes',
        'created_by',
    ];

    /**
     * Get the registered_business_name attribute in Title Case
     */
    public function getRegisteredBusinessNameAttribute($value)
    {
        return $value ? ucwords(strtolower($value)) : null;
    }

    /**
     * Set the registered_business_name attribute to Title Case
     */
    public function setRegisteredBusinessNameAttribute($value)
    {
        $this->attributes['registered_business_name'] = $value ? ucwords(strtolower($value)) : null;
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function directors()
    {
        return $this->hasMany(AccountDirector::class);
    }

    public function authorisedPurchasePersons()
    {
        return $this->hasMany(AuthorisedPurchasePerson::class);
    }

    public function suppliers()
    {
        return $this->hasMany(AccountSupplier::class);
    }

    public function bankDetails()
    {
        return $this->hasMany(AccountBankDetail::class);
    }

    public function supplyDestinations()
    {
        return $this->hasMany(SupplyDestination::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function approvals()
    {
        return $this->hasMany(CustomerAccountApproval::class, 'customer_account_id');
    }

    public function latestApproval()
    {
        return $this->hasOne(CustomerAccountApproval::class, 'customer_account_id')->latest();
    }

    public function approvedApprovals()
    {
        return $this->hasMany(CustomerAccountApproval::class, 'customer_account_id')->where('status', 'approved');
    }

    public function rejectedApprovals()
    {
        return $this->hasMany(CustomerAccountApproval::class, 'customer_account_id')->where('status', 'rejected');
    }

    public function creditLimitApprovals()
    {
        return $this->hasMany(CustomerAccountApproval::class, 'customer_account_id')->where('approval_type', 'credit_limit_update');
    }

    public function getAccountNumberAttribute($value)
    {
        // If account_number is like ACC-853b296e-0074, return ACC-0074
        if (preg_match('/^(ACC)-(?:[\w-]+)-(\d{4})$/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        // Fallback: return original
        return $value;
    }

    /**
     * Get the current approval status of the account
     */
    public function getApprovalStatusAttribute()
    {
        $approvals = $this->approvals;
        
        if ($approvals->isEmpty()) {
            return 'pending';
        }
        
        // If ANY approval is rejected, the entire account is rejected
        if ($approvals->contains('status', 'rejected')) {
            return 'rejected';
        }
        
        // Check if we have any approved approvals
        $hasApproved = $approvals->contains('status', 'approved');
        
        // Check if we have any pending approvals
        $hasPending = $approvals->contains('status', 'pending');
        
        // If we have pending approvals (regardless of approved ones), status is pending
        if ($hasPending) {
            return 'pending';
        }
        
        // If we have at least one approval and no pending/rejected, then it's approved
        if ($hasApproved) {
            return 'approved';
        }
        
        // Default fallback (shouldn't normally reach here)
        return 'pending';
    }

    /**
     * Check if the account is approved
     */
    public function getIsApprovedAttribute()
    {
        return $this->approval_status === 'approved';
    }

    /**
     * Check if the account is rejected
     */
    public function getIsRejectedAttribute()
    {
        return $this->approval_status === 'rejected';
    }

    /**
     * Get approval statistics for this account
     */
    public function getApprovalStatsAttribute()
    {
        $approvals = $this->approvals;
        $pendingCount = $approvals->where('status', 'pending')->count();
        $approvedCount = $approvals->where('status', 'approved')->count();
        $rejectedCount = $approvals->where('status', 'rejected')->count();
        
        return [
            'total_approvals' => $approvals->count(),
            'approved_count' => $approvedCount,
            'rejected_count' => $rejectedCount,
            'pending_count' => $pendingCount,
            'credit_limit_updates' => $this->creditLimitApprovals->count(),
            'latest_approval_date' => $this->latestApproval?->approved_at,
            'latest_approval_type' => $this->latestApproval?->approval_type,
            'overall_status' => $this->approval_status,
            'has_rejections' => $rejectedCount > 0,
            'has_multiple_approvals' => $approvedCount > 1,
            'approval_summary' => [
                'approved_by' => $this->approvals->where('status', 'approved')->pluck('approver.full_name')->filter()->values(),
                'rejected_by' => $this->approvals->where('status', 'rejected')->pluck('approver.full_name')->filter()->values(),
                'pending_approvals' => $pendingCount
            ]
        ];
    }

    /**
     * Check if the account has been rejected by anyone
     */
    public function getHasRejectionsAttribute()
    {
        return $this->approvals->contains('status', 'rejected');
    }

    /**
     * Check if the account has multiple approvals
     */
    public function getHasMultipleApprovalsAttribute()
    {
        return $this->approvals->where('status', 'approved')->count() > 1;
    }

    /**
     * Get all users who have approved this account
     */
    public function getApprovedByUsersAttribute()
    {
        return $this->approvals()
            ->where('status', 'approved')
            ->with('approver')
            ->get()
            ->pluck('approver')
            ->filter();
    }

    /**
     * Get all users who have rejected this account
     */
    public function getRejectedByUsersAttribute()
    {
        return $this->approvals()
            ->where('status', 'rejected')
            ->with('approver')
            ->get()
            ->pluck('approver')
            ->filter();
    }

    /**
     * Check if there's a pending credit limit change
     */
    public function getHasPendingCreditChangeAttribute()
    {
        return !is_null($this->pending_credit_limit) && $this->pending_credit_limit != $this->credit_required;
    }

    /**
     * Get the pending credit limit information
     */
    public function getPendingCreditInfoAttribute()
    {
        if (!$this->has_pending_credit_change) {
            return null;
        }

        return [
            'current_limit' => $this->credit_required,
            'requested_limit' => $this->pending_credit_limit,
            'change_amount' => $this->pending_credit_limit - $this->credit_required,
            'change_type' => $this->pending_credit_limit > $this->credit_required ? 'increase' : 'decrease'
        ];
    }



}

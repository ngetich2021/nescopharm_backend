<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccountApproval;
use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CustomerAccountApprovalController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    protected function hasPermission(Request $request, $permission, $resourceCompanyId = null)
    {
        $user = $request->user();
        $role = $user->role;
        if (!$role) {
            return false;
        }
        if ($role->hasPermission('can_manage_system')) {
            return true;
        }
        if ($role->hasPermission('can_manage_company')) {
            if ($resourceCompanyId !== null) {
                return $user->company_id === $resourceCompanyId;
            }
            return true;
        }
        return $role->hasPermission($permission);
    }

    /**
     * Get human-readable text for approval type
     */
    protected function getApprovalTypeText($approvalType)
    {
        switch ($approvalType) {
            case 'credit_limit_update':
                return 'Credit limit update';
            case 'account_modification':
                return 'Account modification';
            case 'account_creation':
            default:
                return 'Customer account';
        }
    }

    public function index(Request $request, $customerAccountId)
    {
        $user = $request->user();
        $account = CustomerAccount::find($customerAccountId);
        if (!$account) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer account not found.'
            ], 404);
        }
        if (!$this->hasPermission($request, 'can_view_account_approvals', $account->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized.'
            ], 403);
        }
        $query = CustomerAccountApproval::where('customer_account_id', $customerAccountId);
        
        // Filter by approval type if specified
        if ($request->has('approval_type')) {
            $query->where('approval_type', $request->input('approval_type'));
        }
        
        // Filter by status if specified
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        $approvals = $query->with(['approver:id,first_name,last_name,email', 'createdBy:id,first_name,last_name,email'])
                          ->orderBy('created_at', 'desc')
                          ->get();
                          
        return response()->json(['status' => 'success', 'data' => $approvals]);
    }

    public function store(Request $request, $customerAccountId)
    {
        $user = $request->user();
        $account = CustomerAccount::find($customerAccountId);
        if (!$account) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer account not found.'
            ], 404);
        }
        if (!$this->hasPermission($request, 'can_approve_account', $account->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized.'
            ], 403);
        }
        // Define base validation rules
        $rules = [
            'status' => 'required|in:approved,rejected',
            'notes' => 'nullable|string',
            'approval_type' => 'nullable|string|in:account_creation,credit_limit_update,account_modification',
            'metadata' => 'nullable|array',
        ];

        // For credit limit updates, we can handle both existing pending requests and new manual approvals
        if ($request->input('approval_type') === 'credit_limit_update') {
            // If approving an existing pending request, get the approval record
            if ($request->has('approval_id')) {
                $existingApproval = CustomerAccountApproval::find($request->input('approval_id'));
                if (!$existingApproval || $existingApproval->customer_account_id !== $customerAccountId) {
                    return response()->json([
                        'status' => 'failed',
                        'message' => 'Invalid approval request ID.'
                    ], 400);
                }
            } else {
                // For manual credit limit approvals, require the limits
                $rules['previous_credit_limit'] = 'required|numeric|min:0';
                $rules['new_credit_limit'] = 'required|numeric|min:0';
            }
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors()
            ], 400);
        }
        try {
            // Prepare approval data
            $approvalData = [
                'id' => (string) Str::uuid(),
                'customer_account_id' => $customerAccountId,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'status' => $request->input('status'),
                'notes' => $request->input('notes'),
                'company_id' => $user->company_id,
                'created_by' => $user->id,
                'approval_type' => $request->input('approval_type', 'account_creation'),
                'metadata' => $request->input('metadata', []),
            ];

            // Handle credit limit updates
            if ($request->input('approval_type') === 'credit_limit_update') {
                $previousCreditLimit = null;
                $newCreditLimit = null;

                // Check if this is approving an existing pending request
                if ($request->has('approval_id')) {
                    $existingApproval = CustomerAccountApproval::find($request->input('approval_id'));
                    if ($existingApproval && $existingApproval->status === 'pending') {
                        // Update the existing approval record instead of creating new one
                        $existingApproval->update([
                            'approved_by' => $user->id,
                            'approved_at' => now(),
                            'status' => $request->input('status'),
                            'notes' => $request->input('notes', $existingApproval->notes),
                            'metadata' => array_merge($existingApproval->metadata ?? [], $request->input('metadata', []))
                        ]);

                        $approval = $existingApproval;
                        $previousCreditLimit = $existingApproval->previous_credit_limit;
                        $newCreditLimit = $existingApproval->new_credit_limit;
                    }
                } else {
                    // Manual credit limit approval (legacy support)
                    $previousCreditLimit = $request->input('previous_credit_limit');
                    $newCreditLimit = $request->input('new_credit_limit');
                    $approvalData['previous_credit_limit'] = $previousCreditLimit;
                    $approvalData['new_credit_limit'] = $newCreditLimit;
                }

                // Process the approval/rejection
                if ($request->input('status') === 'approved') {
                    // Update the customer account's credit limit
                    $account->update([
                        'credit_required' => $newCreditLimit,
                        'pending_credit_limit' => null // Clear pending limit
                    ]);
                    
                    Log::info('Customer account credit limit updated', [
                        'customer_account_id' => $customerAccountId,
                        'previous_limit' => $previousCreditLimit,
                        'new_limit' => $newCreditLimit,
                        'approved_by' => $user->id
                    ]);
                } else {
                    // Rejected - clear pending credit limit
                    $account->update([
                        'pending_credit_limit' => null
                    ]);
                    
                    Log::info('Customer account credit limit change rejected', [
                        'customer_account_id' => $customerAccountId,
                        'previous_limit' => $previousCreditLimit,
                        'requested_limit' => $newCreditLimit,
                        'rejected_by' => $user->id
                    ]);
                }
            }

            // Create approval record only if not updating existing one
            if (!isset($approval)) {
                $approval = CustomerAccountApproval::create($approvalData);
            }

            // Determine the success message based on approval type
            $approvalTypeText = $this->getApprovalTypeText($request->input('approval_type', 'account_creation'));
            $message = $approvalTypeText . ' ' . $approval->status . ' successfully.';

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'data' => $approval
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to process customer account approval', [
                'customer_account_id' => $customerAccountId,
                'approval_type' => $request->input('approval_type'),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to process approval: ' . $e->getMessage()
            ], 500);
        }
    }
}

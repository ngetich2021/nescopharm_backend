<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Company;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use App\Http\Traits\HandlesDatabaseErrors;
use Illuminate\Support\Facades\Storage;

class CustomerController extends Controller
{
    use HandlesDatabaseErrors;

    protected string $disk;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->disk = config('filesystems.default', 's3');
    }

    protected function hasPermission(Request $request, $permission, $resourceCompanyId = null)
    {
        $user = $request->user();
        $role = $user->role;
        if (!$role) {
            return false;
        }
        // System admin: can manage everything
        if ($role->hasPermission('can_manage_system')) {
            return true;
        }
        // Company admin: can manage their own company only
        if ($role->hasPermission('can_manage_company')) {
            if ($resourceCompanyId !== null) {
                return $user->company_id === $resourceCompanyId;
            }
            return true;
        }
        // Fallback: check specific permission
        return $role->hasPermission($permission);
    }

    protected function generateCustomerNumber($companyId)
    {
        $prefix = 'CUST-' . substr($companyId, 0, 8) . '-';

        // Use raw query to avoid model accessors interfering
        $lastCustomer = DB::table('customers')
            ->select('customer_number')
            ->where('customer_number', 'like', $prefix . '%')
            ->orderBy('customer_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastCustomer ? (int) substr($lastCustomer->customer_number, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    protected function generateDocumentNumber($companyId)
    {
        $prefix = 'DOC-' . substr($companyId, 0, 8) . '-';

        // Use raw query to avoid model accessors interfering
        $lastDocument = DB::table('documents')
            ->select('document_number')
            ->where('company_id', $companyId)
            ->where('document_number', 'like', $prefix . '%')
            ->orderBy('document_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastDocument ? (int) substr($lastDocument->document_number, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function formatPhoneNumber($phone)
    {
        if (!$phone) {
            return null;
        }

        // Remove all non-digit characters
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        // If phone is empty after cleaning, return null
        if (empty($cleanPhone)) {
            return null;
        }

        // If phone already starts with a 3-digit country code, return as is
        if (strlen($cleanPhone) >= 10 && in_array(substr($cleanPhone, 0, 3), ['254', '256', '255', '250'])) {
            return $cleanPhone;
        }

        // If phone starts with 0, replace with Kenya country code (254)
        if (str_starts_with($cleanPhone, '0')) {
            return '254' . substr($cleanPhone, 1);
        }

        // If phone is 9 digits (local Kenyan number), add country code
        if (strlen($cleanPhone) === 9) {
            return '254' . $cleanPhone;
        }

        // For other cases, assume it's already properly formatted or add default country code if too short
        if (strlen($cleanPhone) < 10) {
            return '254' . $cleanPhone;
        }

        return $cleanPhone;
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $companyId = $user->company_id;
        if (!$this->hasPermission($request, 'can_create_customers', $companyId)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to create customers.',
            ], 403);
        }

        $data = $request->all();
        $isBatch = isset($data['customers']) && is_array($data['customers']);
        $customersData = $isBatch ? $data['customers'] : [$data];
        $results = [];

        DB::beginTransaction();
        try {
            foreach ($customersData as $customerData) {
                // Generate unique customer number
                $customerData['customer_number'] = $this->generateCustomerNumber($companyId);

                // Validate required fields
                $validator = Validator::make($customerData, [
                    'name' => 'required|string|max:255',
                    'email' => 'nullable|email|max:255|unique:customers,email',
                    'phone' => 'nullable|string|max:50',
                    'status' => 'nullable|string|in:active,inactive,pending',
                    'payment_method' => 'nullable|string',
                    'address' => 'nullable|string',
                    'city' => 'nullable|string|max:100',
                    'state' => 'nullable|string|max:100',
                    'country' => 'nullable|string|max:100',
                    'postal_code' => 'nullable|string|max:20',
                    'notes' => 'nullable|string',
                    'tags' => 'nullable|array',
                    'tags.*' => 'string|max:50',
                    'preferred_communication_channel' => 'nullable|string',
                    'last_contact_date' => 'nullable|date',
                    'customer_type' => 'nullable|string|in:individual,company',
                    'business_name' => 'nullable|string|max:255',
                    'nature_of_business' => 'nullable|string|max:255',
                    'pin_number' => 'nullable|string|max:100',
                    'contact_person_name' => 'nullable|string|max:255',
                    'contact_person_phone' => 'nullable|string|max:50',
                    'contact_person_email' => 'nullable|email|max:255',
                ]);
                if ($validator->fails()) {
                    DB::rollBack();
                    return response()->json(['status' => 'failed', 'errors' => $validator->errors()], 422);
                }

                // Create customer
                $customer = Customer::create([
                    'id' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'customer_number' => $customerData['customer_number'],
                    'name' => $customerData['name'],
                    'email' => $customerData['email'] ?? null,
                    'phone' => $this->formatPhoneNumber($customerData['phone'] ?? null),
                    'status' => $customerData['status'] ?? 'active',
                    'payment_method' => $customerData['payment_method'] ?? null,
                    'address' => $customerData['address'] ?? null,
                    'city' => $customerData['city'] ?? null,
                    'state' => $customerData['state'] ?? null,
                    'country' => $customerData['country'] ?? null,
                    'postal_code' => $customerData['postal_code'] ?? null,
                    'notes' => $customerData['notes'] ?? null,
                    'tags' => $customerData['tags'] ?? [],
                    'preferred_communication_channel' => $customerData['preferred_communication_channel'] ?? null,
                    'last_contact_date' => $customerData['last_contact_date'] ?? null,
                    'customer_type' => $customerData['customer_type'] ?? null,
                    'business_name' => $customerData['business_name'] ?? null,
                    'nature_of_business' => $customerData['nature_of_business'] ?? null,
                    'pin_number' => $customerData['pin_number'] ?? null,
                    'contact_person_name' => $customerData['contact_person_name'] ?? null,
                    'contact_person_phone' => $customerData['contact_person_phone'] ?? null,
                    'contact_person_email' => $customerData['contact_person_email'] ?? null,
                    'timestamp' => $customerData['timestamp'] ?? null,
                    'created_by' => $user->id,
                ]);

                $results[] = $customer;
            }
            DB::commit();
            return response()->json(['status' => 'success', 'customers' => $results], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create customer(s)', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null,
                'request' => $request->all()
            ]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to create customer(s): ' . $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $customerId)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_update_customers', $user->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to update customers.',
                'data' => null
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255|unique:customers,email,' . $customerId . ',id',
            'phone' => 'nullable|string|max:50',
            'status' => 'sometimes|required|string|in:active,inactive,pending',
            'company' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'notes' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'preferred_communication_channel' => 'nullable|string|in:email,phone,sms,none',
            'last_contact_date' => 'nullable|date',
            'customer_type' => 'nullable|string|in:individual,company',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
            ], 400);
        }

        $customer = Customer::find($customerId);
        if (!$customer) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer not found.',
            ], 404);
        }

        if (!$this->hasPermission($request, 'can_update_customers', $customer->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to edit customers.',
            ], 403);
        }

        try {
            $tags = Arr::wrap($request->input('tags', $customer->tags ?? []));
            // Ensure tags are properly cast to PostgreSQL array
            $tagsForPostgres = empty($tags) ? DB::raw('ARRAY[]::text[]') : DB::raw("ARRAY[" . implode(',', array_map(function ($tag) {
                return "'" . addslashes($tag) . "'";
            }, $tags)) . "]::text[]");

            $customer->forceFill([
                'name' => $request->input('name', $customer->name),
                'email' => $request->input('email', $customer->email),
                'phone' => $request->has('phone') ? $this->formatPhoneNumber($request->input('phone')) : $customer->phone,
                'status' => $request->input('status', $customer->status),
                'address' => $request->input('address', $customer->address),
                'city' => $request->input('city', $customer->city),
                'state' => $request->input('state', $customer->state),
                'country' => $request->input('country', $customer->country),
                'postal_code' => $request->input('postal_code', $customer->postal_code),
                'notes' => $request->input('notes', $customer->notes),
                'tags' => $tagsForPostgres,
                'preferred_communication_channel' => $request->input('preferred_communication_channel', $customer->preferred_communication_channel),
                'last_contact_date' => $request->input('last_contact_date', $customer->last_contact_date),
                'customer_type' => $request->input('customer_type', $customer->customer_type),
                'payment_method' => $request->input('payment_method', $customer->payment_method),
                'contact_person_name' => $request->input('contact_person_name', $customer->contact_person_name),
                'contact_person_phone' => $request->input('contact_person_phone', $customer->contact_person_phone),
                'contact_person_email' => $request->input('contact_person_email', $customer->contact_person_email),
                'business_name' => $request->input('business_name', $customer->business_name),
                'nature_of_business' => $request->input('nature_of_business', $customer->nature_of_business),
                'pin_number' => $request->input('pin_number', $customer->pin_number),
                'timestamp' => $request->input('timestamp', $customer->timestamp),
            ])->save();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer updated successfully.',
                'customer' => $customer,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to update customer', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to update customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$this->hasPermission($request, 'can_view_customers', $user->company_id)) {
            Log::warning('Unauthorized customer list access', ['user_id' => $user->id, 'company_id' => $user->company_id]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view customers.',
                'data' => null
            ], 403);
        }

        $customers = Customer::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'status' => 'success',
            'message' => 'Customers retrieved successfully.',
            'data' => $customers
        ]);
    }

    public function showProfile(Request $request, $customer_id)
    {
        $user = $request->user();
        $customer = Customer::find($customer_id);
        if (!$customer) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer not found.'
            ], 404);
        }
        // Permission check: must have can_view_customers for this company
        if (!$this->hasPermission($request, 'can_view_customers', $customer->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view customer profile.'
            ], 403);
        }
        try {
            $customer = Customer::where('id', $customer_id)
                ->where('company_id', $customer->company_id)
                ->with([
                    'orders' => function ($query) {
                        $query->select('id', 'customer_id', 'order_number', 'total_amount', 'status', 'created_at', 'updated_at');
                    },
                    'orders.payments' => function ($query) {
                        $query->select('id', 'order_id', 'amount_paid', 'payment_method', 'status', 'created_at');
                    },
                ])
                ->first();

            if (!$customer) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Customer not found or not authorized.',
                ], 404);
            }

            // Calculate total_spend and total_orders
            $orders = $customer->orders ?? collect();
            $total_spend = $orders->sum(function ($order) {
                return (float) $order->total_amount;
            });
            $total_orders = $orders->count();

            // Convert customer to array and override total_spend and total_orders
            $customerArr = $customer->toArray();
            $customerArr['total_spend'] = number_format($total_spend, 2, '.', '');
            $customerArr['total_orders'] = $total_orders;

            return response()->json([
                'status' => 'success',
                'message' => 'Customer profile retrieved successfully.',
                'customer' => $customerArr,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve customer profile', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to retrieve customer profile: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $customerId)
    {
        $user = $request->user();
        // Use withTrashed to allow for soft-deleted lookup
        $customer = Customer::withTrashed()->find($customerId);
        if (!$customer) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer not found.'
            ], 404);
        }
        // Permission check: must have can_delete_customers for this company
        if (!$this->hasPermission($request, 'can_delete_customers', $customer->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to delete customers.'
            ], 403);
        }
        try {
            // Only soft delete if not already deleted
            if (is_null($customer->deleted_at)) {
                $customer->delete();
            }
            return response()->json([
                'status' => 'success',
                'message' => 'Customer deleted successfully.'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to delete customer', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to delete customer: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all documents for a specific customer
     */
    public function getDocuments(Request $request, $customerId)
    {
        $user = $request->user();

        // Clean the customer ID to remove any extra characters
        $customerId = trim($customerId, '{}');

        $customer = Customer::find($customerId);

        if (!$customer) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer not found.'
            ], 404);
        }

        if (!$this->hasPermission($request, 'can_view_customers', $customer->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to view customer documents.',
                'data' => null
            ], 403);
        }

        try {
            $documents = $customer->documents()->get();

            return response()->json([
                'status' => 'success',
                'message' => 'Customer documents retrieved successfully.',
                'data' => $documents
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve customer documents', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to retrieve customer documents: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new document for a specific customer
     */
    public function createDocument(Request $request, $customerId)
    {
        $user = $request->user();
        $customer = Customer::find($customerId);

        if (!$customer) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Customer not found.'
            ], 404);
        }

        if (!$this->hasPermission($request, 'can_create_documents', $customer->company_id)) {
            return response()->json([
                'status' => 'failed',
                'message' => 'Unauthorized to create customer documents.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'document_name' => 'required|string|max:255',
            'reference_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'regulatory_body' => 'nullable|string|max:255',
            'other_information' => 'nullable|string',
            'document_image' => 'nullable|file|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'failed',
                'message' => $validator->errors(),
            ], 400);
        }

        $documentImageUrl = null;
        if ($request->hasFile('document_image')) {
            $file = $request->file('document_image');
            try {
                $path = 'documents/' . date('Y/m/d') . '/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
                $stored = Storage::disk($this->disk)->put($path, file_get_contents($file->getRealPath()));

                if ($stored) {
                    $documentImageUrl = Storage::disk($this->disk)->url($path);
                } else {
                    throw new \Exception('Failed to store document image');
                }
            } catch (\Exception $e) {
                return response()->json([
                    'status' => 'failed',
                    'message' => 'Document image upload failed: ' . $e->getMessage(),
                ], 500);
            }
        }

        try {
            $documentNumber = $this->generateDocumentNumber($customer->company_id);
            $document = Document::create([
                'id' => (string) Str::uuid(),
                'document_name' => $request->input('document_name'),
                'document_number' => $documentNumber,
                'reference_number' => $request->input('reference_number'),
                'expiry_date' => $request->input('expiry_date'),
                'regulatory_body' => $request->input('regulatory_body'),
                'document_image' => $documentImageUrl,
                'documentable_type' => Customer::class,
                'documentable_id' => $customerId,
                'company_id' => $customer->company_id,
                'created_by' => $user->id,
                'other_information' => $request->input('other_information'),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Customer document created successfully.',
                'data' => $document,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create customer document', ['error' => $e->getMessage()]);
            return response()->json([
                'status' => 'failed',
                'message' => 'Failed to create customer document: ' . $e->getMessage(),
            ], 500);
        }
    }
}

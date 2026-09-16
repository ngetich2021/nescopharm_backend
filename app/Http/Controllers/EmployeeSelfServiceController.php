<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\SalaryAdvance;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EmployeeSelfServiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function me(Request $request)
    {
        $employee = $this->resolveEmployee($request);

        if (!$employee) {
            return response()->json([
                'status' => 'failed',
                'message' => 'No employee profile is linked to this login.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'employee' => $employee->load($this->employeeRelationships()),
        ]);
    }

    public function leaveIndex(Request $request)
    {
        $employee = $this->resolveEmployee($request);
        if (!$employee) {
            return response()->json(['status' => 'failed', 'message' => 'No employee profile is linked to this login.'], 404);
        }

        $query = LeaveRequest::with('employee')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'status' => 'success',
            'leave_requests' => $query->latest()->get()->map(fn ($lr) => $this->formatLeaveRequest($lr)),
        ]);
    }

    public function leaveStore(Request $request)
    {
        $employee = $this->resolveEmployee($request);
        if (!$employee) {
            return response()->json(['status' => 'failed', 'message' => 'No employee profile is linked to this login.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'leave_type' => 'required|in:annual,sick,maternity,paternity,unpaid',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'failed', 'message' => $validator->errors()], 422);
        }

        $leaveRequest = LeaveRequest::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'leave_type' => $request->leave_type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'reason' => $request->reason,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Leave request submitted successfully.',
            'leave_request' => $this->formatLeaveRequest($leaveRequest->load('employee')),
        ], 201);
    }

    public function salaryAdvanceIndex(Request $request)
    {
        $employee = $this->resolveEmployee($request);
        if (!$employee) {
            return response()->json(['status' => 'failed', 'message' => 'No employee profile is linked to this login.'], 404);
        }

        $query = SalaryAdvance::with('employee')
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'status' => 'success',
            'salary_advances' => $query->latest()->get()->map(fn ($adv) => $this->formatAdvance($adv)),
        ]);
    }

    public function salaryAdvanceStore(Request $request)
    {
        $employee = $this->resolveEmployee($request);
        if (!$employee) {
            return response()->json(['status' => 'failed', 'message' => 'No employee profile is linked to this login.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'request_date' => 'required|date',
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'failed', 'message' => $validator->errors()], 422);
        }

        $advance = SalaryAdvance::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'amount' => $request->amount,
            'request_date' => $request->request_date,
            'reason' => $request->reason,
            'status' => 'pending',
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Salary advance request submitted successfully.',
            'salary_advance' => $this->formatAdvance($advance->load('employee')),
        ], 201);
    }

    private function resolveEmployee(Request $request): ?Employee
    {
        $user = $request->user();

        $employee = Employee::where('company_id', $user->company_id)
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->first();

        if ($employee) {
            return $employee;
        }

        $employee = Employee::where('company_id', $user->company_id)
            ->whereRaw('LOWER(first_name) = ?', [strtolower($user->first_name)])
            ->whereRaw('LOWER(last_name) = ?', [strtolower($user->last_name)])
            ->first();

        if ($employee) {
            if (empty($employee->email) && !empty($user->email)) {
                $employee->update(['email' => $user->email]);
            }

            return $employee;
        }

        $payload = [
            'id' => (string) Str::uuid(),
            'company_id' => $user->company_id,
            'employee_number' => $this->generateEmployeeNumber($user->company_id),
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'hire_date' => now()->toDateString(),
            'employment_type' => 'full_time',
            'payment_frequency' => 'monthly',
            'basic_salary' => 0,
            'is_active' => true,
            'created_by' => $user->id,
            'metadata' => [
                'provisioned_from_user_id' => $user->id,
                'provisioned_from_employee_portal' => true,
            ],
        ];

        if ($this->hasEmployeeApproverColumns()) {
            $defaultApproverId = $this->resolveDefaultApproverId($user->company_id, $user->id);
            $payload['leave_approver_id'] = $defaultApproverId;
            $payload['salary_advance_approver_id'] = $defaultApproverId;
        }

        return Employee::create($payload);
    }

    private function generateEmployeeNumber(string $companyId): string
    {
        $nextNumber = Employee::where('company_id', $companyId)->count() + 1;

        do {
            $employeeNumber = 'EMP-' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
            $exists = Employee::where('company_id', $companyId)
                ->where('employee_number', $employeeNumber)
                ->exists();
            $nextNumber++;
        } while ($exists);

        return $employeeNumber;
    }

    private function resolveDefaultApproverId(string $companyId, string $currentUserId): ?string
    {
        return \App\Models\User::where('company_id', $companyId)
            ->where('id', '!=', $currentUserId)
            ->whereRaw('is_active = true')
            ->orderBy('created_at')
            ->value('id');
    }

    private function hasEmployeeApproverColumns(): bool
    {
        return Schema::hasColumn('employees', 'leave_approver_id')
            && Schema::hasColumn('employees', 'salary_advance_approver_id');
    }

    private function employeeRelationships(): array
    {
        $relationships = ['statutoryDetails', 'supervisor'];

        if ($this->hasEmployeeApproverColumns()) {
            $relationships[] = 'leaveApprover';
            $relationships[] = 'salaryAdvanceApprover';
        }

        return $relationships;
    }

    private function formatLeaveRequest(LeaveRequest $lr): array
    {
        return [
            'id' => $lr->id,
            'employee_id' => $lr->employee_id,
            'employee' => $lr->employee ? trim($lr->employee->first_name . ' ' . $lr->employee->last_name) : '',
            'leaveType' => $lr->leave_type,
            'startDate' => optional($lr->start_date)->format('Y-m-d'),
            'endDate' => optional($lr->end_date)->format('Y-m-d'),
            'reason' => $lr->reason,
            'status' => $lr->status,
            'created_at' => $lr->created_at,
            'updated_at' => $lr->updated_at,
        ];
    }

    private function formatAdvance(SalaryAdvance $adv): array
    {
        return [
            'id' => $adv->id,
            'employee_id' => $adv->employee_id,
            'employee' => $adv->employee ? trim($adv->employee->first_name . ' ' . $adv->employee->last_name) : '',
            'amount' => $adv->amount,
            'requestDate' => optional($adv->request_date)->format('Y-m-d'),
            'reason' => $adv->reason,
            'status' => $adv->status,
            'created_at' => $adv->created_at,
            'updated_at' => $adv->updated_at,
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Sop;
use App\Models\SopAnnexure;
use App\Models\SopAnnexureEntry;
use App\Models\SopComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SopCommentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    protected function hasPermission(Request $request, string $permission, ?string $resourceCompanyId = null): bool
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
            return $resourceCompanyId === null || $user->company_id === $resourceCompanyId;
        }

        return $role->hasPermission($permission);
    }

    protected function getSopOrFail(string $companyId, string $sopId): Sop
    {
        return Sop::where('company_id', $companyId)->findOrFail($sopId);
    }

    protected function validateLinkedResources(string $companyId, string $sopId, ?string $annexureId, ?string $entryId): ?JsonResponse
    {
        if ($annexureId) {
            $annexure = SopAnnexure::where('company_id', $companyId)
                ->where('sop_id', $sopId)
                ->find($annexureId);
            if (!$annexure) {
                return response()->json(['message' => 'Invalid annexure for this SOP'], 422);
            }
        }

        if ($entryId) {
            $entryQuery = SopAnnexureEntry::where('company_id', $companyId)
                ->where('sop_id', $sopId)
                ->where('id', $entryId);

            if ($annexureId) {
                $entryQuery->where('sop_annexure_id', $annexureId);
            }

            if (!$entryQuery->exists()) {
                return response()->json(['message' => 'Invalid annexure entry for this SOP'], 422);
            }
        }

        return null;
    }

    public function index(Request $request, string $sopId): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        if (
            !$this->hasPermission($request, 'can_view_sops', $companyId)
            && !$this->hasPermission($request, 'can_view_reports', $companyId)
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sop = $this->getSopOrFail($companyId, $sopId);
        $query = SopComment::with('commentedBy:id,first_name,last_name,email')
            ->where('company_id', $companyId)
            ->where('sop_id', $sop->id);

        if ($request->filled('comment_type')) {
            $query->where('comment_type', $request->comment_type);
        }
        if ($request->filled('sop_annexure_id')) {
            $query->where('sop_annexure_id', $request->sop_annexure_id);
        }
        if ($request->filled('sop_annexure_entry_id')) {
            $query->where('sop_annexure_entry_id', $request->sop_annexure_entry_id);
        }

        $perPage = max(1, min((int) $request->get('per_page', 30), 200));
        $comments = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json($comments);
    }

    public function store(Request $request, string $sopId): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        if (
            !$this->hasPermission($request, 'can_comment_sops', $companyId)
            && !$this->hasPermission($request, 'can_view_sops', $companyId)
            && !$this->hasPermission($request, 'can_view_reports', $companyId)
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sop = $this->getSopOrFail($companyId, $sopId);

        $validator = Validator::make($request->all(), [
            'comment_type' => 'sometimes|string|in:guidance,capa,general',
            'comment' => 'required|string',
            'sop_annexure_id' => 'nullable|uuid|exists:sop_annexures,id',
            'sop_annexure_entry_id' => 'nullable|uuid|exists:sop_annexure_entries,id',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $relationError = $this->validateLinkedResources(
            $companyId,
            $sop->id,
            $request->sop_annexure_id,
            $request->sop_annexure_entry_id
        );
        if ($relationError) {
            return $relationError;
        }

        $comment = SopComment::create([
            'sop_id' => $sop->id,
            'sop_annexure_id' => $request->sop_annexure_id,
            'sop_annexure_entry_id' => $request->sop_annexure_entry_id,
            'company_id' => $companyId,
            'commented_by' => $user->id,
            'comment_type' => $request->comment_type ?? 'guidance',
            'comment' => $request->comment,
            'metadata' => $request->metadata,
        ]);

        return response()->json([
            'message' => 'SOP comment created successfully',
            'data' => $comment->load('commentedBy:id,first_name,last_name,email'),
        ], 201);
    }

    public function destroy(Request $request, string $sopId, string $commentId): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;
        $isManager = $this->hasPermission($request, 'can_manage_company', $companyId)
            || $this->hasPermission($request, 'can_manage_system', $companyId);

        if (
            !$this->hasPermission($request, 'can_comment_sops', $companyId)
            && !$this->hasPermission($request, 'can_view_sops', $companyId)
            && !$isManager
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sop = $this->getSopOrFail($companyId, $sopId);
        $comment = SopComment::where('company_id', $companyId)
            ->where('sop_id', $sop->id)
            ->findOrFail($commentId);

        if (!$isManager && $comment->commented_by !== $user->id) {
            return response()->json(['message' => 'You can only delete your own comments'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'SOP comment deleted successfully']);
    }
}

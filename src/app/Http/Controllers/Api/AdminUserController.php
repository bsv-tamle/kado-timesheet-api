<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Support\TemporaryPasswordService;
use App\Support\UserPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'locked'])],
            'department_id' => ['nullable', 'integer'],
            'position_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query();

        if (isset($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($q) use ($keyword): void {
                $q->where('full_name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%")
                    ->orWhere('phone', 'like', "%{$keyword}%");
            });
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (isset($validated['department_id'])) {
            $query->where('department_id', (int) $validated['department_id']);
        }

        if (isset($validated['position_id'])) {
            $query->where('position_id', (int) $validated['position_id']);
        }

        $users = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $items = collect($users->items())
            ->map(fn (User $user): array => UserPayload::summary($user))
            ->values()
            ->all();

        return $this->successResponse([
            'current_page' => $users->currentPage(),
            'data' => $items,
            'from' => $users->firstItem(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'to' => $users->lastItem(),
            'total' => $users->total(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorResponse('User not found.', 404);
        }

        return $this->successResponse(UserPayload::summary($user));
    }

    public function store(Request $request, TemporaryPasswordService $tempPasswordService): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'role' => ['nullable', Rule::in(['admin', 'employee'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'locked'])],
            'send_invitation_email' => ['nullable', 'boolean'],
        ]);

        $role = $validated['role'] ?? 'employee';
        if ($role === 'admin' && User::query()->where('role', 'admin')->exists()) {
            return $this->errorResponse('Validation error', 422, [
                'role' => ['Only one admin account is allowed.'],
            ]);
        }

        $temporaryPassword = $tempPasswordService->generate();

        $user = User::query()->create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'position_id' => $validated['position_id'] ?? null,
            'role' => $role,
            'status' => $validated['status'] ?? 'active',
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ]);

        $sendInvitationEmail = (bool) ($validated['send_invitation_email'] ?? false);

        return $this->successResponse([
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'must_change_password' => (bool) $user->must_change_password,
            'onboarding' => [
                'temp_password_generated' => true,
                'invitation_email_sent' => false,
                'invitation_email_requested' => $sendInvitationEmail,
            ],
        ], 'User created successfully', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorResponse('User not found.', 404);
        }

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'locked'])],
            'role' => ['sometimes', Rule::in(['admin', 'employee'])],
        ]);

        if (array_key_exists('role', $validated) && $validated['role'] !== $user->role) {
            return $this->errorResponse('Validation error', 422, [
                'role' => ['Changing role is not allowed in this endpoint.'],
            ]);
        }

        if (isset($validated['status'])) {
            $statusRuleError = $this->validateCanChangeStatus($user, $validated['status']);
            if ($statusRuleError) {
                return $statusRuleError;
            }
        }

        unset($validated['role']);

        if ($validated === []) {
            return $this->errorResponse('Validation error', 422, [
                'request' => ['At least one field is required for update.'],
            ]);
        }

        $user->fill($validated);
        $user->save();

        return $this->successResponse(UserPayload::summary($user), 'User updated successfully');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorResponse('User not found.', 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive', 'locked'])],
        ]);

        $statusRuleError = $this->validateCanChangeStatus($user, $validated['status']);
        if ($statusRuleError) {
            return $statusRuleError;
        }

        $user->forceFill(['status' => $validated['status']])->save();

        return $this->successResponse([
            'id' => $user->id,
            'status' => $user->status,
        ], 'User status updated successfully');
    }

    public function resetPassword(Request $request, int $id, TemporaryPasswordService $tempPasswordService): JsonResponse
    {
        $user = User::query()->find($id);
        if (! $user) {
            return $this->errorResponse('User not found.', 404);
        }

        $validated = $request->validate([
            'new_password' => ['nullable', 'string', 'min:8', 'max:255'],
            'send_invitation_email' => ['nullable', 'boolean'],
        ]);

        $newPassword = $validated['new_password'] ?? $tempPasswordService->generate();
        $isTemporaryPasswordGenerated = ! isset($validated['new_password']);

        $user->forceFill([
            'password' => Hash::make($newPassword),
            'must_change_password' => true,
        ])->save();

        $sendInvitationEmail = (bool) ($validated['send_invitation_email'] ?? false);

        return $this->successResponse([
            'reset' => true,
            'must_change_password' => true,
            'onboarding' => [
                'temp_password_generated' => $isTemporaryPasswordGenerated,
                'invitation_email_sent' => false,
                'invitation_email_requested' => $sendInvitationEmail,
            ],
        ], 'Temporary password has been reset');
    }

    private function validateCanChangeStatus(User $user, string $nextStatus): ?JsonResponse
    {
        if ($user->role === 'admin' && $user->status === 'active' && in_array($nextStatus, ['inactive', 'locked'], true)) {
            $activeAdminCount = User::query()
                ->where('role', 'admin')
                ->where('status', 'active')
                ->count();

            if ($activeAdminCount <= 1) {
                return $this->errorResponse('Validation error', 422, [
                    'status' => ['Cannot lock or inactivate the last active admin.'],
                ]);
            }
        }

        return null;
    }
}

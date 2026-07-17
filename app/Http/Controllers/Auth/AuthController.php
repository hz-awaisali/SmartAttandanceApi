<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StudentService;
use App\Services\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private TeacherService $teachers,
        private StudentService $students,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }

        $user = match ($data['role']) {
            'teacher' => $this->teachers->create($data)->user,
            'hod' => $this->teachers->create($data, 'hod')->user,
            'student' => $this->students->create($data)->user,
            default => User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => $data['role'], 'avatar' => $data['avatar'] ?? null]),
        };

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->ok([
            'user' => UserResource::make($user),
            'token' => $token,
        ], 'Registration successful', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Auth::getProvider()->validateCredentials($user, $request->validated())) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        if ($user->status !== 'active') {
            return $this->fail('Your account is inactive. Please contact the administrator.', 403);
        }

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->ok([
            'user' => UserResource::make($user),
            'token' => $token,
        ], 'Login successful');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(null, 'Logged out successfully');
    }

    public function user(Request $request): JsonResponse
    {
        return $this->ok(UserResource::make($request->user()), 'Authenticated user');
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->findUserForPasswordReset($request->email, $request->identity_no);

        return $this->ok(null, 'Identity verified. You can now set a new password.');
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $user = $this->findUserForPasswordReset($request->email, $request->identity_no);

        $user->forceFill(['password' => $request->password])->setRememberToken(Str::random(60));
        $user->save();
        $user->tokens()->delete();

        return $this->ok(null, 'Your password has been reset.');
    }

    /**
     * Direct (no email link) reset flow: the requester must supply the ID on
     * file alongside the email — a student's registration_no or a
     * teacher/HOD's employee_no — so knowing an email alone isn't enough to
     * take over an account.
     */
    private function findUserForPasswordReset(string $email, string $identityNo): User
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => ["We can't find a user with that email address."]]);
        }

        $expected = match ($user->role) {
            'student' => $user->student?->registration_no,
            'teacher', 'hod' => $user->teacher?->employee_no,
            default => null,
        };

        if ($expected === null) {
            throw ValidationException::withMessages(['identity_no' => ['Password reset is not available for this account.']]);
        }

        if (strcasecmp(trim($identityNo), $expected) !== 0) {
            throw ValidationException::withMessages(['identity_no' => ['The provided ID does not match our records.']]);
        }

        return $user;
    }
}

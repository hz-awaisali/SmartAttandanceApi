<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendCodeRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use App\Mail\VerificationCodeMail;
use App\Models\User;
use App\Services\StudentService;
use App\Services\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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

        $user = match ($data['role']) {
            'teacher' => $this->teachers->create($data)->user,
            'hod' => $this->teachers->create($data, 'hod')->user,
            'student' => $this->students->create($data)->user,
            default => User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'role' => $data['role']]),
        };

        $this->sendVerificationCode($user);

        return $this->ok([
            'user' => UserResource::make($user),
        ], 'Registration successful. A verification code has been sent to your email.', 201);
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if ($user->isEmailVerified()) {
            return $this->fail('This email is already verified.');
        }

        if ($user->verification_code !== $request->code || $user->verification_code_expires_at?->isPast()) {
            return $this->fail('The verification code is invalid or has expired.');
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ])->save();

        $token = $user->createToken('api-token')->plainTextToken;

        return $this->ok([
            'user' => UserResource::make($user),
            'token' => $token,
        ], 'Email verified successfully');
    }

    public function resendVerificationCode(ResendCodeRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if ($user->isEmailVerified()) {
            return $this->fail('This email is already verified.');
        }

        $this->sendVerificationCode($user);

        return $this->ok(null, 'A new verification code has been sent to your email.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Auth::getProvider()->validateCredentials($user, $request->validated())) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        if (! $user->isEmailVerified()) {
            return $this->fail('Please verify your email before logging in.', 403);
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

    private function sendVerificationCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        $user->forceFill([
            'verification_code' => $code,
            'verification_code_expires_at' => now()->addMinutes(10),
        ])->save();

        Mail::to($user->email)->send(new VerificationCodeMail($user->name, $code));
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
}

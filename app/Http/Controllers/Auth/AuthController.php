<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\StudentService;
use App\Services\TeacherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
}

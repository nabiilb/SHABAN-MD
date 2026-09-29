<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\AuthService;
use App\Support\AuthSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /** Sets the XSRF-TOKEN cookie (done by the CSRF middleware on every API response). */
    public function csrf(): Response
    {
        return response()->noContent();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $b = $request->payload();

        return response()->json($this->auth->login($request, $b['email'], $b['password']));
    }

    public function refresh(Request $request): JsonResponse
    {
        return response()->json($this->auth->refresh($request));
    }

    /** Works whatever the session state: whatever identifies the session is ended. */
    public function logout(Request $request): Response
    {
        AuthSession::end($request);

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->auth->me($request));
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        return response()->json($this->auth->forgotPassword($request->payload()['email']));
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $b = $request->payload();

        return response()->json($this->auth->resetPassword($b['token'], $b['email'], $b['password']));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    /** Register a user AND found their starter company in one step. */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'company_name' => ['required', 'string', 'max:60'],
            'country' => ['nullable', 'string', 'in:'.implode(',', array_keys(config('transoria.countries')))],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed via cast
        ]);

        $company = $this->companies->found($user, $data['company_name'], $data['country'] ?? null);

        $token = $user->createToken('transoria')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'company' => new CompanyResource($company->loadCount('vehicles', 'drivers')->load('headquarters')),
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        $token = $user->createToken('transoria')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'company' => $company
                ? new CompanyResource($company->loadCount('vehicles', 'drivers')->load('headquarters'))
                : null,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}

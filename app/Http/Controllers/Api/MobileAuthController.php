<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    /**
     * Login kasir dari aplikasi mobile.
     * Hanya role 'cashier' atau 'admin' yang boleh login.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with(['store', 'role'])
            ->where('email', $request->email)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda tidak aktif. Hubungi admin.'],
            ]);
        }

        $roleName = strtolower($user->role?->name ?? '');
        if (! in_array($roleName, ['cashier', 'admin'])) {
            throw ValidationException::withMessages([
                'email' => ['Anda tidak memiliki akses ke aplikasi kasir.'],
            ]);
        }

        // Kasir wajib punya store
        if ($roleName === 'cashier' && ! $user->store_id) {
            throw ValidationException::withMessages([
                'email' => ['Akun kasir belum ditugaskan ke toko manapun.'],
            ]);
        }

        // Hapus token lama (opsional, agar 1 device = 1 token)
        $user->tokens()->where('name', 'cashier-mobile')->delete();

        $token = $user->createToken('cashier-mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * Ambil data user yang sedang login.
     */
    public function me(Request $request)
    {
        $user = $request->user()->load(['store', 'role']);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Logout — hapus token yang sedang dipakai.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Format payload user untuk konsumsi mobile.
     */
    private function userPayload(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'avatar_url' => $this->resolveAvatarUrl($user->avatar),
            'email'    => $user->email,
            'role'     => strtolower($user->role?->name ?? ''),
            'store_id' => $user->store_id,
            'store'    => $user->store ? [
                'id'       => $user->store->id,
                'name'     => $user->store->name,
                'code'     => $user->store->code,
                'location' => $user->store->location,
            ] : null,
        ];
    }

    private function resolveAvatarUrl(?string $avatar): ?string
    {
        if (empty($avatar)) return null;
        // Sudah URL absolut (mis. dari S3, Cloudinary, dsb.)
        if (preg_match('/^https?:\/\//i', $avatar)) {
            return $avatar;
        }
        // Hasil: http://<host>/storage/avatars/x.jpg
        return Storage::disk('public')->url($avatar);
    }
}

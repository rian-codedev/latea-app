<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    /* ══════════════════════════════════════════════════════
       EXISTING — Login / Me / Logout
       ══════════════════════════════════════════════════════ */

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

        if ($roleName === 'cashier' && ! $user->store_id) {
            throw ValidationException::withMessages([
                'email' => ['Akun kasir belum ditugaskan ke toko manapun.'],
            ]);
        }

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

    /* ══════════════════════════════════════════════════════
       NEW — Update Profil User
       ══════════════════════════════════════════════════════ */

    /**
     * Update nama & email user.
     * Jika email berubah, `current_password` wajib diisi & harus cocok.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:100'],
            'email'            => [
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'current_password' => ['nullable', 'string'],
        ]);

        // Email berubah → verifikasi password lama
        $emailChanged = $user->email !== $validated['email'];
        if ($emailChanged) {
            if (empty($validated['current_password'])
                || ! Hash::check($validated['current_password'], $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['Password salah.'],
                ]);
            }
        }

        $user->update([
            'name'  => $validated['name'],
            'email' => $validated['email'],
        ]);

        return response()->json([
            'user' => $this->userPayload($user->fresh(['store', 'role'])),
        ]);
    }

    /**
     * Upload / ganti avatar user.
     * File lama otomatis dihapus.
     */
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $user = $request->user();

        try {
            // Hapus file lama
            if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
                Storage::disk('public')->delete($user->avatar);
            }

            // Simpan file baru
            $path = $request->file('avatar')->store('avatars', 'public');

            $user->avatar = $path;
            $user->save();
        } catch (\Throwable $e) {
            Log::error('Failed to update user avatar', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal menyimpan foto. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'user' => $this->userPayload($user->fresh(['store', 'role'])),
        ]);
    }

    /* ══════════════════════════════════════════════════════
       NEW — Update Password (Force Re-login)
       ══════════════════════════════════════════════════════ */

    /**
     * Ubah password user.
     * Setelah sukses, SEMUA token user dihapus (force logout).
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password lama salah.'],
            ]);
        }

        if ($request->current_password === $request->new_password) {
            throw ValidationException::withMessages([
                'new_password' => ['Password baru tidak boleh sama dengan password lama.'],
            ]);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        // Hapus SEMUA token user (force logout semua device)
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan login ulang.',
        ]);
    }

    /* ══════════════════════════════════════════════════════
       NEW — Update Toko
       ══════════════════════════════════════════════════════ */

    /**
     * Update nama toko user.
     */
    public function updateStore(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        if (! $user->store_id || ! $user->store) {
            throw ValidationException::withMessages([
                'store' => ['Anda belum ditugaskan ke toko.'],
            ]);
        }

        $user->store->update(['name' => $request->name]);

        return response()->json([
            'user' => $this->userPayload($user->fresh(['store', 'role'])),
        ]);
    }

    /**
     * Upload / ganti avatar toko.
     * File lama otomatis dihapus. Disimpan di folder `stores/` (sama
     * dengan Livewire StoreForm).
     */
    public function updateStoreAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048'],
        ]);

        $user = $request->user();

        if (! $user->store_id || ! $user->store) {
            throw ValidationException::withMessages([
                'store' => ['Anda belum ditugaskan ke toko.'],
            ]);
        }

        $store = $user->store;

        try {
            // Hapus file lama
            if ($store->image && Storage::disk('public')->exists($store->image)) {
                Storage::disk('public')->delete($store->image);
            }

            // Simpan file baru
            $path = $request->file('avatar')->store('stores', 'public');

            $store->image = $path;
            $store->save();
        } catch (\Throwable $e) {
            Log::error('Failed to update store avatar', [
                'store_id' => $store->id,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Gagal menyimpan foto toko. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'user' => $this->userPayload($user->fresh(['store', 'role'])),
        ]);
    }

    /* ══════════════════════════════════════════════════════
       HELPERS
       ══════════════════════════════════════════════════════ */

    /**
     * Format payload user untuk konsumsi mobile.
     */
    private function userPayload(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'avatar_url' => $this->resolveAssetUrl($user->avatar),
            'email'      => $user->email,
            'role'       => strtolower($user->role?->name ?? ''),
            'store_id'   => $user->store_id,
            'store'      => $user->store ? [
                'id'         => $user->store->id,
                'name'       => $user->store->name,
                'code'       => $user->store->code,
                'location'   => $user->store->location,
                'avatar_url' => $this->resolveAssetUrl($user->store->image),
            ] : null,
        ];
    }

    /**
     * Resolve path file relatif (di disk 'public') menjadi URL absolut.
     *
     * Mendukung:
     *   - null / ''             → null
     *   - 'http://...' absolut  → pass-through (S3, CDN, dll)
     *   - 'avatars/x.jpg'       → http://<host>/storage/avatars/x.jpg
     */
    private function resolveAssetUrl(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return asset('storage/' . $path);
    }
}

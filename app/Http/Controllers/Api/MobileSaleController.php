<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Sale;
use App\Services\InvoiceNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class MobileSaleController extends Controller
{
    /**
     * Submit transaksi baru dari kasir.
     */
    public function store(Request $request, InvoiceNumberService $invoiceService)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $storeId = $user->store_id;

        if (!$storeId) {
            return response()->json([
                'message' => 'Kasir belum ditugaskan ke toko manapun.',
            ], 403);
        }

        return DB::transaction(function () use ($validated, $user, $storeId, $invoiceService) {
            $subtotal = 0;
            $total = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $product = Product::with([
                    'stores' => fn($q) => $q->where('stores.id', $storeId)
                        ->withPivot(['price', 'is_available']),
                ])->find($item['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => ["Produk ID {$item['product_id']} tidak ditemukan."],
                    ]);
                }

                // Validasi produk tersedia di store ini
                $pivot = $product->stores->first()?->pivot;
                if (!$pivot || !$pivot->is_available) {
                    throw ValidationException::withMessages([
                        'items' => ["Produk {$product->name} tidak tersedia di toko Anda."],
                    ]);
                }

                // Harga dasar (pakai harga khusus toko jika ada)
                $basePrice = $pivot->price !== null
                    ? (float) $pivot->price
                    : (float) $product->price;

                // Harga diskon dari produk (jika ada)
                $discountPrice = $product->discount_price !== null
                    ? (float) $product->discount_price
                    : null;
                $minimalDiscount = $product->minimal_discount;

                // ⭐ Hitung harga efektif — SAMA dengan logika di mobile
                $qty = (int) $item['quantity'];
                $effectivePrice = $basePrice;

                if (
                    $discountPrice !== null
                    && $minimalDiscount !== null
                    && $qty >= $minimalDiscount
                ) {
                    $effectivePrice = $discountPrice;
                }

                $lineTotal = $effectivePrice * $qty;

                $subtotal += $basePrice * $qty;
                $total += $lineTotal;

                // Snapshot data produk
                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'price' => $basePrice,
                    'discount_price' => $discountPrice,
                    'effective_price' => $effectivePrice,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            $discountAmount = $subtotal - $total;
            $paymentAmount = (float) $validated['payment_amount'];

            if ($paymentAmount < $total) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['Uang diterima kurang dari total transaksi.'],
                ]);
            }

            $changeAmount = $paymentAmount - $total;

            // Buat sale header
            $sale = Sale::create([
                'store_id' => $storeId,
                'cashier_id' => $user->id,
                'cashier_name' => $user->name,
                'invoice_number' => $invoiceService->generate(),
                'sale_date' => now(),
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'tax_amount' => 0,
                'total' => $total,
                'payment_amount' => $paymentAmount,
                'change_amount' => $changeAmount,
                'status' => 'completed',
            ]);

            // Insert sale items
            $sale->items()->createMany($itemsData);

            return response()->json([
                'data' => $this->salePayload($sale->fresh(['items', 'store'])),
            ], 201);
        });
    }

    /**
     * Riwayat transaksi kasir yang sedang login.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $storeId = $user->store_id;

        if (!$storeId) {
            return response()->json(['message' => 'Kasir belum ditugaskan ke toko.'], 403);
        }

        // ⭐ BACKEND MENENTUKAN TANGGAL HARI INI (WIB)
        $today = Carbon::now('Asia/Jakarta')->toDateString();

        // Query Dasar
        $baseQuery = Sale::query()
            ->where('status', 'completed')
            ->where('store_id', $storeId)
            ->whereDate('sale_date', $today); // ← Selalu gunakan hari ini

        // ── 1. METRICS PERSONAL (Kasir ini) ──
        $myQuery = (clone $baseQuery)->where('cashier_id', $user->id);

        $myRevenue = (clone $myQuery)->sum('total');
        $myTransactions = (clone $myQuery)->count();
        $myItemsSold = (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.store_id', $storeId)
            ->where('sales.cashier_id', $user->id)
            ->whereDate('sales.sale_date', $today)
            ->sum('sale_items.quantity');

        // ── 2. METRICS TOKO (Semua kasir) ──
        $storeRevenue = (clone $baseQuery)->sum('total');
        $storeTransactions = (clone $baseQuery)->count();
        $storeItemsSold = (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.store_id', $storeId)
            ->whereDate('sales.sale_date', $today)
            ->sum('sale_items.quantity');

        // ── 3. TRANSAKSI LIST ──
        $sales = (clone $myQuery)
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->orderByDesc('sale_date')
            ->paginate(20);

        return response()->json([
            'metrics' => [
                'my_revenue' => (float) $myRevenue,
                'my_transactions' => $myTransactions,
                'my_items_sold' => $myItemsSold,
                'store_revenue' => (float) $storeRevenue,
                'store_transactions' => $storeTransactions,
                'store_items_sold' => $storeItemsSold,
            ],
            'data' => $sales->map(fn(Sale $sale) => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'sale_date' => $sale->sale_date->toIso8601String(),
                'total' => (float) $sale->total,
                'items_count' => $sale->items_count,
                'total_quantity' => (int) ($sale->total_quantity ?? 0),
                'status' => $sale->status,
            ]),
            'meta' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
                'server_time'  => [
                    'time'      => now('Asia/Jakarta')->format('H:i'),
                    'date_full' => now('Asia/Jakarta')->translatedFormat('l, j F Y'),
                ],
            ],
        ]);
    }

    /**
     * Detail struk transaksi.
     */
    public function show(Request $request, Sale $sale)
    {
        $user = $request->user();
        if ((int) $sale->cashier_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses ke transaksi ini.',
            ], 403);
        }

        return response()->json([
            'data' => $this->salePayload($sale->load(['items', 'store'])),
        ]);
    }

    /**
     * Format payload sale untuk mobile.
     */
    private function salePayload(Sale $sale): array
    {
        return [
            'id' => $sale->id,
            'invoice_number' => $sale->invoice_number,
            'sale_date' => $sale->sale_date->toIso8601String(),
            'cashier_name' => $sale->cashier_name,
            'store' => $sale->store ? [
                'id' => $sale->store->id,
                'name' => $sale->store->name,
                'code' => $sale->store->code,
                'location' => $sale->store->location,
            ] : null,
            'items' => $sale->items->map(fn($item) => [
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'price' => (float) $item->price,
                'discount_price' => $item->discount_price !== null
                    ? (float) $item->discount_price
                    : null,
                'effective_price' => (float) $item->effective_price,
                'quantity' => $item->quantity,
                'line_total' => (float) $item->line_total,
            ]),
            'subtotal' => (float) $sale->subtotal,
            'discount_amount' => (float) $sale->discount_amount,
            'tax_amount' => (float) $sale->tax_amount,
            'total' => (float) $sale->total,
            'payment_amount' => (float) $sale->payment_amount,
            'change_amount' => (float) $sale->change_amount,
            'status' => $sale->status,
        ];
    }

    /**
     * Ringkasan jumlah terjual per produk (per hari / range tanggal).
     */
    public function productSummary(Request $request)
    {
        $user = $request->user();

        $dateFrom = $request->date_from ?? now()->toDateString();
        $dateTo   = $request->date_to   ?? now()->toDateString();

        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.cashier_id', $user->id)
            ->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $dateFrom)
            ->whereDate('sales.sale_date', '<=', $dateTo)
            ->selectRaw('
                sale_items.product_id,
                products.name as product_name,
                products.code as product_code,
                SUM(sale_items.quantity) as total_qty,
                SUM(sale_items.line_total) as total_revenue
            ')
            ->groupBy(
                'sale_items.product_id',
                'products.name',
                'products.code'
            )
            ->orderByDesc('total_qty')
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * ⭐ Validasi sale boleh diubah/hapus.
     */
    private function authorizeSale(Sale $sale, Request $request): void
    {
        $user = $request->user();

        if ((int) $sale->cashier_id !== (int) $user->id) {
            abort(403, 'Anda tidak memiliki akses ke transaksi ini.');
        }

        if (!$sale->sale_date->isToday()) {
            abort(403, 'Hanya transaksi hari ini yang dapat diubah.');
        }

        if ($sale->status !== 'completed') {
            abort(403, 'Transaksi ini tidak dapat diubah.');
        }
    }

    /**
     * ⭐ Update sale — replace semua items.
     */
    public function update(Request $request, Sale $sale)
    {
        $this->authorizeSale($sale, $request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $storeId = $user->store_id;

        return DB::transaction(function () use ($validated, $sale, $storeId) {
            // ── Hitung ulang (logika sama dengan store()) ──
            $subtotal = 0;
            $total = 0;
            $itemsData = [];

            foreach ($validated['items'] as $item) {
                $product = Product::with([
                    'stores' => fn($q) => $q->where('stores.id', $storeId)
                        ->withPivot(['price', 'is_available']),
                ])->find($item['product_id']);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'items' => ["Produk ID {$item['product_id']} tidak ditemukan."],
                    ]);
                }

                $pivot = $product->stores->first()?->pivot;
                if (!$pivot || !$pivot->is_available) {
                    throw ValidationException::withMessages([
                        'items' => ["Produk {$product->name} tidak tersedia di toko Anda."],
                    ]);
                }

                $basePrice = $pivot->price !== null
                    ? (float) $pivot->price
                    : (float) $product->price;

                $discountPrice = $product->discount_price !== null
                    ? (float) $product->discount_price
                    : null;
                $minimalDiscount = $product->minimal_discount;

                $qty = (int) $item['quantity'];
                $effectivePrice = $basePrice;

                if (
                    $discountPrice !== null
                    && $minimalDiscount !== null
                    && $qty >= $minimalDiscount
                ) {
                    $effectivePrice = $discountPrice;
                }

                $lineTotal = $effectivePrice * $qty;
                $subtotal += $basePrice * $qty;
                $total += $lineTotal;

                $itemsData[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_code' => $product->code,
                    'price' => $basePrice,
                    'discount_price' => $discountPrice,
                    'effective_price' => $effectivePrice,
                    'quantity' => $qty,
                    'line_total' => $lineTotal,
                ];
            }

            $paymentAmount = (float) $validated['payment_amount'];

            if ($paymentAmount < $total) {
                throw ValidationException::withMessages([
                    'payment_amount' => ['Uang diterima kurang dari total transaksi.'],
                ]);
            }

            // ── Update header ──
            $sale->update([
                'subtotal' => $subtotal,
                'discount_amount' => $subtotal - $total,
                'total' => $total,
                'payment_amount' => $paymentAmount,
                'change_amount' => $paymentAmount - $total,
                'sale_date' => now(),  // ⭐ update timestamp
            ]);

            // ── Replace items: hapus lama, insert baru ──
            $sale->items()->delete();
            $sale->items()->createMany($itemsData);

            return response()->json([
                'data' => $this->salePayload($sale->fresh(['items', 'store'])),
            ]);
        });
    }

    /**
     * ⭐ Soft delete — set status jadi 'void'.
     */
    public function destroy(Request $request, Sale $sale)
    {
        $this->authorizeSale($sale, $request);

        $sale->update(['status' => 'void']);

        return response()->json([
            'message' => 'Transaksi berhasil dibatalkan.',
        ]);
    }

}

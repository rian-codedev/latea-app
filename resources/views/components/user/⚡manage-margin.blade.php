<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Models\Sale;
use App\Models\Store;
use App\Models\OperationalCost;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Product;
use App\Exports\FinancialAnalysisExport;

new #[Title('Analisis Pendapatan')] class extends Component {
    public $dateFrom;
    public $dateTo;

    // Chart-specific filters
    public string $chartPeriod = 'daily';
    public string $chartType = 'line';

    public function mount()
    {
        $this->dateFrom = now()->subDays(30)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function setPeriod(string $period): void
    {
        $this->chartPeriod = $period;
        $this->dispatch('chart-updated');
    }

    public function setChartType(string $type): void
    {
        $this->chartType = $type;
        $this->dispatch('chart-updated');
    }

    public function updatedDateFrom(): void
    {
        $this->dispatch('chart-updated');
    }

    public function updatedDateTo(): void
    {
        $this->dispatch('chart-updated');
    }

    /**
     * Helper: ambil store_id user yang login.
     * Return null kalau kasir belum di-assign ke toko.
     */
    protected function getStoreId(): ?int
    {
        $user = Auth::user();
        return $user?->store_id ? (int) $user->store_id : null;
    }

    protected function buildChartData(Carbon $from, Carbon $to): array
    {
        $storeId = $this->getStoreId();
        if (!$storeId) {
            return [];
        }

        $query = Sale::query()
            ->where('status', 'completed')
            ->where('store_id', $storeId)
            ->whereBetween('sale_date', [$from, $to]);

        $groupExpr = match ($this->chartPeriod) {
            'daily' => 'DATE(sale_date)',
            'weekly' => 'YEARWEEK(sale_date, 1)',
            'monthly' => 'DATE_FORMAT(sale_date, "%Y-%m")',
            'yearly' => 'YEAR(sale_date)',
        };

        $rows = $query
            ->selectRaw(
                "
                {$groupExpr} as period,
                MIN(sale_date) as first_date,
                MAX(sale_date) as last_date,
                MIN(total) as low,
                MAX(total) as high,
                SUM(total) as total_revenue,
                COUNT(*) as transaction_count
            ",
            )
            ->groupByRaw($groupExpr)
            ->orderBy('first_date')
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $firstSale =
                Sale::where('status', 'completed')
                    ->where('store_id', $storeId)
                    ->whereBetween('sale_date', [$from, $to])
                    ->where('sale_date', $row->first_date)
                    ->value('total') ?? 0;

            $lastSale =
                Sale::where('status', 'completed')
                    ->where('store_id', $storeId)
                    ->whereBetween('sale_date', [$from, $to])
                    ->where('sale_date', $row->last_date)
                    ->value('total') ?? 0;

            $data[] = [
                'label' => $this->formatPeriodLabel($row->first_date),
                'o' => (float) $firstSale,
                'h' => (float) $row->high,
                'l' => (float) $row->low,
                'c' => (float) $lastSale,
                'revenue' => (float) $row->total_revenue,
                'count' => (int) $row->transaction_count,
            ];
        }

        return $data;
    }

    protected function formatPeriodLabel($date): string
    {
        $carbon = Carbon::parse($date);

        return match ($this->chartPeriod) {
            'daily' => $carbon->format('d M'),
            'weekly' => 'W' . $carbon->weekOfYear . ' ' . $carbon->format('Y'),
            'monthly' => $carbon->format('M Y'),
            'yearly' => $carbon->format('Y'),
        };
    }

    public function export()
    {
        $storeId = $this->getStoreId();
        if (!$storeId) {
            session()->flash('error', 'Anda belum ditugaskan ke toko manapun.');
            return;
        }

        $data = $this->with();
        $storeName = Store::find($storeId)?->name;

        $filename = 'analisis-margin-' . $this->dateFrom . '-to-' . $this->dateTo . '-' . \Illuminate\Support\Str::slug($storeName ?? 'toko') . '.xlsx';
        return (new FinancialAnalysisExport(
            metrics: $data['metrics'],
            chartData: $data['chartData'],
            topProducts: $data['topProducts'],
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
            storeName: $storeName,
            chartPeriod: $this->chartPeriod,
            productivityProducts: $data['productivityProducts'],   // ← baru
            productivitySummary: $data['productivitySummary'],     // ← baru
        ))->download($filename);

    }

    protected function buildProductivityData(Carbon $from, Carbon $to): array
    {
        $storeId = $this->getStoreId();

        // ⭐ Kasir belum punya toko — return kosong
        if (!$storeId) {
            return [
                'products' => collect(),
                'summary' => [
                    'total_products' => 0,
                    'fast_count' => 0,
                    'normal_count' => 0,
                    'slow_count' => 0,
                    'dead_count' => 0,
                    'growing_count' => 0,
                    'declining_count' => 0,
                    'total_days' => 0,
                    'avg_velocity' => 0,
                ],
                'store_filter' => null,
            ];
        }

        $totalDays = (int) $from->diffInDays($to) + 1;
        $midPoint = $from->copy()->addDays((int) floor($totalDays / 2));
        $midPointStr = $midPoint->toDateTimeString();

        // ─── Aggregate sales per product (scoped ke toko kasir) ───
        $salesData = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.store_id', $storeId) // ← LOCK ke toko
            ->whereBetween('sales.sale_date', [$from, $to])
            ->selectRaw(
                '
                sale_items.product_id,
                SUM(sale_items.quantity) as total_qty,
                SUM(sale_items.line_total) as total_revenue,
                COUNT(DISTINCT sale_items.sale_id) as transaction_count,
                COUNT(DISTINCT DATE(sales.sale_date)) as days_active,
                SUM(CASE WHEN sales.sale_date < ? THEN sale_items.quantity ELSE 0 END) as qty_first_half,
                SUM(CASE WHEN sales.sale_date >= ? THEN sale_items.quantity ELSE 0 END) as qty_second_half
            ',
                [$midPointStr, $midPointStr],
            )
            ->groupBy('sale_items.product_id')
            ->get()
            ->keyBy('product_id');

        $soldProductIds = $salesData->keys()->all();

        // ─── Produk yang PERNAH dijual di toko ini (all-time) ───
        // Agar dead stock akurat: produk yang dulu pernah laku di toko ini
        // tetap dihitung dead kalau tidak ada penjualan di periode ini
        $everSoldAtStore = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.status', 'completed')->where('sales.store_id', $storeId)->distinct()->pluck('sale_items.product_id');

        // ─── Build produk relevan ───
        $products = Product::query()
            ->select(['id', 'name', 'code', 'is_active'])
            ->where(function ($q) use ($soldProductIds, $everSoldAtStore) {
                $q->whereIn('id', $soldProductIds)->orWhere('is_active', true);

                if ($everSoldAtStore->isNotEmpty()) {
                    $q->orWhereIn('id', $everSoldAtStore->all());
                }
            })
            ->get()
            ->map(function ($product) use ($salesData, $totalDays) {
                $data = $salesData->get($product->id);

                $totalQty = $data ? (int) $data->total_qty : 0;
                $totalRevenue = $data ? (float) $data->total_revenue : 0;
                $transactionCount = $data ? (int) $data->transaction_count : 0;
                $daysActive = $data ? (int) $data->days_active : 0;
                $qtyFirstHalf = $data ? (float) $data->qty_first_half : 0;
                $qtySecondHalf = $data ? (float) $data->qty_second_half : 0;

                $velocity = $totalDays > 0 ? $totalQty / $totalDays : 0;
                $revenuePerDay = $totalDays > 0 ? $totalRevenue / $totalDays : 0;
                $consistencyPct = $totalDays > 0 ? ($daysActive / $totalDays) * 100 : 0;

                if ($qtyFirstHalf > 0) {
                    $growthPct = (($qtySecondHalf - $qtyFirstHalf) / $qtyFirstHalf) * 100;
                } elseif ($qtySecondHalf > 0) {
                    $growthPct = 100;
                } else {
                    $growthPct = 0;
                }

                return (object) [
                    'id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code,
                    'is_active' => (bool) $product->is_active,
                    'total_qty' => $totalQty,
                    'total_revenue' => $totalRevenue,
                    'transaction_count' => $transactionCount,
                    'days_active' => $daysActive,
                    'velocity' => $velocity,
                    'revenue_per_day' => $revenuePerDay,
                    'consistency_pct' => $consistencyPct,
                    'growth_pct' => $growthPct,
                ];
            });

        // ─── Klasifikasi persentil ───
        $activeVelocities = $products->where('total_qty', '>', 0)->pluck('velocity')->sort()->values();
        $count = $activeVelocities->count();

        $p25 = $count > 0 ? (float) ($activeVelocities->get((int) floor($count * 0.25)) ?? 0) : 0;
        $p75 = $count > 0 ? (float) ($activeVelocities->get((int) floor($count * 0.75)) ?? 0) : 0;

        $products = $products->map(function ($p) use ($p25, $p75) {
            if ($p->total_qty == 0) {
                $p->status = 'dead';
            } elseif ($p->velocity >= $p75) {
                $p->status = 'fast';
            } elseif ($p->velocity <= $p25) {
                $p->status = 'slow';
            } else {
                $p->status = 'normal';
            }

            if ($p->growth_pct > 20) {
                $p->trend = 'growing';
            } elseif ($p->growth_pct < -20) {
                $p->trend = 'declining';
            } else {
                $p->trend = 'stable';
            }

            return $p;
        });

        $products = $products->sortByDesc('velocity')->values();

        $summary = [
            'total_products' => $products->count(),
            'fast_count' => $products->where('status', 'fast')->count(),
            'normal_count' => $products->where('status', 'normal')->count(),
            'slow_count' => $products->where('status', 'slow')->count(),
            'dead_count' => $products->where('status', 'dead')->count(),
            'growing_count' => $products->where('trend', 'growing')->count(),
            'declining_count' => $products->where('trend', 'declining')->count(),
            'total_days' => $totalDays,
            'avg_velocity' => $products->where('total_qty', '>', 0)->avg('velocity') ?? 0,
        ];

        return [
            'products' => $products,
            'summary' => $summary,
            'store_filter' => $storeId,
        ];
    }

    public function with()
    {
        $storeId = $this->getStoreId();
        $store = $storeId ? Store::find($storeId) : null;

        // ⭐ Kalau kasir belum punya store — return kosong
        if (!$storeId) {
            return [
                'store' => null,
                'metrics' => [
                    'totalRevenue' => 0,
                    'totalCogs' => 0,
                    'grossProfit' => 0,
                    'grossMarginPct' => 0,
                    'operationalCost' => 0,
                    'netProfit' => 0,
                    'netMarginPct' => 0,
                    'markupPct' => 0,
                    'transactionCount' => 0,
                    'totalItemsSold' => 0,
                    'avgSellingPrice' => 0,
                    'avgCogsPerItem' => 0,
                    'contributionMarginPerUnit' => 0,
                    'bepUnits' => 0,
                    'bepRevenue' => 0,
                ],
                'topProducts' => collect(),
                'chartData' => [],
                // ⭐ baru
                'productivityProducts' => collect(),
                'productivitySummary' => [
                    'total_products' => 0,
                    'fast_count' => 0,
                    'normal_count' => 0,
                    'slow_count' => 0,
                    'dead_count' => 0,
                    'growing_count' => 0,
                    'declining_count' => 0,
                    'total_days' => 0,
                    'avg_velocity' => 0,
                ],
                'productivityStoreFilter' => null,
            ];
        }

        $from = Carbon::parse($this->dateFrom)->startOfDay();
        $to = Carbon::parse($this->dateTo)->endOfDay();

        // ⭐ LOCK ke store kasir
        $saleQuery = Sale::query()
            ->where('status', 'completed')
            ->where('store_id', $storeId)
            ->whereBetween('sale_date', [$from, $to]);

        $chartData = $this->buildChartData($from, $to);

        // Metrics
        $totalRevenue = (clone $saleQuery)->sum('total');
        $transactionCount = (clone $saleQuery)->count();

        // HPP
        $totalCogs = (float) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->where('sales.store_id', $storeId)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->sum(DB::raw('products.initial_price * sale_items.quantity'));

        $totalItemsSold = (int) DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')
            ->where('sales.store_id', $storeId)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->sum('sale_items.quantity');

        $grossProfit = $totalRevenue - $totalCogs;
        $grossMarginPct = $totalRevenue > 0 ? ($grossProfit / $totalRevenue) * 100 : 0;

        // ⭐ Operational cost — HANYA toko kasir
        $operationalCost = (float) OperationalCost::query()
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$from, $to])
            ->sum('cost');

        $netProfit = $grossProfit - $operationalCost;
        $netMarginPct = $totalRevenue > 0 ? ($netProfit / $totalRevenue) * 100 : 0;

        $markupPct = $totalCogs > 0 ? (($totalRevenue - $totalCogs) / $totalCogs) * 100 : 0;

        $avgSellingPrice = $totalItemsSold > 0 ? $totalRevenue / $totalItemsSold : 0;
        $avgCogsPerItem = $totalItemsSold > 0 ? $totalCogs / $totalItemsSold : 0;
        $contributionMarginPerUnit = $avgSellingPrice - $avgCogsPerItem;
        $bepUnits = $contributionMarginPerUnit > 0 ? ceil($operationalCost / $contributionMarginPerUnit) : 0;
        $bepRevenue = $bepUnits * $avgSellingPrice;

        // Top products
        $topProducts = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')
            ->where('sales.store_id', $storeId)
            ->whereBetween('sales.sale_date', [$from, $to])
            ->selectRaw(
                '
                sale_items.product_id,
                products.name as product_name,
                SUM(sale_items.quantity) as total_qty,
                SUM(sale_items.line_total) as total_revenue,
                SUM(products.initial_price * sale_items.quantity) as total_cogs,
                (SUM(sale_items.line_total) - SUM(products.initial_price * sale_items.quantity)) as gross_profit
            ',
            )
            ->groupBy('sale_items.product_id', 'products.name')
            ->orderByDesc('gross_profit')
            ->get()
            ->map(function ($row) {
                $row->margin_pct = $row->total_revenue > 0 ? ($row->gross_profit / $row->total_revenue) * 100 : 0;
                return $row;
            });

        // ⭐ Productivity data (scoped ke toko kasir via getStoreId)
        $productivity = $this->buildProductivityData($from, $to);

        return [
            'store' => $store,
            'metrics' => compact('totalRevenue', 'totalCogs', 'grossProfit', 'grossMarginPct', 'operationalCost', 'netProfit', 'netMarginPct', 'markupPct', 'transactionCount', 'totalItemsSold', 'avgSellingPrice', 'avgCogsPerItem', 'contributionMarginPerUnit', 'bepUnits', 'bepRevenue'),
            'topProducts' => $topProducts,
            'chartData' => $chartData,
            // ⭐ baru
            'productivityProducts' => $productivity['products'],
            'productivitySummary' => $productivity['summary'],
            'productivityStoreFilter' => $productivity['store_filter'],
        ];
    }
};
?>

<div>
    <x-page-header title="Analisis Pendapatan" leading="Analisis keuangan toko Anda berdasarkan pendapatan"
        :time="true" />

    <div class="mx-auto mt-2 max-w-7xl space-y-3">

        {{-- ⭐ Kalau kasir belum punya store --}}
        @if (!$store)
            <div
                class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-center dark:border-amber-900/50 dark:bg-amber-950/30">
                <div
                    class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/60">
                    <svg class="h-6 w-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                </div>
                <h3 class="mt-3 text-sm font-semibold text-amber-800 dark:text-amber-400">
                    Anda belum ditugaskan ke toko manapun
                </h3>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-500">
                    Hubungi admin untuk mengatur toko Anda terlebih dahulu.
                </p>
            </div>
        @else
            {{-- ⭐ INFO STORE --}}
            <div class="rounded-xl border border-stone-200 bg-white px-3 py-3 dark:border-stone-800 dark:bg-stone-900">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <div
                            class="bg-sage-100 dark:bg-sage-900/60 text-sage-700 dark:text-sage-400 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-xs font-bold uppercase">
                            {{ mb_substr($store->name, 0, 2) }}
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-stone-800 dark:text-stone-100">
                                {{ $store->name }}
                            </p>
                            <p class="text-[10px] text-stone-500 dark:text-stone-400">
                                {{ $store->code }} · {{ $store->location }}
                            </p>
                        </div>
                    </div>

                    {{-- Periode Info --}}
                    <div class="flex items-center gap-2 text-[10px] text-stone-500 dark:text-stone-400">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                        </svg>
                        <span>{{ \Carbon\Carbon::parse($dateFrom)->translatedFormat('d M Y') }} –
                            {{ \Carbon\Carbon::parse($dateTo)->translatedFormat('d M Y') }}</span>
                    </div>
                </div>
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- CHART SECTION                                  --}}
            {{-- ════════════════════════════════════════════════ --}}
            <div x-data="salesChart({
                data: @js($chartData),
                type: @js($chartType),
            })" x-init="init()"
                class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">

                {{-- Header --}}
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <div
                            class="bg-blue-100 dark:bg-blue-900/60 flex h-7 w-7 items-center justify-center rounded-lg">
                            <svg class="text-blue-700 dark:text-blue-400 h-4.5 w-4.5" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <g id="SVGRepo_bgCarrier" stroke-width="0"></g>
                                <g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g>
                                <g id="SVGRepo_iconCarrier">
                                    <path
                                        d="M9 9H9.01M15 15H15.01M16 8L8 16M9.2019 20.6009C9.52965 20.5575 9.86073 20.6464 10.1218 20.8475L11.3251 21.7708C11.7228 22.0764 12.2761 22.0764 12.6727 21.7708L13.9215 20.812C14.1548 20.6331 14.4492 20.5542 14.7403 20.5931L16.3024 20.7986C16.799 20.8642 17.2779 20.5875 17.4701 20.1242L18.0712 18.6709C18.1834 18.3987 18.3989 18.1832 18.6711 18.0709L20.1243 17.4698C20.5876 17.2787 20.8643 16.7988 20.7987 16.3021L20.601 14.7966C20.5576 14.4688 20.6465 14.1377 20.8476 13.8766L21.7709 12.6733C22.0764 12.2755 22.0764 11.7222 21.7709 11.3256L20.812 10.0767C20.6332 9.84339 20.5543 9.54896 20.5932 9.25785L20.7987 7.69568C20.8643 7.19902 20.5876 6.72015 20.1243 6.52793L18.6711 5.92684C18.3989 5.81462 18.1834 5.59907 18.0712 5.32685L17.4701 3.87356C17.279 3.41024 16.799 3.13358 16.3024 3.19913L14.7403 3.40468C14.4492 3.44468 14.1548 3.36579 13.9226 3.18802L12.6738 2.22916C12.2761 1.92361 11.7228 1.92361 11.3262 2.22916L10.0774 3.18802C9.84407 3.36579 9.54965 3.44468 9.25856 3.40691L7.69647 3.20136C7.19984 3.1358 6.721 3.41246 6.52879 3.87578L5.92884 5.32907C5.81552 5.60018 5.59998 5.81573 5.32889 5.92906L3.87568 6.52904C3.41238 6.72126 3.13574 7.20013 3.20129 7.69679L3.40683 9.25897C3.4446 9.55007 3.36572 9.8445 3.18796 10.0767L2.22915 11.3256C1.92362 11.7233 1.92362 12.2767 2.22915 12.6733L3.18796 13.9222C3.36683 14.1555 3.44571 14.4499 3.40683 14.741L3.20129 16.3032C3.13574 16.7999 3.41238 17.2787 3.87568 17.471L5.32889 18.0721C5.60109 18.1843 5.81663 18.3998 5.92884 18.672L6.5299 20.1253C6.721 20.5887 7.20096 20.8653 7.69758 20.7998L9.2019 20.6009ZM9.5 9C9.5 9.27614 9.27614 9.5 9 9.5C8.72386 9.5 8.5 9.27614 8.5 9C8.5 8.72386 8.72386 8.5 9 8.5C9.27614 8.5 9.5 8.72386 9.5 9ZM15.5 15C15.5 15.2761 15.2761 15.5 15 15.5C14.7239 15.5 14.5 15.2761 14.5 15C14.5 14.7239 14.7239 14.5 15 14.5C15.2761 14.5 15.5 14.7239 15.5 15Z"
                                        stroke="#60a5fa" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round"></path>
                                </g>
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Tren Penjualan</h2>
                            <p class="text-[10px] text-stone-500 dark:text-stone-400">
                                Visualisasi peningkatan revenue toko Anda
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Controls: Date Range + Chart Type --}}
                <div class="mb-3 grid grid-cols-1 gap-2 lg:grid-cols-[auto_1fr_auto] lg:items-center">

                    {{-- Date Range --}}
                    <div class="flex items-center gap-2">
                        <label class="hidden text-[11px] font-medium text-stone-600 md:block dark:text-stone-400">
                            Periode:
                        </label>
                        <input wire:model.live="dateFrom" type="date"
                            class="focus:ring-sage-500 flex-1 rounded-lg border border-stone-200 bg-stone-50 px-2.5 py-1.5 text-xs text-stone-700 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-300" />
                        <span class="text-[10px] text-stone-400">s/d</span>
                        <input wire:model.live="dateTo" type="date"
                            class="focus:ring-sage-500 flex-1 rounded-lg border border-stone-200 bg-stone-50 px-2.5 py-1.5 text-xs text-stone-700 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-300" />
                    </div>

                    {{-- Spacer --}}
                    <div></div>

                    {{-- Chart Type Switcher --}}
                    <div
                        class="flex items-center gap-1 overflow-x-auto rounded-lg border border-stone-200 bg-stone-50 p-0.5 dark:border-stone-700 dark:bg-stone-800">
                        @foreach (['candlestick' => 'Candle', 'line' => 'Line', 'bar' => 'Bar'] as $type => $label)
                            <button wire:click="setChartType('{{ $type }}')" type="button"
                                class="flex-1 cursor-pointer whitespace-nowrap rounded-md px-2 py-1 text-[10px] font-medium transition
                                {{ $chartType === $type
                                    ? 'bg-white text-sage-700 shadow-sm dark:bg-stone-700 dark:text-sage-400'
                                    : 'text-stone-500 hover:text-stone-700 dark:text-stone-400 dark:hover:text-stone-200' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Period Switcher + Export --}}
                <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">

                    <div
                        class="flex items-center gap-1 overflow-x-auto rounded-lg border border-stone-200 bg-stone-50 p-0.5 sm:flex-1 sm:overflow-visible dark:border-stone-700 dark:bg-stone-800">
                        @foreach (['daily' => 'Hari', 'weekly' => 'Minggu', 'monthly' => 'Bulan', 'yearly' => 'Tahun'] as $period => $label)
                            <button wire:click="setPeriod('{{ $period }}')" type="button"
                                wire:key="period-{{ $period }}"
                                class="flex-1 cursor-pointer whitespace-nowrap rounded-md px-2 py-1.5 text-[10px] font-medium transition
                                {{ $chartPeriod === $period
                                    ? 'bg-white text-sage-700 shadow-sm dark:bg-stone-700 dark:text-sage-400'
                                    : 'text-stone-500 hover:text-stone-700 dark:text-stone-400 dark:hover:text-stone-200' }}">
                                <span class="hidden sm:inline">Per {{ $label }}</span>
                                <span class="sm:hidden">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>

                    <button wire:click="export" wire:loading.attr="disabled" wire:target="export" type="button"
                        class="bg-sage-600 hover:bg-sage-700 focus:ring-sage-500 dark:bg-sage-500 dark:hover:bg-sage-600 inline-flex w-full cursor-pointer items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto">
                        <span wire:loading.remove wire:target="export" class="flex items-center gap-1.5">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                            </svg>
                            <span class="hidden sm:inline">{{ __('Export Excel') }}</span>
                            <span class="sm:hidden">{{ __('Export') }}</span>
                        </span>
                        <span wire:loading wire:target="export" class="flex items-center gap-2">
                            <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                    stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                            </svg>
                            <span class="hidden sm:inline">{{ __('Menyiapkan…') }}</span>
                        </span>
                    </button>
                </div>

                {{-- Chart --}}
                <div x-ref="chart" wire:key="chart-{{ $chartType }}-{{ $chartPeriod }}" class="w-full"></div>
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- MATRIX 1: PROFITABILITY                        --}}
            {{-- ════════════════════════════════════════════════ --}}
            <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                <div class="mb-3 flex items-center gap-2">
                    <div class="bg-sage-100 dark:bg-sage-900/60 flex h-7 w-7 items-center justify-center rounded-lg">
                        <svg class="text-sage-700 dark:text-sage-400 h-3.5 w-3.5" fill="none"
                            stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">
                            {{ __('Matriks Profitabilitas') }}</h2>
                        <p class="text-[10px] text-stone-500 dark:text-stone-400">
                            {{ __('Ringkasan kesehatan finansial toko Anda') }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                    {{-- Revenue --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">
                            {{ __('Penjualan') }}</p>
                        <p class="mt-1 font-mono text-base font-semibold text-stone-800 dark:text-stone-100">
                            Rp {{ number_format($metrics['totalRevenue'], 0, ',', '.') }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-stone-400">
                            {{ $metrics['transactionCount'] }} transaksi · {{ $metrics['totalItemsSold'] }} item
                        </p>
                    </div>

                    {{-- COGS --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">HPP (COGS)
                        </p>
                        <p class="mt-1 font-mono text-base font-semibold text-amber-700 dark:text-amber-400">
                            Rp {{ number_format($metrics['totalCogs'], 0, ',', '.') }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-stone-400">
                            {{ $metrics['totalRevenue'] > 0 ? number_format(($metrics['totalCogs'] / $metrics['totalRevenue']) * 100, 1) : 0 }}%
                            dari revenue
                        </p>
                    </div>

                    {{-- Gross Profit --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">Laba Kotor
                        </p>
                        <p
                            class="mt-1 font-mono text-base font-semibold {{ $metrics['grossProfit'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                            Rp {{ number_format($metrics['grossProfit'], 0, ',', '.') }}
                        </p>
                        <p
                            class="mt-0.5 text-[10px] font-semibold {{ $metrics['grossProfit'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($metrics['grossMarginPct'], 2) }}% margin
                        </p>
                    </div>

                    {{-- Net Profit --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">Laba Bersih
                        </p>
                        <p
                            class="mt-1 font-mono text-base font-semibold {{ $metrics['netProfit'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                            Rp {{ number_format($metrics['netProfit'], 0, ',', '.') }}
                        </p>
                        <p
                            class="mt-0.5 text-[10px] font-semibold {{ $metrics['netProfit'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ number_format($metrics['netMarginPct'], 2) }}% margin
                        </p>
                    </div>
                </div>

                {{-- Formula --}}
                <div
                    class="mt-3 space-y-1 rounded-md border border-stone-100 bg-stone-50/50 p-2.5 dark:border-stone-800 dark:bg-stone-800/30">
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">{{ __('Gross Margin (Margin Laba Kotor)') }}</span> = ((Penjualan
                        − HPP) / Penjualan) × 100%
                        = (({{ number_format($metrics['totalRevenue'], 0, ',', '.') }} −
                        {{ number_format($metrics['totalCogs'], 0, ',', '.') }}) /
                        {{ number_format($metrics['totalRevenue'], 0, ',', '.') }}) × 100%
                        = <span
                            class="font-bold text-sage-700 dark:text-sage-400">{{ number_format($metrics['grossMarginPct'], 2) }}%</span>
                    </p>
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">{{ __('Net Margin (Margin Laba Bersih)') }}</span> = ((Penjualan −
                        Total Biaya) / Penjualan) × 100%
                        = (({{ number_format($metrics['totalRevenue'], 0, ',', '.') }} −
                        {{ number_format($metrics['totalCogs'] + $metrics['operationalCost'], 0, ',', '.') }}) /
                        {{ number_format($metrics['totalRevenue'], 0, ',', '.') }}) × 100%
                        = <span
                            class="font-bold text-sage-700 dark:text-sage-400">{{ number_format($metrics['netMarginPct'], 2) }}%</span>
                    </p>
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">Biaya Operasional</span> = Rp
                        {{ number_format($metrics['operationalCost'], 0, ',', '.') }}
                        <span class="text-stone-400 dark:text-stone-500">
                            ({{ $metrics['transactionCount'] > 0 ? number_format($metrics['operationalCost'] / max($metrics['transactionCount'], 1), 0, ',', '.') : 0 }}
                            per transaksi)
                        </span>
                    </p>
                </div>
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- MATRIX 2: PRICING & BEP                        --}}
            {{-- ════════════════════════════════════════════════ --}}
            <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                <div class="mb-3 flex items-center gap-2">
                    <div class="bg-blue-100 dark:bg-blue-900/60 flex h-7 w-7 items-center justify-center rounded-lg">
                        <svg class="text-blue-700 dark:text-blue-400 h-5 w-5" fill="none" stroke="currentColor"
                            stroke-width="1" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">Harga & Break-Even</h2>
                        <p class="text-[10px] text-stone-500 dark:text-stone-400">
                            Analisis harga & titik impas toko Anda
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                    {{-- Markup --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">Markup</p>
                        <p class="mt-1 font-mono text-base font-semibold text-blue-700 dark:text-blue-400">
                            {{ number_format($metrics['markupPct'], 2) }}%
                        </p>
                        <p class="mt-0.5 text-[10px] text-stone-400">di atas HPP</p>
                    </div>

                    {{-- Avg Price --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">Harga
                            Rata-rata</p>
                        <p class="mt-1 font-mono text-base font-semibold text-stone-800 dark:text-stone-100">
                            Rp {{ number_format($metrics['avgSellingPrice'], 0, ',', '.') }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-stone-400">per item terjual</p>
                    </div>

                    {{-- Contribution Margin --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">
                            Kontribusi/Item</p>
                        <p class="mt-1 font-mono text-base font-semibold text-green-700 dark:text-green-400">
                            Rp {{ number_format($metrics['contributionMarginPerUnit'], 0, ',', '.') }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-stone-400">harga − HPP</p>
                    </div>

                    {{-- BEP --}}
                    <div
                        class="rounded-lg border border-stone-200 bg-stone-50 p-3 dark:border-stone-700 dark:bg-stone-800/50">
                        <p class="text-[10px] uppercase tracking-wider text-stone-500 dark:text-stone-400">Break-Even
                            Point</p>
                        <p class="mt-1 font-mono text-base font-semibold text-amber-700 dark:text-amber-400">
                            {{ number_format($metrics['bepUnits'], 0, ',', '.') }} unit
                        </p>
                        <p class="mt-0.5 text-[10px] text-stone-400">
                            ≈ Rp {{ number_format($metrics['bepRevenue'], 0, ',', '.') }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-3 space-y-1 rounded-md border border-stone-100 bg-stone-50/50 p-2.5 dark:border-stone-800 dark:bg-stone-800/30">
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">Markup</span> = ((Harga Jual − HPP) / HPP) × 100%
                        = <span
                            class="font-bold text-blue-700 dark:text-blue-400">{{ number_format($metrics['markupPct'], 2) }}%</span>
                    </p>
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">BEP</span> = Fixed Cost / (Harga − Variable Cost)
                        = {{ number_format($metrics['operationalCost'], 0, ',', '.') }} /
                        ({{ number_format($metrics['avgSellingPrice'], 0, ',', '.') }} −
                        {{ number_format($metrics['avgCogsPerItem'], 0, ',', '.') }})
                        = <span
                            class="font-bold text-amber-700 dark:text-amber-400">{{ number_format($metrics['bepUnits'], 0, ',', '.') }}
                            unit</span>
                    </p>
                </div>
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- TOP PRODUCTS                                   --}}
            {{-- ════════════════════════════════════════════════ --}}
            <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                <div class="mb-3 flex items-center gap-2">
                    <div
                        class="bg-purple-100 dark:bg-purple-900/60 flex h-7 w-7 items-center justify-center rounded-lg">
                        <svg class="text-purple-700 dark:text-purple-400 h-4 w-4" fill="currentColor"
                            viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                            <g id="Layer_2" data-name="Layer 2">
                                <g id="invisible_box" data-name="invisible box">
                                    <rect width="48" height="48" fill="none" />
                                </g>
                                <g id="Q3_icons" data-name="Q3 icons">
                                    <path
                                        d="M44,7.1V14a2,2,0,0,1-2,2H35a2,2,0,0,1-2-2.3A2.1,2.1,0,0,1,35.1,12h2.3A18,18,0,0,0,6.1,22.2a2,2,0,0,1-2,1.8h0a2,2,0,0,1-2-2.2A22,22,0,0,1,40,8.9V7a2,2,0,0,1,2.3-2A2.1,2.1,0,0,1,44,7.1Z" />
                                    <path
                                        d="M4,40.9V34a2,2,0,0,1,2-2h7a2,2,0,0,1,2,2.3A2.1,2.1,0,0,1,12.9,36H10.6A18,18,0,0,0,41.9,25.8a2,2,0,0,1,2-1.8h0a2,2,0,0,1,2,2.2A22,22,0,0,1,8,39.1V41a2,2,0,0,1-2.3,2A2.1,2.1,0,0,1,4,40.9Z" />
                                    <path
                                        d="M24.7,22c-3.5-.7-3.5-1.3-3.5-1.8s.2-.6.5-.9a3.4,3.4,0,0,1,1.8-.4,6.3,6.3,0,0,1,3.3.9,1.8,1.8,0,0,0,2.7-.5,1.9,1.9,0,0,0-.4-2.8A9.1,9.1,0,0,0,26,15.3V13a2,2,0,0,0-4,0v2.2c-3,.5-5,2.5-5,5.2s3.3,4.9,6.5,5.5,3.3,1.3,3.3,1.8-1.1,1.4-2.5,1.4h0a6.7,6.7,0,0,1-4.1-1.3,2,2,0,0,0-2.8.6,1.8,1.8,0,0,0,.3,2.6A10.9,10.9,0,0,0,22,32.8V35a2,2,0,0,0,4,0V32.8a6.3,6.3,0,0,0,3-1.3,4.9,4.9,0,0,0,2-4h0C31,23.8,27.6,22.6,24.7,22Z" />
                                </g>
                            </g>
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">
                            {{ __('Kontribusi Laba per Produk') }}
                        </h2>
                        <p class="text-[10px] text-stone-500 dark:text-stone-400">
                            Diurutkan berdasarkan laba kotor tertinggi
                        </p>
                    </div>
                </div>

                <div class="mb-2 flex items-center justify-between text-[10px] text-stone-500 dark:text-stone-400">
                    <span class="font-mono uppercase tracking-wider">
                        {{ $topProducts->count() }} {{ __('produk') }}
                    </span>
                </div>

                <div class="max-h-[600px] overflow-auto rounded-lg border border-stone-200 dark:border-stone-700">
                    <table class="w-full text-left text-[11px]">
                        <thead class="sticky top-0 z-10">
                            <tr
                                class="border-b border-stone-200 bg-stone-50 font-semibold uppercase tracking-wider text-stone-500 dark:border-stone-700 dark:bg-stone-800/50 dark:text-stone-400">
                                <th class="w-8 px-3 py-1.5 text-center">#</th>
                                <th class="px-3 py-1.5">{{ __('Produk') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Qty') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Revenue') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('HPP') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Laba Kotor') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Margin') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60">
                            @forelse ($topProducts as $product)
                                <tr class="transition-colors hover:bg-stone-50 dark:hover:bg-stone-800/30">
                                    <td class="px-3 py-1.5 text-center font-mono text-stone-400 dark:text-stone-500">
                                        {{ $loop->iteration }}
                                    </td>
                                    <td class="px-3 py-1.5 font-medium text-stone-800 dark:text-stone-200">
                                        {{ $product->product_name }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-stone-600 dark:text-stone-400">
                                        {{ number_format($product->total_qty, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-stone-700 dark:text-stone-300">
                                        Rp {{ number_format($product->total_revenue, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-amber-700 dark:text-amber-400">
                                        Rp {{ number_format($product->total_cogs, 0, ',', '.') }}
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-right font-mono font-semibold text-green-700 dark:text-green-400">
                                        Rp {{ number_format($product->gross_profit, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right">
                                        <span
                                            class="inline-flex items-center rounded-full bg-sage-50 px-2 py-0.5 font-mono text-[10px] font-semibold text-sage-700 dark:bg-sage-950/40 dark:text-sage-400">
                                            {{ number_format($product->margin_pct, 1) }}%
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-6 text-center text-[11px] text-stone-400">
                                        {{ __('Tidak ada data produk pada periode ini') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>

                        @if ($topProducts->isNotEmpty())
                            @php
                                $totalQty = $topProducts->sum('total_qty');
                                $totalRevenue = $topProducts->sum('total_revenue');
                                $totalCogs = $topProducts->sum('total_cogs');
                                $totalProfit = $topProducts->sum('gross_profit');
                                $totalMarginPct = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0;
                            @endphp
                            <tfoot class="sticky bottom-0 z-10">
                                <tr
                                    class="border-t-2 border-stone-300 bg-stone-100 font-semibold dark:border-stone-600 dark:bg-stone-800">
                                    <td colspan="2" class="px-3 py-2 text-stone-800 dark:text-stone-100">
                                        {{ __('TOTAL') }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-stone-800 dark:text-stone-100">
                                        {{ number_format($totalQty, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-stone-800 dark:text-stone-100">
                                        Rp {{ number_format($totalRevenue, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-amber-700 dark:text-amber-400">
                                        Rp {{ number_format($totalCogs, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-mono text-green-700 dark:text-green-400">
                                        Rp {{ number_format($totalProfit, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <span
                                            class="inline-flex items-center rounded-full bg-sage-100 px-2 py-0.5 font-mono text-[10px] font-bold text-sage-800 dark:bg-sage-900/60 dark:text-sage-300">
                                            {{ number_format($totalMarginPct, 1) }}%
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- MATRIKS 3: PRODUKTIVITAS PRODUK                --}}
            {{-- ════════════════════════════════════════════════ --}}
            <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">

                {{-- Header --}}
                <div class="mb-3 flex items-center gap-2">
                    <div
                        class="bg-indigo-100 dark:bg-indigo-900/60 flex h-7 w-7 items-center justify-center rounded-lg">
                        <svg class="text-indigo-700 dark:text-indigo-400 h-4 w-4" fill="none"
                            stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-semibold text-stone-800 dark:text-stone-100">
                            {{ __('Analisis Produktivitas Produk') }}
                        </h2>
                        <p class="text-[10px] text-stone-500 dark:text-stone-400">
                            Evaluasi performa tiap produk toko Anda dalam {{ $productivitySummary['total_days'] }} hari
                            terakhir
                        </p>
                    </div>
                </div>

                {{-- Summary Cards --}}
                <div class="mb-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
                    <div
                        class="rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-900/50 dark:bg-green-950/30">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] uppercase tracking-wider text-green-700 dark:text-green-400">Cepat
                                Laku</p>
                            <span class="font-mono text-[9px] text-green-600 dark:text-green-500">Fast</span>
                        </div>
                        <p class="mt-1 font-mono text-lg font-semibold text-green-700 dark:text-green-400">
                            {{ $productivitySummary['fast_count'] }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-green-600/80 dark:text-green-500/80">produk</p>
                    </div>

                    <div
                        class="rounded-lg border border-blue-200 bg-blue-50 p-3 dark:border-blue-900/50 dark:bg-blue-950/30">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] uppercase tracking-wider text-blue-700 dark:text-blue-400">Normal</p>
                            <span class="font-mono text-[9px] text-blue-600 dark:text-blue-500">Mid</span>
                        </div>
                        <p class="mt-1 font-mono text-lg font-semibold text-blue-700 dark:text-blue-400">
                            {{ $productivitySummary['normal_count'] }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-blue-600/80 dark:text-blue-500/80">produk</p>
                    </div>

                    <div
                        class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900/50 dark:bg-amber-950/30">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] uppercase tracking-wider text-amber-700 dark:text-amber-400">Lambat
                            </p>
                            <span class="font-mono text-[9px] text-amber-600 dark:text-amber-500">Slow</span>
                        </div>
                        <p class="mt-1 font-mono text-lg font-semibold text-amber-700 dark:text-amber-400">
                            {{ $productivitySummary['slow_count'] }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-amber-600/80 dark:text-amber-500/80">produk</p>
                    </div>

                    <div
                        class="rounded-lg border border-red-200 bg-red-50 p-3 dark:border-red-900/50 dark:bg-red-950/30">
                        <div class="flex items-center justify-between">
                            <p class="text-[10px] uppercase tracking-wider text-red-700 dark:text-red-400">Tidak Laku
                            </p>
                            <span class="font-mono text-[9px] text-red-600 dark:text-red-500">Dead</span>
                        </div>
                        <p class="mt-1 font-mono text-lg font-semibold text-red-700 dark:text-red-400">
                            {{ $productivitySummary['dead_count'] }}
                        </p>
                        <p class="mt-0.5 text-[10px] text-red-600/80 dark:text-red-500/80">produk</p>
                    </div>
                </div>

                {{-- Trend Summary --}}
                <div
                    class="mb-3 flex flex-wrap items-center gap-3 rounded-md border border-stone-100 bg-stone-50/50 px-3 py-2 dark:border-stone-800 dark:bg-stone-800/30">
                    <span
                        class="text-[10px] font-medium text-stone-500 dark:text-stone-400">{{ __('Tren') }}:</span>
                    <span class="inline-flex items-center gap-1 text-[10px] text-green-700 dark:text-green-400">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22M15.75 6.75h5.5m0 0v5.5" />
                        </svg>
                        <span class="font-semibold">{{ $productivitySummary['growing_count'] }}</span> Naik
                    </span>
                    <span class="inline-flex items-center gap-1 text-[10px] text-red-700 dark:text-red-400">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M2.25 6L9 12.75l4.306-4.307a11.95 11.95 0 015.814 5.519l2.74 1.22M15.75 17.25h5.5m0 0v-5.5" />
                        </svg>
                        <span class="font-semibold">{{ $productivitySummary['declining_count'] }}</span> Turun
                    </span>
                    <span class="inline-flex items-center gap-1 text-[10px] text-stone-500 dark:text-stone-400">
                        <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                        </svg>
                        <span
                            class="font-semibold">{{ $productivitySummary['total_products'] - $productivitySummary['growing_count'] - $productivitySummary['declining_count'] }}</span>
                        Stabil
                    </span>
                    <span class="ml-auto font-mono text-[10px] text-stone-400 dark:text-stone-500">
                        Ø Velocity: {{ number_format($productivitySummary['avg_velocity'], 2) }} unit/hari
                    </span>
                </div>

                {{-- Table --}}
                <div class="max-h-[600px] overflow-auto rounded-lg border border-stone-200 dark:border-stone-700">
                    <table class="w-full text-left text-[11px]">
                        <thead class="sticky top-0 z-10">
                            <tr
                                class="border-b border-stone-200 bg-stone-50 font-semibold uppercase tracking-wider text-stone-500 dark:border-stone-700 dark:bg-stone-800/50 dark:text-stone-400">
                                <th class="w-8 px-3 py-1.5 text-center">#</th>
                                <th class="px-3 py-1.5">{{ __('Produk') }}</th>
                                <th class="px-3 py-1.5 text-center">{{ __('Status') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Qty') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Transaksi') }}</th>
                                <th class="px-3 py-1.5 text-center">{{ __('Hari Aktif') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Velocity') }}</th>
                                <th class="px-3 py-1.5 text-right">{{ __('Revenue/Hari') }}</th>
                                <th class="px-3 py-1.5 text-center">{{ __('Tren') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60">
                            @forelse ($productivityProducts as $product)
                                @php
                                    $statusConfig = match ($product->status) {
                                        'fast' => [
                                            'label' => 'Cepat',
                                            'class' =>
                                                'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-400',
                                        ],
                                        'normal' => [
                                            'label' => 'Normal',
                                            'class' =>
                                                'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400',
                                        ],
                                        'slow' => [
                                            'label' => 'Lambat',
                                            'class' =>
                                                'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
                                        ],
                                        'dead' => [
                                            'label' => 'Dead',
                                            'class' => 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400',
                                        ],
                                        default => [
                                            'label' => '?',
                                            'class' =>
                                                'bg-stone-100 text-stone-600 dark:bg-stone-800 dark:text-stone-400',
                                        ],
                                    };
                                @endphp
                                <tr class="transition-colors hover:bg-stone-50 dark:hover:bg-stone-800/30">
                                    <td class="px-3 py-1.5 text-center font-mono text-stone-400 dark:text-stone-500">
                                        {{ $loop->iteration }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <div class="flex items-center gap-2">
                                            <div class="min-w-0">
                                                <div class="truncate font-medium text-stone-800 dark:text-stone-200">
                                                    {{ $product->name }}
                                                </div>
                                                <div class="truncate text-[10px] text-stone-400 dark:text-stone-500">
                                                    {{ $product->code }}
                                                </div>
                                            </div>
                                            @if (!$product->is_active)
                                                <span
                                                    class="shrink-0 rounded-full bg-stone-200 px-1.5 py-0.5 text-[8px] font-semibold text-stone-600 dark:bg-stone-700 dark:text-stone-400">
                                                    OFF
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-center">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[9px] font-semibold {{ $statusConfig['class'] }}">
                                            {{ $statusConfig['label'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-stone-700 dark:text-stone-300">
                                        {{ number_format($product->total_qty, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-stone-600 dark:text-stone-400">
                                        {{ number_format($product->transaction_count, 0, ',', '.') }}
                                    </td>
                                    <td class="px-3 py-1.5 text-center">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <div
                                                class="h-1.5 w-12 overflow-hidden rounded-full bg-stone-200 dark:bg-stone-700">
                                                <div class="h-full rounded-full bg-indigo-500 dark:bg-indigo-400"
                                                    style="width: {{ min(100, $product->consistency_pct) }}%"></div>
                                            </div>
                                            <span class="font-mono text-[10px] text-stone-500 dark:text-stone-400">
                                                {{ $product->days_active }}/{{ $productivitySummary['total_days'] }}
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono">
                                        @if ($product->velocity > 0)
                                            <span class="font-semibold text-indigo-700 dark:text-indigo-400">
                                                {{ number_format($product->velocity, 2) }}
                                            </span>
                                            <span class="text-[10px] text-stone-400">/hari</span>
                                        @else
                                            <span class="text-stone-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 text-right font-mono text-stone-600 dark:text-stone-400">
                                        @if ($product->revenue_per_day > 0)
                                            Rp {{ number_format($product->revenue_per_day, 0, ',', '.') }}
                                        @else
                                            <span class="text-stone-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 text-center">
                                        @if ($product->trend === 'growing')
                                            <span
                                                class="inline-flex items-center gap-0.5 text-[10px] font-semibold text-green-600 dark:text-green-400">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor"
                                                    stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M4.5 15.75l7.5-7.5 7.5 7.5" />
                                                </svg>
                                                {{ number_format($product->growth_pct, 0) }}%
                                            </span>
                                        @elseif ($product->trend === 'declining')
                                            <span
                                                class="inline-flex items-center gap-0.5 text-[10px] font-semibold text-red-600 dark:text-red-400">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor"
                                                    stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                                </svg>
                                                {{ number_format(abs($product->growth_pct), 0) }}%
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-0.5 text-[10px] text-stone-400">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor"
                                                    stroke-width="2.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M5 12h14" />
                                                </svg>
                                                Stabil
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-6 text-center text-[11px] text-stone-400">
                                        {{ __('Tidak ada data produk pada periode ini') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Legend --}}
                <div
                    class="mt-3 space-y-1 rounded-md border border-stone-100 bg-stone-50/50 p-2.5 dark:border-stone-800 dark:bg-stone-800/30">
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">Velocity</span> = Qty Terjual / Total Hari = rata-rata unit terjual
                        per hari
                    </p>
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">Konsistensi</span> = Hari Ada Penjualan / Total Hari × 100%
                    </p>
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">Tren</span> = Perbandingan qty paruh kedua vs paruh pertama periode
                        (bermakna untuk periode ≥ 7 hari)
                    </p>
                    <p class="font-mono text-[10px] text-stone-600 dark:text-stone-400">
                        <span class="font-semibold">Status</span> = Berdasarkan persentil velocity:
                        <span class="text-green-700 dark:text-green-400">Cepat</span> (top 25%),
                        <span class="text-blue-700 dark:text-blue-400">Normal</span> (mid 50%),
                        <span class="text-amber-700 dark:text-amber-400">Lambat</span> (bottom 25%),
                        <span class="text-red-700 dark:text-red-400">Dead</span> (0 penjualan)
                    </p>
                </div>
            </div>

            {{-- ════════════════════════════════════════════════ --}}
            {{-- CHEATSHEET                                     --}}
            {{-- ════════════════════════════════════════════════ --}}
            <div class="rounded-xl border border-stone-200 bg-white p-4 dark:border-stone-800 dark:bg-stone-900">
                <h2 class="mb-3 text-sm font-semibold text-stone-800 dark:text-stone-100">
                    {{ __('Cheatsheet Formula') }}
                </h2>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md border border-stone-200 p-2.5 dark:border-stone-700">
                        <p class="text-[10px] font-bold uppercase text-stone-700 dark:text-stone-300">Gross Margin</p>
                        <p class="mt-1 font-mono text-[10px] text-stone-600 dark:text-stone-400">
                            (Penjualan − HPP) / Penjualan × 100%
                        </p>
                    </div>
                    <div class="rounded-md border border-stone-200 p-2.5 dark:border-stone-700">
                        <p class="text-[10px] font-bold uppercase text-stone-700 dark:text-stone-300">Net Margin</p>
                        <p class="mt-1 font-mono text-[10px] text-stone-600 dark:text-stone-400">
                            (Penjualan − Total Biaya) / Penjualan × 100%
                        </p>
                    </div>
                    <div class="rounded-md border border-stone-200 p-2.5 dark:border-stone-700">
                        <p class="text-[10px] font-bold uppercase text-stone-700 dark:text-stone-300">Markup</p>
                        <p class="mt-1 font-mono text-[10px] text-stone-600 dark:text-stone-400">
                            (Harga − HPP) / HPP × 100%
                        </p>
                    </div>
                    <div class="rounded-md border border-stone-200 p-2.5 dark:border-stone-700">
                        <p class="text-[10px] font-bold uppercase text-stone-700 dark:text-stone-300">Break-Even</p>
                        <p class="mt-1 font-mono text-[10px] text-stone-600 dark:text-stone-400">
                            Fixed Cost / (Harga − Var. Cost)
                        </p>
                    </div>
                </div>
            </div>

        @endif
    </div>
</div>

@script
    <script>
        Alpine.data('salesChart', ({
            data,
            type
        }) => ({
            chart: null,
            data: data,
            type: type,
            _observer: null,
            _resizeObserver: null,
            _lastBreakpoint: null,

            init() {
                this.$nextTick(() => {
                    this._lastBreakpoint = this.detectBreakpoint();
                    this.render();
                });
                this.$watch('data', () => this.render());
                this.$watch('type', () => this.render());
                this._observer = new MutationObserver(() => this.render());
                this._observer.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class']
                });
                this._resizeObserver = new ResizeObserver(() => {
                    const bp = this.detectBreakpoint();
                    if (bp !== this._lastBreakpoint) {
                        this._lastBreakpoint = bp;
                        this.render();
                    } else if (this.chart) {
                        this.chart.updateOptions({
                            chart: {
                                width: '100%'
                            }
                        });
                    }
                });
                this._resizeObserver.observe(this.$refs.chart);
            },

            destroy() {
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
                }
                if (this._observer) {
                    this._observer.disconnect();
                    this._observer = null;
                }
                if (this._resizeObserver) {
                    this._resizeObserver.disconnect();
                    this._resizeObserver = null;
                }
            },

            detectBreakpoint() {
                const el = this.$refs.chart;
                if (!el) return 'desktop';
                const w = el.clientWidth || window.innerWidth;
                if (w < 640) return 'mobile';
                if (w < 1024) return 'tablet';
                return 'desktop';
            },

            render() {
                if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
                }
                const container = this.$refs.chart;
                if (!container) return;
                container.innerHTML = '';
                if (!this.data || this.data.length === 0) {
                    container.innerHTML =
                        '<div class="flex h-[320px] items-center justify-center text-xs text-stone-400">Tidak ada data pada periode ini</div>';
                    return;
                }
                this.chart = new ApexCharts(container, this.buildOptions(this.buildSeries()));
                this.chart.render();
            },

            buildSeries() {
                if (this.type === 'candlestick') {
                    return [{
                        name: 'Revenue',
                        data: this.data.map(d => ({
                            x: d.label,
                            y: [d.o, d.h, d.l, d.c]
                        }))
                    }];
                }
                return [{
                    name: 'Revenue',
                    data: this.data.map(d => ({
                        x: d.label,
                        y: d.revenue
                    }))
                }];
            },

            buildOptions(series) {
                const isCandle = this.type === 'candlestick';
                const isBar = this.type === 'bar';
                const isDark = document.documentElement.classList.contains('dark');
                const bp = this.detectBreakpoint();
                const isMobile = bp === 'mobile';
                const isTablet = bp === 'tablet';
                const chartHeight = isMobile ? 280 : (isTablet ? 320 : 360);
                const labelRotate = isMobile ? -90 : (isTablet ? -45 : -30);
                const labelRotateAlways = isMobile;
                const labelFontSize = isMobile ? '9px' : '10px';
                const maxTicks = isMobile ? 6 : (isTablet ? 10 : 15);
                const gridPadding = isMobile ? {
                    left: 4,
                    right: 4,
                    bottom: 30
                } : {
                    left: 8,
                    right: 8,
                    bottom: 10
                };

                return {
                    series: series,
                    chart: {
                        type: this.type,
                        height: chartHeight,
                        width: '100%',
                        parentHeightOffset: 0,
                        redrawOnParentResize: true,
                        redrawOnWindowResize: true,
                        toolbar: {
                            show: false
                        },
                        fontFamily: 'inherit',
                        background: 'transparent',
                        animations: {
                            enabled: true,
                            speed: 400
                        },
                    },
                    theme: {
                        mode: isDark ? 'dark' : 'light'
                    },
                    plotOptions: {
                        candlestick: {
                            colors: {
                                upward: '#16a34a',
                                downward: '#dc2626'
                            },
                            wick: {
                                useFillColor: true
                            }
                        },
                        bar: {
                            columnWidth: isMobile ? '70%' : '60%',
                            borderRadius: 3
                        },
                    },
                    stroke: {
                        width: isBar ? 0 : (isCandle ? 1 : 2),
                        curve: 'smooth'
                    },
                    colors: isCandle ? undefined : ['#84a98c'],
                    dataLabels: {
                        enabled: false
                    },
                    markers: {
                        size: isBar || isCandle ? 0 : (isMobile ? 2 : 4),
                        colors: ['#84a98c'],
                        strokeColors: isDark ? '#1c1917' : '#fff',
                        strokeWidth: 2
                    },
                    xaxis: {
                        type: 'category',
                        tickAmount: maxTicks,
                        labels: {
                            style: {
                                fontSize: labelFontSize,
                                colors: isDark ? '#a8a29e' : '#78716c',
                                fontWeight: 400
                            },
                            rotate: labelRotate,
                            rotateAlways: labelRotateAlways,
                            trim: true,
                            hideOverlappingLabels: true,
                            maxHeight: isMobile ? 80 : 60,
                            offsetY: 0
                        },
                        axisBorder: {
                            show: false
                        },
                        axisTicks: {
                            show: false
                        },
                    },
                    yaxis: {
                        labels: {
                            style: {
                                fontSize: labelFontSize,
                                colors: isDark ? '#a8a29e' : '#78716c'
                            },
                            formatter: (val) => {
                                if (Math.abs(val) >= 1_000_000) return 'Rp ' + (val / 1_000_000).toFixed(
                                    1) + 'jt';
                                if (Math.abs(val) >= 1_000) return 'Rp ' + (val / 1_000).toFixed(0) + 'rb';
                                return 'Rp ' + val;
                            }
                        },
                    },
                    grid: {
                        borderColor: isDark ? '#292524' : '#e7e5e4',
                        strokeDashArray: 4,
                        xaxis: {
                            lines: {
                                show: false
                            }
                        },
                        yaxis: {
                            lines: {
                                show: true
                            }
                        },
                        padding: gridPadding
                    },
                    legend: {
                        show: !isMobile,
                        fontSize: '10px'
                    },
                    tooltip: {
                        theme: isDark ? 'dark' : 'light',
                        style: {
                            fontSize: isMobile ? '10px' : '11px'
                        },
                        y: {
                            formatter: (val, opts) => {
                                if (isCandle) {
                                    const point = opts.w.config.series[opts.seriesIndex].data[opts
                                        .dataPointIndex];
                                    const [o, h, l, c] = point.y;
                                    const fmt = (n) => 'Rp ' + Number(n).toLocaleString('id-ID');
                                    if (isMobile)
                                    return `O: ${fmt(o)} H: ${fmt(h)} L: ${fmt(l)} C: ${fmt(c)}`;
                                    return [`Open: ${fmt(o)}`, `High: ${fmt(h)}`, `Low: ${fmt(l)}`,
                                        `Close: ${fmt(c)}`
                                    ].join(' | ');
                                }
                                return 'Rp ' + Number(val).toLocaleString('id-ID');
                            },
                        },
                    },
                };
            },
        }));
    </script>
@endscript

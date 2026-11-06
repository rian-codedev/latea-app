<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\Title;
use Livewire\Attributes\Locked;
use App\Models\Store;
use App\Models\Product;
use App\Models\StoreProduct;

new #[Title('Manage Store Products')] class extends Component {
    use WithPagination;
    #[Locked]
    public int $storeId;

    public $search;
    public $filterAvailable = "";
    public $filterStatus = ""; // 'assigned' | 'not_assigned' | ''

    public function mount(int $store)
    {
        $this->storeId = $store;
    }

    public function detachProduct(int $productId)
    {
        StoreProduct::where('store_id', $this->storeId)
            ->where('product_id', $productId)
            ->delete();

        session()->flash('success', 'Produk berhasil dihapus dari toko ini.');
        $this->dispatch('product-detached');
    }

    public function toggleAvailable(int $productId)
    {
        $pivot = StoreProduct::where('store_id', $this->storeId)
            ->where('product_id', $productId)
            ->first();

        if ($pivot) {
            $pivot->update(['is_available' => !$pivot->is_available]);
            $this->dispatch('availability-toggled');
        }
    }

    public function with()
    {
        $store = Store::findOrFail($this->storeId);

        // Query produk yang SUDAH di-assign ke toko ini
        $assignedProducts = Product::query()
            ->whereHas('stores', fn ($q) => $q->where('store_id', $this->storeId))
            ->with(['stores' => fn ($q) => $q->where('store_id', $this->storeId)])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%' . $this->search . '%')
                      ->orWhere('code', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->filterAvailable !== "", function ($query) {
                $query->whereHas('stores', function ($q) {
                    $q->where('store_id', $this->storeId)
                      ->wherePivot('is_available', $this->filterAvailable);
                });
            })
            ->latest()
            ->paginate(10);

        return [
            'store' => $store,
            'products' => $assignedProducts,
        ];
    }
};
?>

<div>
    <x-page-header :title="'Products di ' . $store->name" leading="Kelola produk yang dijual di toko ini" />

    <div class="mx-auto mt-2 max-w-7xl space-y-2">

        {{-- Breadcrumb / Back --}}
        <div class="flex items-center gap-2 text-[11px] text-stone-500 dark:text-stone-400">
            <a href="{{ route('admin.stores') }}" wire:navigate
                class="hover:text-sage-600 dark:hover:text-sage-400 flex items-center gap-1 transition">
                <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                {{ __('Kembali ke Manage Stores') }}
            </a>
            <span class="text-stone-300 dark:text-stone-600">/</span>
            <span class="text-stone-700 dark:text-stone-300">{{ $store->name }}</span>
        </div>

        {{-- Store Info --}}
        <div class="rounded-xl border border-stone-200 bg-white px-3 py-3 dark:border-stone-800 dark:bg-stone-900">
            <div class="flex items-center gap-3">
                <div class="bg-sage-100 dark:bg-sage-900/60 text-sage-700 dark:text-sage-400 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold uppercase">
                    {{ mb_substr($store->name, 0, 2) }}
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-sm font-semibold text-stone-800 dark:text-stone-100">{{ $store->name }}</h2>
                    <p class="truncate text-[11px] text-stone-500 dark:text-stone-400">
                        {{ $store->code }} · {{ $store->location }}
                    </p>
                </div>
            </div>
        </div>

        {{-- FLASH MESSAGE --}}
        @if (session('success'))
            <div class="bg-sage-50 dark:bg-sage-950 border-sage-200 dark:border-sage-800 text-sage-700 dark:text-sage-300 flex items-center gap-2.5 rounded-xl border px-3 py-2 text-xs">
                <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                {{ session('success') }}
            </div>
        @endif

        {{-- FILTER BAR --}}
        <div class="rounded-xl border border-stone-200 bg-white px-3 py-3 dark:border-stone-800 dark:bg-stone-900">
            <div class="flex flex-col gap-2 sm:flex-row">

                {{-- Search --}}
                <div class="relative flex-1">
                    <svg class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-stone-400 dark:text-stone-500"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" />
                    </svg>
                    <input wire:model.live.debounce.300ms="search" type="text"
                        placeholder="Cari nama atau code produk…"
                        class="focus:ring-sage-500 w-full rounded-lg border border-stone-200 bg-stone-50 py-1.5 pl-8 pr-3 text-xs text-stone-800 transition placeholder:text-stone-400 focus:border-transparent focus:outline-none focus:ring-1 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-100 dark:placeholder:text-stone-500" />
                </div>

                {{-- Filter Availability --}}
                <select wire:model.live="filterAvailable"
                    class="focus:ring-sage-500 rounded-lg border border-stone-200 bg-stone-50 px-2.5 py-1.5 text-xs text-stone-700 transition focus:outline-none focus:ring-1 dark:border-stone-700 dark:bg-stone-800 dark:text-stone-300">
                    <option value="">Semua Ketersediaan</option>
                    <option value="1">Tersedia</option>
                    <option value="0">Habis</option>
                </select>

                <a x-data x-on:click="$dispatch('attach-product-modal', { storeId: {{ $store->id }} })"
                    class="bg-sage-600 hover:bg-sage-700 dark:bg-sage-500 dark:hover:bg-sage-600 inline-flex cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Attach Product
                </a>

            </div>
        </div>

        {{-- TABLE CARD --}}
        <div class="overflow-hidden rounded-xl border border-stone-200 bg-white px-4 dark:border-stone-800 dark:bg-stone-900">

            {{-- Table meta --}}
            <div class="flex items-center justify-between border-b border-stone-100 py-2 dark:border-stone-800">
                <span class="font-mono text-[10px] uppercase tracking-wider text-stone-400 dark:text-stone-500">
                    {{ $products->total() }} {{ __('products found') }}
                </span>
                <div wire:loading class="text-sage-600 dark:text-sage-400 flex items-center gap-1 text-[11px]">
                    <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                    </svg>
                    {{ __('Memuat…') }}
                </div>
            </div>

            {{-- Table --}}
            <div class="overflow-x-auto rounded-xl border bg-white shadow-sm dark:border-stone-800 dark:bg-stone-900">
                <table x-data
                    @product-detached.window="$wire.$refresh()"
                    @availability-toggled.window="$wire.$refresh()"
                    @product-attached.window="$wire.$refresh()"
                    class="w-full border-collapse text-left text-[11px]">
                    <thead>
                        <tr class="border-b border-stone-200 bg-stone-50 font-semibold uppercase tracking-wider text-stone-500 dark:border-stone-800 dark:bg-stone-800/50 dark:text-stone-400">
                            <th class="w-6 px-2.5 py-1.5 text-center">#</th>
                            <th class="px-2.5 py-1.5">{{ __('Product') }}</th>
                            <th class="hidden px-2.5 py-1.5 sm:table-cell">{{ __('Code') }}</th>
                            <th class="hidden px-2.5 py-1.5 md:table-cell text-right">{{ __('Harga Default') }}</th>
                            <th class="hidden px-2.5 py-1.5 md:table-cell text-right">{{ __('Harga Toko Ini') }}</th>
                            <th class="hidden w-20 px-2.5 py-1.5 text-center sm:table-cell">{{ __('Tersedia') }}</th>
                            <th class="w-20 px-2.5 py-1.5 text-right">{{ __('Aksi') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100 dark:divide-stone-800/60">
                        @forelse ($products as $product)
                            @php
                                $pivot = $product->stores->first()?->pivot;
                            @endphp
                            <tr class="group transition-colors hover:bg-stone-50 dark:hover:bg-stone-800/30">

                                {{-- No --}}
                                <td class="px-2.5 py-1.5 text-center font-mono text-stone-400 dark:text-stone-600">
                                    {{ $loop->iteration + ($products->currentPage() - 1) * $products->perPage() }}
                                </td>

                                {{-- Product --}}
                                <td class="px-2.5 py-1.5">
                                    <div class="max-w-45 flex items-center gap-2 sm:max-w-xs">
                                        @if ($product->image)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($product->image) }}"
                                                alt="{{ $product->name }}"
                                                class="h-6 w-6 shrink-0 rounded-md border border-stone-200 object-cover dark:border-stone-700" />
                                        @else
                                            <div class="bg-sage-100 dark:bg-sage-900/60 text-sage-700 dark:text-sage-400 flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-[9px] font-bold uppercase">
                                                {{ mb_substr($product->name, 0, 2) }}
                                            </div>
                                        @endif
                                        <div class="truncate">
                                            <div class="truncate font-medium text-stone-800 dark:text-stone-200">
                                                {{ $product->name }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                {{-- Code --}}
                                <td class="hidden px-2.5 py-1.5 font-mono text-stone-500 sm:table-cell dark:text-stone-400">
                                    {{ $product->code }}
                                </td>

                                {{-- Harga Default --}}
                                <td class="hidden px-2.5 py-1.5 md:table-cell text-right font-mono text-stone-500 dark:text-stone-400">
                                    {{ $product->price }}
                                </td>

                                {{-- Harga Toko Ini --}}
                                <td class="hidden px-2.5 py-1.5 md:table-cell text-right">
                                    @if ($pivot && $pivot->price)
                                        <span class="font-mono font-medium text-sage-700 dark:text-sage-400">
                                            Rp {{ $pivot->price }}
                                        </span>
                                    @else
                                        <span class="text-[10px] italic text-stone-400 dark:text-stone-500">
                                            (default)
                                        </span>
                                    @endif
                                </td>

                                {{-- Tersedia --}}
                                <td class="hidden px-2.5 py-1.5 text-center sm:table-cell">
                                    <button wire:click="toggleAvailable({{ $product->id }})"
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-semibold transition
                                            {{ $pivot?->is_available
                                                ? 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-950/40 dark:text-green-400 dark:hover:bg-green-950/60'
                                                : 'bg-stone-100 text-stone-600 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-400 dark:hover:bg-stone-700' }}"
                                        title="Klik untuk toggle ketersediaan">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $pivot?->is_available ? 'bg-green-500' : 'bg-stone-400 dark:bg-stone-500' }}"></span>
                                        {{ $pivot?->is_available ? __('Tersedia') : __('Habis') }}
                                    </button>
                                </td>

                                {{-- Aksi --}}
                                <td class="px-2.5 py-1.5 text-right">
                                    <div class="flex items-center justify-end" x-data="{ open: false }">
                                        <div class="relative inline-block text-left">

                                            {{-- Tombol Titik Tiga --}}
                                            <button @click="open = !open" @click.outside="open = false"
                                                class="cursor-pointer rounded-md p-1 text-stone-400 transition-colors hover:bg-stone-100 hover:text-stone-700 focus:outline-none dark:hover:bg-stone-800 dark:hover:text-stone-200"
                                                title="Menu Aksi">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                                                </svg>
                                            </button>

                                            {{-- Dropdown --}}
                                            <div x-show="open" x-transition:enter="transition ease-out duration-100"
                                                x-transition:enter-start="transform opacity-0 scale-95"
                                                x-transition:enter-end="transform opacity-100 scale-100"
                                                x-transition:leave="transition ease-in duration-75"
                                                x-transition:leave-start="transform opacity-100 scale-100"
                                                x-transition:leave-end="transform opacity-0 scale-95"
                                                class="absolute right-0 z-30 mt-1 w-40 origin-top-right rounded-md border border-stone-200 bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none dark:border-stone-800 dark:bg-stone-900"
                                                style="display: none;">
                                                <div class="space-y-0.5 p-1">

                                                    {{-- Edit Harga --}}
                                                    <button x-data
                                                        x-on:click="$dispatch('edit-store-product-modal', {
                                                            storeId: {{ $store->id }},
                                                            productId: {{ $product->id }},
                                                        })"
                                                        @click="open = false"
                                                        class="text-sage-600 dark:text-sage-400 hover:bg-sage-50 dark:hover:bg-sage-950/30 flex w-full cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-left text-xs transition-colors">
                                                        <svg class="text-sage-500 h-3.5 w-3.5" fill="none"
                                                            stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                        </svg>
                                                        Edit Harga
                                                    </button>

                                                    <flux:separator />

                                                    {{-- Detach --}}
                                                    <button wire:click="detachProduct({{ $product->id }})"
                                                        wire:confirm="Hapus produk ini dari toko?"
                                                        @click="open = false"
                                                        class="flex w-full cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-left text-xs text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40">
                                                        <svg class="h-3.5 w-3.5 text-red-400" fill="none"
                                                            stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                        Detach
                                                    </button>

                                                </div>
                                            </div>

                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-2.5 py-10 text-center">
                                    <div class="flex flex-col items-center gap-1.5 text-stone-400">
                                        <svg class="h-8 w-8 text-stone-300 dark:text-stone-600" fill="none"
                                            stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                        </svg>
                                        <p class="text-xs font-medium">{{ __('Belum ada produk di toko ini') }}</p>
                                        <p class="text-[11px] text-stone-400 dark:text-stone-500">
                                            {{ __('Klik "Attach Product" untuk menambahkan produk') }}
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="border-t border-stone-100 px-4 py-3 dark:border-stone-800">
                {{ $products->links('pagination::tailwind') }}
            </div>
        </div>

        <livewire:admin.store-products.attach-product :store-id="$store->id" />
        <livewire:admin.store-products.edit-store-product :store-id="$store->id" />
    </div>
</div>

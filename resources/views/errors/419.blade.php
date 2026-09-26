<x-layouts::error code="419" :title="__('Session has been Over')" :message="__(
    'This page has expired because your session timed out. Please refresh the page and try again.',
)">
    <div class="opacity-0-init animate-fade-up delay-600 mt-6">
        <button type="button" onclick="window.location.reload()"
            class="text-sage-600 dark:text-sage-400 hover:text-sage-700 dark:hover:text-sage-300 text-sm font-medium underline underline-offset-4">
            {{ __('Refresh Page') }} →
        </button>
    </div>
</x-layouts::error>

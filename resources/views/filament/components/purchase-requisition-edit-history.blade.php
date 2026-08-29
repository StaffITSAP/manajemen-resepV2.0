<div class="space-y-4">
    @forelse ($record->activityLogs as $log)
        <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
            <div class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ $log->action }}
            </div>
            <div class="mt-1 text-xs text-gray-500">
                PR #{{ $record->id }} &middot; {{ $log->user?->name ?? '-' }} &middot; {{ $log->created_at?->format('d/m/Y H:i') ?? '-' }}
            </div>
            <pre class="mt-3 whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{{ $log->summary ?: '-' }}</pre>
        </div>
    @empty
        <div class="text-sm text-gray-500">Belum ada riwayat edit.</div>
    @endforelse
</div>

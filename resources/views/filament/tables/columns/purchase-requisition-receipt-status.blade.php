@php
    use App\Models\PurchaseRequisition;

    $record = $getRecord();
    $canConfirm = $record instanceof PurchaseRequisition
        && auth()->user()?->can('confirmReceipt', $record) === true
        && $record->isReceiptEligible()
        && $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_PENDING;
@endphp

<div class="flex w-full justify-center">
    @if (! $record instanceof PurchaseRequisition || ! $record->isReceiptEligible())
        <span class="text-sm text-gray-500 dark:text-gray-400">-</span>
    @elseif ($canConfirm)
        <button
            type="button"
            aria-label="Konfirmasi Penerimaan Barang"
            class="rounded-md focus:outline-none focus:ring-2 focus:ring-warning-500/30"
            wire:click.stop.prevent="mountTableAction('confirmReceipt', '{{ $record->getKey() }}')"
        >
            <x-filament::badge color="warning" class="cursor-pointer transition duration-150 ease-out hover:scale-110">
                Belum Diterima
            </x-filament::badge>
        </button>
    @else
        <x-filament::badge :color="$record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_RECEIVED ? 'success' : 'warning'">
            {{ $record->receiptStatusOrDefault() === PurchaseRequisition::RECEIPT_STATUS_RECEIVED ? 'Diterima' : 'Belum Diterima' }}
        </x-filament::badge>
    @endif
</div>

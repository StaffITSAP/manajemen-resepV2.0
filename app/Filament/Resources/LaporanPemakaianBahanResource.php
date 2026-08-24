<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LaporanPemakaianBahanResource\Pages;
use App\Models\Produksi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use App\Services\Accurate\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;

class LaporanPemakaianBahanResource extends Resource
{
    protected static ?string $model = Produksi::class;

    protected static ?string $navigationIcon  = 'heroicon-o-document-chart-bar';
    protected static ?string $navigationLabel = 'Laporan Pemakaian Bahan';
    protected static ?string $label = 'Laporan Pemakaian Bahan';
    protected static ?string $navigationGroup = 'Laporan';
    protected static ?int    $navigationSort  = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Gate::allows('viewReportBahan', Produksi::class);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('dari_tanggal')->label('Dari Tanggal'),
                Forms\Components\DatePicker::make('sampai_tanggal')->label('Sampai Tanggal'),
                Forms\Components\Select::make('status')
                    ->options([
                        'selesai'  => 'Selesai',
                        'diproses' => 'Diproses',
                        'draft'    => 'Draft',
                        'batal'    => 'Batal',
                    ])
                    ->label('Status Produksi'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('nomor_produksi')
                    ->searchable()
                    ->label('No. Produksi'),
                Tables\Columns\TextColumn::make('accurate_number')
                    ->searchable()
                    ->label('No. Accurate')
                    ->description(fn(Produksi $record) => ($record->accurate_rollover_number ?? '-')),
                Tables\Columns\TextColumn::make('tanggal')
                    ->date('d F Y')
                    ->sortable()
                    ->label('Tanggal'),
                Tables\Columns\TextColumn::make('barang_setengah_jadi_list_with_selisih')
                    ->label('Barang 1/2 Jadi')
                    ->state(function (Produksi $r) {
                        $fmt = function ($v) {
                            $v = (float) ($v ?? 0);
                            return $v == (int) $v
                                ? number_format($v, 0, ',', '.')
                                : number_format($v, 2, ',', '.');
                        };

                        $rows = $r->itemProduksi->map(function ($it) use ($fmt) {
                            $nm   = $it->barang?->nama ?? '-';
                            $sat  = $it->barang?->satuan?->nama ?? '';
                            $ren  = (float) ($it->jumlah ?? 0);
                            $akt  = (float) ($it->jumlah_aktual ?? 0);
                            $sel  = $akt - $ren;
                            $unit = $sat ? " {$sat}" : '';

                            return "• <span class='font-medium'>{$nm}</span> "
                                . "<span class='text-xs text-gray-500'><br>"
                                . "Resep: {$fmt($ren)}{$unit} &middot; "
                                . "Produksi: {$fmt($akt)}{$unit} &middot; "
                                . "Selisih: {$fmt($sel)}{$unit}</span>";
                        });

                        return $rows->implode('<br>');
                    })
                    ->html()
                    ->wrap()
                    ->lineClamp(6)
                    ->extraAttributes(['class' => 'cursor-help'])
                    ->tooltip(function (Produksi $r) {
                        $fmt = function ($v) {
                            $v = (float) ($v ?? 0);
                            return $v == (int) $v
                                ? number_format($v, 0, ',', '.')
                                : number_format($v, 2, ',', '.');
                        };

                        $lines = $r->itemProduksi->map(function ($it) use ($fmt) {
                            $nm   = $it->barang?->nama ?? '-';
                            $sat  = $it->barang?->satuan?->nama ?? '';
                            $ren  = (float) ($it->jumlah ?? 0);
                            $akt  = (float) ($it->jumlah_aktual ?? 0);
                            $sel  = $akt - $ren;
                            $unit = $sat ? " {$sat}" : '';

                            return "• {$nm} — Resep: {$fmt($ren)}{$unit}; "
                                . "Produksi: {$fmt($akt)}{$unit}; "
                                . "Selisih: {$fmt($sel)}{$unit}";
                        });

                        return $lines->isEmpty() ? '-' : $lines->implode(PHP_EOL);
                    })
                    ->copyable()
                    ->copyableState(function (Produksi $r) {
                        $fmt = fn($v) => ((float)($v ?? 0) == (int)($v ?? 0))
                            ? number_format((float)$v, 0, ',', '.')
                            : number_format((float)$v, 2, ',', '.');

                        return $r->itemProduksi->map(function ($it) use ($fmt) {
                            $nm  = $it->barang?->nama ?? '-';
                            $sat = $it->barang?->satuan?->nama ?? '';
                            $ren = (float)($it->jumlah ?? 0);
                            $akt = (float)($it->jumlah_aktual  ?? 0);
                            $sel = $akt - $ren;
                            $unit = $sat ? " {$sat}" : '';

                            return "• {$nm}\n"
                                . "  - Resep: {$fmt($ren)}{$unit}\n"
                                . "  - Produksi: {$fmt($akt)}{$unit}\n"
                                . "  - Selisih: {$fmt($sel)}{$unit}";
                        })->implode("\n\n");
                    })
                    ->copyMessage('Berhasil disalin ke clipboard')
                    ->copyMessageDuration(1500)
                    ->searchable(
                        // jangan pakai nama kolom virtual, pakai custom query:
                        query: function (Builder $query, string $search): Builder {
                            return $query->whereHas('itemProduksi.barang', function (Builder $q) use ($search) {
                                $q->where('nama', 'like', "%{$search}%");
                            });
                        },
                    ),

                Tables\Columns\TextColumn::make('bahan_list_db')
                    ->label('Bahan yang Dibutuhkan')
                    ->state(fn(\App\Models\Produksi $r) => $r->bahan_list_db)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->lineClamp(6)
                    ->tooltip(fn(Produksi $r) => $r->bahan_list)
                    ->copyable()
                    ->copyableState(fn(Produksi $r) => $r->bahan_list)
                    ->copyMessage('Bahan dibutuhkan disalin')
                    ->copyMessageDuration(1500),

                Tables\Columns\TextColumn::make('bahan_pemakaian_aktual_selisih_list')
                    ->label('Pemakaian Bahan (Aktual) & Selisih')
                    ->state(fn(Produksi $r) => $r->bahan_pemakaian_aktual_selisih_list)
                    ->html()
                    ->formatStateUsing(fn($state) => nl2br(e($state)))
                    ->wrap()
                    ->lineClamp(6)
                    ->tooltip(fn(Produksi $r) => $r->bahan_pemakaian_aktual_selisih_list)
                    ->copyable()
                    ->copyableState(fn(Produksi $r) => $r->bahan_pemakaian_aktual_selisih_list)
                    ->copyMessage('Berhasil disalin ke clipboard')
                    ->copyMessageDuration(1500),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->default('draft')
                    ->color(fn(string $state): string => match ($state) {
                        'draft'    => 'gray',
                        'diproses' => 'warning',
                        'selesai'  => 'success',
                        'batal'    => 'danger',
                        default    => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\Filter::make('tanggal')
                    ->form([
                        Forms\Components\DatePicker::make('dari_tanggal'),
                        Forms\Components\DatePicker::make('sampai_tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['dari_tanggal'] ?? null,
                                fn(Builder $query, $date): Builder => $query->whereDate('tanggal', '>=', $date),
                            )
                            ->when(
                                $data['sampai_tanggal'] ?? null,
                                fn(Builder $query, $date): Builder => $query->whereDate('tanggal', '<=', $date),
                            );
                    }),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft'    => 'Draft',
                        'diproses' => 'Diproses',
                        'selesai'  => 'Selesai',
                        'batal'    => 'Batal',
                    ]),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('lihat_detail')
                        ->label('Detail Pemakaian')
                        ->icon('heroicon-o-eye')
                        ->modalHeading('Detail Pemakaian Bahan')
                        ->modalContent(fn(Produksi $record) => view(
                            'filament.components.detail-pemakaian-bahan',
                            ['produksi' => $record]
                        ))
                        ->slideOver()
                        ->modalWidth('screen')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Tutup')
                        ->extraModalFooterActions([
                            Tables\Actions\Action::make('print')
                                ->label('Print')
                                ->icon('heroicon-o-printer')
                                ->color('gray')
                                ->visible(fn() => Gate::allows('exportReportBahan', Produksi::class))
                                ->authorize(fn() => Gate::allows('exportReportBahan', Produksi::class))
                                ->url(fn(Produksi $record) => route('produksi.print', [
                                    'record' => $record->getKey(),
                                ]))
                                ->openUrlInNewTab(),
                            self::postJobOrderAction(),
                            self::postRollOverAction(),
                            Tables\Actions\Action::make('hapus_accurate_number')
                                ->label('Hapus No Accurate')
                                ->icon('heroicon-o-trash')
                                ->color('danger')
                                ->requiresConfirmation()
                                ->modalHeading('Hapus Nomor Accurate')
                                ->modalDescription(fn(\App\Models\Produksi $record) => sprintf(
                                    "Tindakan ini akan mengosongkan kedua kolom:\n- No Job Order: %s\n- No Rollover: %s\nLanjutkan?",
                                    $record->accurate_number ?: '—',
                                    $record->accurate_rollover_number ?: '—'
                                ))
                                ->visible(fn() => auth()->user()?->hasRole('superadmin'))
                                ->disabled(
                                    fn(\App\Models\Produksi $record) =>
                                    blank($record->accurate_number) && blank($record->accurate_rollover_number)
                                )
                                ->tooltip(
                                    fn(\App\Models\Produksi $record) => (blank($record->accurate_number) && blank($record->accurate_rollover_number))
                                        ? 'Belum ada nomor Accurate yang bisa dihapus.'
                                        : 'Hapus nomor Accurate pada record ini.'
                                )
                                ->action(function (\App\Models\Produksi $record) {
                                    DB::transaction(function () use ($record) {
                                        /** @var \App\Models\Produksi $locked */
                                        $locked = \App\Models\Produksi::query()
                                            ->whereKey($record->getKey())
                                            ->lockForUpdate()
                                            ->firstOrFail();

                                        $locked->forceFill([
                                            'accurate_number'          => null,
                                            'accurate_rollover_number' => null,
                                        ])->save();
                                    });

                                    $nomor = $record->nomor ?? 'record ini';

                                    \Filament\Notifications\Notification::make()
                                        ->title('Nomor Accurate dihapus')
                                        ->body('No Job Order dan No Rollover untuk ' . $nomor . ' berhasil dikosongkan.')
                                        ->success()
                                        ->send();

                                    // ⬇⬇⬇ BALIK KE LIST RESOURCE
                                    return redirect()->to(self::getUrl('index'));
                                    // atau cukup: return redirect(self::getUrl());
                                }),
                        ]),
                    self::postJobOrderAction(),
                    self::postRollOverAction(),
                    Tables\Actions\Action::make('hapus_accurate_number')
                        ->label('Hapus No Accurate')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Hapus Nomor Accurate')
                        ->modalDescription(fn(\App\Models\Produksi $record) => sprintf(
                            "Tindakan ini akan mengosongkan kedua kolom:\n- No Job Order: %s\n- No Rollover: %s\nLanjutkan?",
                            $record->accurate_number ?: '—',
                            $record->accurate_rollover_number ?: '—'
                        ))
                        ->visible(fn() => auth()->user()?->hasRole('superadmin'))
                        ->disabled(
                            fn(\App\Models\Produksi $record) =>
                            blank($record->accurate_number) && blank($record->accurate_rollover_number)
                        )
                        ->tooltip(
                            fn(\App\Models\Produksi $record) => (blank($record->accurate_number) && blank($record->accurate_rollover_number))
                                ? 'Belum ada nomor Accurate yang bisa dihapus.'
                                : 'Hapus nomor Accurate pada record ini.'
                        )
                        ->action(function (\App\Models\Produksi $record) {
                            DB::transaction(function () use ($record) {
                                /** @var \App\Models\Produksi $locked */
                                $locked = \App\Models\Produksi::query()
                                    ->whereKey($record->getKey())
                                    ->lockForUpdate()
                                    ->firstOrFail();

                                $locked->forceFill([
                                    'accurate_number'          => null,
                                    'accurate_rollover_number' => null,
                                ])->save();
                            });

                            $nomor = $record->nomor ?? 'record ini';

                            \Filament\Notifications\Notification::make()
                                ->title('Nomor Accurate dihapus')
                                ->body('No Job Order dan No Rollover untuk ' . $nomor . ' berhasil dikosongkan.')
                                ->success()
                                ->send();

                            // ⬇⬇⬇ BALIK KE LIST RESOURCE
                            return redirect()->to(self::getUrl('index'));
                            // atau cukup: return redirect(self::getUrl());
                        }),
                ])
            ])
            ->actionsPosition(ActionsPosition::BeforeColumns)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\Action::make('exportExcel')
                        ->label('Export Excel')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn() => Gate::allows('exportReportBahan', Produksi::class))
                        ->authorize(fn() => Gate::allows('exportReportBahan', Produksi::class))
                        ->action(function (Collection $records, array $data) {
                            abort_unless(Gate::allows('exportReportBahan', Produksi::class), 403);
                            // TODO: isi logic export
                        })
                        ->form([
                            Forms\Components\DatePicker::make('dari_tanggal'),
                            Forms\Components\DatePicker::make('sampai_tanggal'),
                        ]),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLaporanPemakaianBahans::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'itemProduksi.barang',
            ]);
    }

    /***************
     *  ACCURATE HELPERS
     ***************/
    /*******************
     * ROLL-OVER HELPERS
     *******************/

    private static function isDuplicateRolloverError($resp): bool
    {
        $s = is_array($resp) ? json_encode($resp) : (string)$resp;
        $s = mb_strtolower($s);

        return str_contains($s, 'hanya diperbolehkan satu penyelesaian pekerjaan')
            || str_contains($s, 'only one')
            || str_contains($s, 'duplicate')
            || str_contains($s, 'already')
            || str_contains($s, 'exists');
    }

    private static function fetchRolloverNumberByJobOrder(string $jobOrderNumber): ?string
    {
        [$url, $qs] = self::accurateEndpoint('roll-over/list.do');
        $headers    = self::accurateHeaders();

        $params = [
            'fields'      => 'number,jobOrderNumber,transDate',
            'sp.page'     => 1,
            'sp.pageSize' => 1,
            'sort'        => 'number|desc',
            'filter.jobOrderNumber' => $jobOrderNumber,
        ];

        $resp = Http::withHeaders($headers)
            ->connectTimeout(10)->timeout(30)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&' . http_build_query($params) : ('?' . http_build_query($params))));

        if (!$resp->ok()) return null;

        $json = $resp->json() ?: [];
        $rows = $json['d'] ?? $json['data'] ?? $json['value'] ?? [];

        return isset($rows[0]['number']) ? (string)$rows[0]['number'] : null;
    }

    private static function fetchLastRolloverNumber(): ?string
    {
        [$url, $qs] = self::accurateEndpoint('roll-over/list.do');
        $headers    = self::accurateHeaders();

        $params = [
            'fields'      => 'number',
            'sp.page'     => 1,
            'sp.pageSize' => 1,
            'sort'        => 'number|desc',
        ];

        $resp = Http::withHeaders($headers)
            ->connectTimeout(10)->timeout(30)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&' . http_build_query($params) : ('?' . http_build_query($params))));

        if (!$resp->ok()) return null;

        $json = $resp->json() ?: [];
        $rows = $json['d'] ?? $json['data'] ?? $json['value'] ?? [];

        return isset($rows[0]['number']) ? (string)$rows[0]['number'] : null;
    }

    private static function parseDocNumber(array $json): ?string
    {
        $number = $json['number']
            ?? ($json['data']['number'] ?? null)
            ?? ($json['d']['number'] ?? null)
            ?? (isset($json['value'][0]['number']) ? $json['value'][0]['number'] : null);

        if (!$number) {
            $flat = json_encode($json);
            if ($flat && preg_match('/\b[A-Z]{2}\.[\d\.]+\b/u', $flat, $m)) {
                $number = $m[0];
            }
        }
        return $number ? (string)$number : null;
    }

    private static function accurateHeaders(): array
    {
        $tz  = (string) config('accurate.tz', 'Asia/Jakarta');
        $ts  = Signature::makeTimestamp($tz);
        $sig = Signature::hmac($ts, (string) config('accurate.secret'));

        return [
            'Authorization'   => 'Bearer ' . (string) config('accurate.token'),
            'X-Api-AppKey'    => (string) config('accurate.app_key'),
            'X-Api-Timestamp' => $ts,
            'X-Api-Signature' => $sig,
            'Accept'          => 'application/json',
            'Content-Type'    => 'application/json',
        ];
    }

    private static function accurateEndpoint(string $path): array
    {
        $base = rtrim((string) config('accurate.base_url', 'https://zeus.accurate.id/accurate/api'), '/');
        $url  = $base . '/' . ltrim($path, '/');

        $query = [];
        if ($dbId = config('accurate.db_id')) {
            $query['dbId'] = $dbId;
        }
        $qs = $query ? ('?' . http_build_query($query)) : '';

        return [$url, $qs];
    }

    private static function fetchLastAccurateNumber(): ?string
    {
        [$url, $qs] = self::accurateEndpoint('job-order/list.do');
        $headers = self::accurateHeaders();

        $params = [
            'fields'      => 'number',
            'sp.page'     => 1,
            'sp.pageSize' => 1,
            'sort'        => 'number|desc',
        ];

        $resp = Http::withHeaders($headers)
            ->timeout((int) config('accurate.timeout', 60))
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&' . http_build_query($params) : ('?' . http_build_query($params))));

        if (!$resp->ok()) return null;

        $json = $resp->json() ?: [];
        $rows = $json['data'] ?? $json['d'] ?? $json['value'] ?? null;

        if (is_array($rows) && isset($rows[0]['number'])) return (string) $rows[0]['number'];
        if (isset($json['number'])) return (string) $json['number'];

        return null;
    }

    private static function parseAccurateNumber(array $json): ?string
    {
        $number = $json['number']
            ?? ($json['data']['number'] ?? null)
            ?? ($json['d']['number'] ?? null)
            ?? ($json['result']['number'] ?? null)
            ?? (isset($json['value'][0]['number']) ? $json['value'][0]['number'] : null);

        if (!$number) {
            $flat = json_encode($json);
            if ($flat && preg_match('/JC\.[\w\.\-]+/', $flat, $m)) {
                $number = $m[0];
            }
        }
        return $number ? (string) $number : null;
    }

    /* ===================== Gudang Default ===================== */

    private static ?array $warehouseDefault = null;
    private static ?array $warehouseCache   = null;

    private static function norm(?string $s): string
    {
        $s = (string) $s;
        $s = preg_replace('/\s+/', ' ', trim($s));
        return mb_strtolower($s);
    }

    private static function fetchWarehouseById(int|string $id): ?array
    {
        [$url, $qs] = self::accurateEndpoint('warehouse/detail.do');
        $headers    = self::accurateHeaders();

        $params = ['id' => $id];

        $resp = Http::withHeaders($headers)
            ->connectTimeout(10)->timeout(30)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&' . http_build_query($params) : ('?' . http_build_query($params))));

        if (!$resp->ok()) {
            Log::warning('Gagal fetch warehouse/detail.do', ['id' => $id, 'http' => $resp->status(), 'body' => $resp->body()]);
            return null;
        }

        $d = $resp->json()['d'] ?? null;
        if (!$d || !isset($d['id'])) return null;

        return [
            'id'   => $d['id'],
            'name' => $d['name'] ?? ($d['description'] ?? null),
            'code' => $d['name'] ?? null,
        ];
    }

    private static function fetchWarehouses(): array
    {
        if (self::$warehouseCache !== null) return self::$warehouseCache;

        [$url, $qs] = self::accurateEndpoint('warehouse/list.do');
        $headers    = self::accurateHeaders();
        $params     = [
            'fields'      => 'id,name,description,locationId',
            'sp.page'     => 1,
            'sp.pageSize' => 200,
        ];

        $resp = Http::withHeaders($headers)
            ->connectTimeout(10)->timeout(30)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&' . http_build_query($params) : ('?' . http_build_query($params))));

        if (!$resp->ok()) {
            Log::warning('Gagal fetch warehouse/list.do', ['http' => $resp->status(), 'body' => $resp->body()]);
            return self::$warehouseCache = [];
        }

        $rows = $resp->json()['d'] ?? $resp->json()['data'] ?? [];
        return self::$warehouseCache = array_map(function ($r) {
            $nm = $r['name'] ?? ($r['description'] ?? null);
            return [
                'id'   => $r['id'] ?? null,
                'name' => $nm,
                'code' => $nm,
                '_nid' => self::norm(isset($r['id']) ? (string) $r['id'] : ''),
                '_nnm' => self::norm($nm ?? ''),
            ];
        }, $rows);
    }

    private static function resolveDefaultWarehouse(): ?array
    {
        if (self::$warehouseDefault !== null) return self::$warehouseDefault;

        $wantId = trim((string) config('accurate.default_warehouse_id'));
        if ($wantId !== '') {
            $byId = self::fetchWarehouseById($wantId);
            if ($byId) return self::$warehouseDefault = $byId;

            Log::warning('warehouse/detail.do tidak menemukan ID', ['id' => $wantId]);
        }

        $wantName = self::norm((string) config('accurate.default_warehouse', 'KITCHEN'));
        if ($wantName !== '') {
            $list = self::fetchWarehouses();
            foreach ($list as $w) {
                if ($w['_nnm'] === $wantName) return self::$warehouseDefault = $w;
            }
            foreach ($list as $w) {
                if ($w['_nnm'] !== '' && str_contains($w['_nnm'], $wantName)) {
                    return self::$warehouseDefault = $w;
                }
            }
        }

        return self::$warehouseDefault = null;
    }

    protected static function parseAccurateTotalAmount(array $json): ?float
    {
        $candidates = [
            $json['r']['totalAmount'] ?? null,
            $json['data']['totalAmount'] ?? null,
            $json['value']['totalAmount'] ?? null,
            $json['totalAmount'] ?? null,
            $json['r']['total'] ?? null,
            $json['r']['grandTotal'] ?? null,
        ];

        foreach ($candidates as $val) {
            $num = self::normalizeNumberOrNull($val);
            if ($num !== null) return $num;
        }
        return null;
    }

    protected static function fetchAccurateJobOrderTotalByNumber(string $jobNumber): ?float
    {
        [$url, $qs] = self::accurateEndpoint('job-order/detail.do');
        $headers = self::accurateHeaders();

        $query = $qs . (str_contains($qs, '?') ? '&' : '?') . 'number=' . urlencode($jobNumber);

        $resp = \Illuminate\Support\Facades\Http::withHeaders($headers)
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . $query);

        if (!$resp->ok()) {
            return null;
        }

        $json = $resp->json() ?: [];

        $candidates = [
            $json['r']['totalAmount'] ?? null,
            $json['r']['total'] ?? null,
            $json['r']['grandTotal'] ?? null,
            $json['totalAmount'] ?? null,
        ];

        foreach ($candidates as $val) {
            $num = self::normalizeNumberOrNull($val);
            if ($num !== null) return $num;
        }

        return null;
    }

    protected static function parseAccurateAmountFlexible(array $json): ?float
    {
        $candidates = [
            $json['r']['totalAmount'] ?? null,
            $json['r']['grandTotal'] ?? null,
            $json['r']['total'] ?? null,
            $json['totalAmount'] ?? null,
            $json['grandTotal'] ?? null,
            $json['total'] ?? null,
            $json['data']['totalAmount'] ?? null,
            $json['value']['totalAmount'] ?? null,
        ];

        foreach ($candidates as $v) {
            $num = self::normalizeNumberOrNull($v);
            if ($num !== null) return $num;
        }
        return null;
    }

    protected static function fetchJobOrderRowByNumber(string $jobNumber, array $wantFields = ['id', 'number', 'totalAmount'])
    {
        [$url, $qs] = self::accurateEndpoint('job-order/list.do');
        $headers = self::accurateHeaders();

        $fields = implode(',', array_unique(array_merge(['id', 'number'], $wantFields)));

        $query = $qs
            . (str_contains($qs, '?') ? '&' : '?')
            . 'fields=' . urlencode($fields)
            . '&filter.number.op=EQUAL'
            . '&filter.number.val=' . urlencode($jobNumber)
            . '&sp.page=1&sp.pageSize=1';

        $resp = Http::withHeaders($headers)
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . $query);

        if (!$resp->ok()) return null;

        $json = $resp->json() ?: [];
        $rows = [];
        if (isset($json['d']) && is_array($json['d'])) {
            $rows = $json['d'];
        } elseif (isset($json['r']['data']) && is_array($json['r']['data'])) {
            $rows = $json['r']['data'];
        } elseif (isset($json['r']) && is_array($json['r']) && isset($json['r'][0])) {
            $rows = $json['r'];
        }

        return $rows[0] ?? null;
    }

    protected static function fetchJobOrderDetailByIdWithRetry(int $id, int $maxTries = 3, int $sleepMs = 500)
    {
        for ($i = 1; $i <= $maxTries; $i++) {
            $detail = self::fetchJobOrderDetailById($id);
            $amt = is_array($detail) ? self::parseAccurateAmountFlexible($detail) : null;
            if ($detail && $amt !== null && (float)$amt > 0.0) {
                return $detail;
            }
            usleep($sleepMs * 1000);
        }
        return self::fetchJobOrderDetailById($id);
    }

    protected static function fetchJobOrderDetailById(int $id)
    {
        [$url, $qs] = self::accurateEndpoint('job-order/detail.do');
        $headers = self::accurateHeaders();

        $query = $qs . (str_contains($qs, '?') ? '&' : '?') . 'id=' . $id;

        $resp = Http::withHeaders($headers)
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . $query);

        if (!$resp->ok()) return null;

        return $resp->json() ?: [];
    }

    protected static function sumDetailItemsAmount(array $detailJson): ?float
    {
        $items = $detailJson['r']['detailItem'] ?? $detailJson['r']['detail'] ?? null;
        if (!is_array($items)) return null;

        $sum = 0.0;
        $found = false;
        foreach ($items as $it) {
            $lineCandidates = [
                $it['total'] ?? null,
                $it['amount'] ?? null,
                $it['lineTotal'] ?? null,
                $it['subtotal'] ?? null,
            ];
            $line = self::firstNonNullNumeric($lineCandidates);
            if ($line !== null) {
                $sum += (float) $line;
                $found = true;
            } else {
                $qty   = self::normalizeNumberOrNull($it['quantity'] ?? null);
                $price = self::normalizeNumberOrNull($it['price'] ?? $it['unitPrice'] ?? null);
                if ($qty !== null && $price !== null) {
                    $sum += $qty * $price;
                    $found = true;
                }
            }
        }
        return $found ? $sum : null;
    }

    protected static function firstNonNullNumeric(array $candidates): ?float
    {
        foreach ($candidates as $v) {
            $num = self::normalizeNumberOrNull($v);
            if ($num !== null) return $num;
        }
        return null;
    }

    protected static function normalizeNumberOrNull($val): ?float
    {
        if ($val === null || $val === '') {
            return null;
        }
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }
        if (is_string($val)) {
            $v = trim($val);
            if ($v === '') {
                return null;
            }

            // Handle format: 1.234,56 → 1234.56
            if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $v)) {
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            }
            // Handle format: 1,234.56 → 1234.56
            elseif (preg_match('/^\d{1,3}(,\d{3})*\.\d+$/', $v)) {
                $v = str_replace(',', '', $v);
            }
            // Handle format: 1.234 → 1234 (angka bulat dengan titik ribuan)
            elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
                $v = str_replace('.', '', $v);
            }
            // Handle format: 1,234 → 1234 (angka bulat dengan koma ribuan)
            elseif (preg_match('/^\d{1,3}(,\d{3})+$/', $v)) {
                $v = str_replace(',', '', $v);
            }

            if (is_numeric($v)) {
                return (float) $v;
            }
        }
        return null;
    }

    protected static function num($v): ?float
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return (float)$v;
        if (is_string($v)) {
            $s = trim($v);
            if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s)) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } elseif (preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
                $s = str_replace(',', '', $s);
            }
            if (is_numeric($s)) return (float)$s;
        }
        return null;
    }

    protected static function fetchRolloverRowByNumber(string $rollNumber, array $wantFields = ['id', 'number', 'allocationCost', 'totalAllocatedCost', 'totalAmount'])
    {
        [$url, $qs] = self::accurateEndpoint('roll-over/list.do');
        $headers = self::accurateHeaders();

        $fields = implode(',', array_unique(array_merge(['id', 'number'], $wantFields)));
        $query  = $qs
            . (str_contains($qs, '?') ? '&' : '?')
            . 'fields=' . urlencode($fields)
            . '&filter.number.op=EQUAL'
            . '&filter.number.val=' . urlencode($rollNumber)
            . '&sp.page=1&sp.pageSize=1';

        $resp = Http::withHeaders($headers)
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . $query);

        if (!$resp->ok()) return null;

        $json = $resp->json() ?: [];
        $rows = $json['d'] ?? $json['data'] ?? $json['value'] ?? ($json['r']['data'] ?? []);
        return is_array($rows) && isset($rows[0]) ? $rows[0] : null;
    }

    protected static function fetchRolloverDetailByIdWithRetry(int $id, int $tries = 3, int $sleepMs = 500)
    {
        for ($i = 1; $i <= $tries; $i++) {
            $detail = self::fetchRolloverDetailById($id);
            $alloc  = is_array($detail) ? self::parseRolloverAllocationFlexible($detail) : null;
            if ($detail && $alloc !== null) return $detail;
            usleep($sleepMs * 1000);
        }
        return self::fetchRolloverDetailById($id);
    }

    protected static function fetchRolloverDetailById(int $id)
    {
        [$url, $qs] = self::accurateEndpoint('roll-over/detail.do');
        $headers    = self::accurateHeaders();

        $query = $qs . (str_contains($qs, '?') ? '&' : '?') . 'id=' . (int)$id;

        $resp = Http::withHeaders($headers)
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . $query);

        if (!$resp->ok()) return null;
        return $resp->json() ?: [];
    }

    protected static function parseRolloverAllocationFlexible(array $json): ?float
    {
        $candidates = [
            $json['allocationCost'] ?? null,
            $json['totalAllocatedCost'] ?? null,
            $json['totalAllocation'] ?? null,
            $json['totalAmount'] ?? null,
            $json['r']['allocationCost'] ?? null,
            $json['r']['totalAllocatedCost'] ?? null,
            $json['r']['totalAllocation'] ?? null,
            $json['r']['totalAmount'] ?? null,
            $json['data']['allocationCost'] ?? null,
            $json['value']['allocationCost'] ?? null,
        ];

        foreach ($candidates as $v) {
            $num = self::normalizeNumberOrNull($v);
            if ($num !== null) return $num;
        }

        $items = $json['r']['detailItem'] ?? $json['r']['detail'] ?? null;
        if (is_array($items)) {
            $sum = 0.0;
            $found = false;
            foreach ($items as $it) {
                $line = self::firstNonNullNumeric([
                    $it['allocationCost'] ?? null,
                    $it['amount'] ?? null,
                    $it['total'] ?? null,
                ]);
                if ($line !== null) {
                    $sum += $line;
                    $found = true;
                }
            }
            if ($found) return $sum;
        }

        return null;
    }

    protected static function fetchJobOrderDetailByIdStrict(int $id)
    {
        [$url, $qs] = self::accurateEndpoint('job-order/detail.do');
        $resp = Http::withHeaders(self::accurateHeaders())
            ->timeout(40)->withOptions(['verify' => (bool)config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&id=' . $id : '?id=' . $id));
        return $resp->ok() ? ($resp->json() ?: []) : null;
    }

    protected static function parseJobOrderAmount(array $j): ?float
    {
        $cands = [
            $j['r']['totalAmount'] ?? null,
            $j['r']['grandTotal'] ?? null,
            $j['r']['total'] ?? null,
            $j['totalAmount'] ?? null,
            $j['grandTotal'] ?? null,
            $j['total'] ?? null,
            $j['data']['totalAmount'] ?? null,
            $j['value']['totalAmount'] ?? null,
        ];
        foreach ($cands as $c) {
            $n = self::num($c);
            if ($n !== null) return $n;
        }

        $items = $j['r']['detailItem'] ?? $j['r']['detail'] ?? null;
        if (is_array($items)) {
            $sum = 0;
            $hit = false;
            foreach ($items as $it) {
                $line = self::num($it['total'] ?? null)
                    ?? self::num($it['amount'] ?? null)
                    ?? self::num($it['lineTotal'] ?? null)
                    ?? self::num($it['subtotal'] ?? null)
                    ?? ((self::num($it['quantity'] ?? null) !== null && self::num($it['price'] ?? ($it['unitPrice'] ?? null)) !== null)
                        ? self::num($it['quantity']) * self::num($it['price'] ?? $it['unitPrice']) : null);
                if ($line !== null) {
                    $sum += $line;
                    $hit = true;
                }
            }
            if ($hit) return $sum;
        }
        return null;
    }

    protected static function fetchRolloverDetailByIdStrict(int $id)
    {
        [$url, $qs] = self::accurateEndpoint('roll-over/detail.do');
        $resp = Http::withHeaders(self::accurateHeaders())
            ->timeout(40)->withOptions(['verify' => (bool)config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&id=' . $id : '?id=' . $id));
        return $resp->ok() ? ($resp->json() ?: []) : null;
    }

    protected static function parseRolloverAllocation(array $j): ?float
    {
        $cands = [
            $j['r']['allocationCost'] ?? null,
            $j['r']['totalAllocatedCost'] ?? null,
            $j['r']['totalAllocation'] ?? null,
            $j['r']['totalAmount'] ?? null,
            $j['allocationCost'] ?? null,
            $j['totalAllocatedCost'] ?? null,
            $j['totalAllocation'] ?? null,
            $j['totalAmount'] ?? null,
            $j['data']['allocationCost'] ?? null,
            $j['value']['allocationCost'] ?? null,
        ];
        foreach ($cands as $c) {
            $n = self::num($c);
            if ($n !== null) return $n;
        }

        $items = $j['r']['detailItem'] ?? $j['r']['detail'] ?? null;
        if (is_array($items)) {
            $sum = 0;
            $hit = false;
            foreach ($items as $it) {
                $line = self::num($it['allocationCost'] ?? null)
                    ?? self::num($it['amount'] ?? null)
                    ?? self::num($it['total'] ?? null);
                if ($line !== null) {
                    $sum += $line;
                    $hit = true;
                }
            }
            if ($hit) return $sum;
        }
        return null;
    }

    protected static function pollAmount(callable $fn, int $tries = 8, int $sleepMs = 800): float
    {
        $val = 0.0;
        for ($i = 1; $i <= $tries; $i++) {
            $v = $fn();
            if ($v !== null) {
                $val = (float)$v;
                break;
            }
            usleep($sleepMs * 1000);
        }
        return (float)$val;
    }

    protected static function normalizeNumberString($val): ?string
    {
        $num = self::normalizeNumberOrNull($val);
        if ($num === null) {
            return null;
        }
        // Format kembali ke string dengan 8 desimal, titik sebagai desimal (standar internal)
        return number_format($num, 8, '.', '');
    }

    protected static function firstNumericString(array $candidates): ?string
    {
        foreach ($candidates as $v) {
            $s = self::normalizeNumberString($v);
            if ($s !== null) return $s;
        }
        return null;
    }

    protected static function parseTotalsFlexible(array $json): array
    {
        $paths = [
            $json,
            $json['r'] ?? [],
            $json['data'] ?? [],
            $json['value'] ?? [],
        ];

        $total = null;
        $alloc = null;

        foreach ($paths as $p) {
            if (!is_array($p)) continue;

            $total = $total ?? self::firstNumericString([
                $p['totalAmount'] ?? null,
                $p['grandTotal'] ?? null,
                $p['total'] ?? null,
            ]);

            $alloc = $alloc ?? self::firstNumericString([
                $p['allocationCost'] ?? null,
                $p['totalAllocatedCost'] ?? null,
                $p['totalAllocation'] ?? null,
            ]);
        }

        if ($total === null) {
            $items = $json['r']['detailItem'] ?? $json['r']['detail'] ?? null;
            if (is_array($items)) {
                $sum = 0.0;
                $found = false;
                foreach ($items as $it) {
                    $line = self::firstNumericString([
                        $it['total'] ?? null,
                        $it['amount'] ?? null,
                        $it['lineTotal'] ?? null,
                        $it['subtotal'] ?? null,
                    ]);
                    if ($line !== null) {
                        $sum += (float)$line;
                        $found = true;
                    } else {
                        $qty   = self::normalizeNumberString($it['quantity'] ?? null);
                        $price = self::normalizeNumberString($it['price'] ?? ($it['unitPrice'] ?? null));
                        if ($qty !== null && $price !== null) {
                            $sum += (float)$qty * (float)$price;
                            $found = true;
                        }
                    }
                }
                if ($found) $total = number_format($sum, 8, '.', '');
            }
        }

        return [
            'total' => $total,
            'alloc' => $alloc,
        ];
    }

    protected static function fetchJobOrderDetailByNumberStrict(string $number)
    {
        [$url, $qs] = self::accurateEndpoint('job-order/detail.do');
        $resp = \Illuminate\Support\Facades\Http::withHeaders(self::accurateHeaders())
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&number=' . urlencode($number) : '?number=' . urlencode($number)));
        return $resp->ok() ? ($resp->json() ?: []) : null;
    }

    protected static function fetchRolloverDetailByNumberStrict(string $number)
    {
        [$url, $qs] = self::accurateEndpoint('roll-over/detail.do');
        $resp = \Illuminate\Support\Facades\Http::withHeaders(self::accurateHeaders())
            ->timeout(40)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&number=' . urlencode($number) : '?number=' . urlencode($number)));
        return $resp->ok() ? ($resp->json() ?: []) : null;
    }

    /* ===================== UNIT HELPERS (BARU) ===================== */

    /** Cache meta unit per itemNo: [ 'byName' => [lowerName => id], 'default' => ['id'=>..., 'name'=>...] ] */
    protected static array $itemUnitMetaCache = [];

    /**
     * Ambil meta unit dari Accurate item/detail.do:
     * - Peta nama->id untuk semua unit
     * - Satuan default (heuristik: flag isDefault / conversion==1 / baseUnit)
     */
    protected static function fetchItemUnitMeta(string $itemNo): ?array
    {
        $key = strtolower(trim($itemNo));
        if (array_key_exists($key, self::$itemUnitMetaCache)) {
            return self::$itemUnitMetaCache[$key];
        }

        [$url, $qs] = self::accurateEndpoint('item/detail.do');
        $resp = \Illuminate\Support\Facades\Http::withHeaders(self::accurateHeaders())
            ->timeout(30)
            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
            ->get($url . ($qs ? $qs . '&number=' . urlencode($itemNo) : '?number=' . urlencode($itemNo)));

        if (!$resp->ok()) {
            return self::$itemUnitMetaCache[$key] = null;
        }

        $json  = $resp->json() ?: [];
        $units = $json['r']['unitConversions'] ?? $json['r']['itemUnits'] ?? $json['r']['units'] ?? [];

        $byName = [];
        $default = ['id' => null, 'name' => null];

        foreach ((array)$units as $u) {
            $id   = isset($u['id']) ? (int)$u['id'] : null;
            $name = trim((string)($u['name'] ?? $u['unitName'] ?? $u['uomName'] ?? ''));
            if ($name !== '' && $id) {
                $byName[strtolower($name)] = $id;
            }

            $isDefault = false;
            $conv      = $u['conversion'] ?? $u['rate'] ?? $u['multiplier'] ?? null;

            if (isset($u['isDefault']) && $u['isDefault']) {
                $isDefault = true;
            } elseif (isset($u['baseUnit']) && $u['baseUnit']) {
                $isDefault = true;
            } elseif (is_numeric($conv) && (float)$conv == 1.0) {
                $isDefault = true;
            }

            if ($isDefault && $id) {
                $default = ['id' => (int)$id, 'name' => ($name ?: null)];
            }
        }

        // Jika belum ketemu default, ambil unit pertama saja
        if (!$default['id'] && !empty($units)) {
            $first = $units[0];
            $default['id']   = isset($first['id']) ? (int)$first['id'] : null;
            $default['name'] = trim((string)($first['name'] ?? $first['unitName'] ?? $first['uomName'] ?? '')) ?: null;
        }

        return self::$itemUnitMetaCache[$key] = [
            'byName'  => $byName,
            'default' => $default,
        ];
    }

    /**
     * Resolve unit id:
     * - Jika $preferredName ada & cocok → kembalikan id-nya
     * - Jika tidak cocok → fallback ke default unit Accurate
     * Return: ['id' => ?int, 'name' => ?string]
     */
    protected static function resolveItemUnitIdOrDefault(string $itemNo, ?string $preferredName): ?array
    {
        $meta = self::fetchItemUnitMeta($itemNo);
        if (!$meta) return null;

        $pref = strtolower(trim((string)$preferredName));
        if ($pref !== '' && isset($meta['byName'][$pref])) {
            return ['id' => (int)$meta['byName'][$pref], 'name' => $preferredName];
        }

        if (!empty($meta['default']['id'])) {
            return ['id' => (int)$meta['default']['id'], 'name' => $meta['default']['name']];
        }

        return null;
    }
    /**
     * Ambil total JO final dengan retry singkat karena indexing Accurate kadang telat.
     * Mengembalikan float > 0 jika berhasil, 0.0 jika gagal.
     */
    protected static function waitFinalJobOrderTotal(string $jobNumber, int $tries = 6, int $sleepMs = 900): float
    {
        for ($i = 0; $i < $tries; $i++) {
            // 1) Coba dari detail.do?number=...
            $detail = self::fetchJobOrderDetailByNumberStrict($jobNumber);
            if (is_array($detail)) {
                $t = self::parseTotalsFlexible($detail);       // sudah ada di file Anda
                $total = self::normalizeNumberOrNull($t['total'] ?? null);  // sudah ada di file Anda
                if ($total !== null && $total > 0) {
                    return (float)$total;
                }
            }

            // 2) Fallback dari list.do (kadang lebih cepat terindeks)
            $row = self::fetchJobOrderRowByNumber($jobNumber, ['id', 'number', 'totalAmount', 'total', 'grandTotal']);
            if (is_array($row)) {
                $cand = self::firstNonNullNumeric([
                    $row['totalAmount'] ?? null,
                    $row['grandTotal'] ?? null,
                    $row['total'] ?? null,
                ]); // firstNonNullNumeric() sudah ada di file Anda
                if ($cand !== null && $cand > 0) {
                    return (float)$cand;
                }

                // 2b) Kalau list dapat id tapi total belum keisi, coba detail.do?id=... + retry pendek
                if (!empty($row['id'])) {
                    $detailById = self::fetchJobOrderDetailByIdWithRetry((int)$row['id'], 3, 600);
                    if (is_array($detailById)) {
                        $t2 = self::parseTotalsFlexible($detailById);
                        $cand2 = self::normalizeNumberOrNull($t2['total'] ?? null);
                        if ($cand2 !== null && $cand2 > 0) {
                            return (float)$cand2;
                        }
                    }
                }
            }

            // belum dapat → tidur sebentar lalu ulang
            usleep($sleepMs * 1000);
        }

        // Gagal mendapatkan total > 0
        return 0.0;
    }
    /**
     * Jumlahkan total quantity dari detail RO (aman untuk struktur campuran).
     * Menerima array detail seperti yang dikirim ke roll-over/save.do (detailItem).
     */
    protected static function sumDetailQuantity(array $detailRO): float
    {
        $sum = 0.0;

        // Kalau yang diterima format {detailItem: [...]} dari JSON, ambil ke dalam $items
        $items = $detailRO;

        // Allow bentuk JSON detail.do: ['r' => ['detailItem' => [...]]] atau ['r' => ['detail' => [...]]]
        if (isset($detailRO['r']) && is_array($detailRO['r'])) {
            $items = $detailRO['r']['detailItem'] ?? $detailRO['r']['detail'] ?? $detailRO;
        }

        // Kalau masih ada pembungkus 'detailItem', pakai itu
        if (isset($items['detailItem']) && is_array($items['detailItem'])) {
            $items = $items['detailItem'];
        }

        foreach ((array) $items as $row) {
            if (!is_array($row)) continue;
            $q = $row['quantity'] ?? 0;
            // normalisasi angka string seperti "12.345,67" → float
            $num = self::normalizeNumberOrNull($q);
            if ($num !== null) {
                $sum += (float) $num;
            } else {
                $sum += (float) $q;
            }
        }

        return (float) $sum;
    }

    /**
     * Menjumlahkan total allocationCost (atau total/amount sebagai fallback) dari detail RO.
     * Dipakai untuk cek pembulatan setelah distribusi biaya.
     */
    protected static function sumAllocationFromDetail(array $detailRO): float
    {
        $sum = 0.0;

        $items = $detailRO;
        if (isset($detailRO['r']) && is_array($detailRO['r'])) {
            $items = $detailRO['r']['detailItem'] ?? $detailRO['r']['detail'] ?? $detailRO;
        }
        if (isset($items['detailItem']) && is_array($items['detailItem'])) {
            $items = $items['detailItem'];
        }

        foreach ((array) $items as $row) {
            if (!is_array($row)) continue;

            // prioritas allocationCost → amount → total
            $cand = [
                $row['allocationCost'] ?? null,
                $row['amount'] ?? null,
                $row['total'] ?? null,
            ];

            $val = self::firstNonNullNumeric($cand); // helper sudah ada di file Anda
            if ($val !== null) {
                $sum += (float) $val;
            }
        }

        return (float) $sum;
    }
    protected static function fetchItemUnitId(string $itemNo, string $unitName): ?int
    {
        $itemNo   = trim($itemNo);
        $unitName = Str::lower(trim($unitName));

        if ($itemNo === '' || $unitName === '') {
            return null;
        }

        $cacheKey = "acc:item:{$itemNo}:unit:{$unitName}";
        return Cache::remember($cacheKey, 600, function () use ($itemNo, $unitName) {
            // 1) Coba langsung detail by item no (beberapa akun Accurate menerima parameter 'no')
            $detail = self::accurateGet('item/detail.do', ['no' => $itemNo]);
            if (!$detail) {
                // 2) Fallback: list -> ambil id -> detail by id
                $list = self::accurateGet('item/list.do', [
                    // keyword biasanya match no/name; aman pakai no
                    'keyword' => $itemNo,
                    'page'    => 1,
                    'limit'   => 1,
                ]);
                $itemId = (int) data_get($list, 'data.0.id');
                if ($itemId <= 0) {
                    return null;
                }
                $detail = self::accurateGet('item/detail.do', ['id' => $itemId]);
                if (!$detail) {
                    return null;
                }
            }

            // Struktur unit di detail Accurate umumnya unit1..unit5
            $candidates = [];
            for ($i = 1; $i <= 5; $i++) {
                $name = Str::lower(trim((string) data_get($detail, "unit{$i}.name")));
                $id   = data_get($detail, "unit{$i}.id");
                if ($name !== '' && $id) {
                    $candidates[$name] = (int) $id;
                }
            }

            // Kadang ada vendorUnit juga
            $vName = Str::lower(trim((string) data_get($detail, 'vendorUnit.name')));
            $vId   = data_get($detail, 'vendorUnit.id');
            if ($vName !== '' && $vId) {
                $candidates[$vName] = (int) $vId;
            }

            // Normalisasi singkat beberapa variasi umum (spasi/tanda titik)
            $normalize = fn(string $s) => Str::of($s)->lower()->replace(['.', '  '], ['', ' '])->trim()->value();
            $target = $normalize($unitName);

            foreach ($candidates as $name => $id) {
                if ($normalize($name) === $target) {
                    return (int) $id;
                }
            }

            // Tidak ketemu → null, biar Accurate pakai default unit
            return null;
        });
    }

    /**
     * Wrapper GET ke Accurate (return bagian 'r' atau struktur data utama).
     */
    protected static function accurateGet(string $path, array $params = [])
    {
        $endpoint = self::accurateEndpoint($path);

        // Terima kedua bentuk: "https://.../path" ATAU ["https://.../path", ["branchId"=>..., "session"=>...]]
        if (is_array($endpoint)) {
            // tambah default agar index selalu ada
            $url = (string) ($endpoint[0] ?? '');
            $qs  = is_array($endpoint[1] ?? null) ? $endpoint[1] : [];
        } else {
            $url = (string) $endpoint;
            $qs  = [];
        }

        // Hardening: pastikan array
        if (! is_array($qs)) {
            $qs = [];
        }

        $resp = Http::withHeaders(self::accurateHeaders())
            ->asForm()
            ->get($url, array_merge($qs, $params));

        if (! $resp->ok()) {
            return null;
        }

        $json = $resp->json();

        // Banyak response Accurate: { s: true, r: {...} }
        if (data_get($json, 's') === true) {
            return data_get($json, 'r', $json);
        }

        // Ada juga yang langsung { data: [...] }
        return $json;
    }
    /**
     * Versi lebih robust dari waitFinalJobOrderTotal: lebih agresif, timeout lebih lama untuk item >10.
     */
    protected static function waitFinalJobOrderTotalRobust(string $jobNumber, int $tries = 12, int $sleepMs = 1000): float
    {
        for ($i = 0; $i < $tries; $i++) {
            // 1. Coba dari detail by number
            $detail = self::fetchJobOrderDetailByNumberStrict($jobNumber);
            if (is_array($detail)) {
                $t = self::parseTotalsFlexible($detail);
                $total = self::normalizeNumberOrNull($t['total'] ?? null);
                if ($total !== null && $total > 0) {
                    return (float) $total;
                }
                // Fallback: hitung manual dari detailItem
                $sum = self::sumDetailItemsAmount($detail);
                if ($sum !== null && $sum > 0) {
                    return (float) $sum;
                }
            }

            // 2. Coba dari list (kadang lebih cepat terindex)
            $row = self::fetchJobOrderRowByNumber($jobNumber, ['id', 'number', 'totalAmount']);
            if (is_array($row)) {
                $cand = self::firstNonNullNumeric([$row['totalAmount'] ?? null, $row['total'] ?? null, $row['grandTotal'] ?? null]);
                if ($cand !== null && $cand > 0) {
                    return (float) $cand;
                }
                // Kalau ada ID, coba detail by ID
                if (!empty($row['id'])) {
                    $detailById = self::fetchJobOrderDetailByIdWithRetry((int)$row['id'], 3, 800);
                    if (is_array($detailById)) {
                        $t2 = self::parseTotalsFlexible($detailById);
                        $c2 = self::normalizeNumberOrNull($t2['total'] ?? null);
                        if ($c2 !== null && $c2 > 0) {
                            return (float) $c2;
                        }
                        $sum2 = self::sumDetailItemsAmount($detailById);
                        if ($sum2 !== null && $sum2 > 0) {
                            return (float) $sum2;
                        }
                    }
                }
            }

            usleep($sleepMs * 1000);
        }

        return 0.0;
    }
    /**
     * Ambil total biaya Job Order yang benar-benar final dari Accurate.
     * Lebih agresif, terutama untuk job order dengan banyak item.
     */
    protected static function getFinalJobOrderTotalRobust(string $jobNumber, int $itemCount = 0): float
    {
        $maxTries = ($itemCount > 10) ? 20 : 15;
        $sleepMs  = ($itemCount > 10) ? 1000 : 800;

        for ($i = 1; $i <= $maxTries; $i++) {
            // Strategi 1: detail.do by number
            $detail = self::fetchJobOrderDetailByNumberStrict($jobNumber);
            if (is_array($detail)) {
                $t = self::parseTotalsFlexible($detail);
                $total = self::normalizeNumberOrNull($t['total'] ?? null);
                if ($total !== null && $total > 0) {
                    return (float) $total;
                }
                // Fallback: hitung manual dari detailItem
                $manualSum = self::sumDetailItemsAmount($detail);
                if ($manualSum !== null && $manualSum > 0) {
                    return (float) $manualSum;
                }
            }

            // Strategi 2: list.do → lalu detail by ID
            $row = self::fetchJobOrderRowByNumber($jobNumber, ['id', 'totalAmount', 'grandTotal', 'total']);
            if (is_array($row)) {
                $cand = self::firstNonNullNumeric([
                    $row['totalAmount'] ?? null,
                    $row['grandTotal'] ?? null,
                    $row['total'] ?? null,
                ]);
                if ($cand !== null && $cand > 0) {
                    return (float) $cand;
                }
                if (!empty($row['id'])) {
                    $detailById = self::fetchJobOrderDetailByIdWithRetry((int)$row['id'], 5, 800);
                    if (is_array($detailById)) {
                        $t2 = self::parseTotalsFlexible($detailById);
                        $cand2 = self::normalizeNumberOrNull($t2['total'] ?? null);
                        if ($cand2 !== null && $cand2 > 0) {
                            return (float) $cand2;
                        }
                        $manualSum2 = self::sumDetailItemsAmount($detailById);
                        if ($manualSum2 !== null && $manualSum2 > 0) {
                            return (float) $manualSum2;
                        }
                    }
                }
            }

            // Strategi 3: Coba ambil total dari payload respons awal (jarang ada, tapi coba)
            // (tidak diterapkan di sini karena respons save biasanya tidak punya total)

            usleep($sleepMs * 1000);
        }

        // Semua strategi gagal
        Log::error("Gagal mendapatkan total JO valid", ['job_number' => $jobNumber, 'item_count' => $itemCount]);
        return 0.0;
    }
    /**
     * Format angka ke string dengan 2 digit desimal, titik sebagai ribuan, koma sebagai desimal.
     * Digunakan untuk tampilan.
     */
    protected static function formatCurrency(float $amount): string
    {
        return number_format($amount, 2, ',', '.');
    }

    /**
     * Format angka ke string dengan 2 digit desimal, titik sebagai desimal.
     * Digunakan untuk penyimpanan internal/database.
     */
    protected static function formatCurrencyInternal(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
    protected static function resolveTransDate(Produksi $locked): array
    {
        $tz = (string) config('accurate.tz', 'Asia/Jakarta');

        $transDate = $locked->tanggal
            ? \Illuminate\Support\Carbon::parse($locked->tanggal, $tz)->timezone($tz)->format('d/m/Y')
            : now($tz)->format('d/m/Y');

        return [$transDate, $tz];
    }
    protected static function buildAndPersistHasilResep(Produksi $locked): string
    {
        $descParts = [];

        foreach ($locked->itemProduksi as $item) {
            $nm = $item->barang?->nama ?? '-';
            $resep = \App\Models\Resep::where('barang_setengah_jadi_id', $item->barang_setengah_jadi_id)->first();

            $hasilDeskripsi = $nm;
            $jumlahTotal    = null;
            $satuan         = null;

            if ($resep && $resep->deskripsi) {
                if (preg_match('/=\s*([\d.,]+)\s*([^\s]+)/u', $resep->deskripsi, $m)) {
                    $angkaPerResep = (float) str_replace(',', '.', $m[1]);
                    $satuan        = trim($m[2]);

                    $total         = $angkaPerResep * (float) ($item->jumlah_aktual ?? 0);
                    $jumlahTotal   = $total;
                    $hasilDeskripsi = "{$nm} ({$total} {$satuan})";
                }
            }

            \App\Models\ItemProduksiHasil::updateOrCreate(
                ['item_produksi_id' => $item->id],
                [
                    'nama_barang'   => $nm,
                    'satuan'        => $satuan,
                    'jumlah_total'  => $jumlahTotal,
                    'keterangan'    => $hasilDeskripsi,
                ]
            );

            $descParts[] = $hasilDeskripsi;
        }

        return implode("\n", $descParts) ?: ($locked->nomor_produksi ?? 'Produksi');
    }
    protected static function buildJobOrderDetailItems(Produksi $locked, array $wh): array
    {
        $detailItem = [];
        $zeroCodeJO = [];

        foreach ($locked->itemProduksi as $ip) {
            foreach ($ip->bahanProduksi as $bp) {
                $kode = optional($bp->bahan)->kode;
                $qty  = (float) $bp->jumlah_aktual;
                $nama = optional($bp->bahan)->nama ?? ('Bahan#' . $bp->bahan_id);
                $unitName = optional($bp->bahan?->satuan)?->kode;

                if ((string)($kode ?? '') === '0') {
                    $zeroCodeJO[] = $nama;
                    continue;
                }

                if ($kode && $qty > 0) {
                    $line = [
                        'itemNo'        => (string) $kode,
                        'quantity'      => $qty,
                        'warehouseId'   => (int) $wh['id'],
                        'warehouseName' => $wh['name'] ?? null,
                    ];

                    if ($unitName) {
                        $unitId = self::fetchItemUnitId((string)$kode, (string)$unitName);
                        if ($unitId) {
                            $line['itemUnitId'] = (int) $unitId;
                        } else {
                            $line['itemUnitName'] = (string) $unitName;
                        }
                    }

                    $detailItem[] = $line;
                }
            }
        }

        return [$detailItem, $zeroCodeJO];
    }
    protected static function buildRollOverDetailItems(Produksi $locked, array $wh): array
    {
        $descRO   = [];
        $detailRO = [];
        $zeroCodeRO = [];

        foreach ($locked->itemProduksi as $ip) {
            $kodeBarang = optional($ip->barangSetengahJadi)?->kode
                ?? optional($ip->barang_setengah_jadi)?->kode;

            $namaBarang = optional($ip->barangSetengahJadi)?->nama
                ?? optional($ip->barang_setengah_jadi)?->nama
                ?? ('Barang#' . $ip->barang_setengah_jadi_id);

            $unitSJKode = optional($ip->barangSetengahJadi?->satuan)?->kode
                ?? optional($ip->barang_setengah_jadi?->satuan)?->kode;

            $hasil = \App\Models\ItemProduksiHasil::where('item_produksi_id', $ip->id)->first();

            $qtyHasil = (float) ($hasil->jumlah_total ?? 0);
            if ($qtyHasil <= 0) {
                $qtyHasil = (float) ($ip->jumlah_aktual ?? 0);
            }

            $labelSatuan = $hasil?->satuan ?: $unitSJKode;

            if ((string)($kodeBarang ?? '') === '0') {
                $zeroCodeRO[] = $namaBarang;
                continue;
            }

            if ($kodeBarang && $qtyHasil > 0) {
                $line = [
                    'itemNo'        => (string) $kodeBarang,
                    'quantity'      => $qtyHasil,
                    'portion'       => 100,
                    'warehouseId'   => (int) $wh['id'],
                    'warehouseName' => $wh['name'] ?? null,
                ];

                if ($unitSJKode) {
                    $unitId = self::fetchItemUnitId((string) $kodeBarang, (string) $unitSJKode);
                    if ($unitId) {
                        $line['itemUnitId'] = (int) $unitId;
                    } else {
                        $line['itemUnitName'] = (string) $unitSJKode;
                    }
                }

                $detailRO[] = $line;

                $descRO[] = trim(
                    $qtyHasil . ' ' .
                        ($labelSatuan ? ($labelSatuan . ' ') : '') .
                        $namaBarang
                );
            }
        }

        $descriptionRO = $descRO ? implode('; ', $descRO) : ($locked->nomor_produksi ?? 'Produksi');

        return [$descriptionRO, $detailRO, $zeroCodeRO];
    }

    /* ===================== POST ACTION ===================== */

    public static function postJobOrderAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('postJobOrder')
            ->label('POST Job Order (Pekerjaan Pesanan)')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Kirim Job Order ke Accurate')
            ->disabled(fn(Produksi $record) => filled($record->accurate_number))
            ->tooltip(
                fn(Produksi $record) => filled($record->accurate_number)
                    ? 'Job Order sudah ada. Hapus dulu untuk kirim ulang.'
                    : null
            )
            ->action(function (Produksi $record) {
                try {
                    DB::transaction(function () use ($record) {
                        /** @var Produksi $locked */
                        $locked = Produksi::query()
                            ->whereKey($record->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (filled($locked->accurate_number)) {
                            Notification::make()
                                ->title('Job Order sudah ada')
                                ->body("Job Order: {$locked->accurate_number}")
                                ->success()
                                ->send();
                            return;
                        }

                        [$transDate, $tz] = self::resolveTransDate($locked);

                        // 1) siapkan deskripsi & simpan hasil resep (tetap sama seperti punyamu)
                        $description = self::buildAndPersistHasilResep($locked);

                        // 2) gudang default
                        $wh = self::resolveDefaultWarehouse();
                        if (!$wh) {
                            throw ValidationException::withMessages([
                                'warehouse' => 'Gudang default tidak ditemukan',
                            ]);
                        }

                        // 3) build detail JO (BAHAN)
                        [$detailItem, $zeroCodeJO] = self::buildJobOrderDetailItems($locked, $wh);

                        if (!empty($zeroCodeJO)) {
                            $list = '• ' . implode("\n• ", array_values(array_unique($zeroCodeJO)));
                            Notification::make()
                                ->title('Gagal: Ada Kode Bahan = 0')
                                ->body("Perbaiki kode Accurate untuk item berikut:\n{$list}")
                                ->danger()->send();

                            throw ValidationException::withMessages([
                                'detailItem' => 'Terdapat bahan dengan kode = 0. Perbaiki terlebih dahulu.',
                            ]);
                        }

                        if (empty($detailItem)) {
                            throw ValidationException::withMessages([
                                'detailItem' => 'Tidak ada bahan valid untuk Job Order',
                            ]);
                        }

                        // 4) POST JO
                        [$url, $qs] = self::accurateEndpoint('job-order/save.do');
                        $headers    = self::accurateHeaders();

                        $payloadJob = [
                            'transDate'     => $transDate,
                            'description'   => $description,
                            'detailItem'    => $detailItem,
                            'warehouseId'   => (int) $wh['id'],
                            'warehouseName' => $wh['name'] ?? null,
                        ];

                        $resp = Http::withHeaders($headers)
                            ->timeout(40)
                            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
                            ->post($url . $qs, $payloadJob);

                        if (!$resp->ok()) {
                            throw ValidationException::withMessages([
                                'accurate' => "Gagal kirim Job Order: HTTP {$resp->status()} {$resp->body()}",
                            ]);
                        }

                        $json = $resp->json() ?: [];
                        $jobNumber = self::parseAccurateNumber($json);

                        if (!$jobNumber) {
                            throw ValidationException::withMessages([
                                'accurate' => 'Tidak dapat membaca nomor Job Order.',
                            ]);
                        }

                        // simpan ke produksi
                        $locked->accurate_number = $jobNumber;
                        $locked->save();

                        // 5) ambil total JO final (robust)
                        $totalJOFloat = self::getFinalJobOrderTotalRobust($jobNumber, count($detailItem));
                        if ($totalJOFloat <= 0.0) {
                            throw ValidationException::withMessages([
                                'accurate' => "Tidak dapat mengambil total biaya Job Order yang valid dari Accurate setelah beberapa percobaan.",
                            ]);
                        }

                        $joId = null;
                        $detailJO = self::fetchJobOrderDetailByNumberStrict($jobNumber);
                        if (is_array($detailJO)) {
                            $joId = (int) data_get($detailJO, 'r.id', 0) ?: null;
                        }

                        // simpan doc JOB_ORDER (8 desimal)
                        $totalJO = self::normalizeNumberString($totalJOFloat) ?? '0.00000000';

                        \App\Models\ProduksiAccurateDoc::updateOrCreate(
                            ['produksi_id' => $locked->id, 'doc_type' => 'JOB_ORDER'],
                            [
                                'doc_number'        => $jobNumber,
                                'external_id'       => $joId,
                                'trans_date'        => \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $transDate)->toDateString(),
                                'total_amount'      => $totalJO,
                                'allocation_amount' => '0.00000000',
                                'payload'           => $payloadJob,
                                'response'          => $json,
                                'status'            => 'posted',
                            ]
                        );

                        Notification::make()
                            ->title('Job Order berhasil dikirim')
                            ->body("Job Order: {$jobNumber}\nTanggal: {$transDate}\nGudang: " . ($wh['name'] ?? $wh['id']))
                            ->success()
                            ->send();
                    });
                } catch (ValidationException $ve) {
                    $msg = collect($ve->errors())->flatten()->implode("\n");
                    Notification::make()
                        ->title('Gagal kirim Job Order')
                        ->body($msg ?: 'Validasi gagal.')
                        ->danger()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Error tak terduga saat kirim Job Order')
                        ->body(substr($e->getMessage(), 0, 800))
                        ->danger()
                        ->send();
                    throw $e;
                }
            });
    }
    public static function postRollOverAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('postRollOver')
            ->label('POST Roll Over (Penyelesaian Pesanan)')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Kirim Roll Over ke Accurate')
            ->disabled(fn(Produksi $record) => blank($record->accurate_number) || filled($record->accurate_rollover_number))
            ->tooltip(
                fn(Produksi $record) => blank($record->accurate_number)
                    ? 'Roll Over butuh Job Order terlebih dahulu.'
                    : (filled($record->accurate_rollover_number) ? 'Roll Over sudah ada. Hapus dulu untuk kirim ulang.' : null)
            )
            ->action(function (Produksi $record) {
                try {
                    DB::transaction(function () use ($record) {
                        /** @var Produksi $locked */
                        $locked = Produksi::query()
                            ->whereKey($record->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (blank($locked->accurate_number)) {
                            throw ValidationException::withMessages([
                                'accurate' => 'Job Order belum ada. Silakan POST Job Order dulu.',
                            ]);
                        }

                        if (filled($locked->accurate_rollover_number)) {
                            Notification::make()
                                ->title('Roll Over sudah ada')
                                ->body("Roll Over: {$locked->accurate_rollover_number}")
                                ->success()
                                ->send();
                            return;
                        }

                        [$transDate, $tz] = self::resolveTransDate($locked);

                        $wh = self::resolveDefaultWarehouse();
                        if (!$wh) {
                            throw ValidationException::withMessages([
                                'warehouse' => 'Gudang default tidak ditemukan',
                            ]);
                        }

                        // Pastikan total JO (ambil dari DB doc dulu kalau ada)
                        $jobNumber = (string) $locked->accurate_number;

                        $docJO = \App\Models\ProduksiAccurateDoc::query()
                            ->where('produksi_id', $locked->id)
                            ->where('doc_type', 'JOB_ORDER')
                            ->first();

                        $totalJOFloat = 0.0;
                        if ($docJO && !blank($docJO->total_amount)) {
                            $totalJOFloat = (float) (self::normalizeNumberOrNull($docJO->total_amount) ?? 0.0);
                        }

                        if ($totalJOFloat <= 0.0) {
                            // fallback ambil dari Accurate lagi
                            $totalJOFloat = self::getFinalJobOrderTotalRobust($jobNumber, 0);
                        }

                        if ($totalJOFloat <= 0.0) {
                            throw ValidationException::withMessages([
                                'accurate' => 'Tidak dapat mengambil total biaya Job Order (untuk alokasi Roll Over).',
                            ]);
                        }

                        // build detail RO + deskripsi
                        [$descriptionRO, $detailRO, $zeroCodeRO] = self::buildRollOverDetailItems($locked, $wh);

                        if (!empty($zeroCodeRO)) {
                            $list = '• ' . implode("\n• ", array_values(array_unique($zeroCodeRO)));
                            Notification::make()
                                ->title('Gagal: Ada Kode Barang Hasil = 0')
                                ->body("Perbaiki kode Accurate untuk item berikut:\n{$list}")
                                ->danger()->send();

                            throw ValidationException::withMessages([
                                'detailItem' => 'Terdapat barang hasil dengan kode = 0. Perbaiki terlebih dahulu.',
                            ]);
                        }

                        if (empty($detailRO)) {
                            throw ValidationException::withMessages([
                                'detailItem' => 'Tidak ada hasil produksi valid untuk Roll Over',
                            ]);
                        }

                        // alokasi biaya presisi penuh
                        $sumQty      = max(self::sumDetailQuantity($detailRO), 1e-9);
                        $targetAlloc = (float) $totalJOFloat;

                        $detailRO = array_map(function ($row) use ($sumQty, $targetAlloc) {
                            $qty = (float)($row['quantity'] ?? 0);
                            $row['allocationCost'] = ($qty <= 0) ? 0.0 : ($targetAlloc * ($qty / $sumQty));
                            return $row; // jangan dibulatkan
                        }, $detailRO);

                        $allocSum = self::sumAllocationFromDetail($detailRO);
                        $diff = $targetAlloc - $allocSum;
                        if (abs($diff) >= 0.00000001) {
                            for ($i = count($detailRO) - 1; $i >= 0; $i--) {
                                if (($detailRO[$i]['quantity'] ?? 0) > 0) {
                                    $detailRO[$i]['allocationCost'] += $diff;
                                    break;
                                }
                            }
                        }

                        // POST RO
                        [$urlR, $qsR] = self::accurateEndpoint('roll-over/save.do');
                        $headersR = self::accurateHeaders();

                        $payloadRO = [
                            'transDate'      => $transDate,
                            'jobOrderNumber' => $jobNumber,
                            'rollOverType'   => 'ITEM',
                            'description'    => $descriptionRO,
                            'detailItem'     => $detailRO,
                            'warehouseId'    => (int) $wh['id'],
                            'warehouseName'  => $wh['name'] ?? null,
                            'allocationCost' => $targetAlloc,
                        ];

                        $respRO = Http::withHeaders($headersR)
                            ->timeout(40)
                            ->withOptions(['verify' => (bool) config('accurate.verify_ssl', true)])
                            ->post($urlR . $qsR, $payloadRO);

                        if (!$respRO->ok()) {
                            // kalau ternyata duplicate / sudah ada
                            if (self::isDuplicateRolloverError($respRO->body())) {
                                $existing = self::fetchRolloverNumberByJobOrder($jobNumber);
                                if ($existing) {
                                    $locked->accurate_rollover_number = $existing;
                                    $locked->save();

                                    Notification::make()
                                        ->title('Roll Over sudah ada di Accurate')
                                        ->body("Roll Over: {$existing}\n(Job Order: {$jobNumber})")
                                        ->warning()
                                        ->send();

                                    return;
                                }
                            }

                            throw ValidationException::withMessages([
                                'accurate' => "Gagal kirim Roll Over: HTTP {$respRO->status()} {$respRO->body()}",
                            ]);
                        }

                        $jsonRO  = $respRO->json() ?: [];
                        $rollNum = self::parseDocNumber($jsonRO);
                        if (blank($rollNum)) {
                            $rollNum = self::fetchRolloverNumberByJobOrder($jobNumber);
                        }
                        if (blank($rollNum)) {
                            throw ValidationException::withMessages([
                                'accurate' => 'Roll Over tersimpan tapi nomor tidak terbaca. Cek di Accurate (filter by Job Order).',
                            ]);
                        }

                        $locked->accurate_rollover_number = $rollNum;
                        $locked->save();

                        // ambil totals RO (kalau perlu)
                        $rollId = null;
                        $totRO  = self::parseTotalsFlexible($jsonRO);
                        $totalRO = $totRO['total'];
                        $allocRO = $totRO['alloc'];

                        if ($totalRO === null || $allocRO === null) {
                            $row = self::fetchRolloverRowByNumber($rollNum, ['id', 'number', 'allocationCost', 'totalAllocatedCost', 'totalAmount']);
                            if ($row) {
                                $rollId = (int)($row['id'] ?? 0) ?: null;
                                $totalRO = $totalRO ?? self::firstNumericString([$row['totalAmount'] ?? null]);
                                $allocRO = $allocRO ?? self::firstNumericString([
                                    $row['allocationCost'] ?? null,
                                    $row['totalAllocatedCost'] ?? null,
                                ]);

                                if ($rollId && ($totalRO === null || $allocRO === null)) {
                                    $detailROId = self::fetchRolloverDetailByIdWithRetry($rollId, 3, 600);
                                    if (is_array($detailROId)) {
                                        $t3 = self::parseTotalsFlexible($detailROId);
                                        $totalRO = $totalRO ?? $t3['total'];
                                        $allocRO = $allocRO ?? $t3['alloc'];
                                    }
                                }
                            }
                        }

                        $totalRO = self::normalizeNumberString($totalRO) ?? '0.00000000';
                        $allocRO = self::normalizeNumberString($allocRO) ?? '0.00000000';

                        \App\Models\ProduksiAccurateDoc::updateOrCreate(
                            ['produksi_id' => $locked->id, 'doc_type' => 'ROLL_OVER'],
                            [
                                'doc_number'        => $rollNum,
                                'external_id'       => $rollId,
                                'trans_date'        => \Illuminate\Support\Carbon::createFromFormat('d/m/Y', $transDate)->toDateString(),
                                'total_amount'      => $totalRO,
                                'allocation_amount' => $allocRO,
                                'payload'           => $payloadRO,
                                'response'          => $jsonRO,
                                'status'            => 'posted',
                            ]
                        );

                        Notification::make()
                            ->title('Roll Over berhasil dikirim')
                            ->body("Job Order: {$jobNumber}\nRoll Over: {$rollNum}\nTanggal: {$transDate}")
                            ->success()
                            ->send();
                    });
                } catch (ValidationException $ve) {
                    $msg = collect($ve->errors())->flatten()->implode("\n");
                    Notification::make()
                        ->title('Gagal kirim Roll Over')
                        ->body($msg ?: 'Validasi gagal.')
                        ->danger()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Error tak terduga saat kirim Roll Over')
                        ->body(substr($e->getMessage(), 0, 800))
                        ->danger()
                        ->send();
                    throw $e;
                }
            });
    }
}

<?php

namespace App\Providers;

use App\Models\LogPerubahan;
use App\Models\MasterBarang;
use App\Models\MasterSatuan;
use App\Models\Produksi;
use App\Models\PurchaseRequisition;
use App\Models\Resep;
use App\Policies\LogPerubahanPolicy;
use App\Policies\MasterBarangPolicy;
use App\Policies\MasterSatuanPolicy;
use App\Policies\ProduksiPolicy;
use App\Policies\PurchaseRequisitionPolicy;
use App\Policies\ResepPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        MasterSatuan::class => MasterSatuanPolicy::class,
        MasterBarang::class => MasterBarangPolicy::class,
        Resep::class => ResepPolicy::class,
        Produksi::class => ProduksiPolicy::class,
        PurchaseRequisition::class => PurchaseRequisitionPolicy::class,
        LogPerubahan::class => LogPerubahanPolicy::class,

    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}

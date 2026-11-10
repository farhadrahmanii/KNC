<?php

namespace Tests\Feature;

use App\Filament\Resources\NumerahaResource\Pages\ListNumerahas;
use App\Models\CustomerNumeraha;
use App\Models\Customers;
use App\Models\Numeraha;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class TmpFixProbeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_customer_saves_without_responsible_person(): void
    {
        $this->actingAs(User::query()->orderBy('id')->first());

        $customer = Customers::create([
            'name' => 'probe customer', 'father_name' => 'f', 'province' => 'کابل',
            'Customer_image' => 'Customers_images/x.png', 'grand_father_name' => 'g',
            'mobile_number' => '0700000000', 'village' => 'v', 'tazkira' => 't',
        ]);
        $this->assertNull($customer->fresh()->responsable_name);

        $plot = Numeraha::create(['numera_id' => 'PROBE-SALE', 'Land_Area' => '100', 'date' => '2026-11-09', 'numera_type' => '5 بسوه ای']);
        $sale = CustomerNumeraha::create([
            'customer_id' => $customer->id, 'numeraha_id' => $plot->id,
            'first_phase' => '500', 'payed_price' => '500', 'total_price' => '1000',
        ]);
        $this->assertNull($sale->fresh()->multipleDocs);
        $this->assertNull($sale->fresh()->sixth_phase);
    }

    public function test_sold_and_remaining_filters(): void
    {
        $this->actingAs(User::query()->orderBy('id')->first());
        $filter = 'پلورل شوی نمری (ځمکی)';

        $make = fn (string $code) => Numeraha::create([
            'numera_id' => $code, 'Land_Area' => '100', 'date' => '2026-11-09',
            'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w', 'numera_type' => '5 بسوه ای',
        ]);
        $sold = $make('PROBE-SOLD');
        $left = $make('PROBE-LEFT');
        $customer = Customers::create(['name' => 'probe buyer']);
        CustomerNumeraha::create(['customer_id' => $customer->id, 'numeraha_id' => $sold->id, 'total_price' => 1, 'payed_price' => 1]);

        Livewire::test(ListNumerahas::class)
            ->assertCanSeeTableRecords([$sold, $left]);

        Livewire::withQueryParams(['tableFilters' => [$filter => ['value' => '1']]])
            ->test(ListNumerahas::class)
            ->assertCanSeeTableRecords([$sold])
            ->assertCanNotSeeTableRecords([$left]);

        Livewire::withQueryParams(['tableFilters' => [$filter => ['value' => '0']]])
            ->test(ListNumerahas::class)
            ->assertCanSeeTableRecords([$left])
            ->assertCanNotSeeTableRecords([$sold]);
    }
}

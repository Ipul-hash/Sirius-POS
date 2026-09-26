<?php

use App\Models\Branch;
use App\Models\ConsignmentSettlement;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;

describe('MODUL 6 & 7: CONSIGNMENT SETTLEMENT & LOSS PREVENTION', function () {

    beforeEach(function () {
        $this->branch = Branch::firstOrCreate(
            ['id' => 1],
            ['code' => 'JKT-01', 'name' => 'Sirius Mart & Coffee Kemang', 'address' => 'Jl. Kemang Raya No. 10', 'phone' => '021-778899']
        );

        $this->rakFront = Location::firstOrCreate(
            ['branch_id' => 1, 'code' => 'RAK-FRONT'],
            ['name' => 'Rak Display Minimarket', 'division' => 'retail', 'is_active' => true]
        );

        $this->barCounter = Location::firstOrCreate(
            ['branch_id' => 1, 'code' => 'BAR-COUNTER'],
            ['name' => 'Bar Counter Kopi', 'division' => 'coffee', 'is_active' => true]
        );

        $this->supplierTitin = Supplier::firstOrCreate(
            ['code' => 'SUP-TITIN'],
            [
                'name' => 'Snack Tradisional Bu Titin (UMKM)',
                'contact_person' => 'Ibu Titin',
                'is_consignment_vendor' => true,
                'revenue_share_percentage' => 20.00,
                'is_active' => true,
            ]
        );

        $this->lemperProduct = Product::where('sku', 'CSG-SNK-001')->first()
            ?? Product::create([
                'sku' => 'CSG-SNK-001',
                'barcode' => '899456789001',
                'name' => 'Lemper Ayam Spesial Bu Titin',
                'category_id' => 2,
                'division' => 'retail',
                'type' => 'standard',
                'primary_unit_id' => 1,
                'purchase_price' => 4000.00,
                'selling_price' => 5000.00,
                'is_consignment' => true,
                'supplier_id' => $this->supplierTitin->id,
                'is_active' => true,
            ]);

        $this->coffeeBeans = Product::where('id', 2)->first()
            ?? Product::where('sku', 'RAW-COF-001')->first();

        $this->susuUht = Product::where('id', 1)->first()
            ?? Product::where('sku', 'RTL-DRY-001')->first();

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@siriuspos.com'],
            ['name' => 'Owner / Admin Sirius', 'password' => bcrypt('password123'), 'role' => 'admin', 'is_active' => true]
        );
    });

    it('API 6.1: calculates and generates consignment settlement for Bu Titin (20% commission)', function () {
        $payload = [
            'supplier_id' => $this->supplierTitin->id,
            'branch_id' => 1,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
            'items' => [
                [
                    'product_id' => $this->lemperProduct->id,
                    'initial_stock_qty' => 0,
                    'received_qty' => 100,
                    'sold_qty' => 80,
                    'returned_damaged_qty' => 20,
                    'closing_stock_qty' => 0,
                ],
            ],
            'notes' => 'Settlement titip jual lemper periode 1-7 September 2026',
        ];

        $response = $this->postJson('/api/consignment/settlements', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.settlement.supplier_id', $this->supplierTitin->id)
            ->assertJsonPath('data.settlement.total_sold_qty', 80)
            ->assertJsonPath('data.settlement.total_returned_qty', 20);

        $settlement = $response->json('data.settlement');
        expect($settlement['settlement_no'])->toMatch('/^SETTLE-\d{6}-\d{4}$/');
        expect((float) $settlement['total_gross_sales'])->toBe(400000.0);
        expect((float) $settlement['store_share_amount'])->toBe(80000.0);
        expect((float) $settlement['vendor_payable_amount'])->toBe(320000.0);

        $savedRecord = ConsignmentSettlement::where('settlement_no', $settlement['settlement_no'])->first();
        expect($savedRecord)->not->toBeNull();
        expect((float) $savedRecord->vendor_payable_amount)->toBe(320000.0);
    });

    it('API 6.1: auto-calculates consignment settlement from POS sales and waste logs when items payload omitted', function () {
        $payload = [
            'supplier_id' => $this->supplierTitin->id,
            'branch_id' => 1,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-07',
        ];

        $response = $this->postJson('/api/consignment/settlements', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'settlement' => [
                        'settlement_no',
                        'supplier_name',
                        'total_sold_qty',
                        'total_gross_sales',
                        'store_share_amount',
                        'vendor_payable_amount',
                    ],
                    'items',
                ],
            ]);
    });

    it('API 7.1: records waste log for barista coffee dial-in calibration and deducts stock', function () {
        $initialBeanStock = (float) InventoryBatch::where('product_id', $this->coffeeBeans->id)
            ->where('location_id', $this->barCounter->id)
            ->active()
            ->sum('current_qty');

        $payload = [
            'location_id' => $this->barCounter->id,
            'product_id' => $this->coffeeBeans->id,
            'quantity' => 50.0,
            'reason' => 'dial_in_beans',
            'notes' => 'Kalibrasi gilingan biji kopi pagi',
        ];

        $response = $this->postJson('/api/inventory/waste', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.waste_log.reason', 'dial_in_beans')
            ->assertJsonPath('data.waste_log.division', 'coffee')
            ->assertJsonPath('data.waste_log.quantity', 50);

        $wasteData = $response->json('data.waste_log');
        expect((float) $wasteData['total_loss'])->toBe(12500.0);

        $movement = StockMovement::where('reference_id', $wasteData['id'])
            ->where('reference_type', 'WASTE_SPOILAGE')
            ->first();

        expect($movement)->not->toBeNull();
        expect((float) $movement->quantity)->toBe(50.0);
        expect((float) $movement->total_cost)->toBe(12500.0);

        $finalBeanStock = (float) InventoryBatch::where('product_id', $this->coffeeBeans->id)
            ->where('location_id', $this->barCounter->id)
            ->active()
            ->sum('current_qty');

        expect(round($finalBeanStock, 2))->toBe(round($initialBeanStock - 50.0, 2));

        $wasteBatch = InventoryBatch::where('product_id', $this->coffeeBeans->id)
            ->where('location_id', $this->barCounter->id)
            ->latest()
            ->first();
        if ($wasteBatch) {
            $wasteBatch->increment('current_qty', 50.0);
        }
    });

    it('API 7.1: rejects waste log with invalid reason', function () {
        $payload = [
            'location_id' => $this->barCounter->id,
            'product_id' => $this->coffeeBeans->id,
            'quantity' => 10.0,
            'reason' => 'aliens_abducted_it',
        ];

        $response = $this->postJson('/api/inventory/waste', $payload);
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    it('API 7.2: performs partial stock opname cycle count and applies stock adjustment', function () {
        $initialSystemQty = (float) InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->rakFront->id)
            ->active()
            ->sum('current_qty');

        $physicalQty = $initialSystemQty - 2.0;

        $payload = [
            'location_id' => $this->rakFront->id,
            'notes' => 'Cycle count parsial rak susu',
            'items' => [
                [
                    'product_id' => $this->susuUht->id,
                    'physical_qty' => $physicalQty,
                    'notes' => 'Susu UHT fisik selisih 2 kotak kurang',
                ],
            ],
        ];

        $response = $this->postJson('/api/inventory/stock-opnames', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stock_opname.status', 'approved');

        $opnameData = $response->json('data');
        expect($opnameData['stock_opname']['opname_number'])->toMatch('/^OPN-\d{8}-\d{4}$/');

        $itemResult = $opnameData['items'][0];
        expect((float) $itemResult['system_qty'])->toBe($initialSystemQty);
        expect((float) $itemResult['physical_qty'])->toBe($physicalQty);
        expect((float) $itemResult['difference_qty'])->toBe(-2.0);

        $movement = StockMovement::where('reference_id', $opnameData['stock_opname']['id'])
            ->where('reference_type', 'STOCK_ADJUSTMENT')
            ->first();

        expect($movement)->not->toBeNull();
        expect((int) $movement->from_location_id)->toBe($this->rakFront->id);
        expect((float) $movement->quantity)->toBe(2.0);

        $finalStock = (float) InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->rakFront->id)
            ->active()
            ->sum('current_qty');

        expect(round($finalStock, 2))->toBe(round($initialSystemQty - 2.0, 2));

        InventoryBatch::where('id', 1)->update(['current_qty' => 24.0, 'status' => 'active']);
        InventoryBatch::where('id', 2)->update(['current_qty' => 36.0, 'status' => 'active']);
    });
});

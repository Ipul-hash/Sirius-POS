<?php

use App\Models\ApInvoice;
use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

describe('MODUL 4 & 5: INVENTORY, TRANSFER, FEFO DASHBOARD & PURCHASING AP', function () {

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

        $this->gudang = Location::firstOrCreate(
            ['branch_id' => 1, 'code' => 'GUDANG-BELAKANG'],
            ['name' => 'Gudang Stok Utama Belakang', 'division' => 'general', 'is_active' => true]
        );

        $this->cashier = User::firstOrCreate(
            ['email' => 'siti.kasir@siriuspos.com'],
            ['name' => 'Siti Kasir', 'password' => bcrypt('password123'), 'role' => 'cashier', 'is_active' => true]
        );

        $this->admin = User::firstOrCreate(
            ['email' => 'admin@siriuspos.com'],
            ['name' => 'Owner / Admin Sirius', 'password' => bcrypt('password123'), 'role' => 'admin', 'is_active' => true]
        );

        $this->supplier = Supplier::firstOrCreate(
            ['code' => 'SUP-ULV'],
            ['name' => 'PT Unilever Indonesia Distributor', 'payment_terms_days' => 14, 'is_active' => true]
        );

        $this->susuUht = Product::where('id', 1)->first()
            ?? Product::where('sku', 'RTL-DRY-001')->first();

        $this->sabun = Product::where('sku', 'RTL-NCS-001')->first();
    });

    it('API 4.1: transfers stock from RAK-FRONT to BAR-COUNTER via FEFO', function () {
        $sourceBatch = InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->rakFront->id)
            ->active()
            ->fefo()
            ->first();

        if (! $sourceBatch || (float) $sourceBatch->current_qty < 10) {
            $sourceBatch = InventoryBatch::create([
                'batch_no' => 'B-MLK-TEST-'.uniqid(),
                'product_id' => $this->susuUht->id,
                'location_id' => $this->rakFront->id,
                'initial_qty' => 20.0000,
                'current_qty' => 20.0000,
                'unit_cost' => 16500.00,
                'expired_at' => Carbon::today()->addDays(20),
                'status' => 'active',
            ]);
        }

        $initialSourceQty = (float) InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->rakFront->id)
            ->active()
            ->sum('current_qty');

        $initialBarQty = (float) InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->barCounter->id)
            ->active()
            ->sum('current_qty');

        $payload = [
            'from_location_id' => $this->rakFront->id,
            'to_location_id' => $this->barCounter->id,
            'items' => [
                ['product_id' => $this->susuUht->id, 'quantity' => 5.0],
            ],
            'notes' => 'Barista ambil susu UHT dari rak depan',
        ];

        $response = $this->postJson('/api/inventory/transfers', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.transfer.status', 'completed')
            ->assertJsonPath('data.transfer.from_location.code', 'RAK-FRONT')
            ->assertJsonPath('data.transfer.to_location.code', 'BAR-COUNTER');

        $data = $response->json('data');
        expect(count($data['items']))->toBeGreaterThanOrEqual(1);
        expect((float) $data['transfer']['total_transfer_value'])->toBe(82500.0);

        $finalSourceQty = (float) InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->rakFront->id)
            ->active()
            ->sum('current_qty');

        $finalBarQty = (float) InventoryBatch::where('product_id', $this->susuUht->id)
            ->where('location_id', $this->barCounter->id)
            ->active()
            ->sum('current_qty');

        expect(round($finalSourceQty, 2))->toBe(round($initialSourceQty - 5.0, 2));
        expect(round($finalBarQty, 2))->toBe(round($initialBarQty + 5.0, 2));

        $movement = StockMovement::where('reference_id', $data['transfer']['id'])
            ->where('reference_type', 'INTERNAL_TRANSFER')
            ->first();

        expect($movement)->not->toBeNull();
        expect((int) $movement->from_location_id)->toBe($this->rakFront->id);
        expect((int) $movement->to_location_id)->toBe($this->barCounter->id);
        expect((float) $movement->quantity)->toBe(5.0);

        InventoryBatch::where('id', 1)->update(['current_qty' => 24.0]);
        InventoryBatch::where('location_id', $this->barCounter->id)->where('batch_no', 'B-MLK-01')->delete();
    });

    it('API 4.1: rejects transfer when source location has insufficient stock', function () {
        $payload = [
            'from_location_id' => $this->rakFront->id,
            'to_location_id' => $this->barCounter->id,
            'items' => [
                ['product_id' => $this->susuUht->id, 'quantity' => 999999.0],
            ],
        ];

        $response = $this->postJson('/api/inventory/transfers', $payload);
        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    });

    it('API 4.2: lists batches approaching expiry with urgency breakdown', function () {
        $rotiProduct = Product::where('sku', 'RTL-BAK-001')->first() ?? $this->susuUht;

        $testBatch = InventoryBatch::create([
            'batch_no' => 'B-ROTI-CRITICAL-'.uniqid(),
            'product_id' => $rotiProduct->id,
            'location_id' => $this->rakFront->id,
            'initial_qty' => 10.0,
            'current_qty' => 10.0,
            'unit_cost' => 12000.0,
            'expired_at' => Carbon::today()->addDays(5),
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/inventory/expiry-alerts?days_threshold=60&branch_id=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => [
                        'days_threshold',
                        'total_batches_at_risk',
                        'total_value_at_risk',
                        'breakdown' => [
                            'expired',
                            'critical_h7',
                            'warning_h30',
                            'attention_h60',
                        ],
                    ],
                    'alerts' => [
                        '*' => [
                            'batch_id',
                            'batch_no',
                            'product_name',
                            'location',
                            'remaining_stock',
                            'days_until_expiry',
                            'urgency',
                        ],
                    ],
                ],
            ]);

        $alerts = $response->json('data.alerts');
        expect(count($alerts))->toBeGreaterThanOrEqual(1);

        for ($i = 0; $i < count($alerts) - 1; $i++) {
            expect($alerts[$i]['days_until_expiry'])->toBeLessThanOrEqual($alerts[$i + 1]['days_until_expiry']);
        }

        $testBatch->delete();
    });

    it('API 5.1: creates purchase order to supplier with status sent', function () {
        $payload = [
            'supplier_id' => $this->supplier->id,
            'branch_id' => 1,
            'expected_delivery_date' => Carbon::today()->addDays(3)->toDateString(),
            'notes' => 'Pemesanan Sabun Lifebuoy 100 karton',
            'items' => [
                [
                    'product_id' => $this->sabun->id,
                    'ordered_qty' => 100.0,
                    'unit_price' => 3100.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/purchasing/orders', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.supplier_id', $this->supplier->id);

        $po = $response->json('data');
        expect($po['po_number'])->toMatch('/^PO-\d{6}-\d{3}$/');
        expect((float) $po['total_amount'])->toBe(310000.0);
        expect(count($po['items']))->toBe(1);
    });

    it('API 5.2: verifies goods receipt, generates inventory batch and issues AP invoice', function () {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.Carbon::today()->format('Ym').'-'.substr(uniqid(), -4),
            'supplier_id' => $this->supplier->id,
            'branch_id' => 1,
            'order_date' => Carbon::today(),
            'expected_delivery_date' => Carbon::today()->addDays(2),
            'total_amount' => 310000.00,
            'status' => 'sent',
            'created_by' => $this->admin->id,
        ]);

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->sabun->id,
            'ordered_qty' => 100.0,
            'received_qty' => 0.0,
            'unit_id' => $this->sabun->primary_unit_id,
            'unit_price' => 3100.00,
            'subtotal' => 310000.00,
        ]);

        $batchNumber = 'B-LFB-'.uniqid();
        $payload = [
            'purchase_order_id' => $po->id,
            'invoice_ref_number' => 'SJ-ULV-'.uniqid(),
            'location_id' => $this->gudang->id,
            'notes' => 'Mobil boks Unilever tiba, barang komplit dan segel utuh',
            'items' => [
                [
                    'po_item_id' => $poItem->id,
                    'product_id' => $this->sabun->id,
                    'received_qty' => 100.0,
                    'batch_no' => $batchNumber,
                    'expired_at' => Carbon::today()->addYears(2)->toDateString(),
                    'unit_cost' => 3100.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/purchasing/goods-receipt', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.goods_receipt_note.status', 'verified')
            ->assertJsonPath('data.goods_receipt_note.po_status', 'received')
            ->assertJsonPath('data.ap_invoice.status', 'unpaid');

        $data = $response->json('data');

        $createdBatch = InventoryBatch::where('batch_no', $batchNumber)->first();
        expect($createdBatch)->not->toBeNull();
        expect((int) $createdBatch->location_id)->toBe($this->gudang->id);
        expect((float) $createdBatch->current_qty)->toBe(100.0);
        expect((float) $createdBatch->unit_cost)->toBe(3100.0);

        $movement = StockMovement::where('reference_id', $data['goods_receipt_note']['id'])
            ->where('reference_type', 'PO_RECEIPT')
            ->first();
        expect($movement)->not->toBeNull();
        expect((float) $movement->quantity)->toBe(100.0);

        $apInvoice = ApInvoice::where('id', $data['ap_invoice']['id'])->first();
        expect($apInvoice)->not->toBeNull();
        expect((float) $apInvoice->total_amount)->toBe(310000.0);
        expect($apInvoice->due_date->toDateString())->toBe(Carbon::today()->addDays(14)->toDateString());
    });

    it('API 5.3: reports AP aging alerts for overdue and upcoming due invoices', function () {
        ApInvoice::create([
            'invoice_number' => 'INV-AP-OVERDUE-'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'branch_id' => 1,
            'issue_date' => Carbon::today()->subDays(30),
            'due_date' => Carbon::today()->subDays(5),
            'total_amount' => 500000.00,
            'paid_amount' => 0.00,
            'status' => 'unpaid',
        ]);

        $response = $this->getJson('/api/purchasing/ap-alerts?days_threshold=7&branch_id=1');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary' => [
                        'days_threshold',
                        'total_invoices_alert',
                        'total_outstanding_amount',
                        'total_overdue_amount',
                        'total_due_soon_amount',
                        'breakdown',
                    ],
                    'invoices',
                ],
            ]);

        $summary = $response->json('data.summary');
        expect((float) $summary['total_overdue_amount'])->toBeGreaterThanOrEqual(500000.0);

        $invoices = $response->json('data.invoices');
        $overdueItem = collect($invoices)->firstWhere('urgency', 'overdue');
        expect($overdueItem)->not->toBeNull();
        expect($overdueItem['days_until_due'])->toBeLessThan(0);
    });
});

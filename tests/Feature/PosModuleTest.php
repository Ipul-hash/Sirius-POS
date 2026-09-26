<?php

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\Location;
use App\Models\LoyaltyPointsLedger;
use App\Models\Order;
use App\Models\StockMovement;
use App\Models\User;

describe('MODUL 2: POS CHECKOUT 1 STRUK PANJANG (HYBRID RETAIL + COFFEE)', function () {

    beforeEach(function () {
        // Ensure standard locations exist
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

        // Ensure Cashier user and open cash shift exist
        $this->cashier = User::firstOrCreate(
            ['email' => 'siti.kasir@siriuspos.com'],
            ['name' => 'Siti Kasir', 'password' => bcrypt('password123'), 'role' => 'cashier', 'is_active' => true]
        );

        $this->cashShift = CashShift::where('branch_id', 1)->open()->first();
        if (! $this->cashShift) {
            $this->cashShift = CashShift::create([
                'branch_id' => 1,
                'user_id' => $this->cashier->id,
                'start_time' => now(),
                'opening_balance' => 300000.00,
                'expected_cash_in_drawer' => 300000.00,
                'status' => 'open',
            ]);
        }
    });

    // =========================================================================
    // API 2.1: Katalog Produk & Barcode Scan
    // =========================================================================
    it('API 2.1: can list products and scan barcode', function () {
        // 1. Get all products
        $responseAll = $this->getJson('/api/pos/products?branch_id=1&division=all');
        $responseAll->assertOk()
            ->assertJsonStructure([
                'success',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'sku',
                        'barcode',
                        'name',
                        'division',
                        'type',
                        'selling_price',
                        'available_stock',
                        'price_tiers',
                        'recipe_boms',
                    ],
                ],
            ]);

        // 2. Barcode Scan search (e.g. 899123456001 for Susu UHT)
        $responseScan = $this->getJson('/api/pos/products?branch_id=1&search=899123456001&division=all');
        $responseScan->assertOk()
            ->assertJsonPath('success', true);

        $items = $responseScan->json('data');
        expect(count($items))->toBeGreaterThanOrEqual(1);
        expect($items[0]['barcode'])->toBe('899123456001');

        // 3. Filter division=coffee
        $responseCoffee = $this->getJson('/api/pos/products?branch_id=1&division=coffee');
        $responseCoffee->assertOk();
        $coffeeItems = $responseCoffee->json('data');
        foreach ($coffeeItems as $coffee) {
            expect($coffee['division'])->toBe('coffee');
            expect($coffee['customizations'])->not->toBeNull();
            expect($coffee['customizations'])->toHaveKeys(['sugar_levels', 'ice_levels', 'milk_options']);
        }
    });

    // =========================================================================
    // API 2.2: Preview Kalkulasi Keranjang (Auto-Bundling Promo & Grosir)
    // =========================================================================
    it('API 2.2: calculates cart with auto-bundling 50% discount on Americano when buying 2 Roti Gandum', function () {
        // Pelanggan beli 2 Roti Gandum (id 6) + 1 Iced Americano (id 5, notes: "Less Ice, Normal Sugar")
        $payload = [
            'branch_id' => 1,
            'items' => [
                ['product_id' => 6, 'quantity' => 2],
                ['product_id' => 5, 'quantity' => 1, 'notes' => 'Less Ice, Normal Sugar'],
            ],
        ];

        $response = $this->postJson('/api/pos/calculate-cart', $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'items',
                    'summary' => [
                        'total_items',
                        'subtotal',
                        'discount_amount',
                        'tax_amount',
                        'total_amount',
                        'division_breakdown' => [
                            'retail',
                            'coffee',
                        ],
                    ],
                    'applied_promotions',
                ],
            ]);

        $data = $response->json('data');

        // Total Belanja:
        // Roti Gandum: 2 x 15,000 = 30,000
        // Americano: 1 x 18,000 = 18,000
        // Gross subtotal = 48,000
        // Diskon 50% Americano = 9,000
        // Final total = 39,000
        expect((float) $data['summary']['subtotal'])->toBe(48000.0);
        expect((float) $data['summary']['discount_amount'])->toBe(9000.0);
        expect((float) $data['summary']['total_amount'])->toBe(39000.0);

        // Americano item discount
        $americanoItem = collect($data['items'])->firstWhere('product_id', 5);
        expect((float) $americanoItem['discount_amount'])->toBe(9000.0);
        expect((float) $americanoItem['subtotal'])->toBe(9000.0);
        expect($americanoItem['notes'])->toBe('Less Ice, Normal Sugar');

        // Applied promo info
        expect(count($data['applied_promotions']))->toBeGreaterThanOrEqual(1);
        expect($data['applied_promotions'][0]['promo_name'])->toContain('Bundling Sarapan');
    });

    it('API 2.2: calculates wholesale price tier discount for bulk retail purchase', function () {
        // Sabun Lifebuoy (id 7): selling price = 4,500. Tier >= 10: unit price = 4,000
        $payload = [
            'branch_id' => 1,
            'items' => [
                ['product_id' => 7, 'quantity' => 10],
            ],
        ];

        $response = $this->postJson('/api/pos/calculate-cart', $payload);
        $response->assertOk();

        $data = $response->json('data');
        // Gross: 10 x 4,500 = 45,000
        // Tier discount: 10 x 500 = 5,000
        // Final: 40,000
        expect((float) $data['summary']['subtotal'])->toBe(45000.0);
        expect((float) $data['summary']['discount_amount'])->toBe(5000.0);
        expect((float) $data['summary']['total_amount'])->toBe(40000.0);
    });

    // =========================================================================
    // API 2.3: Eksekusi Pembayaran (Checkout 1 Struk Panjang & Potong Stok BOM)
    // =========================================================================
    it('API 2.3: executes checkout with FEFO retail stock deduction, BOM coffee consumption, KDS ticketing, and 1 Long Receipt', function () {
        // Initial inventory quantities
        $soapBatch = InventoryBatch::where('product_id', 7)->where('location_id', $this->rakFront->id)->first();
        $initialSoapQty = (float) ($soapBatch?->current_qty ?? 100);

        $coffeeBeansBatch = InventoryBatch::where('product_id', 2)->where('location_id', $this->barCounter->id)->first();
        $initialBeansQty = (float) ($coffeeBeansBatch?->current_qty ?? 5000);

        $milkBarBatch = InventoryBatch::where('product_id', 1)->where('location_id', $this->barCounter->id)->first();
        $initialMilkQty = (float) ($milkBarBatch?->current_qty ?? 8);

        $cupBatch = InventoryBatch::where('product_id', 3)->where('location_id', $this->barCounter->id)->first();
        $initialCupQty = (float) ($cupBatch?->current_qty ?? 500);

        $existingCustomer = Customer::where('phone', '081234567890')->first();
        $initialCustomerPoints = $existingCustomer ? $existingCustomer->current_points : 0;

        // Input Payload as described in user request:
        // Sabun Mandi (id 7) x 2, Iced Latte (id 4) x 1 with notes "Less Sugar, Oat Milk"
        $payload = [
            'branch_id' => 1,
            'customer_phone' => '081234567890',
            'payment_method' => 'cash',
            'amount_paid' => 50000.00,
            'items' => [
                ['product_id' => 7, 'quantity' => 2],
                ['product_id' => 4, 'quantity' => 1, 'notes' => 'Less Sugar, Oat Milk'],
            ],
        ];

        $response = $this->postJson('/api/pos/checkout', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order' => [
                        'id',
                        'invoice_number',
                        'queue_number',
                        'subtotal',
                        'total_amount',
                        'payment_method',
                        'amount_paid',
                        'change_amount',
                        'items',
                    ],
                    'division_breakdown' => [
                        'retail' => ['omset', 'cogs', 'gross_profit'],
                        'coffee' => ['omset', 'cogs', 'gross_profit'],
                        'total_omset',
                        'total_cogs',
                        'total_gross_profit',
                    ],
                    'kds_ticket' => [
                        'id',
                        'queue_number',
                        'status',
                        'items',
                    ],
                    'receipt_text',
                ],
            ]);

        $data = $response->json('data');

        // 1. Order Details & Calculation
        // Sabun Mandi: 2 x 4,500 = 9,000
        // Iced Latte: 1 x 24,000 = 24,000
        // Total = 33,000. Bayar = 50,000. Kembalian = 17,000.
        expect((float) $data['order']['subtotal'])->toBe(33000.0);
        expect((float) $data['order']['total_amount'])->toBe(33000.0);
        expect((float) $data['order']['change_amount'])->toBe(17000.0);

        // 2. Queue Number generated (starts with C-)
        $queueNumber = $data['order']['queue_number'];
        expect($queueNumber)->toMatch('/^C-\d{3}$/');

        // 3. KDS Ticket created
        expect($data['kds_ticket'])->not->toBeNull();
        expect($data['kds_ticket']['queue_number'])->toBe($queueNumber);
        expect($data['kds_ticket']['status'])->toBe('queued');
        expect(count($data['kds_ticket']['items']))->toBe(1);
        expect($data['kds_ticket']['items'][0]['custom_notes'])->toBe('Less Sugar, Oat Milk');

        // 4. Stock Deductions Verification:
        // Retail: 2 soaps deducted from RAK-FRONT
        $soapBatch->refresh();
        expect((float) $soapBatch->current_qty)->toBe(round($initialSoapQty - 2, 4));

        // Coffee BOM:
        // Biji Kopi: 18g * 1.05 = 18.9g
        $coffeeBeansBatch->refresh();
        expect((float) $coffeeBeansBatch->current_qty)->toBe(round($initialBeansQty - 18.9, 4));

        // Susu UHT at BAR-COUNTER: 0.20 pcs
        $milkBarBatch->refresh();
        expect((float) $milkBarBatch->current_qty)->toBe(round($initialMilkQty - 0.20, 4));

        // Paper Cup: 1 pcs
        $cupBatch->refresh();
        expect((float) $cupBatch->current_qty)->toBe(round($initialCupQty - 1, 4));

        // 5. Stock Movement Ledger records created
        $orderId = $data['order']['id'];
        $saleMovements = StockMovement::where('reference_id', $orderId)->get();
        expect($saleMovements->where('reference_type', 'POS_SALE')->count())->toBe(1);
        expect($saleMovements->where('reference_type', 'POS_BOM_CONSUMPTION')->count())->toBe(3);

        // 6. Cost Center Division Breakdown (COGS & Omset)
        $divisionBreakdown = $data['division_breakdown'];
        expect((float) $divisionBreakdown['retail']['omset'])->toBe(9000.0);
        expect((float) $divisionBreakdown['retail']['cogs'])->toBe(6200.0); // 2 x 3,100
        expect((float) $divisionBreakdown['retail']['gross_profit'])->toBe(2800.0);

        expect((float) $divisionBreakdown['coffee']['omset'])->toBe(24000.0);
        expect((float) $divisionBreakdown['coffee']['cogs'])->toBe(9225.0); // 18.9*250 + 0.2*16500 + 1*1200
        expect((float) $divisionBreakdown['coffee']['gross_profit'])->toBe(14775.0);

        // 7. Customer Loyalty updated
        $customer = Customer::where('phone', '081234567890')->first();
        expect($customer)->not->toBeNull();
        expect($customer->current_points)->toBe($initialCustomerPoints + 3); // 33,000 / 10,000 = 3 points

        $pointsLedger = LoyaltyPointsLedger::where('order_id', $orderId)->first();
        expect($pointsLedger)->not->toBeNull();
        expect($pointsLedger->points)->toBe(3);
        expect($pointsLedger->type)->toBe('earn');

        // 8. 1 Long Receipt format has Queue Number prominently formatted
        $receipt = $data['receipt_text'];
        expect($receipt)->toContain('ANTREAN COFFEE CORNER');
        expect($receipt)->toContain($queueNumber);
        expect($receipt)->toContain('Sabun Mandi Batang Lifebuoy 100g');
        expect($receipt)->toContain('Caffe Latte (Iced)');
        expect($receipt)->toContain('Less Sugar, Oat Milk');
        expect($receipt)->toContain('TOTAL BELANJA');
        expect($receipt)->toContain('KEMBALIAN');
    });

    it('API 2.3: rejects cash payment when amount_paid is less than total', function () {
        $payload = [
            'branch_id' => 1,
            'payment_method' => 'cash',
            'amount_paid' => 10000.00, // Total is 31,000
            'items' => [
                ['product_id' => 7, 'quantity' => 2],
                ['product_id' => 4, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/pos/checkout', $payload);
        $response->assertStatus(422);
    });

    it('API 2.3: allows QRIS payment method with exact total amount', function () {
        $payload = [
            'branch_id' => 1,
            'payment_method' => 'qris',
            'amount_paid' => 15000.00,
            'reference_no' => 'QRIS-99887766',
            'items' => [
                ['product_id' => 6, 'quantity' => 1], // Roti Gandum 15,000
            ],
        ];

        $response = $this->postJson('/api/pos/checkout', $payload);
        $response->assertStatus(201)
            ->assertJsonPath('data.order.payment_method', 'qris')
            ->assertJsonPath('data.order.change_amount', 0);
    });
});

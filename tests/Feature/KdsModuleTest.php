<?php

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\Customer;
use App\Models\KdsTicket;
use App\Models\KdsTicketItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;

describe('MODUL 3: KITCHEN DISPLAY SYSTEM (KDS BARISTA)', function () {

    beforeEach(function () {
        $this->branch = Branch::firstOrCreate(
            ['id' => 1],
            ['code' => 'JKT-01', 'name' => 'Sirius Mart & Coffee Kemang', 'address' => 'Jl. Kemang Raya No. 10', 'phone' => '021-778899']
        );

        $this->barista = User::firstOrCreate(
            ['email' => 'reza.barista@siriuspos.com'],
            ['name' => 'Reza Barista', 'password' => bcrypt('password123'), 'role' => 'barista', 'is_active' => true]
        );

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

        $this->coffeeProduct = Product::where('sku', 'FNB-COF-001')->first()
            ?? Product::create([
                'sku' => 'FNB-COF-001',
                'barcode' => 'FNB-LATTE',
                'name' => 'Caffe Latte (Iced)',
                'division' => 'coffee',
                'type' => 'composite',
                'selling_price' => 24000.00,
                'is_active' => true,
            ]);
    });

    // =========================================================================
    // API 3.1: Daftar Tiket Antrean Barista (GET /api/kds/tickets)
    // =========================================================================
    it('API 3.1: can list active tickets for barista tablet with queue number and custom notes', function () {
        // Create an order with coffee item and KDS ticket
        $order = Order::create([
            'invoice_number' => 'INV-TEST-KDS-'.uniqid(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'queue_number' => 'C-042',
            'subtotal' => 24000.00,
            'total_amount' => 24000.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_date' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->coffeeProduct->id,
            'division' => 'coffee',
            'quantity' => 1,
            'unit_price' => 24000.00,
            'cost_price' => 9225.00,
            'subtotal' => 24000.00,
            'notes' => 'Less Sugar, Oat Milk',
        ]);

        $ticket = KdsTicket::create([
            'order_id' => $order->id,
            'branch_id' => 1,
            'queue_number' => 'C-042',
            'status' => 'queued',
            'notes' => 'Pesanan dari Kasir POS',
        ]);

        KdsTicketItem::create([
            'ticket_id' => $ticket->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $this->coffeeProduct->id,
            'quantity' => 1,
            'custom_notes' => 'Less Sugar, Oat Milk',
            'status' => 'queued',
        ]);

        // Request active tickets
        $response = $this->getJson('/api/kds/tickets?branch_id=1&status=active');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'count',
                'data' => [
                    '*' => [
                        'id',
                        'queue_number',
                        'status',
                        'status_label',
                        'branch_id',
                        'notes',
                        'started_at',
                        'ready_at',
                        'collected_at',
                        'elapsed_seconds',
                        'elapsed_minutes',
                        'created_at',
                        'order' => [
                            'id',
                            'invoice_number',
                            'order_date',
                        ],
                        'items' => [
                            '*' => [
                                'id',
                                'product_id',
                                'product_name',
                                'quantity',
                                'custom_notes',
                                'status',
                            ],
                        ],
                    ],
                ],
            ]);

        $tickets = $response->json('data');
        expect(count($tickets))->toBeGreaterThanOrEqual(1);

        // Find our ticket in the response
        $targetTicket = collect($tickets)->firstWhere('queue_number', 'C-042');
        expect($targetTicket)->not->toBeNull();
        expect($targetTicket['status'])->toBe('queued');
        expect($targetTicket['status_label'])->toBe('Menunggu Antrean');
        expect($targetTicket['items'][0]['product_name'])->toBe('Caffe Latte (Iced)');
        expect($targetTicket['items'][0]['custom_notes'])->toBe('Less Sugar, Oat Milk');
    });

    it('API 3.1: sorts active tickets chronologically (FIFO) by cashier order creation time', function () {
        // Create 2 tickets with distinct timestamps
        $orderA = Order::create([
            'invoice_number' => 'INV-TEST-FIFO-A-'.uniqid(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'queue_number' => 'C-101',
            'subtotal' => 24000.00,
            'total_amount' => 24000.00,
            'payment_status' => 'paid',
            'order_date' => Carbon::now()->subMinutes(15),
        ]);

        $ticketA = KdsTicket::create([
            'order_id' => $orderA->id,
            'branch_id' => 1,
            'queue_number' => 'C-101',
            'status' => 'queued',
            'created_at' => Carbon::now()->subMinutes(15),
        ]);

        $orderB = Order::create([
            'invoice_number' => 'INV-TEST-FIFO-B-'.uniqid(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'queue_number' => 'C-102',
            'subtotal' => 24000.00,
            'total_amount' => 24000.00,
            'payment_status' => 'paid',
            'order_date' => Carbon::now()->subMinutes(5),
        ]);

        $ticketB = KdsTicket::create([
            'order_id' => $orderB->id,
            'branch_id' => 1,
            'queue_number' => 'C-102',
            'status' => 'queued',
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $response = $this->getJson('/api/kds/tickets?branch_id=1&status=active');
        $response->assertOk();

        $tickets = $response->json('data');
        $indexA = collect($tickets)->search(fn ($t) => $t['queue_number'] === 'C-101');
        $indexB = collect($tickets)->search(fn ($t) => $t['queue_number'] === 'C-102');

        expect($indexA)->not->toBeFalse();
        expect($indexB)->not->toBeFalse();
        expect($indexA)->toBeLessThan($indexB); // Ticket A arrived earlier, so it must be displayed first
    });

    // =========================================================================
    // API 3.2: Update Status Antrean KDS (PATCH /api/kds/tickets/{id}/status)
    // =========================================================================
    it('API 3.2: full lifecycle: queued -> preparing (start) -> ready -> collected', function () {
        // Step 0: Create ticket in 'queued' status
        $order = Order::create([
            'invoice_number' => 'INV-TEST-CYCLE-'.uniqid(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'queue_number' => 'C-099',
            'subtotal' => 24000.00,
            'total_amount' => 24000.00,
            'payment_status' => 'paid',
            'order_date' => now(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->coffeeProduct->id,
            'division' => 'coffee',
            'quantity' => 1,
            'unit_price' => 24000.00,
            'cost_price' => 9225.00,
            'subtotal' => 24000.00,
            'notes' => 'Less Sugar, Oat Milk',
        ]);

        $ticket = KdsTicket::create([
            'order_id' => $order->id,
            'branch_id' => 1,
            'queue_number' => 'C-099',
            'status' => 'queued',
        ]);

        $ticketItem = KdsTicketItem::create([
            'ticket_id' => $ticket->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $this->coffeeProduct->id,
            'quantity' => 1,
            'custom_notes' => 'Less Sugar, Oat Milk',
            'status' => 'queued',
        ]);

        // Step 1: Barista clicks "Start" -> status: preparing
        $responseStart = $this->patchJson("/api/kds/tickets/{$ticket->id}/status", [
            'status' => 'start',
            'barista_id' => $this->barista->id,
        ]);

        $responseStart->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'preparing')
            ->assertJsonPath('data.barista.id', $this->barista->id);

        $startData = $responseStart->json('data');
        expect($startData['started_at'])->not->toBeNull();
        expect($startData['items'][0]['status'])->toBe('in_progress');

        // Step 2: Barista finishes coffee -> clicks "Ready" -> status: ready
        $responseReady = $this->patchJson("/api/kds/tickets/{$ticket->id}/status", [
            'status' => 'ready',
        ]);

        $responseReady->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ready');

        $readyData = $responseReady->json('data');
        expect($readyData['ready_at'])->not->toBeNull();
        expect($readyData['preparation_time_seconds'])->not->toBeNull();
        expect($readyData['items'][0]['status'])->toBe('done');

        // Step 3: Customer presents receipt at pickup counter -> clicks "Collected" -> status: collected
        $responseCollected = $this->patchJson("/api/kds/tickets/{$ticket->id}/status", [
            'status' => 'collected',
        ]);

        $responseCollected->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'collected');

        $collectedData = $responseCollected->json('data');
        expect($collectedData['collected_at'])->not->toBeNull();

        // Verify ticket is no longer active in active filter
        $responseActive = $this->getJson('/api/kds/tickets?branch_id=1&status=active');
        $activeTickets = $responseActive->json('data');
        $foundInActive = collect($activeTickets)->firstWhere('id', $ticket->id);
        expect($foundInActive)->toBeNull();

        // But it exists in collected filter
        $responseHistory = $this->getJson('/api/kds/tickets?branch_id=1&status=collected');
        $collectedTickets = $responseHistory->json('data');
        $foundInHistory = collect($collectedTickets)->firstWhere('id', $ticket->id);
        expect($foundInHistory)->not->toBeNull();
    });

    it('API 3.2: rejects invalid status update with 422', function () {
        $ticket = KdsTicket::first();

        $response = $this->patchJson("/api/kds/tickets/{$ticket->id}/status", [
            'status' => 'flying_saucer',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    });

    it('API 3.2: returns 404 when updating non-existent ticket', function () {
        $response = $this->patchJson('/api/kds/tickets/999999/status', [
            'status' => 'ready',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    });

    it('API 3.1 & 3.2: single ticket show endpoint returns complete details', function () {
        $ticket = KdsTicket::first();

        $response = $this->getJson("/api/kds/tickets/{$ticket->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'queue_number',
                    'status',
                    'items',
                ],
            ]);
    });
});

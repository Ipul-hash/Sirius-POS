<?php

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\InventoryBatch;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

describe('MODUL 8: KEAMANAN KASIR & SUPERVISOR PIN OVERRIDE', function () {

    beforeEach(function () {
        $this->branch = Branch::firstOrCreate(
            ['id' => 1],
            ['code' => 'JKT-01', 'name' => 'Sirius Mart & Coffee Kemang', 'address' => 'Jl. Kemang Raya No. 10', 'phone' => '021-778899']
        );

        $this->supervisor = User::where('role', 'supervisor')->first();
        if (! $this->supervisor) {
            $this->supervisor = User::create([
                'name' => 'Budi Supervisor',
                'email' => 'budi.spv@siriuspos.com',
                'password' => Hash::make('password123'),
                'role' => 'supervisor',
                'pin_code' => '998877',
                'is_active' => true,
            ]);
        } else {
            $this->supervisor->update([
                'pin_code' => '998877',
                'is_active' => true,
            ]);
        }

        $this->cashier = User::where('role', 'cashier')->first();
        if (! $this->cashier) {
            $this->cashier = User::create([
                'name' => 'Siti Kasir',
                'email' => 'siti.kasir@siriuspos.com',
                'password' => Hash::make('password123'),
                'role' => 'cashier',
                'is_active' => true,
            ]);
        }

        $this->cashShift = CashShift::where('branch_id', 1)->open()->first();
        if (! $this->cashShift) {
            $this->cashShift = CashShift::create([
                'branch_id' => 1,
                'user_id' => $this->cashier->id,
                'start_time' => Carbon::now(),
                'opening_balance' => 500000.00,
                'expected_cash_in_drawer' => 500000.00,
                'status' => 'open',
            ]);
        }
    });

    test('API 8.1: kasir dapat membatalkan pesanan (void order) dengan otorisasi PIN supervisor 998877', function () {
        $product = Product::firstOrCreate(
            ['sku' => 'SKU-VOID-TEST'],
            [
                'name' => 'Produk Void Test',
                'division' => 'retail',
                'type' => 'standard',
                'primary_unit_id' => 1,
                'purchase_price' => 10000.00,
                'selling_price' => 15000.00,
                'is_active' => true,
            ]
        );

        $locationId = 1;
        $batch = InventoryBatch::create([
            'batch_no' => 'B-VOID-'.time(),
            'product_id' => $product->id,
            'location_id' => $locationId,
            'initial_qty' => 10.0,
            'current_qty' => 8.0,
            'unit_cost' => 10000.00,
            'expired_at' => Carbon::today()->addMonths(6),
            'status' => 'active',
        ]);

        $order = Order::create([
            'invoice_number' => 'INV-TEST-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 30000.00,
            'total_amount' => 30000.00,
            'retail_subtotal' => 30000.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_date' => Carbon::now(),
            'is_void' => false,
        ]);

        StockMovement::create([
            'branch_id' => 1,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'from_location_id' => $locationId,
            'to_location_id' => null,
            'reference_type' => 'POS_SALE',
            'reference_id' => $order->id,
            'quantity' => 2.0,
            'unit_cost' => 10000.00,
            'total_cost' => 20000.00,
            'notes' => 'Penjualan POS test',
            'created_by' => $this->cashier->id,
            'created_at' => Carbon::now(),
        ]);

        $this->cashShift->recalculateTotals();
        $this->cashShift->refresh();
        $cashBeforeVoid = (float) $this->cashShift->expected_cash_in_drawer;

        $payload = [
            'order_id' => $order->id,
            'supervisor_pin' => '998877',
            'reason' => 'Pembeli salah bawa varian susu',
        ];

        $response = $this->actingAs($this->cashier)->postJson('/api/pos/void-order', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.is_void', true)
            ->assertJsonPath('data.order.payment_status', 'void')
            ->assertJsonPath('data.order.void_reason', 'Pembeli salah bawa varian susu')
            ->assertJsonPath('data.order.void_by.id', $this->supervisor->id)
            ->assertJsonPath('data.audit_log.action', 'VOID_ORDER')
            ->assertJsonPath('data.audit_log.supervisor_id', $this->supervisor->id);

        $order->refresh();
        expect($order->is_void)->toBeTrue()
            ->and($order->payment_status)->toBe('void')
            ->and($order->void_reason)->toBe('Pembeli salah bawa varian susu')
            ->and((int) $order->void_by)->toBe($this->supervisor->id);

        $this->cashShift->refresh();
        expect((float) $this->cashShift->expected_cash_in_drawer)->toBe($cashBeforeVoid - 30000.00);

        $batch->refresh();
        expect((float) $batch->current_qty)->toBe(10.0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'VOID_ORDER',
            'reference_type' => 'Order',
            'reference_id' => $order->id,
            'supervisor_id' => $this->supervisor->id,
            'reason' => 'Pembeli salah bawa varian susu',
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'POS_VOID_RETURN',
            'reference_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2.0,
        ]);
    });

    test('API 8.1: ditolak jika PIN supervisor salah atau tidak terdaftar', function () {
        $order = Order::create([
            'invoice_number' => 'INV-FAIL-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 15000.00,
            'total_amount' => 15000.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_date' => Carbon::now(),
            'is_void' => false,
        ]);

        $payload = [
            'order_id' => $order->id,
            'supervisor_pin' => '112233',
            'reason' => 'Otorisasi palsu',
        ];

        $response = $this->actingAs($this->cashier)->postJson('/api/pos/void-order', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $order->refresh();
        expect($order->is_void)->toBeFalse();
    });

    test('API 8.1: dicegah jika order sudah pernah di-void sebelumnya', function () {
        $order = Order::create([
            'invoice_number' => 'INV-DOUBLE-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $this->cashShift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 15000.00,
            'total_amount' => 15000.00,
            'payment_status' => 'void',
            'payment_method' => 'cash',
            'order_date' => Carbon::now(),
            'is_void' => true,
            'void_reason' => 'Sudah void dari awal',
            'void_by' => $this->supervisor->id,
        ]);

        $payload = [
            'order_id' => $order->id,
            'supervisor_pin' => '998877',
            'reason' => 'Mencoba void dua kali',
        ];

        $response = $this->actingAs($this->cashier)->postJson('/api/pos/void-order', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    });

    test('API 8.2: mencatat pembukaan laci kasir tanpa transaksi (No Sale Drawer Open)', function () {
        $payload = [
            'branch_id' => 1,
            'reason' => 'Tukar uang receh ke kasir sebelah',
        ];

        $response = $this->actingAs($this->cashier)->postJson('/api/pos/open-drawer', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Audit log terekam dengan timestamp dan user kasir.')
            ->assertJsonPath('data.drawer_status', 'opened')
            ->assertJsonPath('data.audit_log.action', 'OPEN_DRAWER_NO_SALE')
            ->assertJsonPath('data.audit_log.reason', 'Tukar uang receh ke kasir sebelah')
            ->assertJsonPath('data.audit_log.cashier_id', $this->cashier->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'OPEN_DRAWER_NO_SALE',
            'branch_id' => 1,
            'user_id' => $this->cashier->id,
            'reason' => 'Tukar uang receh ke kasir sebelah',
        ]);
    });

    test('API 8.2: validasi gagal jika reason tidak disertakan atau kosong', function () {
        $payload = [
            'branch_id' => 1,
            'reason' => '',
        ];

        $response = $this->actingAs($this->cashier)->postJson('/api/pos/open-drawer', $payload);

        $response->assertStatus(422);
    });
});

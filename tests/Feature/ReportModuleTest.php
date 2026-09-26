<?php

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\InventoryWasteLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;

describe('MODUL 9: LAPORAN KEUANGAN & COST CENTER DIVISI (ERP P&L)', function () {

    beforeEach(function () {
        $this->branch = Branch::firstOrCreate(
            ['id' => 1],
            ['code' => 'JKT-01', 'name' => 'Sirius Mart & Coffee Kemang', 'address' => 'Jl. Kemang Raya No. 10', 'phone' => '021-778899']
        );

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
    });

    test('API 9.1: laporan laba kotor harian per divisi (Retail vs Coffee Bar) beserta waste dan status kasir', function () {
        $testDate = '2026-11-20';

        Payment::whereHas('order', fn ($q) => $q->whereDate('order_date', $testDate))->delete();
        Order::whereDate('order_date', $testDate)->delete();
        CashShift::whereDate('start_time', $testDate)->delete();
        InventoryWasteLog::whereDate('created_at', $testDate)->delete();

        $shift = CashShift::create([
            'branch_id' => 1,
            'user_id' => $this->cashier->id,
            'start_time' => Carbon::parse("{$testDate} 08:00:00"),
            'end_time' => Carbon::parse("{$testDate} 22:00:00"),
            'opening_balance' => 500000.00,
            'total_cash_sales' => 5200000.00,
            'total_qris_sales' => 2100000.00,
            'total_cash_out' => 0.00,
            'expected_cash_in_drawer' => 5700000.00,
            'actual_cash_in_drawer' => 5700000.00,
            'discrepancy' => 0.00,
            'status' => 'closed',
            'notes' => 'Shift selesai dan klop',
        ]);

        $orderCash = Order::create([
            'invoice_number' => 'INV-PNL-CASH-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $shift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 5200000.00,
            'total_amount' => 5200000.00,
            'retail_subtotal' => 4500000.00,
            'coffee_subtotal' => 700000.00,
            'retail_cogs' => 3600000.00,
            'coffee_cogs' => 210000.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_date' => Carbon::parse("{$testDate} 12:00:00"),
            'is_void' => false,
        ]);

        Payment::create([
            'order_id' => $orderCash->id,
            'payment_method' => 'cash',
            'amount' => 5200000.00,
            'change_amount' => 0.00,
            'created_at' => Carbon::parse("{$testDate} 12:00:00"),
        ]);

        $orderQris = Order::create([
            'invoice_number' => 'INV-PNL-QRIS-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $shift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 2100000.00,
            'total_amount' => 2100000.00,
            'retail_subtotal' => 0.00,
            'coffee_subtotal' => 2100000.00,
            'retail_cogs' => 0.00,
            'coffee_cogs' => 630000.00,
            'payment_status' => 'paid',
            'payment_method' => 'qris',
            'order_date' => Carbon::parse("{$testDate} 14:00:00"),
            'is_void' => false,
        ]);

        Payment::create([
            'order_id' => $orderQris->id,
            'payment_method' => 'qris',
            'amount' => 2100000.00,
            'change_amount' => 0.00,
            'created_at' => Carbon::parse("{$testDate} 14:00:00"),
        ]);

        InventoryWasteLog::create([
            'branch_id' => 1,
            'location_id' => 1,
            'product_id' => 1,
            'division' => 'retail',
            'quantity' => 1.0,
            'unit_id' => 1,
            'unit_cost' => 15000.00,
            'total_loss' => 15000.00,
            'reason' => 'expired',
            'notes' => 'Roti kadaluarsa',
            'recorded_by' => $this->cashier->id,
            'created_at' => Carbon::parse("{$testDate} 16:00:00"),
        ]);

        InventoryWasteLog::create([
            'branch_id' => 1,
            'location_id' => 2,
            'product_id' => 2,
            'division' => 'coffee',
            'quantity' => 0.15,
            'unit_id' => 2,
            'unit_cost' => 233333.33,
            'total_loss' => 35000.00,
            'reason' => 'dial_in_beans',
            'notes' => 'Kalibrasi pagi barista',
            'recorded_by' => $this->cashier->id,
            'created_at' => Carbon::parse("{$testDate} 08:30:00"),
        ]);

        $response = $this->getJson("/api/reports/daily-pnl?branch_id=1&date={$testDate}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.report_date', $testDate)
            ->assertJsonPath('data.branch.id', 1);

        $data = $response->json('data');
        expect((float) $data['divisions']['retail']['revenue'])->toBe(4500000.0)
            ->and((float) $data['divisions']['retail']['cogs'])->toBe(3600000.0)
            ->and((float) $data['divisions']['retail']['gross_profit'])->toBe(900000.0)
            ->and((float) $data['divisions']['retail']['margin_percentage'])->toBe(20.0)
            ->and((float) $data['divisions']['retail']['waste_loss'])->toBe(15000.0)
            ->and((float) $data['divisions']['retail']['net_profit_after_waste'])->toBe(885000.0)
            ->and((float) $data['divisions']['coffee']['revenue'])->toBe(2800000.0)
            ->and((float) $data['divisions']['coffee']['cogs'])->toBe(840000.0)
            ->and((float) $data['divisions']['coffee']['gross_profit'])->toBe(1960000.0)
            ->and((float) $data['divisions']['coffee']['margin_percentage'])->toBe(70.0)
            ->and((float) $data['divisions']['coffee']['waste_loss'])->toBe(35000.0)
            ->and((float) $data['divisions']['coffee']['net_profit_after_waste'])->toBe(1925000.0)
            ->and((float) $data['consolidated']['gross_revenue'])->toBe(7300000.0)
            ->and((float) $data['consolidated']['total_cogs'])->toBe(4440000.0)
            ->and((float) $data['consolidated']['total_gross_profit'])->toBe(2860000.0)
            ->and((float) $data['consolidated']['total_waste_loss'])->toBe(50000.0)
            ->and((float) $data['payment_methods']['cash'])->toBe(5200000.0)
            ->and((float) $data['payment_methods']['qris'])->toBe(2100000.0)
            ->and((float) $data['payment_methods']['total'])->toBe(7300000.0)
            ->and((float) $data['waste_breakdown']['coffee'])->toBe(35000.0)
            ->and((float) $data['waste_breakdown']['retail'])->toBe(15000.0)
            ->and($data['cashier_status']['all_shifts_balanced'])->toBeTrue()
            ->and($data['cashier_status']['shifts'][0]['is_balanced'])->toBeTrue()
            ->and((float) $data['cashier_status']['shifts'][0]['discrepancy'])->toBe(0.0);

        $statusLabel = $response->json('data.cashier_status.shifts.0.status_label');
        expect($statusLabel)->toContain('klop');
    });

    test('API 9.1: transaksi yang dibatalkan (void) otomatis diabaikan dari perhitungan laba kotor', function () {
        $testDate = '2026-11-21';

        Order::whereDate('order_date', $testDate)->delete();

        $shift = CashShift::firstOrCreate(
            ['branch_id' => 1, 'status' => 'open'],
            [
                'user_id' => $this->cashier->id,
                'start_time' => Carbon::now(),
                'opening_balance' => 100000.00,
                'expected_cash_in_drawer' => 100000.00,
            ]
        );

        Order::create([
            'invoice_number' => 'INV-VALID-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $shift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 100000.00,
            'total_amount' => 100000.00,
            'retail_subtotal' => 100000.00,
            'coffee_subtotal' => 0.00,
            'retail_cogs' => 70000.00,
            'coffee_cogs' => 0.00,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_date' => Carbon::parse("{$testDate} 10:00:00"),
            'is_void' => false,
        ]);

        Order::create([
            'invoice_number' => 'INV-VOIDED-'.time(),
            'branch_id' => 1,
            'cash_shift_id' => $shift->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => 200000.00,
            'total_amount' => 200000.00,
            'retail_subtotal' => 200000.00,
            'coffee_subtotal' => 0.00,
            'retail_cogs' => 140000.00,
            'coffee_cogs' => 0.00,
            'payment_status' => 'void',
            'payment_method' => 'cash',
            'order_date' => Carbon::parse("{$testDate} 11:00:00"),
            'is_void' => true,
            'void_reason' => 'Salah input',
        ]);

        $response = $this->getJson("/api/reports/daily-pnl?branch_id=1&date={$testDate}");

        $response->assertStatus(200);
        $data = $response->json('data');
        expect((float) $data['divisions']['retail']['revenue'])->toBe(100000.0)
            ->and((float) $data['divisions']['retail']['cogs'])->toBe(70000.0)
            ->and((float) $data['divisions']['retail']['gross_profit'])->toBe(30000.0)
            ->and($data['consolidated']['total_orders_count'])->toBe(1);
    });

    test('API 9.1: tanggal tanpa transaksi mengembalikan nilai nol dengan aman', function () {
        $emptyDate = '2027-01-01';

        $response = $this->getJson("/api/reports/daily-pnl?branch_id=1&date={$emptyDate}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        expect((float) $data['consolidated']['gross_revenue'])->toBe(0.0)
            ->and((float) $data['consolidated']['total_cogs'])->toBe(0.0)
            ->and((float) $data['consolidated']['total_gross_profit'])->toBe(0.0)
            ->and($data['consolidated']['total_orders_count'])->toBe(0);
    });

    test('API 9.1: validasi gagal jika format tanggal salah', function () {
        $response = $this->getJson('/api/reports/daily-pnl?branch_id=1&date=invalid-date');

        $response->assertStatus(422);
    });
});

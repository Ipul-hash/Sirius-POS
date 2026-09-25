<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashShift;
use App\Models\PettyCashExpense;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashShiftController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $shift = CashShift::where('user_id', $user->id)->open()->first();

        if ($shift) {
            $shift->recalculateTotals();
        }

        return response()->json([
            'success' => true,
            'has_open_shift' => $shift !== null,
            'shift' => $shift,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'opening_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        // Check if there is already an open shift for this user
        $existing = CashShift::where('user_id', $user->id)->open()->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'Kasir masih memiliki shift yang sedang aktif.',
                'shift' => $existing,
            ], 422);
        }

        $shift = CashShift::create([
            'branch_id' => $request->branch_id,
            'user_id' => $user->id,
            'start_time' => Carbon::now(),
            'opening_balance' => $request->opening_balance,
            'expected_cash_in_drawer' => $request->opening_balance,
            'status' => 'open',
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift kasir berhasil dibuka.',
            'shift' => $shift,
        ], 201);
    }

    public function pettyCash(Request $request): JsonResponse
    {
        $request->validate([
            'category' => 'required|in:emergency_ice,gallon_water,trash_security,cleaning_supplies,other',
            'division' => 'nullable|in:retail,coffee,general',
            'amount' => 'required|numeric|min:1',
            'recipient' => 'required|string|max:100',
            'receipt_photo' => 'nullable|image|max:5120', // max 5MB
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $shift = CashShift::where('user_id', $user->id)->open()->first();

        if (! $shift) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada shift kasir yang aktif.',
            ], 422);
        }

        $photoPath = null;
        if ($request->hasFile('receipt_photo')) {
            $photoPath = $request->file('receipt_photo')->store('petty_cash_receipts', 'public');
        }

        $expense = PettyCashExpense::create([
            'cash_shift_id' => $shift->id,
            'branch_id' => $shift->branch_id,
            'category' => $request->category,
            'division' => $request->division ?? 'general',
            'amount' => $request->amount,
            'recipient' => $request->recipient,
            'receipt_photo_path' => $photoPath,
            'notes' => $request->notes,
            'recorded_by' => $user->id,
        ]);

        // Recalculate shift totals
        $shift->recalculateTotals();

        return response()->json([
            'success' => true,
            'message' => 'Pengeluaran kas kecil (petty cash) berhasil dicatat.',
            'expense' => $expense,
            'shift' => $shift->fresh(),
        ], 201);
    }

    public function close(Request $request): JsonResponse
    {
        $request->validate([
            'actual_cash_in_drawer' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        $shift = CashShift::where('user_id', $user->id)->open()->first();

        if (! $shift) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada shift kasir yang aktif untuk ditutup.',
            ], 422);
        }

        $shift->recalculateTotals();

        $actual = (float) $request->actual_cash_in_drawer;
        $expected = (float) $shift->expected_cash_in_drawer;
        $discrepancy = round($actual - $expected, 2);

        $shift->update([
            'end_time' => Carbon::now(),
            'actual_cash_in_drawer' => $actual,
            'discrepancy' => $discrepancy,
            'status' => 'closed',
            'notes' => $request->notes ?? $shift->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Shift kasir berhasil ditutup.',
            'summary' => [
                'shift_id' => $shift->id,
                'opening_balance' => (float) $shift->opening_balance,
                'total_cash_sales' => (float) $shift->total_cash_sales,
                'total_qris_sales' => (float) $shift->total_qris_sales,
                'total_cash_out' => (float) $shift->total_cash_out,
                'expected_cash' => $expected,
                'actual_cash' => $actual,
                'discrepancy' => $discrepancy,
                'is_balanced' => $discrepancy == 0.0,
            ],
        ]);
    }
}

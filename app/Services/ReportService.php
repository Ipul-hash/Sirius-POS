<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashShift;
use App\Models\InventoryWasteLog;
use App\Models\Order;
use App\Models\PettyCashExpense;
use Carbon\Carbon;

class ReportService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getDailyPnl(array $filters = []): array
    {
        $branchId = (int) ($filters['branch_id'] ?? 1);
        $targetDate = $filters['date'] ?? Carbon::today()->toDateString();

        $branch = Branch::findOrFail($branchId);

        $orders = Order::with(['items', 'payments'])
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('order_date', $targetDate)
                    ->orWhereDate('created_at', $targetDate);
            })
            ->where('is_void', false)
            ->where('payment_status', 'paid')
            ->get();

        $retailRevenue = 0.0;
        $coffeeRevenue = 0.0;
        $retailCogs = 0.0;
        $coffeeCogs = 0.0;

        foreach ($orders as $order) {
            $orderRetailSubtotal = (float) $order->retail_subtotal;
            $orderCoffeeSubtotal = (float) $order->coffee_subtotal;
            $orderRetailCogs = (float) $order->retail_cogs;
            $orderCoffeeCogs = (float) $order->coffee_cogs;

            if ($orderRetailSubtotal == 0.0 && $orderCoffeeSubtotal == 0.0 && $order->items->isNotEmpty()) {
                foreach ($order->items as $item) {
                    $itemSubtotal = (float) $item->subtotal;
                    $itemCost = (float) ($item->cost_price * $item->quantity);
                    if ($item->division === 'coffee') {
                        $orderCoffeeSubtotal += $itemSubtotal;
                        $orderCoffeeCogs += $itemCost;
                    } else {
                        $orderRetailSubtotal += $itemSubtotal;
                        $orderRetailCogs += $itemCost;
                    }
                }
            } elseif ($orderRetailSubtotal == 0.0 && $orderCoffeeSubtotal == 0.0 && $order->items->isEmpty()) {
                $orderRetailSubtotal = (float) $order->subtotal;
            }

            $retailRevenue += $orderRetailSubtotal;
            $coffeeRevenue += $orderCoffeeSubtotal;
            $retailCogs += $orderRetailCogs;
            $coffeeCogs += $orderCoffeeCogs;
        }

        $retailGrossProfit = round($retailRevenue - $retailCogs, 2);
        $retailMargin = $retailRevenue > 0 ? round(($retailGrossProfit / $retailRevenue) * 100, 2) : 0.0;

        $coffeeGrossProfit = round($coffeeRevenue - $coffeeCogs, 2);
        $coffeeMargin = $coffeeRevenue > 0 ? round(($coffeeGrossProfit / $coffeeRevenue) * 100, 2) : 0.0;

        $wasteLogs = InventoryWasteLog::where('branch_id', $branchId)
            ->whereDate('created_at', $targetDate)
            ->get();

        $retailWaste = (float) $wasteLogs->where('division', 'retail')->sum('total_loss');
        $coffeeWaste = (float) $wasteLogs->where('division', 'coffee')->sum('total_loss');
        $generalWaste = (float) $wasteLogs->whereNotIn('division', ['retail', 'coffee'])->sum('total_loss');
        $totalWaste = round($retailWaste + $coffeeWaste + $generalWaste, 2);

        $retailNetProfit = round($retailGrossProfit - $retailWaste, 2);
        $coffeeNetProfit = round($coffeeGrossProfit - $coffeeWaste, 2);

        $cashPayments = 0.0;
        $qrisPayments = 0.0;

        foreach ($orders as $order) {
            if ($order->payments->isNotEmpty()) {
                foreach ($order->payments as $payment) {
                    $method = strtolower($payment->payment_method);
                    $netAmount = max(0, (float) $payment->amount - (float) $payment->change_amount);
                    if ($method === 'cash') {
                        $cashPayments += $netAmount;
                    } elseif ($method === 'qris') {
                        $qrisPayments += (float) $payment->amount;
                    }
                }
            } else {
                $method = strtolower($order->payment_method);
                $netAmount = (float) $order->total_amount;
                if ($method === 'cash') {
                    $cashPayments += $netAmount;
                } elseif ($method === 'qris') {
                    $qrisPayments += $netAmount;
                }
            }
        }

        $totalConsolidatedRevenue = round($retailRevenue + $coffeeRevenue, 2);
        $totalConsolidatedCogs = round($retailCogs + $coffeeCogs, 2);
        $totalConsolidatedGrossProfit = round($totalConsolidatedRevenue - $totalConsolidatedCogs, 2);
        $consolidatedMargin = $totalConsolidatedRevenue > 0
            ? round(($totalConsolidatedGrossProfit / $totalConsolidatedRevenue) * 100, 2)
            : 0.0;

        $pettyCashExpenses = PettyCashExpense::where('branch_id', $branchId)
            ->whereDate('created_at', $targetDate)
            ->get();
        $totalPettyCash = (float) $pettyCashExpenses->sum('amount');

        $netOperatingProfit = round($totalConsolidatedGrossProfit - $totalWaste - $totalPettyCash, 2);

        $shifts = CashShift::with('user')
            ->where('branch_id', $branchId)
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('start_time', $targetDate)
                    ->orWhereDate('created_at', $targetDate);
            })
            ->get();

        $shiftSummaries = [];
        $allShiftsBalanced = true;
        $totalDrawerExpected = 0.0;
        $totalDrawerActual = 0.0;

        foreach ($shifts as $shift) {
            $isClosed = $shift->status === 'closed';
            $discrepancy = (float) ($shift->discrepancy ?? 0.0);
            $isBalanced = $isClosed ? ($discrepancy === 0.0) : true;

            if ($isClosed && $discrepancy !== 0.0) {
                $allShiftsBalanced = false;
            }

            $cashierName = $shift->user?->name ?? 'Kasir';
            $statusLabel = $isBalanced
                ? "Laci kasir {$cashierName} klop (selisih Rp 0)"
                : "Laci kasir {$cashierName} selisih Rp ".number_format(abs($discrepancy), 0, ',', '.');

            $expected = (float) $shift->expected_cash_in_drawer;
            $actual = (float) ($shift->actual_cash_in_drawer ?? $shift->expected_cash_in_drawer);

            $totalDrawerExpected += $expected;
            $totalDrawerActual += $actual;

            $shiftSummaries[] = [
                'shift_id' => $shift->id,
                'cashier_name' => $cashierName,
                'opening_balance' => (float) $shift->opening_balance,
                'total_cash_sales' => (float) $shift->total_cash_sales,
                'total_qris_sales' => (float) $shift->total_qris_sales,
                'total_cash_out' => (float) $shift->total_cash_out,
                'expected_cash' => $expected,
                'actual_cash' => $actual,
                'discrepancy' => $discrepancy,
                'is_balanced' => $isBalanced,
                'status' => $shift->status,
                'status_label' => $statusLabel,
            ];
        }

        return [
            'report_date' => $targetDate,
            'branch' => [
                'id' => $branch->id,
                'code' => $branch->code,
                'name' => $branch->name,
            ],
            'divisions' => [
                'retail' => [
                    'division_name' => 'Minimarket / Retail',
                    'revenue' => round($retailRevenue, 2),
                    'cogs' => round($retailCogs, 2),
                    'gross_profit' => $retailGrossProfit,
                    'margin_percentage' => $retailMargin,
                    'waste_loss' => round($retailWaste, 2),
                    'net_profit_after_waste' => $retailNetProfit,
                ],
                'coffee' => [
                    'division_name' => 'Coffee Bar / F&B',
                    'revenue' => round($coffeeRevenue, 2),
                    'cogs' => round($coffeeCogs, 2),
                    'gross_profit' => $coffeeGrossProfit,
                    'margin_percentage' => $coffeeMargin,
                    'waste_loss' => round($coffeeWaste, 2),
                    'net_profit_after_waste' => $coffeeNetProfit,
                ],
            ],
            'consolidated' => [
                'gross_revenue' => $totalConsolidatedRevenue,
                'total_discount' => round((float) $orders->sum('discount_amount'), 2),
                'total_tax' => round((float) $orders->sum('tax_amount'), 2),
                'net_revenue' => round((float) $orders->sum('total_amount'), 2),
                'total_cogs' => $totalConsolidatedCogs,
                'total_gross_profit' => $totalConsolidatedGrossProfit,
                'gross_margin_percentage' => $consolidatedMargin,
                'total_waste_loss' => $totalWaste,
                'total_petty_cash_expense' => round($totalPettyCash, 2),
                'net_operating_profit' => $netOperatingProfit,
                'total_orders_count' => count($orders),
            ],
            'payment_methods' => [
                'cash' => round($cashPayments, 2),
                'qris' => round($qrisPayments, 2),
                'total' => round($cashPayments + $qrisPayments, 2),
            ],
            'waste_breakdown' => [
                'coffee' => round($coffeeWaste, 2),
                'retail' => round($retailWaste, 2),
                'general' => round($generalWaste, 2),
                'total' => $totalWaste,
            ],
            'cashier_status' => [
                'all_shifts_balanced' => $allShiftsBalanced,
                'total_cash_in_drawer_expected' => round($totalDrawerExpected, 2),
                'total_cash_in_drawer_actual' => round($totalDrawerActual, 2),
                'shifts' => $shiftSummaries,
            ],
        ];
    }
}

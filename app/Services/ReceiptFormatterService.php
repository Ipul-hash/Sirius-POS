<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;

class ReceiptFormatterService
{
    protected int $width = 40;

    /**
     * Generate thermal printer monospace receipt text for an order.
     */
    public function format(Order $order): string
    {
        $order->loadMissing(['branch', 'cashier', 'customer', 'items.product', 'payments']);

        $branch = $order->branch ?? Branch::find($order->branch_id);
        $cashier = $order->cashier;
        $customer = $order->customer;
        $payment = $order->payments->first();

        $lines = [];

        // 1. Header Toko
        $lines[] = $this->center(strtoupper($branch?->name ?? 'SIRIUS MART & COFFEE'));
        if ($branch?->address) {
            $lines[] = $this->center($branch->address);
        }
        if ($branch?->phone) {
            $lines[] = $this->center('Telp: '.$branch->phone);
        }
        $lines[] = str_repeat('=', $this->width);

        // 2. Metadata Transaksi
        $lines[] = $this->twoColumns('No. Faktur :', $order->invoice_number);
        $lines[] = $this->twoColumns('Tanggal    :', $order->order_date ? $order->order_date->format('d/m/Y H:i:s') : now()->format('d/m/Y H:i:s'));
        $lines[] = $this->twoColumns('Kasir      :', $cashier?->name ?? 'Kasir');
        if ($customer) {
            $lines[] = $this->twoColumns('Pelanggan  :', $customer->name.' ('.$customer->phone.')');
            if ($customer->current_points > 0) {
                $lines[] = $this->twoColumns('Poin Reward:', (string) $customer->current_points.' pts');
            }
        }
        $lines[] = str_repeat('-', $this->width);

        // 3. Header Tabel Item
        $lines[] = $this->twoColumns('ITEM', 'TOTAL');
        $lines[] = str_repeat('-', $this->width);

        // 4. Group / List Items
        foreach ($order->items as $item) {
            $prefix = $item->division === 'coffee' ? '[COFFEE] ' : '[RETAIL] ';
            $productName = $prefix.($item->product?->name ?? 'Item');
            $lines[] = $productName;

            $qtyPriceText = '  '.(float) $item->quantity.' x Rp '.number_format((float) $item->unit_price, 0, ',', '.');
            $lineTotalText = 'Rp '.number_format((float) $item->subtotal, 0, ',', '.');
            $lines[] = $this->twoColumns($qtyPriceText, $lineTotalText);

            if ((float) $item->discount_amount > 0) {
                $discountText = '  (Diskon: -Rp '.number_format((float) $item->discount_amount, 0, ',', '.').')';
                $lines[] = $discountText;
            }

            if ($item->notes) {
                $lines[] = '  * Note: '.$item->notes;
            }
        }

        $lines[] = str_repeat('-', $this->width);

        // 5. Total dan Pembayaran
        $lines[] = $this->twoColumns('Subtotal', 'Rp '.number_format((float) $order->subtotal, 0, ',', '.'));
        if ((float) $order->discount_amount > 0) {
            $lines[] = $this->twoColumns('Total Diskon', '-Rp '.number_format((float) $order->discount_amount, 0, ',', '.'));
        }
        if ((float) $order->tax_amount > 0) {
            $lines[] = $this->twoColumns('Pajak (PPN)', 'Rp '.number_format((float) $order->tax_amount, 0, ',', '.'));
        }
        $lines[] = str_repeat('-', $this->width);
        $lines[] = $this->twoColumns('TOTAL BELANJA', 'Rp '.number_format((float) $order->total_amount, 0, ',', '.'));

        $paymentMethodLabel = strtoupper($order->payment_method ?? 'CASH');
        $amountPaid = $payment ? (float) $payment->amount : (float) $order->total_amount;
        $changeAmount = $payment ? (float) $payment->change_amount : 0.0;

        $lines[] = $this->twoColumns('BAYAR ('.$paymentMethodLabel.')', 'Rp '.number_format($amountPaid, 0, ',', '.'));
        $lines[] = $this->twoColumns('KEMBALIAN', 'Rp '.number_format($changeAmount, 0, ',', '.'));

        // 6. Ringkasan Omset per Divisi (Cost Center ERP)
        $lines[] = str_repeat('-', $this->width);
        $lines[] = 'RINGKASAN OMSET DIVISI:';
        $lines[] = $this->twoColumns('  - Divisi Retail', 'Rp '.number_format((float) $order->retail_subtotal, 0, ',', '.'));
        $lines[] = $this->twoColumns('  - Divisi Coffee', 'Rp '.number_format((float) $order->coffee_subtotal, 0, ',', '.'));

        // 7. Banner Nomor Antrean Kopi Jika Ada Menu Kopi
        if (! empty($order->queue_number)) {
            $lines[] = str_repeat('=', $this->width);
            $lines[] = $this->center('ANTREAN COFFEE CORNER');
            $lines[] = '';
            $lines[] = $this->center('[ '.$order->queue_number.' ]');
            $lines[] = '';
            $lines[] = $this->center('Tunjukkan nomor ini ke Barista');
            $lines[] = $this->center('untuk pengambilan minuman Anda');
            $lines[] = str_repeat('=', $this->width);
        } else {
            $lines[] = str_repeat('=', $this->width);
        }

        // 8. Footer
        $lines[] = $this->center('Terima kasih atas kunjungan Anda!');
        $lines[] = $this->center('Barang yang sudah dibeli tidak dapat ditukar');
        $lines[] = $this->center('kecuali ada perjanjian sebelumnya.');
        $lines[] = str_repeat('=', $this->width);

        return implode("\n", $lines);
    }

    protected function center(string $text): string
    {
        $len = mb_strlen($text);
        if ($len >= $this->width) {
            return $text;
        }

        $padLeft = (int) floor(($this->width - $len) / 2);

        return str_repeat(' ', $padLeft).$text;
    }

    protected function twoColumns(string $left, string $right): string
    {
        $totalLen = mb_strlen($left) + mb_strlen($right);
        if ($totalLen >= $this->width) {
            return $left.' '.$right;
        }

        $spaces = $this->width - $totalLen;

        return $left.str_repeat(' ', $spaces).$right;
    }
}

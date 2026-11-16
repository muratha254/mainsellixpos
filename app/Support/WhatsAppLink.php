<?php

namespace App\Support;

use App\Models\Quotation;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class WhatsAppLink
{
    public static function url(Quotation $quotation): ?string
    {
        $quotation->loadMissing(['customer', 'company']);
        $phone = self::number(optional($quotation->customer)->mobile ?: optional($quotation->customer)->phone);
        if ($phone === null) {
            return null;
        }

        return 'https://wa.me/'.$phone.'?text='.rawurlencode(self::message($quotation));
    }

    public static function number(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        if (Str::startsWith($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $digits = '254'.$digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }

    public static function message(Quotation $quotation): string
    {
        $quotation->loadMissing(['customer', 'company']);
        $customer = $quotation->customer;
        $name = ($customer && ! $customer->is_walk_in) ? trim((string) $customer->name) : '';
        $company = trim((string) optional($quotation->company)->name);
        $lines = [
            $name !== '' ? 'Hello '.$name.',' : 'Hello,',
            'Please find quotation '.$quotation->number.' from '.($company !== '' ? $company : 'us').'.',
            'Total: KSh '.number_format((float) $quotation->total, 2),
        ];

        if ($quotation->valid_until) {
            $lines[] = 'Valid until: '.$quotation->valid_until->format('j F Y');
        }

        $lines[] = 'Download: '.URL::temporarySignedRoute(
            'quotations.share',
            now()->addDays(30),
            ['quotation' => $quotation->id]
        );

        return implode("\n", $lines);
    }
}

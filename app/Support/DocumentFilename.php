<?php

namespace App\Support;

use Illuminate\Support\Str;

class DocumentFilename
{
    public static function make(string $kind, ?string $customer, string $number): string
    {
        $prefix = $kind === 'quotation' ? 'quo' : 'inv';
        $name = Str::slug((string) $customer);
        $name = $name !== '' ? trim(substr($name, 0, 40), '-') : 'customer';

        $code = strtolower(trim($number));
        $code = preg_replace('/^(inv|invoice|quo|qt|qtn|quotation)[^a-z0-9]*/', '', $code) ?? '';
        $code = Str::slug($code !== '' ? $code : $number);
        if ($code === '') {
            $code = 'document';
        }

        return $prefix.'-'.$name.'-'.$code.'.pdf';
    }
}

<?php

namespace App\Support;

use App\Models\Company;

class CompanyDocumentProfile
{
    /**
     * @return array<string, mixed>
     */
    public static function forCompany(Company $company): array
    {
        $logoUrl = $company->logo_path ? asset('storage/'.ltrim($company->logo_path, '/')) : null;

        return [
            'companyName' => $company->name,
            'companyAddress' => $company->address ?: '-',
            'companyPhone' => $company->phone ?: '-',
            'companyEmail' => $company->email ?: '-',
            'companyWebsite' => $company->website ?: '',
            'companyTaxPin' => $company->tax_pin ?: '',
            'invoiceFooter' => 'Thank you for your business.',
            'logo_url' => $logoUrl,
            'logo_pdf_path' => $company->logo_path ? storage_path('app/public/'.ltrim($company->logo_path, '/')) : null,
        ];
    }
}

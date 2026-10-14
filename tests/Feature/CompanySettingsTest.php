<?php

namespace Tests\Feature;

use App\Models\Company;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_receipt_settings_save_when_another_company_already_uses_the_name_slug(): void
    {
        $other = Company::query()->create([
            'name' => 'Skyblue Cosmetics',
            'slug' => 'skyblue-cosmetics',
            'phone' => '0700000001',
            'email' => 'other@sellix.local',
            'address' => 'Other branch',
            'is_active' => true,
        ]);
        $other->delete();

        $this->actingAsAdmin()
            ->put(route('settings.company.update'), $this->receiptPayload())
            ->assertRedirect(route('settings.company', ['tab' => 'receipt']));

        $this->company->refresh();
        $this->assertSame('Skyblue Cosmetics', $this->company->name);
        $this->assertSame('skyblue-cosmetics-2', $this->company->slug);
    }

    private function receiptPayload(): array
    {
        return [
            'tab' => 'receipt',
            'name' => 'Skyblue Cosmetics',
            'phone' => '0711000000',
            'tax_pin' => '',
            'address' => 'Ruiru',
            'receipt_control' => 'Print One Copy',
            'choose_print' => 'Invoice Only',
            'captain_category' => 'Captain Without Price',
            'display_logo' => 'Yes',
            'display_name' => 'Yes',
            'display_address' => 'Yes',
            'display_phone' => 'Yes',
            'display_postcode' => 'No',
            'display_email' => 'No',
            'printer_type' => 'Thermal Printer 80mm',
            'display_vat_kra' => 'No',
            'display_tax_calc' => 'No',
            'display_prev_bal' => 'No',
            'display_standing_bal' => 'Yes',
            'receipt_heading' => 'Original Cash Sale Receipt',
            'receipt_confirm_note' => 'PLEASE CONFIRM YOUR GOODS AND CHANGE',
            'receipt_vat_note' => 'All Prices are VAT inclusive',
            'receipt_footer' => 'Thank You',
            'thermal_receipt_style' => 'dashed',
            'thermal_font_size' => '10px',
            'thermal_font_family' => 'Tahoma',
            'till_paybill' => '542542',
            'till_account_no' => '202331',
        ];
    }
}

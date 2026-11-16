<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Quotation;
use App\Services\SettingsService;
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

    public function test_invoice_and_quotation_colours_are_saved_and_printed(): void
    {
        $this->actingAsAdmin()->get(route('settings.company'))
            ->assertOk()
            ->assertSee('Invoice and quotation header colour')
            ->assertSee('Invoice and quotation accent colour');

        $this->actingAsAdmin()
            ->put(route('settings.company.update'), $this->profilePayload([
                'document_header_color' => '#112233',
                'document_accent_color' => '#ABCDEF',
            ]))
            ->assertRedirect(route('settings.company', ['tab' => 'basic']));

        $settings = app(SettingsService::class);
        $this->assertSame('#112233', $settings->get($this->company->id, 'document_header_color'));
        $this->assertSame('#ABCDEF', $settings->get($this->company->id, 'document_accent_color'));

        $this->actingAsAdmin()->post(route('quotations.store'), [
            'customer_id' => $this->customer->id,
            'quote_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 0,
            ]],
        ])->assertRedirect();

        $quotation = Quotation::query()->latest('id')->first();
        $this->actingAsAdmin()->get(route('quotations.print', $quotation))
            ->assertOk()
            ->assertSee('#112233')
            ->assertSee('#ABCDEF');
    }

    private function profilePayload(array $extra = []): array
    {
        return array_merge([
            'tab' => 'basic',
            'name' => $this->company->name,
            'phone' => $this->company->phone,
            'email' => $this->company->email,
            'address' => $this->company->address,
            'customer_setup' => 'No',
            'login_ui' => 'Touch Login2',
            'waiting_time' => 15,
            'expiry_alert_days' => 5,
            'loyalty_points_rate' => 0,
            'allow_order_sale' => 'No',
            'allow_credit_sale' => 'Yes',
            'show_qty_pos' => 'Yes',
            'show_cost_pos' => 'No',
            'show_uom_pos' => 'No',
            'show_disc_pos' => 'No',
            'menu_order' => 'Fast Moving',
            'quotation_module' => 'Yes',
            'delivery_note' => 'No',
            'task_module' => 'No',
            'enable_one_off_sale' => 'No',
            'capture_serial' => 'Yes',
            'duplicate_item_pos' => 'No',
            'item_cost_control' => 'Get Average',
            'shift_control' => 'When Shift Invoices are fully settled',
            'default_price' => 'RETAIL PRICE',
        ], $extra);
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

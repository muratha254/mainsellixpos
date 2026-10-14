<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\PermissionCatalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\CreatesSellixWorld;
use Tests\TestCase;

class StockTakeTest extends TestCase
{
    use CreatesSellixWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSellixWorld();
    }

    public function test_cashier_cannot_open_stock_take_and_does_not_see_the_menu(): void
    {
        $this->actingAsCashier()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Stock Take');

        $this->get(route('stock.takes.index'))->assertForbidden();
        $this->post(route('stock.takes.store'), [
            'branch_id' => $this->branch->id,
            'scope' => 'all',
        ])->assertForbidden();
    }

    public function test_inventory_manager_sees_stock_take_and_creating_a_session_does_not_change_stock(): void
    {
        $this->actingAsInventoryManager()
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Stock Take');

        $this->post(route('stock.takes.store'), [
            'branch_id' => $this->branch->id,
            'scope' => 'products',
            'product_ids' => [$this->product->id],
        ])->assertRedirect();

        $take = StockTake::query()->first();
        $this->assertNotNull($take);
        $this->assertStringStartsWith('STK-', $take->number);
        $this->assertSame(StockTake::STATUS_IN_PROGRESS, $take->status);
        $this->assertSame(100.0, (float) $take->lines->first()->system_quantity);
        $this->assertSame(100.0, $this->stockQty());
        $this->assertSame(0, StockMovement::query()->where('type', StockMovement::ADJUSTMENT)->count());

        $this->get(route('stock.takes.index'))
            ->assertOk()
            ->assertSee($take->number)
            ->assertSee('In Progress');
    }

    public function test_excel_and_pdf_exports_and_a_completed_sheet_can_be_imported(): void
    {
        $take = $this->startTake();

        $excel = $this->actingAsInventoryManager()->get(route('stock.takes.excel', $take));
        $excel->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'stk') . '.xlsx';
        file_put_contents($path, $excel->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        $this->assertSame($take->number, $sheet->getCell('B1')->getValue());
        $this->assertSame('MAIN', $sheet->getCell('B2')->getValue());
        $this->assertSame($this->product->id, (int) $sheet->getCell('B6')->getValue());
        $this->assertSame(100.0, (float) $sheet->getCell('H6')->getValue());
        $this->assertSame(null, $sheet->getCell('I6')->getValue());
        $this->assertStringContainsString('I6-H6', (string) $sheet->getCell('J6')->getValue());

        $sheet->setCellValue('H6', 999);
        $sheet->setCellValue('I6', 95);
        (new Xlsx($sheet->getParent()))->save($path);

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.upload', $take), [
                'sheet' => new UploadedFile($path, 'count.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])
            ->assertSessionHas('success');

        $line = $take->fresh()->lines->first();
        $this->assertEquals(95, (float) $line->counted_quantity);
        $this->assertEquals(100, (float) $line->system_quantity);
        $this->assertEquals(-5, $line->variance());
        $this->assertSame('Shortage', $line->countStatus());
        $this->assertSame(100.0, $this->stockQty());

        $this->actingAsInventoryManager()
            ->get(route('stock.takes.show', $take))
            ->assertOk()
            ->assertSee('Shortage')
            ->assertSee('-5')
            ->assertSee('-250.00');

        $pdf = $this->actingAsInventoryManager()->get(route('stock.takes.pdf', $take));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_blank_counts_stay_blank_and_zero_is_a_real_count(): void
    {
        $take = $this->startTake();
        $this->upload($take, [$this->product->id => 95]);
        $this->upload($take, [$this->product->id => '']);
        $line = $take->fresh()->lines->first();
        $this->assertNull($line->counted_quantity);
        $this->assertSame('Uncounted', $line->countStatus());

        $this->upload($take, [$this->product->id => 0]);
        $line = $take->fresh()->lines->first();
        $this->assertEquals(0, (float) $line->counted_quantity);
        $this->assertSame('Shortage', $line->countStatus());
        $this->assertEquals(-100, $line->variance());
        $this->assertSame(100.0, $this->stockQty());
    }

    public function test_invalid_rows_are_rejected_without_changing_counts_or_stock(): void
    {
        $take = $this->startTake();
        $this->upload($take, [$this->product->id => 95]);

        $cases = [
            [[$take->number, $this->product->id, '', '', '', '', '', 100, -3, '', '']],
            [[$take->number, $this->product->id, '', '', '', '', '', 100, 'abc', '', '']],
            [[$take->number, $this->product->id, '', '', '', '', '', 100, '1.23456', '', '']],
            [[$take->number, 999999, '', '', '', '', '', 100, 1, '', '']],
            [
                [$take->number, $this->product->id, '', '', '', '', '', 100, 90, '', ''],
                [$take->number, $this->product->id, '', '', '', '', '', 100, 80, '', ''],
            ],
        ];

        foreach ($cases as $rows) {
            $this->actingAsInventoryManager()
                ->post(route('stock.takes.upload', $take), ['sheet' => $this->workbook($take, [], $rows)])
                ->assertSessionHas('error');
            $this->assertEquals(95, (float) $take->fresh()->lines->first()->counted_quantity);
            $this->assertSame(100.0, $this->stockQty());
        }

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.upload', $take), [
                'sheet' => $this->workbook($take, [], [
                    [$take->number, $this->product->id, '', '', '', '', '', 100, 90, '', ''],
                ], 'STK-OTHER'),
            ])
            ->assertSessionHas('error');

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.upload', $take), [
                'sheet' => $this->workbook($take, [], [
                    [$take->number, $this->product->id, '', '', '', '', '', 100, 90, '', ''],
                ], null, 'WAREHOUSE'),
            ])
            ->assertSessionHas('error');

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.upload', $take), [
                'sheet' => UploadedFile::fake()->create('counts.csv', 20, 'text/csv'),
            ])
            ->assertSessionHasErrors('sheet');

        $this->assertEquals(95, (float) $take->fresh()->lines->first()->counted_quantity);
        $this->assertSame(100.0, $this->stockQty());
    }

    public function test_a_user_cannot_open_another_branch_stock_take(): void
    {
        $take = $this->startTake();
        $user = User::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->secondaryBranch->id,
            'role_id' => $this->roles[PermissionCatalog::INVENTORY_MANAGER]->id,
            'name' => 'Warehouse',
            'email' => 'warehouse@test.local',
            'username' => 'warehouse',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        $this->actingAs($user->fresh(['role.permissions']))
            ->withSession(['current_branch_id' => $this->secondaryBranch->id]);

        foreach ([
            $this->get(route('stock.takes.show', $take)),
            $this->post(route('stock.takes.upload', $take), [
                'sheet' => $this->workbook($take, [$this->product->id => 1]),
            ]),
            $this->post(route('stock.takes.reconcile', $take)),
        ] as $response) {
            $this->assertContains($response->status(), [403, 404]);
        }
        $this->assertSame(100.0, $this->stockQty());
    }

    public function test_approval_and_reconciliation_set_stock_once_and_keep_the_snapshot(): void
    {
        $take = $this->startTake();
        $this->upload($take, [$this->product->id => 95]);

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.reconcile', $take))
            ->assertSessionHas('error');
        $this->assertSame(100.0, $this->stockQty());

        $this->actingAsInventoryManager()->post(route('stock.takes.submit', $take))->assertSessionHas('success');
        $this->actingAsInventoryManager()->post(route('stock.takes.approve', $take))->assertSessionHas('success');
        $this->actingAsInventoryManager()
            ->post(route('stock.takes.reconcile', $take))
            ->assertSessionHas('success');

        $take->refresh();
        $this->assertSame(StockTake::STATUS_RECONCILED, $take->status);
        $this->assertNotNull($take->approved_at);
        $this->assertNotNull($take->reconciled_at);
        $this->assertSame(100.0, (float) $take->lines->first()->system_quantity);
        $this->assertEquals(95, (float) $take->lines->first()->counted_quantity);
        $this->assertEquals(-5, (float) $take->lines->first()->applied_variance);
        $this->assertSame(95.0, $this->stockQty());

        $movements = StockMovement::query()
            ->where('reference_type', StockTake::class)
            ->where('reference_id', $take->id)
            ->get();
        $this->assertCount(1, $movements);
        $this->assertSame(StockMovement::ADJUSTMENT, $movements->first()->type);
        $this->assertEquals(5, (float) $movements->first()->quantity_out);
        $this->assertSame($take->number, $movements->first()->reference_number);
        $this->assertTrue(
            AuditLog::query()->where('module', 'stock_takes')->where('action', 'reconcile')->where('auditable_id', $take->id)->exists()
        );

        $this->actingAsInventoryManager()->post(route('stock.takes.reconcile', $take))->assertSessionHas('success');
        $this->assertSame(95.0, $this->stockQty());
        $this->assertSame(1, StockMovement::query()->where('reference_type', StockTake::class)->where('reference_id', $take->id)->count());

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.upload', $take), [
                'sheet' => $this->workbook($take, [$this->product->id => 10]),
            ])
            ->assertSessionHas('error');
        $this->assertSame(95.0, $this->stockQty());
    }

    public function test_reconciliation_uses_live_stock_and_skips_uncounted_items(): void
    {
        $other = $this->makeProduct('Other Item', 'OTH-1', 20);
        $take = $this->startTake([$this->product->id, $other->id]);
        app(InventoryService::class)->apply([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'product_id' => $this->product->id,
            'type' => StockMovement::ADJUSTMENT,
            'quantity_out' => 10,
            'user_id' => $this->inventoryManager->id,
            'notes' => 'Sold during the count',
        ]);
        $this->assertSame(90.0, $this->stockQty());

        $this->upload($take, [$this->product->id => 95, $other->id => '']);
        $this->actingAsInventoryManager()->post(route('stock.takes.submit', $take));
        $this->actingAsInventoryManager()->post(route('stock.takes.approve', $take));
        $this->actingAsInventoryManager()->post(route('stock.takes.reconcile', $take))->assertSessionHas('success');

        $this->assertSame(95.0, $this->stockQty());
        $this->assertSame(20.0, $this->stockQty($this->branch->id, $other->id));
        $line = $take->fresh()->lines->firstWhere('product_id', $this->product->id);
        $this->assertEquals(100, (float) $line->system_quantity);
        $this->assertEquals(5, (float) $line->applied_variance);
        $otherLine = $take->lines->firstWhere('product_id', $other->id);
        $this->assertNull($otherLine->counted_quantity);
        $this->assertNull($otherLine->applied_variance);
    }

    public function test_a_failed_reconciliation_rolls_back_every_line(): void
    {
        $other = $this->makeProduct('Second Item', 'OTH-2', 10);
        $take = $this->startTake([$this->product->id, $other->id]);
        $this->upload($take, [$this->product->id => 90, $other->id => 4]);
        $this->actingAsInventoryManager()->post(route('stock.takes.submit', $take));
        $this->actingAsInventoryManager()->post(route('stock.takes.approve', $take));
        $take->lines()->where('product_id', $other->id)->update(['counted_quantity' => -5]);

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.reconcile', $take))
            ->assertSessionHas('error');

        $take->refresh();
        $this->assertSame(StockTake::STATUS_PENDING, $take->status);
        $this->assertNull($take->reconciled_at);
        $this->assertSame(100.0, $this->stockQty());
        $this->assertSame(10.0, $this->stockQty($this->branch->id, $other->id));
        $this->assertSame(0, StockMovement::query()->where('reference_type', StockTake::class)->where('reference_id', $take->id)->count());
    }

    private function startTake(?array $productIds = null): StockTake
    {
        $this->actingAsInventoryManager()->post(route('stock.takes.store'), [
            'branch_id' => $this->branch->id,
            'scope' => 'products',
            'product_ids' => $productIds ?: [$this->product->id],
        ])->assertRedirect();

        return StockTake::query()->latest('id')->firstOrFail();
    }

    private function upload(StockTake $take, array $counts): void
    {
        $rows = [];
        foreach ($take->lines()->orderBy('id')->get() as $line) {
            $rows[] = [
                $take->number,
                $line->product_id,
                $line->sku,
                '',
                $line->item_name,
                '',
                '',
                $line->system_quantity,
                $counts[$line->product_id] ?? '',
                '',
                '',
            ];
        }

        $this->actingAsInventoryManager()
            ->post(route('stock.takes.upload', $take), ['sheet' => $this->workbook($take, [], $rows)])
            ->assertSessionHas('success');
    }

    private function workbook(StockTake $take, array $counts, ?array $rows = null, ?string $number = null, ?string $branch = null): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Stock Take');
        $sheet->setCellValue('B1', $number ?? $take->number);
        $sheet->setCellValue('A2', 'Branch');
        $sheet->setCellValue('B2', $branch ?? 'MAIN');
        $sheet->fromArray([
            'Stock Take Ref', 'Product ID', 'SKU', 'Barcode', 'Item Name', 'Category', 'Unit',
            'System Quantity', 'Counted Quantity', 'Variance', 'Notes',
        ], null, 'A5');

        if ($rows === null) {
            $row = 6;
            foreach ($take->lines as $line) {
                $sheet->setCellValue('A' . $row, $take->number);
                $sheet->setCellValue('B' . $row, $line->product_id);
                $sheet->setCellValue('I' . $row, $counts[$line->product_id] ?? null);
                $row++;
            }
        } else {
            $sheet->fromArray($rows, null, 'A6', true);
        }

        $path = tempnam(sys_get_temp_dir(), 'stk') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'count.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function makeProduct(string $name, string $sku, float $quantity): Product
    {
        $product = Product::query()->create([
            'company_id' => $this->company->id,
            'name' => $name,
            'sku' => $sku,
            'purchase_price' => 50,
            'selling_price' => 100,
            'manage_stock' => true,
            'allow_negative_stock' => false,
            'is_active' => true,
            'for_sale' => true,
        ]);
        ProductBranchStock::query()->create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'product_id' => $product->id,
            'product_variant_id' => 0,
            'quantity' => $quantity,
            'average_cost' => 50,
        ]);

        return $product;
    }
}

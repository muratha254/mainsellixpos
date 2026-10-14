<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBranchStock;
use App\Models\StockMovement;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class StockTakeService
{
    private InventoryService $inventory;

    private DocumentNumberService $numbers;

    private AuditLogger $audit;

    public function __construct(InventoryService $inventory, DocumentNumberService $numbers, AuditLogger $audit)
    {
        $this->inventory = $inventory;
        $this->numbers = $numbers;
        $this->audit = $audit;
    }

    public function create(int $companyId, int $branchId, User $user, string $scope, ?int $categoryId, array $productIds): StockTake
    {
        $products = Product::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->availableAtBranch($branchId)
            ->with(['category', 'unit'])
            ->when($scope === 'category', function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->when($scope === 'products', function ($query) use ($productIds) {
                $query->whereIn('id', $productIds);
            })
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            throw new InvalidArgumentException('No products at this branch match the selection.');
        }

        return DB::transaction(function () use ($companyId, $branchId, $user, $scope, $categoryId, $products) {
            $take = StockTake::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'number' => $this->numbers->next($companyId, 'stock_take'),
                'status' => StockTake::STATUS_IN_PROGRESS,
                'scope' => $scope,
                'category_id' => $scope === 'category' ? $categoryId : null,
                'created_by' => $user->id,
            ]);

            foreach ($products as $product) {
                $stock = ProductBranchStock::query()
                    ->withoutGlobalScope('company')
                    ->withoutGlobalScope('branch')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->where('product_id', $product->id)
                    ->orderByRaw('CASE WHEN product_variant_id = 0 THEN 0 ELSE 1 END')
                    ->first();

                $take->lines()->create([
                    'product_id' => $product->id,
                    'product_variant_id' => (int) ($stock->product_variant_id ?? 0),
                    'sku' => $product->sku,
                    'barcode' => $product->barcode,
                    'item_name' => $product->name,
                    'category_name' => optional($product->category)->name,
                    'unit_name' => optional($product->unit)->short_name ?: optional($product->unit)->name,
                    'system_quantity' => $stock ? (float) $stock->quantity : 0,
                ]);
            }

            $this->audit->record('create', 'stock_takes', $take, null, [
                'number' => $take->number,
                'branch_id' => $branchId,
                'lines' => $products->count(),
            ]);

            return $take->load('lines');
        });
    }

    public function workbook(StockTake $take): Spreadsheet
    {
        $take->loadMissing(['lines', 'branch']);
        $sheet = (new Spreadsheet())->getActiveSheet();
        $sheet->setTitle('Stock Take');
        $sheet->setCellValue('A1', 'Stock Take');
        $sheet->setCellValue('B1', $take->number);
        $sheet->setCellValue('A2', 'Branch');
        $sheet->setCellValue('B2', optional($take->branch)->name);
        $sheet->setCellValue('A3', 'Generated');
        $sheet->setCellValue('B3', optional($take->created_at)->format('Y-m-d H:i'));

        $headers = [
            'A' => 'Stock Take Ref',
            'B' => 'Product ID',
            'C' => 'SKU',
            'D' => 'Barcode',
            'E' => 'Item Name',
            'F' => 'Category',
            'G' => 'Unit',
            'H' => 'System Quantity',
            'I' => 'Counted Quantity',
            'J' => 'Variance',
            'K' => 'Notes',
        ];
        foreach ($headers as $column => $label) {
            $sheet->setCellValue($column . '5', $label);
        }

        $row = 6;
        foreach ($take->lines as $line) {
            $sheet->setCellValue('A' . $row, $take->number);
            $sheet->setCellValue('B' . $row, $line->product_id);
            $sheet->setCellValue('C' . $row, $line->sku);
            $sheet->setCellValue('D' . $row, $line->barcode);
            $sheet->setCellValue('E' . $row, $line->item_name);
            $sheet->setCellValue('F' . $row, $line->category_name);
            $sheet->setCellValue('G' . $row, $line->unit_name);
            $sheet->setCellValue('H' . $row, (float) $line->system_quantity);
            if ($line->counted_quantity !== null) {
                $sheet->setCellValue('I' . $row, (float) $line->counted_quantity);
            }
            $sheet->setCellValue('J' . $row, '=IF(I' . $row . '="","",I' . $row . '-H' . $row . ')');
            $sheet->setCellValue('K' . $row, $line->notes);
            $row++;
        }
        $last = max(6, $row - 1);

        $sheet->getStyle('A5:K5')->getFont()->setBold(true);
        $sheet->getStyle('A5:K5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E79');
        $sheet->getStyle('A5:K5')->getFont()->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('H6:J' . $last)->getNumberFormat()->setFormatCode('0.####');
        $sheet->getStyle('I6:I' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
        $sheet->getStyle('K6:K' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
        $sheet->getStyle('A6:H' . $last)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF2F2F2');
        $sheet->freezePane('A6');
        $sheet->setAutoFilter('A5:K' . $last);

        $widths = ['A' => 18, 'B' => 14, 'C' => 16, 'D' => 16, 'E' => 32, 'F' => 18, 'G' => 12, 'H' => 18, 'I' => 18, 'J' => 14, 'K' => 28];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $validation = $sheet->getCell('I6')->getDataValidation();
        $validation->setType(DataValidation::TYPE_DECIMAL);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle('Quantity');
        $validation->setError('Enter a number. Leave the cell blank if the item was not counted.');
        $validation->setFormula1('-999999');
        $validation->setFormula2('9999999');
        if ($last >= 6) {
            $sheet->setDataValidation('I6:I' . $last, $validation);
        }

        $sheet->getProtection()->setSheet(true);
        $sheet->getStyle('I6:I' . $last)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $sheet->getStyle('K6:K' . $last)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);

        return $sheet->getParent();
    }

    public function writeXlsx(Spreadsheet $spreadsheet, $stream): void
    {
        (new Xlsx($spreadsheet))->save($stream);
    }

    /**
     * @return array<int, string>
     */
    public function import(StockTake $take, UploadedFile $file): array
    {
        if (! $take->isOpen()) {
            throw new InvalidArgumentException('This stock take can no longer accept a count sheet.');
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable $e) {
            return ['The file is not a readable Excel workbook.'];
        }

        $sheet = $spreadsheet->getActiveSheet();
        $reference = trim((string) $sheet->getCell('B1')->getValue());
        $branchName = trim((string) $sheet->getCell('B2')->getValue());
        if ($reference !== $take->number) {
            return ['This workbook is for stock take ' . ($reference !== '' ? $reference : '(blank)') . ', not ' . $take->number . '.'];
        }
        $expectedBranch = trim((string) optional($take->branch)->name);
        if (strcasecmp($branchName, $expectedBranch) !== 0) {
            return ['This workbook is for branch "' . $branchName . '", not "' . $expectedBranch . '".'];
        }

        $headerRow = null;
        $columns = [];
        for ($row = 1; $row <= min(15, $sheet->getHighestRow()); $row++) {
            $found = [];
            foreach (range('A', 'K') as $column) {
                $label = strtolower(trim((string) $sheet->getCell($column . $row)->getValue()));
                if ($label !== '') {
                    $found[$label] = $column;
                }
            }
            if (isset($found['product id'])) {
                $headerRow = $row;
                $columns = $found;
                break;
            }
        }
        if (! $headerRow || ! isset($columns['stock take ref'], $columns['product id'], $columns['counted quantity'])) {
            return ['The workbook is missing the Stock Take Ref, Product ID, or Counted Quantity column.'];
        }

        $lines = $take->lines()->get()->keyBy('product_id');
        $errors = [];
        $updates = [];
        $seen = [];
        $highest = $sheet->getHighestRow();
        for ($row = $headerRow + 1; $row <= $highest; $row++) {
            $productIdRaw = trim((string) $sheet->getCell($columns['product id'] . $row)->getValue());
            $countedRaw = $sheet->getCell($columns['counted quantity'] . $row)->getValue();
            $notes = isset($columns['notes']) ? trim((string) $sheet->getCell($columns['notes'] . $row)->getValue()) : '';
            $ref = trim((string) $sheet->getCell($columns['stock take ref'] . $row)->getValue());
            if ($productIdRaw === '' && ($countedRaw === null || $countedRaw === '') && $notes === '' && $ref === '') {
                continue;
            }
            if ($ref !== '' && $ref !== $take->number) {
                $errors[] = 'Row ' . $row . ': stock take reference does not match ' . $take->number . '.';
                continue;
            }
            if ($productIdRaw === '' || ! ctype_digit($productIdRaw)) {
                $errors[] = 'Row ' . $row . ': product ID is missing or not a number.';
                continue;
            }
            $productId = (int) $productIdRaw;
            if (isset($seen[$productId])) {
                $errors[] = 'Row ' . $row . ': product ID ' . $productId . ' is duplicated (also on row ' . $seen[$productId] . ').';
                continue;
            }
            $seen[$productId] = $row;
            $line = $lines->get($productId);
            if (! $line) {
                $errors[] = 'Row ' . $row . ': product ID ' . $productId . ' is not on this stock take.';
                continue;
            }
            if ($countedRaw === null || $countedRaw === '') {
                $updates[$line->id] = ['counted_quantity' => null, 'notes' => $notes !== '' ? $notes : null];
                continue;
            }
            $countedText = trim((string) $countedRaw);
            if (! is_numeric($countedText) || ! preg_match('/^-?\d+(\.\d+)?$/', $countedText)) {
                $errors[] = 'Row ' . $row . ': counted quantity must be a number. Leave it blank if the item was not counted.';
                continue;
            }
            if (preg_match('/\.\d{5,}$/', $countedText)) {
                $errors[] = 'Row ' . $row . ': counted quantity uses more decimal places than stock allows (4).';
                continue;
            }
            $counted = round((float) $countedText, 4);
            $product = $line->product ?: Product::query()->withoutGlobalScope('company')->find($productId);
            if ($counted < 0 && ! ($product && $product->allow_negative_stock)) {
                $errors[] = 'Row ' . $row . ': counted quantity cannot be negative.';
                continue;
            }
            $updates[$line->id] = ['counted_quantity' => $counted, 'notes' => $notes !== '' ? $notes : null];
        }

        if ($errors !== []) {
            return $errors;
        }

        DB::transaction(function () use ($take, $updates, $file) {
            $take->lines()->update([
                'counted_quantity' => null,
                'notes' => null,
            ]);
            foreach ($updates as $lineId => $values) {
                StockTakeLine::query()->whereKey($lineId)->update($values);
            }
            $take->forceFill([
                'status' => StockTake::STATUS_UPLOADED,
                'upload_name' => $file->getClientOriginalName(),
                'upload_hash' => hash_file('sha256', $file->getRealPath()),
                'approved_by' => null,
                'approved_at' => null,
            ])->save();
        });

        $this->audit->record('update', 'stock_takes', $take, null, [
            'number' => $take->number,
            'upload' => $file->getClientOriginalName(),
            'counted' => collect($updates)->filter(function ($row) {
                return $row['counted_quantity'] !== null;
            })->count(),
        ]);

        return [];
    }

    public function submit(StockTake $take): void
    {
        if ($take->status !== StockTake::STATUS_UPLOADED) {
            throw new InvalidArgumentException('Upload a completed count sheet before submitting it for approval.');
        }
        if (! $take->lines()->whereNotNull('counted_quantity')->exists()) {
            throw new InvalidArgumentException('At least one counted quantity is required. Blank cells are not treated as zero.');
        }
        $take->forceFill([
            'status' => StockTake::STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();
    }

    public function approve(StockTake $take, User $user): void
    {
        if ($take->status !== StockTake::STATUS_PENDING) {
            throw new InvalidArgumentException('Only a stock take that is pending approval can be approved.');
        }
        $take->forceFill([
            'approved_by' => $user->id,
            'approved_at' => now(),
        ])->save();
        $this->audit->record('approve', 'stock_takes', $take, null, ['number' => $take->number]);
    }

    public function cancel(StockTake $take, User $user): void
    {
        if ($take->status === StockTake::STATUS_RECONCILED) {
            throw new InvalidArgumentException('A reconciled stock take cannot be cancelled.');
        }
        if ($take->status === StockTake::STATUS_CANCELLED) {
            return;
        }
        $take->forceFill([
            'status' => StockTake::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();
        $this->audit->record('cancel', 'stock_takes', $take, null, [
            'number' => $take->number,
            'user_id' => $user->id,
        ]);
    }

    public function reconcile(StockTake $take, User $user): void
    {
        if ($take->status === StockTake::STATUS_RECONCILED) {
            return;
        }
        if ($take->status !== StockTake::STATUS_PENDING || ! $take->approved_at) {
            throw new InvalidArgumentException('Approve the stock take before reconciling it.');
        }

        DB::transaction(function () use ($take, $user) {
            $locked = StockTake::query()->whereKey($take->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === StockTake::STATUS_RECONCILED) {
                return;
            }
            if ($locked->status !== StockTake::STATUS_PENDING || ! $locked->approved_at) {
                throw new InvalidArgumentException('Approve the stock take before reconciling it.');
            }

            $already = StockMovement::query()
                ->withoutGlobalScope('company')
                ->withoutGlobalScope('branch')
                ->where('reference_type', StockTake::class)
                ->where('reference_id', $locked->id)
                ->exists();
            if ($already) {
                $locked->forceFill([
                    'status' => StockTake::STATUS_RECONCILED,
                    'reconciled_by' => $locked->reconciled_by ?: $user->id,
                    'reconciled_at' => $locked->reconciled_at ?: now(),
                ])->save();

                return;
            }

            foreach ($locked->lines()->get() as $line) {
                if ($line->counted_quantity === null) {
                    continue;
                }
                $stock = ProductBranchStock::query()
                    ->withoutGlobalScope('company')
                    ->withoutGlobalScope('branch')
                    ->where('company_id', $locked->company_id)
                    ->where('branch_id', $locked->branch_id)
                    ->where('product_id', $line->product_id)
                    ->where('product_variant_id', (int) $line->product_variant_id)
                    ->lockForUpdate()
                    ->first();
                $current = $stock ? round((float) $stock->quantity, 4) : 0.0;
                $target = round((float) $line->counted_quantity, 4);
                $delta = round($target - $current, 4);
                $line->forceFill([
                    'reconciled_system_quantity' => $current,
                    'applied_variance' => $delta,
                ])->save();
                if (abs($delta) < 0.0001) {
                    continue;
                }
                $payload = [
                    'company_id' => $locked->company_id,
                    'branch_id' => $locked->branch_id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => (int) $line->product_variant_id,
                    'type' => StockMovement::ADJUSTMENT,
                    'unit_cost' => $stock ? $stock->average_cost : null,
                    'user_id' => $user->id,
                    'notes' => 'Stock take ' . $locked->number,
                    'reference_type' => StockTake::class,
                    'reference_id' => $locked->id,
                    'reference_number' => $locked->number,
                    'occurred_at' => now(),
                ];
                if ($delta > 0) {
                    $payload['quantity_in'] = $delta;
                } else {
                    $payload['quantity_out'] = abs($delta);
                }
                $this->inventory->apply($payload);
            }

            $locked->forceFill([
                'status' => StockTake::STATUS_RECONCILED,
                'reconciled_by' => $user->id,
                'reconciled_at' => now(),
            ])->save();
        });

        $this->audit->record('reconcile', 'stock_takes', $take->fresh(), null, ['number' => $take->number]);
    }

    public function summary(StockTake $take): array
    {
        $lines = $take->lines;
        $counted = $lines->filter->wasCounted();
        $short = $counted->filter(function (StockTakeLine $line) {
            return $line->variance() < -0.0001;
        });
        $surplus = $counted->filter(function (StockTakeLine $line) {
            return $line->variance() > 0.0001;
        });
        $matched = $counted->filter(function (StockTakeLine $line) {
            $variance = $line->variance();

            return $variance !== null && abs($variance) < 0.0001;
        });

        return [
            'included' => $lines->count(),
            'counted' => $counted->count(),
            'uncounted' => $lines->count() - $counted->count(),
            'matched' => $matched->count(),
            'shortages' => $short->count(),
            'surpluses' => $surplus->count(),
            'units_short' => round($short->sum(function (StockTakeLine $line) {
                return abs((float) $line->variance());
            }), 4),
            'units_surplus' => round($surplus->sum(function (StockTakeLine $line) {
                return (float) $line->variance();
            }), 4),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\Product;
use App\Models\StockTake;
use App\Services\StockTakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDF;

class StockTakeController extends Controller
{
    public function index()
    {
        $this->authorizePermission('stocktake.view');

        $takes = StockTake::query()
            ->with(['branch', 'creator', 'approver', 'reconciler'])
            ->withCount([
                'lines',
                'lines as counted_count' => function ($query) {
                    $query->whereNotNull('counted_quantity');
                },
                'lines as shortage_count' => function ($query) {
                    $query->whereNotNull('counted_quantity')->whereRaw('counted_quantity < system_quantity');
                },
                'lines as surplus_count' => function ($query) {
                    $query->whereNotNull('counted_quantity')->whereRaw('counted_quantity > system_quantity');
                },
            ])
            ->latest()
            ->get();

        return view('stock.takes.index', [
            'activeMenu' => 'stock.takes',
            'takes' => $takes,
            'canCreate' => auth()->user()->hasPermission('stocktake.create'),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizePermission('stocktake.create');
        $requested = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
        $branchId = $this->resolveBranchId($requested);
        $lookups = $this->catalogLookups();
        $products = Product::query()
            ->availableAtBranch($branchId)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'sku']);

        return view('stock.takes.create', array_merge($lookups, [
            'activeMenu' => 'stock.takes',
            'products' => $products,
            'branchId' => $branchId,
        ]));
    }

    public function store(Request $request, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.create');
        $data = $request->validate([
            'branch_id' => 'required|integer',
            'scope' => 'required|in:all,category,products',
            'category_id' => 'nullable|integer',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'integer',
        ]);
        if ($data['scope'] === 'category' && empty($data['category_id'])) {
            return back()->withInput()->with('error', 'Choose a category.');
        }
        if ($data['scope'] === 'products' && empty($data['product_ids'])) {
            return back()->withInput()->with('error', 'Choose at least one product.');
        }

        $branchId = $this->resolveBranchId((int) $data['branch_id']);
        $this->assertBranchAccess((int) $branchId);

        try {
            $take = $stockTakes->create(
                (int) auth()->user()->company_id,
                (int) $branchId,
                auth()->user(),
                $data['scope'],
                isset($data['category_id']) ? (int) $data['category_id'] : null,
                $data['product_ids'] ?? []
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock.takes.show', $take)
            ->with('success', 'Stock take ' . $take->number . ' is ready. Download the sheet, count the items, then upload the Excel file.');
    }

    public function show(Request $request, StockTake $stockTake)
    {
        $this->authorizePermission('stocktake.view');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        $stockTake->load(['lines.product', 'branch', 'creator', 'approver', 'reconciler']);

        $filter = $request->input('variance', 'all');
        $lines = $stockTake->lines->filter(function ($line) use ($filter) {
            if ($filter === 'shortage') {
                return $line->countStatus() === 'Shortage';
            }
            if ($filter === 'surplus') {
                return $line->countStatus() === 'Surplus';
            }
            if ($filter === 'matched') {
                return $line->countStatus() === 'Matched';
            }
            if ($filter === 'uncounted') {
                return $line->countStatus() === 'Uncounted';
            }

            return true;
        })->values();

        $current = [];
        $costs = [];
        $rows = DB::table('product_branch_stock')
            ->where('branch_id', $stockTake->branch_id)
            ->whereIn('product_id', $stockTake->lines->pluck('product_id'))
            ->get();
        foreach ($rows as $row) {
            $key = $row->product_id . ':' . (int) $row->product_variant_id;
            $current[$key] = (float) $row->quantity;
            $costs[$key] = (float) $row->average_cost;
        }

        return view('stock.takes.show', [
            'activeMenu' => 'stock.takes',
            'take' => $stockTake,
            'lines' => $lines,
            'filter' => $filter,
            'summary' => app(StockTakeService::class)->summary($stockTake),
            'currentQty' => $current,
            'costByLine' => auth()->user()->hasPermission('products.view_cost') ? $costs : [],
            'canDownload' => auth()->user()->hasPermission('stocktake.download'),
            'canUpload' => auth()->user()->hasPermission('stocktake.upload') && $stockTake->isOpen(),
            'canApprove' => auth()->user()->hasPermission('stocktake.approve') && $stockTake->status === StockTake::STATUS_PENDING && ! $stockTake->approved_at,
            'canReconcile' => auth()->user()->hasPermission('stocktake.reconcile') && $stockTake->status === StockTake::STATUS_PENDING && $stockTake->approved_at,
            'canCancel' => auth()->user()->hasPermission('stocktake.cancel') && $stockTake->isOpen(),
            'canViewCost' => auth()->user()->hasPermission('products.view_cost'),
        ]);
    }

    public function excel(StockTake $stockTake, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.download');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        $spreadsheet = $stockTakes->workbook($stockTake);
        $filename = $stockTake->number . '-stock-take.xlsx';

        return response()->streamDownload(function () use ($stockTakes, $spreadsheet) {
            $stockTakes->writeXlsx($spreadsheet, 'php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function pdf(StockTake $stockTake)
    {
        $this->authorizePermission('stocktake.download');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        $stockTake->load(['lines', 'branch', 'creator']);
        $pdf = PDF::loadView('stock.takes.pdf', [
            'take' => $stockTake,
            'profile' => fleet_company_profile(),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($stockTake->number . '-stock-take.pdf');
    }

    public function upload(Request $request, StockTake $stockTake, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.upload');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        $data = $request->validate([
            'sheet' => 'required|file|mimes:xlsx,xls',
        ]);

        try {
            $errors = $stockTakes->import($stockTake, $data['sheet']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($errors !== []) {
            return back()->with('error', implode(' ', array_slice($errors, 0, 8)));
        }

        return back()->with('success', 'Count sheet uploaded. Review the variances before approval. Inventory has not been changed.');
    }

    public function submit(StockTake $stockTake, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.upload');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        try {
            $stockTakes->submit($stockTake);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock take submitted for approval.');
    }

    public function approve(StockTake $stockTake, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.approve');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        try {
            $stockTakes->approve($stockTake, auth()->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock take approved. It can now be reconciled.');
    }

    public function reconcile(StockTake $stockTake, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.reconcile');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        try {
            $stockTakes->reconcile($stockTake, auth()->user());
        } catch (InvalidArgumentException | NegativeStockException $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Reconciliation could not be completed. No stock was changed.');
        }

        return back()->with('success', 'Stock take reconciled. Counted quantities are now the stock on hand for the items that were counted.');
    }

    public function cancel(StockTake $stockTake, StockTakeService $stockTakes)
    {
        $this->authorizePermission('stocktake.cancel');
        $this->assertBranchAccess((int) $stockTake->branch_id);
        try {
            $stockTakes->cancel($stockTake, auth()->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stock take cancelled. Inventory was not changed.');
    }
}

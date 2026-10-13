<?php

namespace App\Http\Controllers;

use App\Exceptions\NegativeStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\AuditLogger;
use App\Services\DocumentNumberService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockTransferController extends Controller
{
    public function index()
    {
        $this->authorizePermission('inventory.view');

        $branchId = (int) $this->currentBranchId();
        $transfers = StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'user', 'items'])
            ->orderByDesc('id')
            ->get();

        return view('stock.transfers.index', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'stock.transfers',
            'transfers' => $transfers,
            'currentBranchId' => $branchId,
            'incomingCount' => $transfers->filter(function (StockTransfer $transfer) use ($branchId) {
                return $transfer->status === StockTransfer::STATUS_PENDING
                    && (int) $transfer->to_branch_id === $branchId;
            })->count(),
            'canTransfer' => auth()->user()->hasPermission('inventory.transfer'),
        ]));
    }

    public function create()
    {
        $this->authorizePermission('inventory.transfer');

        $products = Product::query()
            ->availableAtBranch($this->currentBranchId())
            ->where('is_active', true)
            ->where('manage_stock', true)
            ->orderBy('name')
            ->get();

        return view('stock.transfers.create', array_merge(fleet_shared_view_data(), $this->catalogLookups(), [
            'activeMenu' => 'stock.transfers',
            'products' => $products,
            'destinationBranches' => \App\Models\Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedBranchId' => $this->currentBranchId(),
            'productCosts' => $products->mapWithKeys(function (Product $product) {
                return [$product->id => (float) $product->purchase_price];
            }),
        ]));
    }

    public function store(Request $request, DocumentNumberService $numbers, InventoryService $inventory, AuditLogger $audit)
    {
        $this->authorizePermission('inventory.transfer');

        $companyId = (int) auth()->user()->company_id;
        $data = $this->validateTransfer($request, $companyId);
        $sendNow = in_array($data['action'] ?? 'draft', ['complete', 'send'], true);

        try {
            $transfer = DB::transaction(function () use ($data, $numbers, $inventory, $audit, $companyId, $sendNow, $request) {
                $transfer = StockTransfer::query()->create([
                    'company_id' => $companyId,
                    'from_branch_id' => (int) $data['from_branch_id'],
                    'to_branch_id' => (int) $data['to_branch_id'],
                    'user_id' => auth()->id(),
                    'number' => $numbers->next($companyId, 'stock_transfer'),
                    'transfer_date' => $data['transfer_date'],
                    'status' => StockTransfer::STATUS_DRAFT,
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($data['items'] as $line) {
                    $product = Product::query()->findOrFail($line['product_id']);
                    StockTransferItem::query()->create([
                        'company_id' => $companyId,
                        'stock_transfer_id' => $transfer->id,
                        'product_id' => $product->id,
                        'product_variant_id' => $line['product_variant_id'] ?? null,
                        'quantity' => round((float) $line['quantity'], 4),
                        'unit_cost' => round((float) ($line['unit_cost'] ?? $product->purchase_price), 4),
                    ]);
                }

                $audit->record('create', 'stock_transfer', $transfer, null, [
                    'number' => $transfer->number,
                    'from_branch_id' => $transfer->from_branch_id,
                    'to_branch_id' => $transfer->to_branch_id,
                    'status' => $transfer->status,
                ], $request);

                if ($sendNow) {
                    $this->dispatchTransfer($transfer->fresh(['items.product']), $inventory, $audit, $request);
                }

                return $transfer->fresh(['items', 'fromBranch', 'toBranch']);
            });
        } catch (NegativeStockException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock.transfers.show', $transfer)
            ->with('success', $sendNow
                ? 'Transfer sent. The receiving branch can now receive or reject it.'
                : 'Stock transfer saved as draft.');
    }

    public function show(StockTransfer $transfer)
    {
        abort_unless(
            auth()->user()
            && (auth()->user()->hasPermission('inventory.view') || auth()->user()->hasPermission('inventory.transfer')),
            403
        );

        $transfer->load(['items.product', 'fromBranch', 'toBranch', 'user']);

        return view('stock.transfers.show', array_merge(fleet_shared_view_data(), [
            'activeMenu' => 'stock.transfers',
            'transfer' => $transfer,
            'currentBranchId' => (int) $this->currentBranchId(),
            'canTransfer' => auth()->user()->hasPermission('inventory.transfer'),
        ]));
    }

    public function complete(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit)
    {
        $this->authorizePermission('inventory.transfer');

        try {
            DB::transaction(function () use ($transfer, $inventory, $audit) {
                $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
                $this->dispatchTransfer($locked->load(['items.product']), $inventory, $audit, request());
            });
        } catch (NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock.transfers.show', $transfer)
            ->with('success', 'Transfer sent. The receiving branch can now receive or reject it.');
    }

    public function receive(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit)
    {
        $this->authorizePermission('inventory.transfer');

        try {
            DB::transaction(function () use ($transfer, $inventory, $audit) {
                $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
                $this->receiveTransfer($locked->load(['items.product']), $inventory, $audit, request());
            });
        } catch (NegativeStockException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock.transfers.show', $transfer)
            ->with('success', 'Items received into this branch.');
    }

    public function reject(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit)
    {
        $this->authorizePermission('inventory.transfer');

        try {
            DB::transaction(function () use ($transfer, $inventory, $audit) {
                $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
                $this->rejectTransfer($locked->load(['items.product']), $inventory, $audit, request());
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock.transfers.show', $transfer)
            ->with('success', 'Transfer rejected. The items were returned to the sending branch.');
    }

    public function cancel(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit)
    {
        $this->authorizePermission('inventory.transfer');

        try {
            DB::transaction(function () use ($transfer, $inventory, $audit) {
                $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === StockTransfer::STATUS_COMPLETED) {
                    throw new \RuntimeException('Received transfers cannot be cancelled.');
                }
                if (in_array($locked->status, [StockTransfer::STATUS_CANCELLED, StockTransfer::STATUS_REJECTED], true)) {
                    throw new \RuntimeException('Transfer is already closed.');
                }
                if ((int) $this->currentBranchId() !== (int) $locked->from_branch_id) {
                    throw new \RuntimeException('Only the sending branch can cancel this transfer.');
                }

                $locked->load(['items.product']);
                if ($locked->status === StockTransfer::STATUS_PENDING) {
                    $this->returnToSource($locked, $inventory, 'Transfer cancelled ' . $locked->number);
                }

                $before = $locked->only(['number', 'status']);
                $locked->update([
                    'status' => StockTransfer::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                ]);
                $audit->record('cancel', 'stock_transfer', $locked, $before, [
                    'number' => $locked->number,
                    'status' => $locked->status,
                ], request());
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('stock.transfers.show', $transfer)
            ->with('success', 'Stock transfer cancelled.');
    }

    private function dispatchTransfer(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit, ?Request $request = null): void
    {
        if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
            throw new \RuntimeException('Only a draft transfer can be sent.');
        }
        if ((int) $this->currentBranchId() !== (int) $transfer->from_branch_id) {
            throw new \RuntimeException('Switch to the sending branch before sending this transfer.');
        }
        if ($transfer->items->isEmpty()) {
            throw new \RuntimeException('Add at least one item before sending.');
        }

        $allowNegative = auth()->user() && auth()->user()->hasPermission('inventory.allow_negative');
        $companyId = (int) $transfer->company_id;

        foreach ($transfer->items as $item) {
            $onHand = $inventory->quantityOnHand(
                $companyId,
                (int) $transfer->from_branch_id,
                (int) $item->product_id,
                (int) ($item->product_variant_id ?? 0)
            );
            $qty = (float) $item->quantity;
            if ($qty - $onHand > 0.0001 && ! $allowNegative) {
                $name = optional($item->product)->name ?: ('Product #' . $item->product_id);
                throw new \RuntimeException("Insufficient stock for {$name} at source branch (available: {$onHand}).");
            }

            $inventory->apply([
                'company_id' => $companyId,
                'branch_id' => (int) $transfer->from_branch_id,
                'product_id' => (int) $item->product_id,
                'product_variant_id' => (int) ($item->product_variant_id ?? 0),
                'type' => StockMovement::TRANSFER_OUT,
                'quantity_out' => $qty,
                'unit_cost' => $item->unit_cost,
                'user_id' => auth()->id(),
                'notes' => 'Transfer out ' . $transfer->number,
                'reference_type' => StockTransfer::class,
                'reference_id' => $transfer->id,
                'reference_number' => $transfer->number,
                'occurred_at' => $transfer->transfer_date,
                'allow_negative' => $allowNegative,
            ]);
        }

        $before = $transfer->only(['number', 'status']);
        $transfer->update(['status' => StockTransfer::STATUS_PENDING]);
        $audit->record('send', 'stock_transfer', $transfer, $before, [
            'number' => $transfer->number,
            'status' => $transfer->status,
        ], $request);
    }

    private function receiveTransfer(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit, ?Request $request = null): void
    {
        if ($transfer->status !== StockTransfer::STATUS_PENDING) {
            throw new \RuntimeException('Only a transfer awaiting receipt can be received.');
        }
        $this->assertReceivingBranch($transfer);

        foreach ($transfer->items as $item) {
            $inventory->apply([
                'company_id' => (int) $transfer->company_id,
                'branch_id' => (int) $transfer->to_branch_id,
                'product_id' => (int) $item->product_id,
                'product_variant_id' => (int) ($item->product_variant_id ?? 0),
                'type' => StockMovement::TRANSFER_IN,
                'quantity_in' => (float) $item->quantity,
                'unit_cost' => $item->unit_cost,
                'user_id' => auth()->id(),
                'notes' => 'Transfer in ' . $transfer->number,
                'reference_type' => StockTransfer::class,
                'reference_id' => $transfer->id,
                'reference_number' => $transfer->number,
                'occurred_at' => now(),
            ]);
        }

        $before = $transfer->only(['number', 'status']);
        $transfer->update([
            'status' => StockTransfer::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $audit->record('receive', 'stock_transfer', $transfer, $before, [
            'number' => $transfer->number,
            'status' => $transfer->status,
        ], $request);
    }

    private function rejectTransfer(StockTransfer $transfer, InventoryService $inventory, AuditLogger $audit, ?Request $request = null): void
    {
        if ($transfer->status !== StockTransfer::STATUS_PENDING) {
            throw new \RuntimeException('Only a transfer awaiting receipt can be rejected.');
        }
        $this->assertReceivingBranch($transfer);
        $this->returnToSource($transfer, $inventory, 'Transfer rejected ' . $transfer->number);

        $before = $transfer->only(['number', 'status']);
        $transfer->update([
            'status' => StockTransfer::STATUS_REJECTED,
            'cancelled_at' => now(),
        ]);
        $audit->record('reject', 'stock_transfer', $transfer, $before, [
            'number' => $transfer->number,
            'status' => $transfer->status,
        ], $request);
    }

    private function assertReceivingBranch(StockTransfer $transfer): void
    {
        if ((int) $this->currentBranchId() !== (int) $transfer->to_branch_id) {
            throw new \RuntimeException('Switch to the receiving branch to receive or reject this transfer.');
        }
    }

    private function returnToSource(StockTransfer $transfer, InventoryService $inventory, string $notes): void
    {
        foreach ($transfer->items as $item) {
            $inventory->apply([
                'company_id' => (int) $transfer->company_id,
                'branch_id' => (int) $transfer->from_branch_id,
                'product_id' => (int) $item->product_id,
                'product_variant_id' => (int) ($item->product_variant_id ?? 0),
                'type' => StockMovement::TRANSFER_IN,
                'quantity_in' => (float) $item->quantity,
                'unit_cost' => $item->unit_cost,
                'user_id' => auth()->id(),
                'notes' => $notes,
                'reference_type' => StockTransfer::class,
                'reference_id' => $transfer->id,
                'reference_number' => $transfer->number,
                'occurred_at' => now(),
            ]);
        }
    }

    private function validateTransfer(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'from_branch_id' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'to_branch_id' => ['required', 'different:from_branch_id', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'action' => 'nullable|in:draft,complete,send',
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'items.*.product_variant_id' => 'nullable|integer|min:0',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        if (! auth()->user()->canSwitchBranches()) {
            $data['from_branch_id'] = $this->currentBranchId();
        }
        $this->assertBranchAccess((int) $data['from_branch_id']);
        $data['from_branch_id'] = $this->currentBranchId();

        $lines = [];
        foreach ($data['items'] as $line) {
            $qty = round((float) $line['quantity'], 4);
            if ($qty <= 0) {
                continue;
            }
            $lines[] = $line;
        }

        if (count($lines) === 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'items' => 'Enter at least one product quantity.',
            ]);
        }

        $data['items'] = $lines;

        return $data;
    }
}

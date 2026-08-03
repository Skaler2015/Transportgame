<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Factory;
use App\Models\Warehouse;
use App\Services\ManufacturingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FactoryController extends Controller
{
    public function __construct(private readonly ManufacturingService $mfg) {}

    private function company(Request $request): Company
    {
        $company = $request->user()->company;
        abort_if(! $company, 403, 'No company found.');

        return $company;
    }

    /** Owned factories (production stepped first) plus the buildable catalog. */
    public function index(Request $request)
    {
        $company = $this->company($request);
        $this->mfg->produceDue($company);

        $factories = Factory::where('company_id', $company->id)
            ->with('warehouse.city')->orderBy('id')->get()
            ->map(fn (Factory $f) => $this->present($f));

        return response()->json([
            'data' => $factories,
            'catalog' => $this->mfg->catalog($company),
        ]);
    }

    public function build(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'recipe' => ['required', 'string'],
        ]);
        $company = $this->company($request);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);

        try {
            $factory = $this->mfg->build($company, $warehouse, $data['recipe']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['recipe' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->present($factory->load('warehouse.city'))], 201);
    }

    public function upgrade(Request $request, Factory $factory)
    {
        $company = $this->company($request);
        try {
            $this->mfg->upgrade($company, $factory);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['factory' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->present($factory->load('warehouse.city'))]);
    }

    public function toggle(Request $request, Factory $factory)
    {
        $company = $this->company($request);
        try {
            $this->mfg->toggle($company, $factory);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['factory' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->present($factory->load('warehouse.city'))]);
    }

    private function present(Factory $f): array
    {
        $cfg = $f->recipeConfig() ?? [];
        $mult = $f->levelMultiplier();

        return [
            'id' => $f->id,
            'recipe' => $f->recipe,
            'name' => $cfg['name'] ?? $f->recipe,
            'icon' => $cfg['icon'] ?? '🏭',
            'level' => $f->level,
            'status' => $f->status,
            'last_note' => $f->last_note,
            'lifetime_output' => $f->lifetime_output,
            'output' => $cfg['output'] ?? null,
            'output_per_cycle' => (int) round(($cfg['output_qty'] ?? 0) * $mult),
            'op_cost' => (int) round(($cfg['op_cost'] ?? 0) * $mult),
            'inputs_per_cycle' => collect($cfg['inputs'] ?? [])
                ->map(fn ($qty, $k) => ['commodity' => $k, 'qty' => (int) ceil($qty * $mult)])->values(),
            'upgrade_cost' => $this->mfg->upgradeCost($f),
            'warehouse' => ['id' => $f->warehouse?->id, 'name' => $f->warehouse?->name, 'city' => $f->warehouse?->city?->name],
        ];
    }
}

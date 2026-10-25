<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Aprobadores y clientes (solo el dueño). */
class SimpleCrudController extends Controller
{
    public function approvers(Request $request)
    {
        return response()->json(Approver::where('company_id', $request->user()->company_id)->orderBy('name')->get());
    }

    public function saveApprover(Request $request, ?Approver $approver = null)
    {
        $cid = $request->user()->company_id;
        if ($approver) {
            abort_unless($approver->company_id === $cid, 404);
        }
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('approvers')->where('company_id', $cid)->ignore($approver?->id)],
            'require_code' => 'boolean',
            'active' => 'boolean',
        ]);
        $approver = $approver ? tap($approver)->update($v) : Approver::create($v + ['company_id' => $cid]);
        return response()->json($approver, $approver->wasRecentlyCreated ? 201 : 200);
    }

    public function customers(Request $request)
    {
        return response()->json(Customer::where('company_id', $request->user()->company_id)->orderBy('name')->get());
    }

    public function saveCustomer(Request $request, ?Customer $customer = null)
    {
        $cid = $request->user()->company_id;
        if ($customer) {
            abort_unless($customer->company_id === $cid, 404);
        }
        $v = $request->validate(['name' => 'required|string|max:190', 'email' => 'nullable|email|max:190',
            'active' => 'boolean', 'accountisi_id' => 'nullable|string|max:60']);
        $customer = $customer ? tap($customer)->update($v) : Customer::create($v + ['company_id' => $cid]);
        return response()->json($customer, $customer->wasRecentlyCreated ? 201 : 200);
    }
}

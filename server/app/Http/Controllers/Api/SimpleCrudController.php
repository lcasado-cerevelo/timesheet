<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Approver;
use App\Models\Customer;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Aprobadores y clientes (solo el dueño). */
class SimpleCrudController extends Controller
{
    public function __construct(private AccountService $accounts)
    {
    }

    public function approvers(Request $request)
    {
        return response()->json(Approver::with('account')->where('company_id', $request->user()->company_id)->orderBy('name')->get()
            ->map(fn ($a) => $this->approverPayload($a)));
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
            'can_login' => 'boolean',
        ]);
        $wantsLogin = array_key_exists('can_login', $v) ? (bool) $v['can_login'] : (bool) $approver?->can_login;
        $account = $approver?->account;

        // La cuenta usa el correo del aprobador: no puede chocar con otra cuenta.
        if ($wantsLogin || $account) {
            $clash = User::where('email', $v['email'])->when($account, fn ($q) => $q->where('id', '!=', $account->id))->exists();
            if ($clash) {
                throw ValidationException::withMessages(['email' => __('Ya existe una cuenta con ese correo.')]);
            }
        }

        $approver = $approver ? tap($approver)->update($v) : Approver::create($v + ['company_id' => $cid]);
        $created = $approver->wasRecentlyCreated;
        $approver->load('company');
        $account = $approver->account()->first();

        if ($wantsLogin && $approver->active) {
            if (! $account) {
                $account = User::create([
                    'company_id' => $cid, 'name' => $approver->name, 'email' => $approver->email, 'role' => 'approver',
                    'worker_type' => 'employee', 'capture_mode' => 'full_day', 'password' => Str::random(64), 'must_set_password' => true,
                    'approver_profile_id' => $approver->id, 'active' => true,
                ]);
                $this->accounts->invite($account->load('company'), $request->user());
            } else {
                $account->update(['name' => $approver->name, 'email' => $approver->email]);
                if (! $account->active) {
                    $account->update(['active' => true]);
                    $this->accounts->invite($account->load('company'), $request->user());
                }
            }
        } elseif ($account) {
            $account->update(['name' => $approver->name, 'email' => $approver->email, 'active' => false]);
            $this->accounts->revokeAccess($account);
        }
        $approver->update(['can_login' => $wantsLogin]);
        return response()->json($this->approverPayload($approver->fresh('account')), $created ? 201 : 200);
    }

    private function approverPayload(Approver $a): array
    {
        return $a->attributesToArray() + ['account_status' => $a->accountStatus(), 'user_id' => $a->account?->id];
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

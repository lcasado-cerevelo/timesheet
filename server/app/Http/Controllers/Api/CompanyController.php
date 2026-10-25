<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Rules;
use Illuminate\Http\Request;

/** Reglas y datos de la compañía (solo el dueño). */
class CompanyController extends Controller
{
    public function show(Request $request)
    {
        $c = $request->user()->company;
        return response()->json(['name' => $c->name, 'timezone' => $c->timezone, 'invoice_prefix' => $c->invoice_prefix, 'locale' => $c->locale,
            'rules' => $c->rules()->all()]);
    }

    public function update(Request $request)
    {
        $c = $request->user()->company;
        $v = $request->validate([
            'name' => 'sometimes|string|max:190',
            'timezone' => 'sometimes|timezone',
            'locale' => 'sometimes|in:es,en',
            'invoice_prefix' => 'sometimes|string|max:20',
            'rules' => 'sometimes|array',
            'rules.weeks_back' => 'integer|between:0,52',
            'rules.deadline_dow' => 'integer|between:0,6',
            'rules.deadline_time' => 'date_format:H:i',
            'rules.flag_late' => 'boolean',
            'rules.increment' => 'numeric|in:0.01,0.05,0.1,0.25,0.5,1',
            'rules.max_day_hours' => 'numeric|between:1,24',
            'rules.approval_remind_days' => 'integer|between:1,60',
            'rules.remind_every_days' => 'integer|between:1,30',
            'rules.token_days' => 'integer|between:1,60',
            'rules.change_notice_minutes' => 'integer|between:0,1440',
            'rules.code_minutes' => 'integer|between:5,60',
            'rules.second_approval_hours' => 'nullable|numeric|min:1',
        ]);
        if (isset($v['rules'])) {
            $v['rules'] = array_intersect_key(array_replace($c->rules ?? [], $v['rules']), Rules::DEFAULTS);
        }
        $c->update($v);
        return $this->show($request);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BrandHistory;
use App\Support\Color;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Configuración > Marca. Solo el dueño. Se aplica a web, emails, PDF y app móvil.
 * No se aceptan SVG: un SVG puede llevar scripts y se serviría desde el mismo origen que la aplicación.
 */
class BrandController extends Controller
{
    private const FIELDS = ['brand_name', 'color_primary', 'color_accent', 'email_from_name', 'email_from_address', 'email_footer',
        'logo_path', 'logo_dark_path'];

    public function show(Request $request)
    {
        return response()->json($request->user()->company->brand());
    }

    public function update(Request $request)
    {
        $company = $request->user()->company;
        $data = $request->validate([
            'brand_name' => 'nullable|string|max:120',
            'color_primary' => 'nullable|string',
            'color_accent' => 'nullable|string',
            'email_from_name' => 'nullable|string|max:120',
            'email_from_address' => 'nullable|email|max:190',
            'email_footer' => 'nullable|string|max:500',
            'logo' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:1024',
            'logo_dark' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:1024',
            'remove_logo' => 'nullable|boolean',
        ]);

        foreach (['color_primary', 'color_accent'] as $f) {
            if (isset($data[$f])) {
                if (! Color::isHex($data[$f])) {
                    throw ValidationException::withMessages([$f => __('El color debe ser hexadecimal, por ejemplo #1f4e79.')]);
                }
                if (Color::contrastWithWhite($data[$f]) < 4.5) {
                    throw ValidationException::withMessages([$f => __('El texto blanco no se lee bien sobre ese color. Usa uno más oscuro.')]);
                }
            }
        }

        $before = $company->only(self::FIELDS);
        $changes = array_intersect_key($data, array_flip(['brand_name', 'color_primary', 'color_accent', 'email_from_name', 'email_from_address', 'email_footer']));

        if ($request->hasFile('logo')) {
            $changes['logo_path'] = $request->file('logo')->store('logos/'.$company->id, 'public');
        }
        if ($request->hasFile('logo_dark')) {
            $changes['logo_dark_path'] = $request->file('logo_dark')->store('logos/'.$company->id, 'public');
        }
        if (! empty($data['remove_logo'])) {
            $changes['logo_path'] = null;
            $changes['logo_dark_path'] = null;
        }

        $company->update($changes);
        BrandHistory::create([
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'before' => $before, 'after' => $company->only(self::FIELDS), 'created_at' => now(),
        ]);
        return response()->json($company->brand());
    }

    public function history(Request $request)
    {
        return response()->json(
            BrandHistory::where('company_id', $request->user()->company_id)->orderByDesc('id')->limit(30)->get()
        );
    }

    /** Vuelve a la marca que había antes de un cambio. */
    public function revert(Request $request, BrandHistory $history)
    {
        abort_unless($history->company_id === $request->user()->company_id, 404);
        $company = $request->user()->company;
        $current = $company->only(self::FIELDS);
        $company->update($history->before);
        BrandHistory::create([
            'company_id' => $company->id, 'user_id' => $request->user()->id,
            'before' => $current, 'after' => $company->only(self::FIELDS), 'created_at' => now(),
        ]);
        return response()->json($company->brand());
    }
}

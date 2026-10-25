<?php

namespace App\Models;

use App\Support\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array', 'show_brand_name' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function rules(): Rules
    {
        return Rules::make($this->rules);
    }

    /** Idioma de la compañía (es o en); el de la aplicación si falta o no es válido. */
    public function lang(): string
    {
        return in_array($this->locale, ['es', 'en'], true) ? $this->locale : (string) config('app.locale');
    }

    /** Dirección base de los enlaces de los correos: el dominio de la compañía o, si no tiene, el de la instalación. */
    public function baseUrl(): string
    {
        return $this->domain ? 'https://'.$this->domain : rtrim((string) config('app.url'), '/');
    }

    /** Compañía cuyo dominio es exactamente este Host (sin puerto, en minúsculas). */
    public static function forHost(?string $host): ?self
    {
        $host = strtolower(trim((string) $host));
        return $host === '' ? null : static::where('domain', $host)->first();
    }

    /** Marca que puede verse sin iniciar sesión (pantalla de entrada y de crear contraseña). */
    public function publicBrand(): array
    {
        return ['name' => $this->displayName(), 'logo_url' => $this->logoUrl(),
            'color_primary' => $this->color_primary, 'color_accent' => $this->color_accent,
            'show_name' => $this->showName()];
    }

    /** Si el nombre de la marca se muestra junto al logo (por defecto sí). */
    public function showName(): bool
    {
        return $this->show_brand_name === null ? true : (bool) $this->show_brand_name;
    }

    public function displayName(): string
    {
        return $this->brand_name ?: $this->name;
    }

    public function logoUrl(bool $dark = false): ?string
    {
        $path = $dark ? ($this->logo_dark_path ?: $this->logo_path) : $this->logo_path;
        return $path ? url(Storage::disk('public')->url($path)) : null;
    }

    /** Campos de marca tal como los lee la web, los emails, los PDF y la app móvil. */
    public function brand(): array
    {
        return [
            'name' => $this->displayName(),
            'logo_url' => $this->logoUrl(),
            'logo_dark_url' => $this->logoUrl(true),
            'color_primary' => $this->color_primary,
            'color_accent' => $this->color_accent,
            'show_name' => $this->showName(),
            'email_from_name' => $this->email_from_name ?: $this->displayName(),
            'email_from_address' => $this->email_from_address,
            'email_footer' => $this->email_footer,
        ];
    }
}

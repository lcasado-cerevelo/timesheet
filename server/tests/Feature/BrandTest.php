<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Timesheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

class BrandTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    public function test_owner_changes_colors_and_logo_without_touching_code_and_everything_follows(): void
    {
        Storage::fake('public');
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/brand', [
            'brand_name' => 'EBVS', 'color_primary' => '#7a1f1f', 'color_accent' => '#0b6e4f',
            'email_from_name' => 'EBVS Time', 'email_footer' => 'EB Validation Services, Caguas PR',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
        ])->assertOk()->assertJsonPath('name', 'EBVS')->assertJsonPath('color_primary', '#7a1f1f');

        // La app móvil lee la marca al iniciar sesión.
        $me = $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'])->assertOk();
        $me->assertJsonPath('user.brand.name', 'EBVS')->assertJsonPath('user.brand.color_accent', '#0b6e4f');
        $this->assertNotNull($me->json('user.brand.logo_url'));

        // Los emails y la página de aprobación usan esa marca.
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        $html = Mail::sent(BrandedMail::class)->last()->render();
        $this->assertStringContainsString('#7a1f1f', $html);
        $this->assertStringContainsString('EB Validation Services, Caguas PR', $html);
        $this->assertStringContainsString('/storage/logos/', $html);
        $plain = $this->tokenFromMail($html);
        $this->get('/a/'.$plain)->assertSee('#7a1f1f', false)->assertSee('EB Validation Services, Caguas PR');
    }

    public function test_svg_logos_are_refused_because_they_can_carry_scripts(): void
    {
        Storage::fake('public');
        $this->world();
        Sanctum::actingAs($this->owner);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->postJson('/api/brand', ['logo' => $svg])->assertStatus(422)->assertJsonValidationErrors('logo');
    }

    public function test_bad_colors_are_rejected(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/brand', ['color_primary' => 'rojo'])->assertStatus(422)->assertJsonValidationErrors('color_primary');
        $this->postJson('/api/brand', ['color_primary' => '#ffff99'])->assertStatus(422)   // el texto blanco no se lee
            ->assertJsonValidationErrors('color_primary');
        $this->postJson('/api/brand', ['color_accent' => '#123456'])->assertOk();
    }

    public function test_a_stored_color_with_low_contrast_does_not_block_saving_other_changes(): void
    {
        $this->world();
        $this->company->update(['color_accent' => '#2e8b57']);   // el verde de antes: contraste 4.25, bajo el mínimo
        Sanctum::actingAs($this->owner);
        // Se guarda el formulario completo con el mismo color de siempre: no debe bloquearse.
        $this->postJson('/api/brand', ['brand_name' => 'Cerevelo', 'color_primary' => $this->company->refresh()->color_primary, 'color_accent' => '#2E8B57'])->assertOk();
        $this->postJson('/api/brand', ['brand_name' => 'Cerevelo', 'color_primary' => null, 'color_accent' => null])->assertOk();   // vacío = no cambiar
        // Pero un color nuevo con poco contraste sigue rechazándose.
        $this->postJson('/api/brand', ['color_accent' => '#33aa66'])->assertStatus(422)->assertJsonValidationErrors('color_accent');
    }

    public function test_only_owner_changes_brand_and_history_can_be_reverted(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/brand', ['brand_name' => 'X'])->assertForbidden();
        $this->getJson('/api/brand')->assertOk();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/brand', ['brand_name' => 'Uno', 'color_primary' => '#222222'])->assertOk();
        $this->postJson('/api/brand', ['brand_name' => 'Dos', 'color_primary' => '#333333'])->assertOk();
        $hist = $this->getJson('/api/brand/history')->assertOk()->json();
        $this->assertCount(2, $hist);
        $this->postJson('/api/brand/history/'.$hist[0]['id'].'/revert')->assertOk()
            ->assertJsonPath('name', 'Uno')->assertJsonPath('color_primary', '#222222');
        $this->assertCount(3, $this->getJson('/api/brand/history')->json());
    }
}

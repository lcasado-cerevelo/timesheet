<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Approver;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Demo;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** El modo demostración (DEMO_MODE=true) cambia lo justo; con false la aplicación se comporta exactamente como siempre. */
class DemoModeTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private function approverAccount(): User
    {
        $this->approver->update(['can_login' => true]);
        return User::create(['company_id' => $this->company->id, 'name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'password' => 'secret-password', 'role' => 'approver',
            'worker_type' => 'employee', 'capture_mode' => 'full_day', 'approver_profile_id' => $this->approver->id, 'must_set_password' => false]);
    }

    public function test_without_demo_mode_an_approver_account_must_give_the_emailed_code_to_sign_in(): void
    {
        Mail::fake();
        $this->world();
        $this->approverAccount();
        $this->postJson('/api/login/web', ['email' => 'jorge@cliente.test', 'password' => 'secret-password'])->assertStatus(202)->assertJsonPath('step', 'code');
        Mail::assertSent(BrandedMail::class, fn ($m) => $m->hasTo('jorge@cliente.test'));
    }

    public function test_in_demo_mode_nobody_is_asked_for_the_sign_in_code(): void
    {
        config(['app.demo' => true]);
        Mail::fake();
        $this->world();
        $this->approverAccount();
        $this->postJson('/api/login/web', ['email' => 'jorge@cliente.test', 'password' => 'secret-password'])->assertOk()->assertJsonStructure(['token', 'user']);
        Mail::assertNothingSent();
    }

    public function test_the_link_needs_the_code_outside_demo_and_not_in_demo(): void
    {
        Mail::fake();
        $this->world();
        $this->clientWithApprover($this->project);
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        $plain = $this->tokenFromMail(Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request')->last()->render());
        $this->autoSign = false;

        $this->get('/a/'.$plain)->assertOk()->assertSee('Verifica que eres tú');
        $this->post('/a/'.$plain.'/approve')->assertSessionHasErrors('code');

        config(['app.demo' => true]);
        $this->get('/a/'.$plain)->assertOk()->assertDontSee('Verifica que eres tú')->assertSee('Demo · datos de ejemplo');
        $this->post('/a/'.$plain.'/approve')->assertOk()->assertSee('Hoja aprobada');
        $this->assertSame(Timesheet::APPROVED, Timesheet::find($e['timesheet_id'])->status);
    }

    public function test_an_approver_account_decides_without_the_code_in_demo_but_needs_it_when_the_approver_asks_for_it_otherwise(): void
    {
        Mail::fake();
        $this->world();
        $this->clientWithApprover($this->project);
        $this->approver->update(['require_code' => true]);
        $acct = $this->approverAccount();
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        Sanctum::actingAs($acct);
        $this->getJson('/api/approver/sheets/'.$e['timesheet_id'])->assertOk()->assertJsonPath('needs_code', true);
        config(['app.demo' => true]);
        $this->getJson('/api/approver/sheets/'.$e['timesheet_id'])->assertOk()->assertJsonPath('needs_code', false);
        $this->postJson('/api/approver/sheets/'.$e['timesheet_id'].'/approve', ['version' => 1, 'signature' => 'secret-password'])->assertOk();
    }

    public function test_the_banner_and_noindex_only_show_in_demo(): void
    {
        $this->world();
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'])->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertJsonPath('user.demo', false);
        config(['app.demo' => true]);
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertJsonPath('user.demo', true);
    }

    public function test_public_config_lists_the_example_accounts_only_in_demo(): void
    {
        $this->world();
        User::create(['company_id' => $this->company->id, 'name' => 'Marisol Vega', 'email' => 'dueno@demo.cerevelo.com', 'password' => Demo::PASSWORD, 'role' => 'owner']);
        $off = $this->getJson('/api/public-config')->assertOk()->json();
        $this->assertFalse($off['demo']);
        $this->assertSame([], $off['demo_accounts']);
        $this->assertStringNotContainsString(Demo::PASSWORD, json_encode($off));

        config(['app.demo' => true]);
        $on = $this->getJson('/api/public-config')->assertOk()->json();
        $this->assertTrue($on['demo']);
        $this->assertSame('dueno@demo.cerevelo.com', $on['demo_accounts'][0]['email']);
        $this->assertSame(Demo::PASSWORD, $on['demo_accounts'][0]['password']);
        $this->assertSame('Dueña', $on['demo_accounts'][0]['label_es']);
    }

    public function test_the_example_accounts_cannot_change_their_password_in_demo(): void
    {
        $this->world();
        $demo = User::create(['company_id' => $this->company->id, 'name' => 'Marisol Vega', 'email' => 'dueno@demo.cerevelo.com', 'password' => Demo::PASSWORD, 'role' => 'owner']);
        $new = 'Otra-Clave-Distinta-77!';
        config(['app.demo' => true]);
        Sanctum::actingAs($demo);
        $this->postJson('/api/password/change', ['current_password' => Demo::PASSWORD, 'password' => $new])->assertForbidden();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(Demo::PASSWORD, $demo->fresh()->password));
        $this->postJson('/api/users/'.$this->worker->id.'/reset-password')->assertOk();   // otras cuentas siguen igual
        // fuera de la demostración, nada de esto se bloquea
        config(['app.demo' => false]);
        $this->postJson('/api/password/change', ['current_password' => Demo::PASSWORD, 'password' => $new])->assertOk();
    }

    public function test_demo_mode_never_sends_real_mail(): void
    {
        config(['mail.default' => 'smtp', 'app.demo' => true]);
        (new \App\Providers\AppServiceProvider($this->app))->boot();
        $this->assertSame('log', config('mail.default'));
    }

    public function test_the_example_password_is_strong_enough_for_the_policy(): void
    {
        foreach (array_column(Demo::LOGIN_ACCOUNTS, 1) as $email) {
            PasswordPolicy::assert(Demo::PASSWORD, $email);
        }
        $this->assertGreaterThanOrEqual(10, strlen(Demo::PASSWORD));
    }

    public function test_the_demo_command_refuses_to_run_without_demo_mode_and_touches_nothing(): void
    {
        $this->world();
        $users = User::count();
        $this->assertSame(1, Artisan::call('tiempo:demo', ['--fresh' => true]));
        $this->assertStringContainsString('DEMO_MODE=true', Artisan::output());
        $this->assertSame($users, User::count());
        $this->assertSame(0, \App\Models\Company::where('slug', Demo::COMPANY_SLUG)->count());
    }

    public function test_the_demo_command_rejects_an_unknown_brand(): void
    {
        config(['app.demo' => true]);
        $this->assertSame(1, Artisan::call('tiempo:demo', ['--brand' => 'otra']));
        $this->assertSame(0, Approver::count());
    }
}

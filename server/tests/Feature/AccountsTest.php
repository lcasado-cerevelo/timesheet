<?php

namespace Tests\Feature;

use App\Mail\BrandedMail;
use App\Models\Approver;
use App\Models\Company;
use App\Models\PasswordToken;
use App\Models\SecurityEvent;
use App\Models\Timesheet;
use App\Models\TrustedDevice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsWorld;
use Tests\TestCase;

/** Cuentas: invitaciones, reseteo del dueño, cambio de clave, aprobadores con cuenta, dominios por compañía y consola. */
class AccountsTest extends TestCase
{
    use RefreshDatabase, BuildsWorld;

    private const NEW_PASSWORD = 'una-clave-larga-1';

    private function mailTo(string $view, string $email): ?BrandedMail
    {
        return Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === $view && $m->hasTo($email))->last();
    }

    private function linkToken(BrandedMail $mail): string
    {
        preg_match('#/app/\#/set-password/([A-Za-z0-9]{48})#', $mail->render(), $m);
        return $m[1] ?? '';
    }

    private function codeFrom(string $email): string
    {
        $m = $this->mailTo('mail.approval-code', $email);
        $this->assertNotNull($m, 'no se envió el código');
        preg_match('/(\d{6})/', $m->mailSubject, $x);
        return $x[1];
    }

    /** El dueño da acceso al aprobador y este crea su contraseña. */
    private function approverAccount(array $approverOver = []): User
    {
        Mail::fake();
        $this->world();
        $this->approver->update($approverOver);
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'can_login' => true])
            ->assertOk()->assertJsonPath('account_status', 'pending');
        $token = $this->linkToken($this->mailTo('mail.account-invite', 'jorge@cliente.test'));
        $this->postJson('/api/password/setup', ['token' => $token, 'password' => self::NEW_PASSWORD])->assertOk();
        return User::where('approver_profile_id', $this->approver->id)->firstOrFail();
    }

    private function webLogin(array $over = [], string $url = '/api/login/web')
    {
        return $this->postJson($url, $over + ['email' => 'jorge@cliente.test', 'password' => self::NEW_PASSWORD]);
    }

    private function submitSheet(): int
    {
        Sanctum::actingAs($this->worker);
        $e = $this->postJson('/api/entries', $this->entry())->assertCreated()->json();
        $this->postJson('/api/sheets/'.$e['timesheet_id'].'/submit')->assertOk();
        return $e['timesheet_id'];
    }

    // ---------- Invitación y creación de contraseña ----------

    public function test_owner_creates_a_person_without_a_password_and_they_get_a_one_time_invitation(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->owner);
        $r = $this->postJson('/api/users', ['name' => 'Pedro Vega', 'email' => 'pedro@x.test', 'worker_type' => 'consultant',
            'capture_mode' => 'from_to', 'password' => 'la-que-escribe-el-dueno'])->assertCreated()
            ->assertJsonPath('invitation_status', 'pending')->assertJsonMissingPath('password');
        $this->assertFalse(\Hash::check('la-que-escribe-el-dueno', User::find($r->json('id'))->password));
        // Sin contraseña no entra
        $this->postJson('/api/login', ['email' => 'pedro@x.test', 'password' => 'la-que-escribe-el-dueno'])->assertStatus(422);

        $mail = $this->mailTo('mail.account-invite', 'pedro@x.test');
        $this->assertNotNull($mail);
        $token = $this->linkToken($mail);
        $this->assertSame(48, strlen($token));
        $this->assertStringContainsString('72', $mail->render());
        // El nombre de la compañía sale como texto, no como el objeto completo.
        $this->assertStringContainsString($this->company->displayName(), $mail->render());
        $this->assertStringNotContainsString('timezone', $mail->render());
        // Solo se guarda el hash
        $this->assertDatabaseMissing('password_tokens', ['token_hash' => $token]);
        $this->assertDatabaseHas('password_tokens', ['token_hash' => PasswordToken::hash($token), 'purpose' => 'invite']);

        $this->getJson('/api/password/setup?token='.$token)->assertOk()
            ->assertJsonPath('purpose', 'invite')->assertJsonPath('email', 'pedro@x.test')->assertJsonPath('name', 'Pedro Vega')
            ->assertJsonStructure(['brand' => ['name', 'logo_url', 'color_primary', 'color_accent']]);

        $this->postJson('/api/password/setup', ['token' => $token, 'password' => 'corta'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/password/setup', ['token' => $token, 'password' => 'pedro@x.test'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson('/api/password/setup', ['token' => $token, 'password' => self::NEW_PASSWORD])->assertOk()->assertExactJson(['ok' => true]);

        // Un solo uso
        $this->postJson('/api/password/setup', ['token' => $token, 'password' => 'otra-clave-larga-2'])->assertNotFound();
        $this->getJson('/api/password/setup?token='.$token)->assertNotFound();

        $this->postJson('/api/login', ['email' => 'pedro@x.test', 'password' => self::NEW_PASSWORD])->assertOk();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/users')->assertJsonFragment(['email' => 'pedro@x.test', 'invitation_status' => 'active']);
    }

    public function test_invitation_expires_after_72_hours_and_can_be_resent(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->owner);
        $id = $this->postJson('/api/users', ['name' => 'Pedro Vega', 'email' => 'pedro@x.test', 'worker_type' => 'consultant', 'capture_mode' => 'from_to'])
            ->assertCreated()->json('id');
        $old = $this->linkToken($this->mailTo('mail.account-invite', 'pedro@x.test'));

        Carbon::setTestNow(Carbon::now()->addHours(71));
        $this->getJson('/api/password/setup?token='.$old)->assertOk();
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->getJson('/api/password/setup?token='.$old)->assertNotFound();
        $this->postJson('/api/password/setup', ['token' => $old, 'password' => self::NEW_PASSWORD])->assertNotFound();

        $this->postJson('/api/users/'.$id.'/resend-invitation')->assertOk();
        $new = $this->linkToken($this->mailTo('mail.account-invite', 'pedro@x.test'));
        $this->assertNotSame($old, $new);
        $this->postJson('/api/password/setup', ['token' => $new, 'password' => self::NEW_PASSWORD])->assertOk();
        // Ya activo: no se reenvía
        $this->postJson('/api/users/'.$id.'/resend-invitation')->assertStatus(422);
    }

    public function test_updating_a_person_never_changes_the_password(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/users/'.$this->worker->id, ['name' => 'Ana Torres', 'email' => 'ana@cerevelo.test', 'worker_type' => 'consultant',
            'capture_mode' => 'from_to', 'password' => 'clave-nueva-del-dueno'])->assertOk();
        $this->assertTrue(\Hash::check('secret-password', $this->worker->fresh()->password));
    }

    // ---------- Reseteo por el dueño ----------

    public function test_owner_reset_voids_the_old_password_revokes_sessions_and_logs_without_any_password(): void
    {
        Mail::fake();
        $this->world();
        $this->worker->createToken('web');
        $this->worker->createToken('app');
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/users/'.$this->worker->id.'/reset-password')->assertOk()
            ->assertExactJson(['ok' => true, 'emailed_to' => 'ana@cerevelo.test']);

        $this->assertSame(0, $this->worker->tokens()->count());
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'])->assertStatus(422);

        $mail = $this->mailTo('mail.account-reset', 'ana@cerevelo.test');
        $this->assertNotNull($mail);
        $this->assertStringContainsString('24', $mail->render());
        $token = $this->linkToken($mail);
        $this->getJson('/api/password/setup?token='.$token)->assertOk()->assertJsonPath('purpose', 'reset');

        $ev = SecurityEvent::where('type', 'password_reset')->first();
        $this->assertSame($this->owner->id, $ev->actor_user_id);
        $this->assertSame($this->worker->id, $ev->target_user_id);
        $this->assertNotNull($ev->ip);
        $this->assertNotNull($ev->created_at);
        $dump = json_encode(SecurityEvent::all()->toArray());
        foreach (['secret-password', $token, 'password":'] as $needle) {
            $this->assertStringNotContainsString($needle, $dump);
        }

        $this->postJson('/api/password/setup', ['token' => $token, 'password' => self::NEW_PASSWORD])->assertOk();
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => self::NEW_PASSWORD])->assertOk();
    }

    public function test_reset_expires_after_24_hours_and_only_the_owner_can_do_it(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->postJson('/api/users/'.$this->owner->id.'/reset-password')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/users/'.$this->owner->id.'/reset-password')->assertStatus(422);   // a sí mismo: consola
        $this->postJson('/api/users/'.$this->worker->id.'/reset-password')->assertOk();
        $token = $this->linkToken($this->mailTo('mail.account-reset', 'ana@cerevelo.test'));
        Carbon::setTestNow(Carbon::now()->addHours(25));
        $this->getJson('/api/password/setup?token='.$token)->assertNotFound();
    }

    public function test_reset_also_drops_trusted_devices(): void
    {
        $account = $this->approverAccount();
        $this->webLogin()->assertStatus(202);
        $ch = $this->webLogin()->json('challenge');
        $this->postJson('/api/login/code', ['challenge' => $ch, 'code' => $this->codeFrom('jorge@cliente.test'), 'remember' => true])->assertOk();
        $this->assertSame(1, TrustedDevice::count());
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/users/'.$account->id.'/reset-password')->assertOk();
        $this->assertSame(0, TrustedDevice::count());
    }

    // ---------- Cambio de contraseña ----------

    public function test_changing_the_password_requires_the_current_one_and_revokes_other_sessions(): void
    {
        $this->world();
        $keep = $this->worker->createToken('este');
        $this->worker->createToken('otro');
        $this->worker->createToken('otro2');
        $this->withToken($keep->plainTextToken);

        $this->postJson('/api/password/change', ['current_password' => 'mala', 'password' => self::NEW_PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/password/change', ['current_password' => 'secret-password', 'password' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertSame(3, $this->worker->tokens()->count());

        $this->postJson('/api/password/change', ['current_password' => 'secret-password', 'password' => self::NEW_PASSWORD])
            ->assertOk()->assertExactJson(['ok' => true]);
        $this->assertSame([$keep->accessToken->id], $this->worker->tokens()->pluck('id')->all());
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'ana@cerevelo.test', 'password' => self::NEW_PASSWORD])->assertOk();
    }

    // ---------- Aprobador con cuenta ----------

    public function test_enabling_can_login_creates_an_approver_account_with_the_same_email_and_disabling_turns_it_off(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/approvers')->assertJsonPath('0.account_status', 'none')->assertJsonPath('0.can_login', false);

        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'can_login' => true])
            ->assertOk()->assertJsonPath('can_login', true)->assertJsonPath('account_status', 'pending');
        $u = User::where('approver_profile_id', $this->approver->id)->first();
        $this->assertSame('approver', $u->role);
        $this->assertSame('jorge@cliente.test', $u->email);
        $this->assertSame('Jorge Ramos', $u->name);
        $this->assertNotNull($this->mailTo('mail.account-invite', 'jorge@cliente.test'));
        $this->getJson('/api/users')->assertJsonMissing(['email' => 'jorge@cliente.test']);

        // El correo del aprobador se sincroniza con la cuenta
        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge R.', 'email' => 'jorge2@cliente.test', 'can_login' => true])->assertOk();
        $this->assertSame('jorge2@cliente.test', $u->fresh()->email);
        $this->assertSame('Jorge R.', $u->fresh()->name);
        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'can_login' => true])->assertOk();

        // Un correo que ya es de otra cuenta no sirve
        $other = Approver::create(['company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'luis@cerevelo.test']);
        $this->putJson('/api/approvers/'.$other->id, ['name' => 'Otro', 'email' => 'luis@cerevelo.test', 'can_login' => true])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $u->createToken('web');
        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'can_login' => false])
            ->assertOk()->assertJsonPath('account_status', 'disabled');
        $this->assertFalse($u->fresh()->active);
        $this->assertSame(0, $u->tokens()->count());

        // Reactivar crea una invitación nueva con la misma cuenta
        $this->putJson('/api/approvers/'.$this->approver->id, ['name' => 'Jorge Ramos', 'email' => 'jorge@cliente.test', 'can_login' => true])
            ->assertOk()->assertJsonPath('account_status', 'pending');
        $this->assertSame(1, User::where('approver_profile_id', $this->approver->id)->count());
        $this->assertTrue($u->fresh()->active);
    }

    public function test_approver_web_login_asks_for_an_emailed_code_and_mobile_login_is_refused(): void
    {
        $this->approverAccount();
        // Móvil: no entran aprobadores
        $this->postJson('/api/login', ['email' => 'jorge@cliente.test', 'password' => self::NEW_PASSWORD])
            ->assertStatus(403)->assertJsonPath('code', 'approver_web_only');
        // Con clave incorrecta no se revela nada
        $this->postJson('/api/login', ['email' => 'jorge@cliente.test', 'password' => 'mala-mala-mala'])->assertStatus(422);

        $r = $this->webLogin()->assertStatus(202)->assertJsonPath('step', 'code')->assertJsonPath('email_hint', 'j***@cliente.test')
            ->assertJsonPath('expires_in', 600)->assertJsonPath('remember_days', 30)->assertJsonMissingPath('token');
        $this->assertDatabaseMissing('login_challenges', ['challenge_hash' => $r->json('challenge')]);
        $code = $this->codeFrom('jorge@cliente.test');
        $this->assertDatabaseMissing('login_challenges', ['code_hash' => $code]);

        $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $code === '000000' ? '111111' : '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $ok = $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $code])->assertOk()
            ->assertJsonStructure(['token', 'user'])->assertJsonMissingPath('device_token');
        // El código sirve una vez
        $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $code])->assertStatus(422);

        Sanctum::actingAs(User::where('email', 'jorge@cliente.test')->first());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role', 'approver')->assertJsonPath('approver_id', $this->approver->id)
            ->assertJsonPath('two_step', true);
        $this->assertSame('approver', $ok->json('user.role'));
    }

    public function test_code_expires_in_10_minutes_and_dies_after_5_wrong_attempts(): void
    {
        $this->approverAccount();
        $r = $this->webLogin()->assertStatus(202);
        $code = $this->codeFrom('jorge@cliente.test');
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $wrong])->assertStatus(422);
        }
        // Ya con 5 intentos, ni el código correcto sirve
        $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $code])->assertStatus(422);

        $r2 = $this->webLogin()->assertStatus(202);
        $code2 = $this->codeFrom('jorge@cliente.test');
        Carbon::setTestNow(Carbon::now()->addMinutes(11));
        $this->postJson('/api/login/code', ['challenge' => $r2->json('challenge'), 'code' => $code2])->assertStatus(422);
    }

    public function test_code_can_be_resent_once_per_minute(): void
    {
        $this->approverAccount();
        $r = $this->webLogin()->assertStatus(202);
        $this->postJson('/api/login/code/resend', ['challenge' => $r->json('challenge')])->assertStatus(429);
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->postJson('/api/login/code/resend', ['challenge' => $r->json('challenge')])->assertOk()->assertExactJson(['ok' => true]);
        $this->postJson('/api/login/code/resend', ['challenge' => $r->json('challenge')])->assertStatus(429);
        // El código nuevo es el que sirve
        $code = $this->codeFrom('jorge@cliente.test');
        $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $code])->assertOk();
    }

    public function test_remember_this_device_skips_the_code_for_n_days(): void
    {
        $this->approverAccount();
        $r = $this->webLogin()->assertStatus(202);
        $ok = $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $this->codeFrom('jorge@cliente.test'), 'remember' => true])
            ->assertOk()->assertJsonStructure(['token', 'user', 'device_token']);
        $device = $ok->json('device_token');
        $this->assertDatabaseMissing('trusted_devices', ['token_hash' => $device]);

        $this->webLogin(['device_token' => $device])->assertOk()->assertJsonStructure(['token', 'user']);
        // Sin la clave correcta, el equipo recordado no sirve
        $this->webLogin(['device_token' => $device, 'password' => 'mala-mala-mala'])->assertStatus(422);
        $this->webLogin(['device_token' => 'otro'])->assertStatus(202);

        Carbon::setTestNow(Carbon::now()->addDays(31));
        $this->webLogin(['device_token' => $device])->assertStatus(202);
    }

    public function test_remember_days_zero_turns_remembering_off(): void
    {
        $this->approverAccount();
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/company', ['rules' => ['remember_days' => 0]])->assertOk()->assertJsonPath('rules.remember_days', 0);
        $r = $this->webLogin()->assertStatus(202)->assertJsonPath('remember_days', 0);
        $this->postJson('/api/login/code', ['challenge' => $r->json('challenge'), 'code' => $this->codeFrom('jorge@cliente.test'), 'remember' => true])
            ->assertOk()->assertJsonMissingPath('device_token');
        $this->assertSame(0, TrustedDevice::count());

        Sanctum::actingAs($this->owner);
        $this->putJson('/api/company', ['rules' => ['remember_days' => 366]])->assertStatus(422);
        $this->getJson('/api/company')->assertJsonPath('rules.remember_days', 0);
    }

    public function test_remember_days_defaults_to_30(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/company')->assertJsonPath('rules.remember_days', 30);
    }

    // ---------- Hojas del aprobador ----------

    public function test_approver_sees_only_their_sheets_and_can_approve_without_a_code(): void
    {
        $this->approverAccount();
        $mine = $this->submitSheet();
        $other = Approver::create(['company_id' => $this->company->id, 'name' => 'Otro', 'email' => 'otro@cliente.test']);
        $theirs = Timesheet::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'week_ending' => '2026-10-18',
            'approver_id' => $other->id, 'status' => 'submitted', 'version' => 1, 'total_hours' => 8]);

        Sanctum::actingAs(User::where('email', 'jorge@cliente.test')->first());
        $list = $this->getJson('/api/approver/sheets')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine)->assertJsonPath('0.user.name', 'Ana Torres')->assertJsonPath('0.status', 'submitted')
            ->assertJsonPath('0.needs_code', false)->assertJsonPath('0.week_ending', '2026-10-25')->assertJsonPath('0.week_starting', '2026-10-19')
            ->assertJsonStructure(['*' => ['id', 'user' => ['id', 'name'], 'week_starting', 'week_ending', 'status', 'total_hours', 'late', 'version',
                'submitted_at', 'decided_at', 'warnings', 'needs_code', 'return_comment']]);
        $this->getJson('/api/approver/sheets?status=approved')->assertJsonCount(0);

        $this->getJson('/api/approver/sheets/'.$mine)->assertOk()->assertJsonPath('id', $mine)->assertJsonStructure(['entries' => [['project']]]);
        $this->getJson('/api/approver/sheets/'.$theirs->id)->assertNotFound();
        $this->postJson('/api/approver/sheets/'.$theirs->id.'/approve', ['version' => 1])->assertNotFound();

        $v = $list->json('0.version');
        $this->postJson('/api/approver/sheets/'.$mine.'/approve', ['version' => $v + 5])->assertStatus(422);
        $this->postJson('/api/approver/sheets/'.$mine.'/approve', ['version' => $v])->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('timesheet_events', ['timesheet_id' => $mine, 'type' => 'approved', 'actor' => 'Jorge Ramos', 'channel' => 'app']);
        $this->getJson('/api/approver/sheets?status=approved')->assertJsonCount(1);
        $this->getJson('/api/approver/sheets?status=all')->assertJsonCount(1);
        $this->getJson('/api/approver/sheets?status=pending')->assertJsonCount(0);
    }

    public function test_approver_returns_a_sheet_with_a_comment(): void
    {
        $this->approverAccount();
        $id = $this->submitSheet();
        Sanctum::actingAs(User::where('email', 'jorge@cliente.test')->first());
        $v = Timesheet::find($id)->version;
        $this->postJson('/api/approver/sheets/'.$id.'/return', ['version' => $v])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->postJson('/api/approver/sheets/'.$id.'/return', ['version' => $v, 'comment' => 'Falta el martes'])->assertOk()
            ->assertJsonPath('status', 'returned')->assertJsonPath('return_comment', 'Falta el martes');
        $this->getJson('/api/approver/sheets?status=returned')->assertJsonCount(1)->assertJsonPath('0.return_comment', 'Falta el martes');
        $this->assertDatabaseHas('timesheet_events', ['timesheet_id' => $id, 'type' => 'returned', 'actor' => 'Jorge Ramos', 'channel' => 'app']);
    }

    public function test_require_code_makes_approve_and_return_need_the_emailed_code(): void
    {
        $this->approverAccount(['require_code' => true]);
        $id = $this->submitSheet();
        $approverUser = User::where('email', 'jorge@cliente.test')->first();
        Sanctum::actingAs($approverUser);
        $v = Timesheet::find($id)->version;

        $this->getJson('/api/approver/sheets')->assertJsonPath('0.needs_code', true);
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v])->assertStatus(422)
            ->assertJsonPath('needs_code', true)->assertJsonStructure(['errors' => ['code']]);
        $this->postJson('/api/approver/sheets/'.$id.'/return', ['version' => $v, 'comment' => 'No cuadra'])->assertStatus(422)->assertJsonPath('needs_code', true);

        $this->postJson('/api/approver/sheets/'.$id.'/code')->assertOk()->assertJsonPath('ok', true)->assertJsonPath('expires_in', 900);
        $code = $this->codeFrom('jorge@cliente.test');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v, 'code' => $wrong])->assertStatus(422)->assertJsonPath('needs_code', true);
        $this->assertSame('submitted', Timesheet::find($id)->status);
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v, 'code' => $code])->assertOk()->assertJsonPath('status', 'approved');
    }

    public function test_require_code_also_applies_to_return(): void
    {
        $this->approverAccount(['require_code' => true]);
        $id = $this->submitSheet();
        Sanctum::actingAs(User::where('email', 'jorge@cliente.test')->first());
        $v = Timesheet::find($id)->version;
        $this->postJson('/api/approver/sheets/'.$id.'/code')->assertOk();
        $code = $this->codeFrom('jorge@cliente.test');
        $this->postJson('/api/approver/sheets/'.$id.'/return', ['version' => $v, 'comment' => 'No cuadra', 'code' => $code])->assertOk()
            ->assertJsonPath('status', 'returned');
    }

    public function test_sheets_over_the_second_approval_limit_need_a_code_even_without_the_checkbox(): void
    {
        $this->approverAccount();
        $this->company->update(['rules' => ['second_approval_hours' => 4]]);
        $id = $this->submitSheet();                 // 8 h
        Sanctum::actingAs(User::where('email', 'jorge@cliente.test')->first());
        $v = Timesheet::find($id)->version;
        $this->getJson('/api/approver/sheets')->assertJsonPath('0.needs_code', true);
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v])->assertStatus(422)->assertJsonPath('needs_code', true);
        $this->postJson('/api/approver/sheets/'.$id.'/code')->assertOk();
        $this->postJson('/api/approver/sheets/'.$id.'/approve', ['version' => $v, 'code' => $this->codeFrom('jorge@cliente.test')])->assertOk();
    }

    public function test_approver_accounts_get_403_everywhere_else(): void
    {
        $this->approverAccount();
        $id = $this->submitSheet();
        Sanctum::actingAs(User::where('email', 'jorge@cliente.test')->first());
        foreach ([['get', '/api/projects'], ['get', '/api/entries'], ['post', '/api/entries'], ['get', '/api/users'], ['get', '/api/sheets'],
                  ['get', '/api/sheets/'.$id], ['post', '/api/sheets/'.$id.'/approve'], ['get', '/api/sheets-pending'], ['get', '/api/company'],
                  ['get', '/api/brand'], ['get', '/api/approvers'], ['get', '/api/reports/summary'], ['post', '/api/users']] as [$m, $url]) {
            $this->json($m, $url)->assertForbidden();
        }
        // Lo permitido
        $this->getJson('/api/me')->assertOk();
        $this->putJson('/api/me/locale', ['locale' => 'en'])->assertOk();
        $this->postJson('/api/password/change', ['current_password' => self::NEW_PASSWORD, 'password' => 'otra-clave-larga-2'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_regular_users_cannot_use_the_approver_endpoints(): void
    {
        $this->world();
        Sanctum::actingAs($this->worker);
        $this->getJson('/api/approver/sheets')->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/approver/sheets')->assertForbidden();
    }

    // ---------- Dominio por compañía ----------

    private function giveDomain(string $domain = 'acme.example.com'): void
    {
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/company', ['domain' => $domain])->assertOk();
    }

    public function test_owner_edits_the_company_domain_with_validation(): void
    {
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/company')->assertJsonPath('domain', null);
        $this->putJson('/api/company', ['domain' => ' ACME.Example.com '])->assertOk()->assertJsonPath('domain', 'acme.example.com');
        $this->putJson('/api/company', ['domain' => 'no es un host'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->putJson('/api/company', ['domain' => 'https://acme.example.com/x'])->assertStatus(422);
        Company::create(['name' => 'Otra', 'slug' => 'otra', 'domain' => 'otra.example.com']);
        $this->putJson('/api/company', ['domain' => 'otra.example.com'])->assertStatus(422)->assertJsonValidationErrors('domain');
        $this->putJson('/api/company', ['domain' => null])->assertOk()->assertJsonPath('domain', null);
        $this->getJson('/api/company')->assertJsonPath('rules.remember_days', 30);
    }

    public function test_public_config_takes_the_brand_from_the_host(): void
    {
        $this->world();
        $this->company->update(['brand_name' => 'Cerevelo SRL', 'color_primary' => '#112233', 'domain' => 'cerevelo.example.com', 'locale' => 'es']);
        Company::create(['name' => 'Otra', 'slug' => 'otra', 'domain' => 'otra.example.com', 'brand_name' => 'Otra Marca', 'locale' => 'en']);

        $this->getJson('http://cerevelo.example.com/api/public-config')->assertOk()
            ->assertJsonPath('brand.name', 'Cerevelo SRL')->assertJsonPath('brand.color_primary', '#112233')->assertJsonPath('locale', 'es')
            ->assertJsonStructure(['turnstile_site_key', 'locale', 'brand' => ['name', 'logo_url', 'color_primary', 'color_accent']]);
        $this->getJson('http://otra.example.com/api/public-config')->assertJsonPath('brand.name', 'Otra Marca')->assertJsonPath('locale', 'en');
        // Dos compañías y un host desconocido: sin marca
        $this->getJson('http://desconocido.example.com/api/public-config')->assertOk()->assertJsonPath('brand', null);
        $this->getJson('http://cerevelo.example.com/api/public-config', ['X-Locale' => 'en'])->assertJsonPath('locale', 'en');
    }

    public function test_public_config_uses_the_only_company_when_the_host_is_unknown(): void
    {
        $this->world();
        $this->getJson('/api/public-config')->assertOk()->assertJsonPath('brand.name', 'Cerevelo');
    }

    public function test_people_only_sign_in_through_their_companys_domain(): void
    {
        $this->world();
        $this->giveDomain('cerevelo.example.com');
        Company::create(['name' => 'Otra', 'slug' => 'otra', 'domain' => 'otra.example.com']);
        $cred = ['email' => 'ana@cerevelo.test', 'password' => 'secret-password'];

        $this->postJson('http://cerevelo.example.com/api/login/web', $cred)->assertOk();
        // Por el dominio de otra compañía: igual que credenciales inválidas
        $this->postJson('http://otra.example.com/api/login/web', $cred)->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Correo o contraseña incorrectos.');
        // Por un host cualquiera, tampoco, porque su compañía tiene dominio
        $this->postJson('http://localhost/api/login/web', $cred)->assertStatus(422);
        // La app móvil tiene su servidor fijo: no depende del dominio
        $this->postJson('/api/login', $cred)->assertOk();
    }

    public function test_emails_link_to_the_recipients_company_domain(): void
    {
        Mail::fake();
        $this->world();
        $this->giveDomain('cerevelo.example.com');
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/users', ['name' => 'Pedro Vega', 'email' => 'pedro@x.test', 'worker_type' => 'consultant', 'capture_mode' => 'from_to'])->assertCreated();
        $this->assertStringContainsString('https://cerevelo.example.com/app/#/set-password/', $this->mailTo('mail.account-invite', 'pedro@x.test')->render());

        $this->postJson('/api/users/'.$this->worker->id.'/reset-password')->assertOk();
        $this->assertStringContainsString('https://cerevelo.example.com/app/#/set-password/', $this->mailTo('mail.account-reset', 'ana@cerevelo.test')->render());

        $this->submitSheet();
        $req = Mail::sent(BrandedMail::class, fn ($m) => $m->viewName === 'mail.approval-request')->last()->render();
        $this->assertMatchesRegularExpression('#https://cerevelo\.example\.com/a/[A-Za-z0-9]{48}#', $req);
    }

    public function test_emails_use_the_installation_url_when_the_company_has_no_domain(): void
    {
        Mail::fake();
        $this->world();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/users/'.$this->worker->id.'/reset-password')->assertOk();
        $this->assertStringContainsString(rtrim(config('app.url'), '/').'/app/#/set-password/', $this->mailTo('mail.account-reset', 'ana@cerevelo.test')->render());
        $this->assertSame(rtrim(config('app.url'), '/'), $this->company->baseUrl());
        $this->company->update(['domain' => 'x.example.com']);
        $this->assertSame('https://x.example.com', $this->company->baseUrl());
    }

    public function test_domain_check_for_caddy(): void
    {
        $this->world();
        $this->company->update(['domain' => 'cerevelo.example.com']);
        $this->getJson('/api/domain-check?domain=cerevelo.example.com')->assertOk();
        $this->getJson('/api/domain-check?domain=CEREVELO.example.com')->assertOk();
        $this->getJson('/api/domain-check?domain=otro.example.com')->assertNotFound();
        $this->getJson('/api/domain-check')->assertNotFound();
    }

    public function test_approver_must_use_the_company_domain_too(): void
    {
        $this->approverAccount();
        $this->company->update(['domain' => 'cerevelo.example.com']);
        $this->postJson('http://otra.example.com/api/login/web', ['email' => 'jorge@cliente.test', 'password' => self::NEW_PASSWORD])->assertStatus(422);
        $this->postJson('http://cerevelo.example.com/api/login/web', ['email' => 'jorge@cliente.test', 'password' => self::NEW_PASSWORD])->assertStatus(202);
    }

    // ---------- Consola ----------

    public function test_reset_command_prints_a_one_time_link_and_emails_it(): void
    {
        Mail::fake();
        $this->world();
        $this->artisan('tiempo:resetear-clave', ['correo' => 'luis@cerevelo.test'])
            ->expectsOutputToContain('/app/#/set-password/')->assertExitCode(0);

        $this->postJson('/api/login', ['email' => 'luis@cerevelo.test', 'password' => 'secret-password'])->assertStatus(422);
        $mail = $this->mailTo('mail.account-reset', 'luis@cerevelo.test');
        $this->assertNotNull($mail);
        $token = $this->linkToken($mail);
        $this->postJson('/api/password/setup', ['token' => $token, 'password' => self::NEW_PASSWORD])->assertOk();
        $this->postJson('/api/login', ['email' => 'luis@cerevelo.test', 'password' => self::NEW_PASSWORD])->assertOk();
        $this->assertSame('password_reset', SecurityEvent::first()->type);
        $this->assertNull(SecurityEvent::first()->actor_user_id);
    }

    public function test_reset_command_fails_for_an_unknown_email(): void
    {
        $this->world();
        $this->artisan('tiempo:resetear-clave', ['correo' => 'nadie@x.test'])->assertExitCode(1);
    }

    public function test_install_command_still_generates_a_password_once(): void
    {
        $this->artisan('tiempo:instalar', ['compania' => 'Nueva SA', 'correo' => 'dueno@nueva.test'])
            ->expectsOutputToContain('Contraseña generada')->assertExitCode(0);
        $u = User::where('email', 'dueno@nueva.test')->first();
        $this->assertFalse($u->must_set_password);
        $this->artisan('tiempo:instalar', ['compania' => 'Otra SA', 'correo' => 'o@otra.test', '--clave' => 'corta'])->assertExitCode(1);
    }
}

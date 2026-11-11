<?php

use App\Models\AuditLog;
use App\Models\TimeEntry;
use App\Models\WorkLocation;
use App\Support\Geo;
use Illuminate\Support\Facades\Mail;

/*
 * Cerca virtual: o RH liga/desliga "bater ponto só nos locais cadastrados".
 * Sede de teste: Praça da Sé, Salvador (-12.9714, -38.5124), raio 150 m.
 */

const SEDE = ['latitude' => -12.9714, 'longitude' => -38.5124];

beforeEach(function () {
    Mail::fake();
    $this->c = makeCompany('Sigma');
});

function addLocation($test, array $over = [])
{
    return $test->actingAs($test->c['rh'])->post('/configuracoes/locais', [
        'name' => 'Sede', 'radius_m' => 150, ...SEDE, ...$over,
    ]);
}

it('calcula a distância e a folga da precisão do GPS', function () {
    // ~111 m por milésimo de grau de latitude.
    expect(round(Geo::distance(-12.9714, -38.5124, -12.9724, -38.5124)))->toBe(111.0);

    $locs = [['name' => 'Sede', 'latitude' => -12.9714, 'longitude' => -38.5124, 'radius_m' => 100]];
    expect(Geo::nearest($locs, -12.9724, -38.5124)['inside'])->toBeFalse()          // 111 m > 100 m
        ->and(Geo::nearest($locs, -12.9724, -38.5124, 20)['inside'])->toBeTrue()    // folga de 20 m
        ->and(Geo::nearest($locs, -12.9744, -38.5124, 500)['inside'])->toBeFalse()  // folga limitada a 50 m
        ->and(Geo::human(1234))->toBe('1,2 km')
        ->and(Geo::human(84))->toBe('80 m');
});

it('RH cadastra, edita e remove locais, com auditoria', function () {
    addLocation($this)->assertSessionHasNoErrors();
    $loc = WorkLocation::firstOrFail();

    $this->actingAs($this->c['rh'])->get('/configuracoes')
        ->assertInertia(fn ($p) => $p->has('locations', 1)->where('locations.0.name', 'Sede')->where('settings.geofence_enabled', false));

    $this->actingAs($this->c['rh'])->post("/configuracoes/locais/{$loc->id}", ['name' => 'Matriz', 'radius_m' => 300, ...SEDE])->assertSessionHasNoErrors();
    expect($loc->fresh()->only(['name', 'radius_m']))->toBe(['name' => 'Matriz', 'radius_m' => 300]);

    $this->actingAs($this->c['rh'])->post("/configuracoes/locais/{$loc->id}/remover")->assertSessionHas('success');
    expect(WorkLocation::count())->toBe(0)
        ->and(AuditLog::whereIn('action', ['work_location.created', 'work_location.updated', 'work_location.deleted'])->count())->toBe(3);
});

it('valida coordenadas e raio do local', function () {
    addLocation($this, ['latitude' => 120])->assertSessionHasErrors('latitude');
    addLocation($this, ['radius_m' => 10])->assertSessionHasErrors('radius_m');
    addLocation($this, ['name' => ''])->assertSessionHasErrors('name');
    expect(WorkLocation::count())->toBe(0);
});

it('só liga a restrição com local cadastrado, e ligar exige GPS', function () {
    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => false, 'geofence_enabled' => true])
        ->assertSessionHasErrors('geofence_enabled');

    addLocation($this);
    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => false, 'geofence_enabled' => true])->assertSessionHasNoErrors();

    $company = $this->c['company']->fresh();
    expect($company->geofence_enabled)->toBeTrue()->and($company->require_location)->toBeTrue();

    // Com a restrição ligada, o último local não pode ser removido.
    $this->actingAs($this->c['rh'])->post('/configuracoes/locais/'.WorkLocation::first()->id.'/remover')->assertSessionHas('error');
    expect(WorkLocation::count())->toBe(1);
});

it('com a restrição ligada, recusa batida fora do raio e aceita dentro', function () {
    addLocation($this);
    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => true, 'geofence_enabled' => true]);
    app('auth')->forgetGuards();
    $this->withToken(apiToken($this->c['employee']->user));

    $this->postJson('/api/me')
        ->assertJsonPath('profile.require_location', true)
        ->assertJsonPath('profile.geofence.0.name', 'Sede')
        ->assertJsonMissingPath('profile.geofence.0.latitude');

    // Sem GPS: recusado.
    $this->postJson('/api/ponto')->assertJsonValidationErrors(['latitude', 'longitude']);

    // ~1,1 km ao sul: recusado com a distância e o nome do local.
    $res = $this->postJson('/api/ponto', ['latitude' => -12.9814, 'longitude' => -38.5124])->assertUnprocessable();
    expect($res->json('errors.latitude.0'))->toContain('1,1 km de Sede')->toContain('150 m');
    expect(TimeEntry::count())->toBe(0);

    // ~80 m do centro: aceito, com as coordenadas gravadas.
    $this->postJson('/api/ponto', ['latitude' => -12.97212, 'longitude' => -38.5124, 'accuracy' => 15])->assertCreated();
    expect(TimeEntry::count())->toBe(1)->and(TimeEntry::first()->latitude)->toBe(-12.97212);
});

it('aceita a batida no local mais próximo quando há vários', function () {
    addLocation($this);
    addLocation($this, ['name' => 'Filial Barra', 'latitude' => -13.0104, 'longitude' => -38.5322, 'radius_m' => 200]);
    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => true, 'geofence_enabled' => true]);
    app('auth')->forgetGuards();

    $this->withToken(apiToken($this->c['employee']->user))
        ->postJson('/api/ponto', ['latitude' => -13.0110, 'longitude' => -38.5320])
        ->assertCreated();
});

it('desligar a restrição volta a aceitar qualquer lugar (cache invalidado)', function () {
    addLocation($this);
    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => true, 'geofence_enabled' => true]);
    app('auth')->forgetGuards();
    $token = apiToken($this->c['employee']->user);
    $this->withToken($token)->postJson('/api/ponto', ['latitude' => -12.0, 'longitude' => -38.0])->assertUnprocessable();

    $this->actingAs($this->c['rh'])->post('/configuracoes', ['require_location' => true, 'geofence_enabled' => false]);
    app('auth')->forgetGuards();
    $this->withToken($token)->postJson('/api/ponto', ['latitude' => -12.0, 'longitude' => -38.0])->assertCreated();
});

it('locais de outra empresa não aparecem nem podem ser alterados', function () {
    addLocation($this);
    $loc = WorkLocation::firstOrFail();
    $other = makeCompany('Tau');

    $this->actingAs($other['rh'])->get('/configuracoes')->assertInertia(fn ($p) => $p->has('locations', 0));
    $this->actingAs($other['rh'])->post("/configuracoes/locais/{$loc->id}", ['name' => 'Hack', 'radius_m' => 5000, ...SEDE])->assertNotFound();
    $this->actingAs($other['rh'])->post("/configuracoes/locais/{$loc->id}/remover")->assertNotFound();
    $this->actingAs($this->c['employee']->user)->post('/configuracoes/locais', ['name' => 'X', 'radius_m' => 100, ...SEDE])->assertForbidden();

    expect($loc->fresh()->name)->toBe('Sede');
});

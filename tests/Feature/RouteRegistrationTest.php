<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Vortech\Trace\Attributes\Traceable;
use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Source\SourceRegistry;
use Vortech\Trace\Tests\Support\ClinicSource;
use Vortech\Trace\Tests\Support\UntrackedClinic;
use Vortech\Trace\Tests\TestCase;

#[Traceable]
final class TraceableWithoutSource extends Model {}

#[Traceable(source: ClinicSource::class)]
final class OtherModelNamingClinicSource extends Model {}

it('serves the same feed whether the route names the model or the source', function (): void {
    makeClinic(['code' => 'CL-1']);
    $headers = ['Authorization' => 'Bearer '.TestCase::TOKEN];

    $bySource = $this->getJson('/api/clinics/snapshot', $headers)->assertOk()->json('data');
    $byModel = $this->getJson('/api/clinics-by-model/snapshot', $headers)->assertOk()->json('data');

    expect($byModel)->toBe($bySource);
});

it('stores the resolved source, never the model, in the route defaults', function (): void {
    expect(app(SourceRegistry::class)->all())->toMatchArray([
        'clinics' => ClinicSource::class,
        'clinics-by-model' => ClinicSource::class,
    ]);
});

it('publishes one model through several feeds when the routes name a source', function (): void {
    Route::source('clinics-copy', ClinicSource::class);

    expect(app(SourceRegistry::class)->all())->toHaveKeys(['clinics', 'clinics-by-model', 'clinics-copy']);
});

it('refuses a model that does not name its source', function (string $class, string $message): void {
    expect(fn () => Route::source('broken', $class))->toThrow(InvalidSourceException::class, $message);
})->with([
    'no #[Traceable]' => [UntrackedClinic::class, 'does not name its source'],
    'no source in #[Traceable]' => [\TraceableWithoutSource::class, 'does not name its source'],
    'source of another model' => [\OtherModelNamingClinicSource::class, 'different model'],
    'neither source nor model' => [\stdClass::class, 'must be a'],
]);

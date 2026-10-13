<?php

declare(strict_types=1);

use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Source;
use Vortech\Trace\Tests\Support\Clinic;
use Vortech\Trace\Tests\Support\ClinicSource;
use Vortech\Trace\Tests\Support\UntrackedClinic;

function sourceWith(string $model, array $fields): Source
{
    return new class($model, $fields) extends Source
    {
        public function __construct(string $model, array $fields)
        {
            $this->model = $model;
            $this->fields = $fields;
        }
    };
}

it('accepts a valid source', function (): void {
    new ClinicSource()->validate();

    expect(new ClinicSource()->fields())->toContain('code', 'name');
});

it('refuses an invalid source', function (string $model, array $fields, string $message): void {
    expect(fn () => sourceWith($model, $fields)->validate())->toThrow(InvalidSourceException::class, $message);
})->with([
    'missing model' => ['Nope\\Missing', ['name'], 'Eloquent model'],
    'not traceable' => [UntrackedClinic::class, ['name'], '#[Traceable]'],
    'no fields' => [Clinic::class, [], 'non-empty list'],
    'hidden on the model' => [Clinic::class, ['name', 'internal_note'], 'hidden'],
    'forbidden by config' => [Clinic::class, ['name', 'password'], 'forbidden_fields'],
    'forbidden regardless of case' => [Clinic::class, ['Remember_Token'], 'forbidden_fields'],
    'the primary key' => [Clinic::class, ['id', 'name'], 'primary key'],
    'duplicates' => [Clinic::class, ['name', 'name'], 'duplicates'],
]);

it('never puts unlisted attributes into the payload', function (): void {
    $clinic = makeClinic(['internal_note' => 'secret', 'name' => 'Central']);

    $attributes = new ClinicSource()->attributesFor($clinic);

    expect(array_keys($attributes))->toBe(['code', 'name', 'address', 'phone', 'email', 'city', 'postal_code', 'is_active'])
        ->and($attributes)->not->toHaveKey('internal_note')
        ->and($attributes)->not->toHaveKey('id')
        ->and($attributes['is_active'])->toBeTrue();
});

it('changes the schema hash with the published fields only', function (): void {
    $a = sourceWith(Clinic::class, ['name', 'code']);
    $b = sourceWith(Clinic::class, ['code', 'name']);
    $c = sourceWith(Clinic::class, ['name']);

    expect($a->schemaHash('clinics'))->toBe($b->schemaHash('clinics'))
        ->and($a->schemaHash('clinics'))->not->toBe($c->schemaHash('clinics'))
        ->and($a->schemaHash('clinics'))->toStartWith('sha256:');
});

it('reports fields that are no columns', function (): void {
    expect(sourceWith(Clinic::class, ['name', 'ghost'])->missingColumns())->toBe(['ghost']);
});

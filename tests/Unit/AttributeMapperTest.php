<?php

declare(strict_types=1);

use Vortech\Trace\Exceptions\InvalidMappingException;
use Vortech\Trace\Exceptions\SchemaMismatchException;
use Vortech\Trace\Mapper;
use Vortech\Trace\Remote\AttributeMapper;
use Vortech\Trace\Tests\Support\ClinicMapper;

final class SloppyMapper extends Mapper
{
    public function fields(): array
    {
        return ['name'];
    }

    public function transform(array $mapped): array
    {
        $mapped['is_admin'] = true;

        return $mapped;
    }
}

function clinicPayload(array $override = []): array
{
    return $override + [
        'name' => 'Central',
        'address' => 'Main st 1',
        'email' => ' Info@Example.COM ',
        'city' => 'Budapest',
        'postal_code' => '1011',
        'phone' => '+36123',
        'is_active' => true,
        'code' => 'CL-1',
    ];
}

it('keeps equal names and renames the others', function (): void {
    $mapped = new AttributeMapper()->map(new ClinicMapper, clinicPayload());

    expect($mapped)->toHaveKeys(['name', 'phone_number', 'active'])
        ->and($mapped)->not->toHaveKeys(['phone', 'is_active', 'code'])
        ->and($mapped['phone_number'])->toBe('+36123')
        ->and($mapped['active'])->toBeTrue();
});

it('runs transform() on local column names', function (): void {
    expect(new AttributeMapper()->map(new ClinicMapper, clinicPayload())['email'])->toBe('info@example.com');
});

it('treats a sent null as a value', function (): void {
    $mapped = new AttributeMapper()->map(new ClinicMapper, clinicPayload(['phone' => null, 'email' => null]));

    expect($mapped)->toHaveKey('phone_number', null)
        ->and($mapped)->toHaveKey('email', null);
});

it('treats a missing attribute as a schema error, never as null', function (): void {
    $payload = clinicPayload();
    unset($payload['phone']);

    expect(fn () => new AttributeMapper()->map(new ClinicMapper, $payload))
        ->toThrow(SchemaMismatchException::class, 'phone');
});

it('refuses a transform() that adds a column outside the whitelist', function (): void {
    expect(fn () => new AttributeMapper()->map(new \SloppyMapper, ['name' => 'x']))
        ->toThrow(InvalidMappingException::class, 'is_admin');
});

it('builds the match values from the remote attributes and skips nulls', function (): void {
    $mapper = new AttributeMapper;

    expect($mapper->matchValues(new ClinicMapper, clinicPayload()))->toBe(['clinic_code' => 'CL-1'])
        ->and($mapper->matchValues(new ClinicMapper, clinicPayload(['code' => null])))->toBe([])
        ->and(fn () => $mapper->matchValues(new ClinicMapper, ['name' => 'x']))->toThrow(SchemaMismatchException::class, 'code');
});

it('exposes the normalised mapping', function (): void {
    expect(new ClinicMapper()->mapping())->toMatchArray(['name' => 'name', 'phone' => 'phone_number', 'is_active' => 'active'])
        ->and(new ClinicMapper()->localColumns())->toContain('phone_number', 'name');
});

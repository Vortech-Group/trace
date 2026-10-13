<?php

declare(strict_types=1);

namespace Vortech\Trace;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Throwable;
use UnitEnum;
use Vortech\Trace\Exceptions\InvalidSourceException;
use Vortech\Trace\Support\ModelType;
use Vortech\Trace\Support\TraceConfig;
use Vortech\Trace\Tracking\TraceableRegistry;

/**
 * Describes which model a feed publishes and which of its attributes. `$fields` is the
 * explicit output whitelist: nothing else ever leaves the application.
 */
abstract class Source
{
    /** @var class-string<Model> */
    protected string $model;

    /** @var list<string> */
    protected array $fields;

    /** @var array<class-string, true> */
    private static array $validated = [];

    /**
     * @throws InvalidSourceException
     * @throws Throwable
     */
    public static function resolve(string $class): self
    {
        $source = class_exists($class) ? app($class) : null;

        throw_unless(
            condition: $source instanceof self,
            exception: new InvalidSourceException("[{$class}] must extend ".self::class.'.'),
        );

        return $source;
    }

    /**
     * @return class-string<Model>
     */
    public function model(): string
    {
        return $this->model;
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function newModel(): Model
    {
        return new ($this->model());
    }

    public function keyName(): string
    {
        return $this->newModel()->getKeyName();
    }

    public function morphClass(): string
    {
        return ModelType::of($this->newModel());
    }

    /**
     * The members of the feed: what the model's own scopes let through, soft deleted rows excluded.
     *
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        return $this->newModel()->newQuery();
    }

    /**
     * Same scopes as query(), but soft deleted rows stay visible so they can become tombstones.
     *
     * @return Builder<Model>
     */
    public function lookupQuery(): Builder
    {
        $query = $this->query();

        if ($this->usesSoftDeletes()) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query;
    }

    public function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->model()), true);
    }

    public function isMember(Model $model): bool
    {
        return ! $this->usesSoftDeletes() || ! method_exists($model, 'trashed') || ! $model->trashed();
    }

    public function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * Only the whitelisted fields, after Eloquent casts and accessors, as JSON-safe values.
     *
     * @return array<string, mixed>
     */
    public function attributesFor(Model $model): array
    {
        $attributes = [];

        foreach ($this->fields() as $field) {
            $attributes[$field] = $this->normalize($model->getAttribute($field));
        }

        return $attributes;
    }

    /** A stable fingerprint of the published structure.
     * @throws JsonException
     */
    public function schemaHash(string $feed): string
    {
        $fields = $this->fields();
        sort($fields);

        return 'sha256:'.hash('sha256', json_encode(['feed' => $feed, 'fields' => $fields], JSON_THROW_ON_ERROR));
    }

    /**
     * @throws InvalidSourceException
     * @throws Throwable
     */
    public function validate(): void
    {
        if (isset(self::$validated[static::class])) {
            return;
        }

        $name = static::class;

        throw_unless(
            condition: isset($this->model) && is_subclass_of($this->model, Model::class),
            exception: new InvalidSourceException("{$name}::\$model must be an Eloquent model class."),
        );

        throw_unless(
            condition: app(TraceableRegistry::class)->isTraceable($this->model),
            exception: new InvalidSourceException("{$this->model} must be marked with #[Traceable] to be published by {$name}."),
        );

        throw_if(
            condition: ! isset($this->fields) || blank($this->fields) || ! array_is_list($this->fields),
            exception: new InvalidSourceException("{$name}::\$fields must be a non-empty list of attribute names."),
        );

        $model = $this->newModel();
        $forbidden = array_map(strtolower(...), [...TraceConfig::forbiddenFields(), ...$model->getHidden()]);

        foreach ($this->fields as $field) {
            throw_if(
                condition: blank($field) || ! is_string($field),
                exception: new InvalidSourceException("{$name}::\$fields may only contain attribute names."),
            );

            throw_if(
                condition: in_array(strtolower($field), $forbidden, true),
                exception: new InvalidSourceException("{$name} must not publish [{$field}]: it is hidden on the model or listed in trace.source.forbidden_fields."),
            );

            throw_if(
                condition: $field === $model->getKeyName(),
                exception: new InvalidSourceException("{$name} must not list the primary key [{$field}]: the key travels as protocol metadata."),
            );
        }

        throw_if(
            condition: count(array_unique($this->fields)) !== count($this->fields),
            exception: new InvalidSourceException("{$name}::\$fields contains duplicates."),
        );

        self::$validated[static::class] = true;
    }

    /**
     * Fields that are not columns of the model's table (accessors are fine, so this is a diagnostic).
     *
     * @return list<string>
     */
    public function missingColumns(): array
    {
        $model = $this->newModel();
        $schema = Schema::connection($model->getConnectionName());

        return array_values(array_filter(
            $this->fields(),
            fn (string $field): bool => ! $schema->hasColumn($model->getTable(), $field),
        ));
    }

    /** Forgets validation results, for tests that redefine sources. */
    public static function flushValidated(): void
    {
        self::$validated = [];
    }

    private function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => array_map($this->normalize(...), $value),
            default => $value,
        };
    }
}

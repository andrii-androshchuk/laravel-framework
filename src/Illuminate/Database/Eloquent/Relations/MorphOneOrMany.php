<?php

namespace Illuminate\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 * @template TResult
 *
 * @extends \Illuminate\Database\Eloquent\Relations\HasOneOrMany<TRelatedModel, TDeclaringModel, TResult>
 */
abstract class MorphOneOrMany extends HasOneOrMany
{
    /**
     * The foreign key type for the relationship.
     *
     * @var string
     */
    protected $morphType;

    /**
     * The class name of the parent model.
     *
     * @var string
     */
    protected $morphClass;

    protected $morphKeyTypeString;

    public function morphKeyTypeString($morphKeyTypeString = true)
    {
        $this->morphKeyTypeString = $morphKeyTypeString;

        return $this;
    }

    /**
     * Create a new morph one or many relationship instance.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @param  string  $type
     * @param  string  $id
     * @param  string  $localKey
     * @return void
     */
    public function __construct(Builder $query, Model $parent, $type, $id, $localKey)
    {
        $this->morphType = $type;

        $this->morphClass = $parent->getMorphClass();

        parent::__construct($query, $parent, $id, $localKey);
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    #[\Override]
    public function addConstraints()
    {
        if (! static::$constraints) {
            return;
        }

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);

        if (SchemaBuilder::$defaultMorphKeyType === 'int') {
            parent::addConstraints();

            return;
        }

        $query = $this->getRelationQuery();

        $query->where($this->foreignKey, '=', transform($this->getParentKey(), fn ($key) => match (SchemaBuilder::$defaultMorphKeyType) {
            'uuid', 'ulid', 'string' => (string) $key,
            default => $key,
        }));

        $query->whereNotNull($this->foreignKey);
    }

    /** @inheritDoc */
    #[\Override]
    public function addEagerConstraints(array $models)
    {
        parent::addEagerConstraints($models);

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Create a new instance of the related model. Allow mass-assignment.
     *
     * @param  array  $attributes
     * @return TRelatedModel
     */
    public function forceCreate(array $attributes = [])
    {
        $attributes[$this->getForeignKeyName()] = $this->getParentKey();
        $attributes[$this->getMorphType()] = $this->morphClass;

        return $this->applyInverseRelationToModel($this->related->forceCreate($attributes));
    }

    /**
     * Set the foreign ID and type for creating a related model.
     *
     * @param  TRelatedModel  $model
     * @return void
     */
    protected function setForeignAttributesForCreate(Model $model)
    {
        $model->{$this->getForeignKeyName()} = $this->getParentKey();

        $model->{$this->getMorphType()} = $this->morphClass;

        foreach ($this->getQuery()->pendingAttributes as $key => $value) {
            if (! $model->hasAttribute($key)) {
                $model->setAttribute($key, $value);
            }
        }

        $this->applyInverseRelationToModel($model);
    }

    /**
     * Insert new records or update the existing ones.
     *
     * @param  array  $values
     * @param  array|string  $uniqueBy
     * @param  array|null  $update
     * @return int
     */
    #[\Override]
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if (! empty($values) && ! is_array(reset($values))) {
            $values = [$values];
        }

        foreach ($values as $key => $value) {
            $values[$key][$this->getMorphType()] = $this->getMorphClass();
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /** @inheritDoc */
    #[\Override]
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->where(
            $query->qualifyColumn($this->getMorphType()), $this->morphClass
        );
    }

    /**
     * Get the foreign key "type" name.
     *
     * @return string
     */
    public function getQualifiedMorphType()
    {
        return $this->morphType;
    }

    /**
     * Get the plain morph type name without the table.
     *
     * @return string
     */
    public function getMorphType()
    {
        return last(explode('.', $this->morphType));
    }

    /**
     * Get the class name of the parent model.
     *
     * @return string
     */
    public function getMorphClass()
    {
        return $this->morphClass;
    }

    /**
     * Get the possible inverse relations for the parent model.
     *
     * @return array<non-empty-string>
     */
    protected function getPossibleInverseRelations(): array
    {
        return array_unique([
            Str::beforeLast($this->getMorphType(), '_type'),
            ...parent::getPossibleInverseRelations(),
        ]);
    }

    /** @inheritDoc */
    #[\Override]
    protected function getKeys(array $models, $key = null)
    {
        if (isset($this->morphKeyTypeString) && $this->morphKeyTypeString === true) {
            $castKeyToString = true;
        } else {
            $castKeyToString = in_array(SchemaBuilder::$defaultMorphKeyType, ['uuid', 'ulid', 'string']);
        }

        return (new Collection(parent::getKeys($models, $key)))
            ->transform(function ($key) use ($castKeyToString) {
                return $castKeyToString === true ? (string) $key : $key;
            })->all();
    }

    /** @inheritDoc */
    #[\Override]
    protected function whereInMethod(Model $model, $key)
    {
        if (! in_array(SchemaBuilder::$defaultMorphKeyType, ['uuid', 'ulid', 'string']) && (! isset($this->morphKeyTypeString) || $this->morphKeyTypeString === false)) {
            return parent::whereInMethod($model, $key);
        }

        return 'whereIn';
    }
}

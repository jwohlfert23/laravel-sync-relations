<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

class SyncableHelpers
{

    public static function isRelationOneToMany(Relation $relation)
    {
        return is_a($relation, HasOneOrMany::class);
    }

    public static function isRelationSingle(Relation $relation)
    {
        return is_a($relation, HasOne::class) || is_a($relation, MorphOne::class) || is_a($relation, BelongsTo::class);
    }

    public static function isRelationMany(Relation $relation)
    {
        return is_a($relation, HasMany::class) || is_a($relation, MorphMany::class);
    }

    /**
     * @param Relation $relation
     * @param $item
     * @return Model|null|SyncableTrait
     */
    public static function relatedExists(Relation $relation, $item)
    {
        $model = $relation->getRelated();
        if (is_a($relation, MorphTo::class)) {
            throw_if(empty($item['syncable_type']), new \InvalidArgumentException("Unable to determine morphed model class to sync"));
            $class = Relation::getMorphedModel($item['syncable_type']) ?: $item['syncable_type'];
            $model = new $class();
        }
        $primaryKey = $model->getKeyName();
        if (!empty($item[$primaryKey])) {
            return $model->find($item[$primaryKey]);
        }
        return null;
    }

    public static function dot($array, $prepend = '')
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value) && array_keys($value)[0] !== 0) {
                $results = array_merge($results, static::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    public static function parseRelationships($dot)
    {
        $arr = [];
        $relations = is_string($dot) ? func_get_args() : $dot;
        foreach ($relations as $relation) {
            Arr::set($arr, $relation, true);
        }
        return $arr;
    }

}

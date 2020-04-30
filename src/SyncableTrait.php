<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\ValidationRuleParser;
use Models\Comment;

trait SyncableTrait
{
    protected $syncable = [];

    public function getSyncable()
    {
        return $this->syncable;
    }

    protected function getSyncValidationRules()
    {
        return [];
    }

    protected function getSyncValidationMessages()
    {
        return [];
    }

    protected function getOrderAttributeName()
    {
        return null;
    }

    public function beforeSync(array $data)
    {
        return $data;
    }

    public function afterSync($data)
    {
        return $this;
    }

    public function syncRelationshipsFromTree(array $relationships, $data)
    {
        if (!is_iterable($relationships))
            return;

        foreach ($relationships as $relationship => $children) {
            $snake = Str::snake($relationship);
            $relationshipModel = $this->{$relationship}();

            if (Arr::has($data, $snake)) {
                $new = Arr::get($data, $snake);

                $relatedModel = $relationshipModel->getRelated();
                $primaryKey = $relatedModel->getKeyName();

                if (SyncableHelpers::isRelationOneToMany($relationshipModel)) {
                    /** @var $relationshipModel HasOneOrMany */

                    // Handle hasOne relationships
                    if (SyncableHelpers::isRelationSingle($relationshipModel)) {
                        $new = [$new];
                    }
                    $new = collect($new);

                    $toRemove = $relationshipModel->pluck($primaryKey)->filter(function ($id) use ($new, $primaryKey) {
                        return !$new->pluck($primaryKey)->contains($id);
                    });

                    foreach ($new as $index => $item) {
                        $item = $relatedModel->beforeSync($item);

                        if ($orderProp = $relatedModel->getOrderAttributeName()) {
                            Arr::set($item, $orderProp, count($new) + 1 - $index);
                        }

                        $related = SyncableHelpers::relatedExists($relationshipModel, $item);
                        if (!$related) {
                            $related = $relationshipModel->make();
                        }

                        $related->fill(Arr::except($item, [$primaryKey]))
                            ->syncBelongsTo($item, is_array($children) ? array_keys($children) : [])
                            ->save();

                        $related->afterSync($item);

                        if (is_array($children)) {
                            $related->syncRelationshipsFromTree($children, $item);
                        }
                    }

                    // Don't use quick delete, otherwise it won't trigger observers
                    $toRemove->each(function ($id) use ($relationshipModel) {
                        if ($model = $relationshipModel->getRelated()->newModelQuery()->find($id)) {
                            $model->delete();
                        }
                    });
                } else if (is_a($relationshipModel, BelongsToMany::class)) {
                    /** @var $relationshipModel BelongsToMany */

                    $ids = collect($new)->pluck($primaryKey)->filter()->values()->toArray();
                    $relationshipModel->sync($ids);
                }

                if ($this->relationLoaded($relationship)) {
                    $this->unsetRelation($relationship);
                }
            }
        }
    }

    public function getNestedRules($relationships)
    {
        $rules = $this->getSyncValidationRules();

        if (is_iterable($relationships)) {
            foreach ($relationships as $relationship => $children) {
                $relationshipModel = $this->{$relationship}();
                $snake = Str::snake($relationship);
                $related = $relationshipModel->getRelated();

                if (SyncableHelpers::isRelationOneToMany($relationshipModel)) {
                    $key = SyncableHelpers::isRelationMany($relationshipModel) ? ($snake . '.*') : $snake;
                    $rules[$key] = $related->getNestedRules($children);
                } else if (is_a($relationshipModel, BelongsTo::class)) {
                    $pk = $related->getKeyName();
                    $rules["$snake.$pk"] = Rule::exists($related->getTable(), $pk);
                }
            }
        }


        return SyncableHelpers::dot($rules);
    }

    public function getCompleteRules($relationships, $data)
    {
        $rules = $this->getNestedRules($relationships);

        // Change all subfields to be only required if they have a PK

        return $rules;
    }

    public function getDataWithExists($relationships, $data, $exists = null)
    {
        $data['_exists'] = is_null($exists) ? !empty($data[$this->getKeyName()]) : $exists;

        if (is_iterable($relationships)) {
            foreach ($relationships as $relationship => $children) {
                $relationshipModel = $this->{$relationship}();
                $snake = Str::snake($relationship);
                $related = $relationshipModel->getRelated();

                if ($item = Arr::get($data, $snake)) {
                    if (SyncableHelpers::isRelationSingle($relationshipModel)) {
                        $data[$snake] = $related->getDataWithExists($children, $item);
                    } else {
                        $data[$snake] = array_map(function ($item) use ($related, $children) {
                            return $related->getDataWithExists($children, $item);
                        }, $data[$snake]);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * @param $relationships
     * @param $data
     *
     * @throws ValidationException
     */
    protected function validateFromTree($relationships, $data)
    {
        $rules = $this->getCompleteRules($relationships, $data);
        $data = $this->getDataWithExists($relationships, $data, $this->exists);
        $validator = Validator::make($data, $rules, $this->getSyncValidationMessages());
        $validator->validate();
    }


    public function syncRelationships($dotRelationship, $data)
    {
        $tree = SyncableHelpers::parseRelationships($dotRelationship);
        $this->syncRelationshipsFromTree($tree, $data);
        return $this;
    }

    public function syncBelongsToFromDot($dotRelationship, $data)
    {
        $tree = SyncableHelpers::parseRelationships($dotRelationship);
        return $this->syncBelongsTo($data, array_keys($tree));
    }

    public function validateForSync($dotRelationship, $data)
    {
        $tree = SyncableHelpers::parseRelationships($dotRelationship);
        $this->validateFromTree($tree, $data);
        return $this;
    }

    /**
     * Will associate all belongsTo relationships that have been passed
     * Will not save
     * Not recursive
     *
     * @param $data
     * @param null $toSync
     */
    public function syncBelongsTo($data, $relationships = [])
    {
        foreach ($relationships as $relationship) {
            $snake = Str::snake($relationship);
            $relationshipModel = $this->{$relationship}();

            if (is_a($relationshipModel, BelongsTo::class) && Arr::has($data, $snake)) {
                /** @var $relationshipModel BelongsTo */
                if ($parent = SyncableHelpers::relatedExists($relationshipModel, $data[$snake])) {
                    $relationshipModel->associate($parent);
                } else {
                    $relationshipModel->dissociate();
                }
            }
        }
        return $this;
    }

    /**
     * @param $data
     * @param array $toSync
     * @return $this
     *
     * @throws ValidationException
     */
    public function saveAndSync($data, array $toSync = null)
    {
        // Default to syncable property on model
        $toSync = is_null($toSync) ? $this->getSyncable() : $toSync;

        $this->validateForSync($toSync, $data);

        $data = $this->beforeSync($data);

        $this->fill($data)
            ->syncBelongsToFromDot($toSync, $data)
            ->save();

        $this->syncRelationships($toSync, $data);

        return $this->afterSync($data);
    }

    public function getSyncableTypeAttribute()
    {
        $alias = array_search(static::class, Relation::$morphMap);
        return $alias === false ? static::class : $alias;
    }

    protected function initializeSyncableTrait()
    {
        $this->appends[] = 'syncable_type';
    }
}

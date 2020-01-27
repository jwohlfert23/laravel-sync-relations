<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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

    /**
     * @param Relation $relation
     * @param $item
     * @return Model|null|SyncableTrait
     */
    protected function relatedExists(Relation $relation, $item)
    {
        $primaryKey = $relation->getRelated()->getKeyName();
        if (!empty($item[$primaryKey])) {
            return $relation->getRelated()->newModelQuery()->find($item[$primaryKey]);
        }
        return null;
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

                if (is_a($relationshipModel, HasOneOrMany::class)) {
                    /** @var $relationshipModel HasOneOrMany */

                    // Handle hasOne relationships
                    if (is_a($relationshipModel, HasOne::class)) {
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

                        $related = $this->relatedExists($relationshipModel, $item);
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
            }
        }
    }

    public function getCompleteRules($relationships)
    {
        $rules = $this->getSyncValidationRules();
        if (!is_iterable($relationships)) {
            return $rules;
        }
        foreach ($relationships as $relationship => $children) {
            $relationshipModel = $this->{$relationship}();
            $snake = Str::snake($relationship);

            // Only validate HasOne or HasMany for now
            if (!is_a($relationshipModel, HasOneOrMany::class)) {
                continue;
            }
            $key = is_a($relationshipModel, HasMany::class) ? $snake . '.*' : $snake;
            $rules[$key] = $relationshipModel->getRelated()->getCompleteRules($children);
        }

        return Arr::dot($rules);
    }

    /**
     * @param $relationships
     * @param $data
     *
     * @throws ValidationException
     */
    protected function validateFromTree($relationships, $data)
    {
        $rules = $this->getCompleteRules($relationships);

        $validator = Validator::make($data, $rules, $this->getSyncValidationMessages());
        $validator->validate();
    }

    public function parseRelationships($dot)
    {
        $arr = [];
        $relations = is_string($dot) ? func_get_args() : $dot;
        foreach ($relations as $relation) {
            Arr::set($arr, $relation, true);
        }
        return $arr;
    }

    public function syncRelationships($dotRelationship, $data)
    {
        $tree = $this->parseRelationships($dotRelationship);
        $this->syncRelationshipsFromTree($tree, $data);
        return $this;
    }

    public function syncBelongsToFromDot($dotRelationship, $data)
    {
        $tree = $this->parseRelationships($dotRelationship);
        return $this->syncBelongsTo($data, array_keys($tree));
    }

    public function validateForSync($dotRelationship, $data)
    {
        $tree = $this->parseRelationships($dotRelationship);
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
                if ($parent = $this->relatedExists($relationshipModel, $data[$snake])) {
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
        // If nothing provided here, use syncable property on model
        if (is_null($toSync)) {
            $toSync = $this->getSyncable();
        }

        $this->validateForSync($toSync, $data);

        $this->fill($data)
            ->syncBelongsToFromDot($toSync, $data)
            ->save();

        $this->syncRelationships($toSync, $data);

        return $this;
    }

}

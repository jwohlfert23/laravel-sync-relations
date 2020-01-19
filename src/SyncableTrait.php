<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    protected function iterateOverList($relationships, $data, callable $callback)
    {
        if (!is_iterable($relationships))
            return;

        foreach ($relationships as $relationship => $children) {
            $snake = Str::snake($relationship);
            $relationshipModel = $this->{$relationship}();

            if (Arr::has($data, $snake)) {
                $new = Arr::get($data, $snake);

                $callback($relationshipModel, $new, function ($related, $item) use ($children, $callback) {
                    $related->iterateOverList($children, $item, $callback);
                });
            }
        }
    }

    /**
     * @param Relation $relation
     * @param $item
     * @return Model|null
     */
    protected function relatedExists(Relation $relation, $item)
    {
        $primaryKey = $relation->getRelated()->getKeyName();
        if (!empty($item[$primaryKey])) {
            return $relation->getRelated()->newModelQuery()->find($item[$primaryKey]);
        }
        return null;
    }

    protected function syncFromList(array $relationships, $data)
    {
        $this->iterateOverList($relationships, $data, function (Relation $relationshipModel, $new, $cb) {
            $relatedModel = $relationshipModel->getRelated();
            $primaryKey = $relatedModel->getKeyName();

            if (is_a($relationshipModel, BelongsTo::class)) {
                /** @var $relationshipModel BelongsTo */
                if ($parent = $this->relatedExists($relationshipModel, $new, false)) {
                    $relationshipModel->associate($parent)->save();
                } else {
                    $relationshipModel->dissociate()->save();
                }
            } else if (is_a($relationshipModel, HasOneOrMany::class)) {
                /** @var $relationshipModel HasOneOrMany */

                // Handle hasOne relationships
                if ($new && !empty($new[$primaryKey])) {
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

                    if ($related = $this->relatedExists($relationshipModel, $item)) {
                        $related->update(Arr::except($item, [$primaryKey]));
                    } else {
                        $related = $relationshipModel->create($item);
                    }

                    $related->afterSync($item);

                    $cb($related, $item);
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
        });
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
    protected function validateFromList($relationships, $data)
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
        $array = $this->parseRelationships($dotRelationship);
        $this->syncFromList($array, $data);
        return $this;
    }

    public function validateForSync($dotRelationship, $data)
    {
        $array = $this->parseRelationships($dotRelationship);
        $this->validateFromList($array, $data);
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

        $this->fill($data);
        $this->save();

        $this->syncRelationships($toSync, $data);

        return $this;
    }

}

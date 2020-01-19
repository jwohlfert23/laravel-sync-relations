<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait SyncableTrait
{
    protected function getSyncValidationRules()
    {
        return [];
    }

    protected function getSyncValidationMessages()
    {
        return [];
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

    protected function syncFromList(array $relationships, $data, $orderProp = null)
    {
        $this->iterateOverList($relationships, $data, function ($relationshipModel, $new, $cb) use ($orderProp) {
            if (is_a($relationshipModel, BelongsTo::class)) {
                /** @var $relationshipModel BelongsTo */
                $parent = $relationshipModel->getRelated()->find($new['id']);
                $relationshipModel->associate($parent)->save();
            } else if (is_a($relationshipModel, HasOneOrMany::class)) {
                /** @var $relationshipModel HasOneOrMany */

                // Handle hasOne relationships
                if ($new && !empty($new['id'])) {
                    $new = [$new];
                }

                $toRemove = $relationshipModel->pluck('id')->filter(function ($id) use ($new) {
                    return !collect($new)->pluck('id')->contains($id);
                });

                foreach ($new as $index => $item) {
                    $relatedModel = $relationshipModel->getRelated();
                    $item = $relatedModel->beforeSync($item);

                    if ($orderProp) {
                        Arr::set($item, $orderProp, count($new) + 1 - $index);
                    }

                    $primaryKey = $relatedModel->getKeyName();
                    if (!empty($item[$primaryKey]) && ($related = $relationshipModel->find($item[$primaryKey]))) {
                        $related->update(Arr::except($item, [$primaryKey]));
                    } else {
                        $related = $relationshipModel->create($item);
                    }

                    $related->afterSync($item);

                    $cb($related, $item);
                }

                // Don't use quick delete, otherwise it won't trigger observers
                $relationshipModel->whereIn('id', $toRemove)->get()->each->delete();
            }
        });
    }

    /**
     * @param $relationships
     * @param $data
     */
    protected function validateFromList($relationships, $data)
    {
        $this->iterateOverList($relationships, $data, function ($relationshipModel, $new, $cb) {
            if (is_a($relationshipModel, BelongsTo::class)) {
                /** @var $relationshipModel BelongsTo */

            } else if (is_a($relationshipModel, HasOneOrMany::class)) {
                foreach ($new as $index => $item) {

                    $relatedModel = $relationshipModel->getRelated();

                    /** @var SyncableTrait $relatedModel */
                    $rules = $relatedModel->getSyncValidationRules();
                    $messages = $relatedModel->getSyncValidationMessages();

                    $validator = Validator::make($item, $rules, $messages);
                    $validator->validate();


                    $cb($relatedModel, $item);
                }
            }
        });
    }

    protected function parseRelationships($dot)
    {
        $arr = [];
        $relations = is_string($dot) ? func_get_args() : $dot;
        foreach ($relations as $relation) {
            Arr::set($arr, $relation, true);
        }
        return $arr;
    }

    public function syncRelationships($dotRelationship, $data, $orderProp = null)
    {
        $array = $this->parseRelationships($dotRelationship);
        $this->syncFromList($array, $data, $orderProp);
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
    public function saveAndSync($data, array $toSync)
    {
        $this->validateForSync($toSync, $data);

        Validator::make($data, $this->getSyncValidationRules(), $this->getSyncValidationMessages())->validate();

        $this->fill($data);
        $this->save();

        $this->syncRelationships($toSync, $data);

        return $this;
    }

}

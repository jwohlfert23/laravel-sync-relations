<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait SyncableTrait
{

    public function mapRequestData(array $data)
    {
        return $data;
    }

    public function beforeSave($data)
    {
        return $data;
    }

    public function afterSave($data)
    {
        return $this;
    }

    protected function syncFromList(array $relationships, $data, $orderProp = null)
    {
        foreach ($relationships as $relationship => $children) {
            $snake = Str::snake($relationship);
            if (Arr::has($data, $snake)) {
                $new = Arr::get($data, $snake);

                // Handle hasOne relationships
                if ($new && !empty($new['id'])) {
                    $new = [$new];
                }

                $toRemove = $this->{$relationship}->pluck('id')->filter(function ($id) use ($new) {
                    return !collect($new)->pluck('id')->contains($id);
                });

                foreach ($new as $index => $item) {
                    /** @var HasOneOrMany $relationshipModel */
                    $relationshipModel = $this->{$relationship}();
                    $relatedModel = $relationshipModel->getRelated();
                    $item = $relatedModel->mapRequestData($item);

                    if ($orderProp) {
                        $item[$orderProp] = count($new) + 1 - $index;
                    }

                    if (!empty($item['id']) && ($related = $this->{$relationship}()->find($item['id']))) {
                        $related->update(Arr::except($item, ['id']));
                        $related->afterSave($item);
                    } else {
                        $related = $this->{$relationship}()->create($item);
                        $related->afterSave($item);
                    }

                    if (is_array($children)) {
                        $related->syncFromList($children, $item);
                    }
                }

                // Don't use quick delete, otherwise it won't trigger observers
                $this->{$relationship}()->whereIn('id', $toRemove)->get()->each->delete();
            }
        }
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

}

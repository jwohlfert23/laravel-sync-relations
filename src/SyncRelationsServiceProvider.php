<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SyncRelationsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Validator::extendImplicit('required_exists', function ($attribute, $value, $parameters, \Illuminate\Validation\Validator $validator) {
            $data = $validator->getData();
            $existsKey = SyncableHelpers::getLocalKey($attribute, '_exists');

            // Is new model
            if (empty(Arr::get($data, $existsKey))) {
                return $validator->validateRequired($attribute, $value);
            }
            // As long as it's not null|false|empty string, we're good
            return !empty(Arr::get($data, $attribute, '_unset'));
        }, "The :attribute field cannot be empty.");

        Validator::extend('unique_exists', function ($attribute, $value, $parameters, \Illuminate\Validation\Validator $validator) {
            $data = $validator->getData();
            $pk = Arr::get($data, SyncableHelpers::getLocalKey($attribute, '_pk'));
            $pkName = Arr::get($data, SyncableHelpers::getLocalKey($attribute, '_pk_name'), 'id');

            $newParams = array_slice($parameters, 0, 2);
            if (!empty($pk)) {
                $newParams[2] = $pk;
                $newParams[3] = $pkName;
            }

            return $validator->validateUnique($attribute, $value, $newParams);

        }, 'The :attribute has already been taken.');
    }
}

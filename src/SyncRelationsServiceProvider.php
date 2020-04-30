<?php namespace Jwohlfert23\LaravelSyncRelations;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SyncRelationsServiceProvider extends ServiceProvider
{
    public function boot()
    {
        Validator::extendImplicit('required_exists', function ($attribute, $value, $parameters, \Illuminate\Validation\Validator $validator) {
            $data = $validator->getData();

            // Get _exists key
            $parts = explode('.', $attribute);
            $parts[count($parts) - 1] = '_exists';
            $existsKey = implode('.', $parts);

            // Is new model
            if (empty(Arr::get($data, $existsKey))) {
                return $validator->validateRequired($attribute, $value);
            }
            // As long as it's not null|false|empty string, we're good
            return !empty(Arr::get($data, $attribute, '_unset'));
        }, "The :attribute field cannot be empty.");
    }
}

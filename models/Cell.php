<?php namespace Models;

use Illuminate\Validation\Rule;

class Cell extends BaseModel
{
    protected function getSyncValidationRules()
    {
        return [
            'content' => 'required_without:{text}',
            'text' => 'required_without:{content}',
            'jack' => Rule::unique('names')
        ];
    }
}

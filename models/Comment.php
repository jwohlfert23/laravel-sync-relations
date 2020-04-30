<?php namespace Models;

use Illuminate\Validation\Rule;

class Comment extends BaseModel
{
    protected $fillable = ['comment', 'type'];

    protected function getSyncValidationRules()
    {
        return [
            'comment' => 'required',
            'author' => 'required_exists',
            'type' => [Rule::in('public', 'private')]
        ];
    }

    public function author()
    {
        return $this->belongsTo(Author::class);
    }

    public function childComments()
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }
}

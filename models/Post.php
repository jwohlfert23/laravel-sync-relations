<?php namespace Models;

use Illuminate\Validation\Rule;

class Post extends BaseModel
{
    protected $fillable = ['title', 'notes'];
    protected $syncable = ['comments', 'author'];

    protected function getSyncValidationRules()
    {
        return [
            'title' => 'required',
            'author' => 'required_exists',
        ];
    }

    public function author()
    {
        return $this->belongsTo(Author::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}

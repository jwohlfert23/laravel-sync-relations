<?php namespace Models;

class Post extends BaseModel
{
    protected $fillable = ['title'];
    protected $syncable = ['comments'];

    protected function getSyncValidationRules()
    {
        return [
            'title' => 'required'
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

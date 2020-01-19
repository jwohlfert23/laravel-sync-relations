<?php namespace Models;

class Comment extends BaseModel
{
    protected $fillable = ['comment'];

    protected function getSyncValidationRules()
    {
        return [
            'comment' => 'required'
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

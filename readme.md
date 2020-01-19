### Laravel Sync Relations


Add this to your models:

```php
use Jwohlfert23/LaravelSyncRelations/SyncableTrait;
...

class Post extends Model {
    use SyncableTrait;
}
```


And you can use it in your controllers like so:

```php

public function update($id) {
    $post = Post::find($id);
    
    $post->saveAndSync(request()->input(), ['tags']);
}
```

Let's assume the following
- Post model has a "hasMany" relationship with tags.
- The request data looks like:

```json
{
  "title": "My Post Title",
  "tags": [{
    "id": 1,
    "name": "My First Tag"
  }, {
     "name": "My Second Tag"
  }]
}
```

By running `saveAndSync`, it will iterate over the 2 tags provided in the request. Because the first one already exists, it will update that one and set the name to "My First Tag". The second one doesn't exist aleady so it will create it using the name "My Second Tag".  After this controller processeed this request, you could run the following:

```php
Post::find($id)->tags->pluck('name')
```
and get this:
```php
["My First Tag", "My Second Tag"]
```

This package also works with "belongsToMany" and "belongsTo" relationship types.  However, for those, it will not update/create the nested objects.  It will only associate the existing models with the parent model (in other words, for these relationship, you must provide the primary key for each child).

Additionally, you could run `saveAndSync(request()->input(), ['comments.author'])` to do a nested sync.

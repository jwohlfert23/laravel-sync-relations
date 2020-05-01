<?php namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Jwohlfert23\LaravelSyncRelations\SyncableHelpers;
use Jwohlfert23\LaravelSyncRelations\SyncRelationsServiceProvider;
use Models\Author;
use Models\Category;
use Models\Comment;
use Models\Post;
use Orchestra\Testbench\TestCase;

class SyncableTraitTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        app()->register(SyncRelationsServiceProvider::class);

        Schema::dropIfExists('posts');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_post');
        Schema::dropIfExists('comments');

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->string('title');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedInteger('post_id');
            $table->string('comment');
            $table->string('type')->nullable();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('category_post', function (Blueprint $table) {
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('category_id');
        });

        Author::create(['name' => 'Jack']);
    }


    public function testInvalid()
    {
        $post = new Post();

        $data = [
            'title' => 'Post #1',
            'comments' => [[
                'comment' => null
            ]]
        ];

        try {
            $post->saveAndSync($data, ['comments']);
            $this->fail("Expected exception not thrown");
        } catch (ValidationException $e) {
            $this->assertEquals($e->errors()["comments.0.comment"], [
                0 => "The comments.0.comment field is required."
            ]);
            $this->assertEquals(Post::count(), 0);
            $this->assertEquals(Comment::count(), 0);
        }
    }

    public function testCreations()
    {
        $post = new Post();

        $data = [
            'title' => 'Post #1',
            'author' => ['id' => 1],
            'comments' => [[
                'author' => ['id' => 1],
                'comment' => 'My Comment'
            ]]
        ];

        $post->saveAndSync($data);

        $this->assertEquals(Post::count(), 1);
        $post = Post::first();

        $this->assertEquals($post->id, 1);
        $this->assertEquals($post->comments()->count(), 1);
        $this->assertEquals($post->comments()->first()->comment, 'My Comment');
    }

    public function testAll()
    {
        $post = Post::forceCreate([
            'title' => 'Post #1',
        ]);

        $category1 = Category::forceCreate([
            'name' => 'Category #1'
        ]);

        $category2 = Category::forceCreate([
            'name' => 'Category #2'
        ]);

        $category3 = Category::forceCreate([
            'name' => 'Category #3'
        ]);


        $author = Author::forceCreate([
            'name' => 'Jack'
        ]);

        $comment = $post->comments()->create([
            'comment' => 'My Comment'
        ]);

        $post->comments()->create([
            'comment' => 'Should Be Deleted'
        ]);

        $post->categories()->sync([$category3->id]);


        $data = $post->toArray();
        $data['author'] = $author->toArray();
        $data['comments'] = [];
        $data['comments'][0] = $comment->toArray();
        $data['comments'][0]['author'] = ['id' => $author->id];
        $data['comments'][1] = ['comment' => 'New Comment', 'author' => $author->toArray()];
        $data['categories'] = [$category1->toArray(), $category2->toArray()];

        $post->saveAndSync($data, ['comments.author', 'author', 'categories']);

        $post = Post::first();

        // Check Posts
        $this->assertEquals(Post::count(), 1);
        $this->assertEquals($post->id, 1);

        // Check Author
        $this->assertEquals($post->author->id, 2);

        // Check Comments
        $comments = $post->comments()->get();
        $this->assertEquals($comments->count(), 2);
        $this->assertEquals($comments->offsetGet(0)->comment, 'My Comment');
        $this->assertEquals($comments->offsetGet(1)->comment, 'New Comment');
        $this->assertEquals($comments->offsetGet(0)->author->name, 'Jack');

        // Check Categories
        $categories = $post->categories()->get();
        $this->assertEquals($categories->count(), 2);
    }

    public function testValidationFails()
    {
        $post = new Post();

        $data = [
            'title' => 'Post #1',
            'author' => ['id' => 1],
            'comments' => [[
                'id' => 123,
                'comment' => 'jack'
            ], [
                'id' => 123,
                'author' => null,
                'comment' => 'jack'
            ], [
                'comment' => 'jack'
            ]]
        ];

        try {
            $post->saveAndSync($data, ['comments', 'author']);
            $this->fail("Expected exception not thrown");
        } catch (ValidationException $e) {
            $this->assertEquals(Post::count(), 0);
            $this->assertEquals(Comment::count(), 0);

            // The last two comments should fail
            $this->assertCount(2, $e->errors());
        }
    }

    public function testPartialValidationPasses()
    {
        $post = Post::create([
            'title' => 'Hello'
        ]);

        $comment = $post->comments()->create([
            'comment' => 'Hello!'
        ]);

        $data = [
            'title' => 'Jack',
            'notes' => 'This is a great post!',
            'comments' => [[
                'id' => $comment->id,
                'comment' => 'this is a note',
                'type' => 'public'
            ]]
        ];

        $post->saveAndSync($data);

        $this->assertEquals($post->notes, 'This is a great post!');
        $this->assertEquals($post->comments[0]->comment, 'this is a note');
    }

    public function testUnique()
    {
        $post = Post::create(['title' => 'Unique Post']);

        $post->saveAndSync(['title' => 'Unique Post']);

        try {
            $newPost = new Post();
            $newPost->saveAndSync([
                'title' => 'Unique Post',
                'author' => ['id' => 1]
            ]);
            $this->fail("Expected exception not thrown");
        } catch (ValidationException $e) {
            dd($e->errors());
            $this->assertCount(1, $e->errors());
        }
    }
}

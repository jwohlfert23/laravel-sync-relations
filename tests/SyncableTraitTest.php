<?php namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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

        Schema::dropIfExists('posts');
        Schema::dropIfExists('authors');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('category_post');
        Schema::dropIfExists('comments');

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
            $table->unsignedInteger('post_id');
            $table->string('comment');
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
    }

    public function testCreations()
    {
        $post = new Post();

        $data = [
            'title' => 'Post #1',
            'comments' => [[
                'comment' => 'My Comment'
            ]]
        ];

        $post->saveAndSync($data, ['comments']);

        $this->assertEquals(Post::count(), 1);
        $post = Post::first();

        $this->assertEquals($post->id, 1);
        $this->assertEquals($post->comments()->count(), 1);
        $this->assertEquals($post->comments()->first()->comment, 'My Comment');
    }

    public function testAll()
    {
        $post = Post::forceCreate([
            'title' => 'Post #1'
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
        $data['comments'] = [];
        $data['comments'][0] = $comment->toArray();
        $data['comments'][0]['author'] = ['id' => $author->id];
        $data['comments'][1] = ['comment' => 'New Comment'];
        $data['categories'] = [$category1->toArray(), $category2->toArray()];

        $post->saveAndSync($data, ['comments.author', 'author', 'categories']);

        $post = Post::first();

        // Check Posts
        $this->assertEquals(Post::count(), 1);
        $this->assertEquals($post->id, 1);

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

    public function testValidation()
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
            $this->assertEquals(Post::count(), 0);
            $this->assertEquals(Comment::count(), 0);
        }
    }
}
